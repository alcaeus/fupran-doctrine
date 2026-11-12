<?php

declare(strict_types=1);

namespace App\Repository;

use App\Aggregation\PriceReport;
use App\Aggregation\UuidRange;
use App\Codec\MetadataCodecFactory;
use App\DateRange;
use App\Document\DailyPrice;
use App\Document\EmbeddedDailyPrice;
use App\Document\Price;
use App\Document\Station;
use App\Document\StationPrice;
use App\Fuel;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\ODM\MongoDB\Iterator\Iterator;
use MongoDB\BSON\Binary;
use MongoDB\Builder\Expression;
use MongoDB\Builder\Pipeline;
use MongoDB\Builder\Stage;
use MongoDB\Builder\Stage\ReplaceWithStage;
use MongoDB\Builder\Type\ExpressionInterface;
use MongoDB\Collection;
use stdClass;
use UnexpectedValueException;

use function array_values;
use function count;
use function iterator_to_array;
use function MongoDB\object;

/** @extends AbstractRepository<DailyPrice> */
class DailyPriceRepository extends AbstractRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly MetadataCodecFactory $codec,
    ) {
        parent::__construct($registry, DailyPrice::class);
    }

    /**
     * Groups the raw price reports of the given stations by station, day and fuel, and merges the result into the
     * target collection. Merging on the unique index of the target makes this safe to repeat for the same stations.
     *
     * @param list<Binary> $stationIds
     */
    public function aggregatePriceReportsByDay(Collection $importCollection, Collection $into, array $stationIds): void
    {
        $cursor = $this->dm->getDocumentCollection(Station::class)->find(
            ['_id' => ['$in' => $stationIds]],
            [
                'projection' => ['name' => 1, 'brand' => 1, 'location' => 1, 'address.postCode' => 1],
                'typeMap' => ['root' => 'object', 'document' => 'object', 'array' => 'array'],
            ],
        );

        $stations = [];
        foreach ($cursor as $station) {
            if (! $station instanceof stdClass) {
                throw new UnexpectedValueException('Expected station to be an object.');
            }

            $stations[] = $station;
        }

        // Reports of stations that no longer exist cannot be aggregated
        if ($stations === []) {
            return;
        }

        $importCollection->aggregate(new Pipeline(
            PriceReport::aggregatePriceReportsByDay($stations),
            Stage::merge(
                into: $into->getCollectionName(),
                on: ['station._id', 'fuel', 'day'],
                whenMatched: 'replace',
                whenNotMatched: 'insert',
            ),
        ));
    }

    /**
     * Looks up the closing price of the last previously imported day before the first day of each station and fuel
     * in the batch. It is stored in the batch's first day for mergeAggregatedPriceReports() to use as opening price.
     */
    public function resolveBoundaryOpeningPrices(Collection $aggregatedPriceReports, ?UuidRange $stationRange = null): void
    {
        $aggregatedPriceReports->aggregate(PriceReport::resolveBoundaryOpeningPrices(
            $this->getDocumentCollection()->getCollectionName(),
            $aggregatedPriceReports->getCollectionName(),
            $stationRange,
        ));
    }

    /**
     * Updates the opening price of the first previously imported day following the batch, for each station and
     * fuel. This is needed if the batch was imported after a later day, as that day was imported without knowing
     * about the batch's closing price. The updated days are written to $updatedDays.
     */
    public function updateOpeningPricesFollowingBatch(Collection $aggregatedPriceReports, Collection $updatedDays, ?UuidRange $stationRange = null): void
    {
        $dailyPriceCollection = $this->getDocumentCollection()->getCollectionName();

        $aggregatedPriceReports->aggregate(PriceReport::findDaysFollowingBatch(
            $dailyPriceCollection,
            $updatedDays->getCollectionName(),
            $stationRange,
        ));

        $updatedDays->aggregate(new Pipeline(
            Stage::merge(
                into: $dailyPriceCollection,
                on: '_id',
                whenMatched: self::getUpdateOpeningPricePipeline(Expression::variable('new.openingPrice')),
                whenNotMatched: 'discard',
            ),
        ));
    }

    /**
     * Adds opening prices to the pre-aggregated price reports and merges them into the DailyPrice collection.
     */
    public function mergeAggregatedPriceReports(Collection $aggregatedPriceReports, ?UuidRange $stationRange = null): void
    {
        $aggregatedPriceReports->aggregate(new Pipeline(
            PriceReport::aggregatePriceData($stationRange),
            // Match on the unique index instead of _id: the _ids of pre-aggregated reports are generated on every
            // run, so an import that is repeated or recovered would not recognise days that were already merged.
            Stage::merge(
                into: $this->getDocumentCollection()->getCollectionName(),
                on: ['fuel', 'day', 'station._id'],
                whenMatched: 'keepExisting',
            ),
        ));
    }

    /**
     * Sets the latest price and the last 30 prices per fuel on stations, optionally only for a slice of stations.
     */
    public function updateLatestStationPrices(?UuidRange $stationRange = null): void
    {
        $this->getDocumentCollection()->aggregate(new Pipeline(
            PriceReport::getLatestPriceReportsPerStation($stationRange),
            Stage::merge(
                into: $this->dm->getDocumentCollection(Station::class)->getCollectionName(),
                on: '_id',
            ),
        ));
    }

    /** @return Iterator<DailyPrice> */
    public function getLatestPricesForStation(Station $station): Iterator
    {
        return $this->createQueryBuilder()
            ->field('station.referencedStation')
            ->equals($station->id)
            ->sort('day', -1)
            ->limit(count(Fuel::cases()))
            ->getQuery()
            ->getIterator();
    }

    /**
     * The daily prices of all fuels of a station on the given day. Fuels without a price change on that day have
     * no document, as their opening price carries over from the previous day.
     *
     * @return list<DailyPrice>
     */
    public function getDayPricesForStation(Station $station, DateTimeImmutable $day): array
    {
        return array_values(iterator_to_array($this->findDayPricesForStation($station, $day), false));
    }

    /** @return Iterator<DailyPrice> */
    private function findDayPricesForStation(Station $station, DateTimeImmutable $day): Iterator
    {
        return $this->createQueryBuilder()
            ->field('station.referencedStation')
            ->equals($station)
            ->field('day')
            ->equals($day->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0))
            ->sort('fuel', 1)
            ->getQuery()
            ->getIterator();
    }

    /**
     * The closing prices of a fuel on the given day for all stations in a post code, reduced to what a map needs to
     * show them. Stations without a location cannot be placed on a map and are left out.
     *
     * @return list<StationPrice>
     */
    public function getStationPricesForPostCode(Fuel $fuel, DateTimeImmutable $day, string $postCode): array
    {
        $builder = $this->createAggregationBuilder();

        /** @var array<int, StationPrice> $prices */
        $prices = iterator_to_array(
            $builder
                ->hydrate(StationPrice::class)
                ->match()
                    ->field('fuel')->equals($fuel->value)
                    ->field('day')->equals($day->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0))
                    ->field('station.address.postCode')->equals($postCode)
                    ->field('station.location')->exists(true)
                ->project()
                    ->field('_id')->expression('$station._id')
                    ->field('name')->expression('$station.name')
                    ->field('brand')->expression('$station.brand')
                    ->field('location')->expression('$station.location')
                    ->field('price')->expression('$closingPrice')
                ->getAggregation()
                ->getIterator(),
            false,
        );

        return array_values($prices);
    }

    /** @return Iterator<DailyPrice> */
    public function getPricesForStationAndFuel(Station $station, Fuel $fuel, DateRange $range): Iterator
    {
        return $this->createQueryBuilder()
            ->field('station.referencedStation')
            ->equals($station)
            ->field('fuel')
            ->equals($fuel)
            ->field('day')
            ->gte($range->from)
            ->field('day')
            ->lte($range->to)
            ->sort('day', 1)
            ->getQuery()
            ->getIterator();
    }

    public function reportPrice(
        Station $station,
        Fuel $fuel,
        float $price,
        DateTimeImmutable $date,
    ): ?DailyPrice {
        $day = $date->setTime(0, 0);
        $priceDocument = new Price($date, $price);

        $encodedPriceDocument = $this->codec->encode($priceDocument);
        $dailyPrice = $this->createQueryBuilder()
            ->findAndUpdate()
            ->upsert()
            ->returnNew()
            ->field('station.referencedStation')->equals($station)
            ->field('fuel')->equals($fuel)
            ->field('day')->equals($day)
            ->pipeline(self::getDailyPriceUpdatePipeline(Expression::variable('priceDocument')))
            ->getQuery([
                'let' => ['priceDocument' => $encodedPriceDocument],
            ])
            ->execute();

        if (! $dailyPrice instanceof DailyPrice) {
            throw new UnexpectedValueException('Expected findAndUpdate() to return a hydrated DailyPrice document.');
        }

        if (! isset($dailyPrice->station->name)) {
            // An empty name indicates that we've just created a new DailyPrice document
            // Copy over station details, then update the opening price
            $dailyPrice->station->refreshData();
            $this->dm->flush();

            $dailyPrice = $this->updateOpeningPrice($station, $dailyPrice);

            if ($dailyPrice === null) {
                throw new UnexpectedValueException('Expected updateOpeningPrice() to return the just-created DailyPrice document.');
            }
        }

        $embeddedDailyPrice = EmbeddedDailyPrice::fromDailyPrice($dailyPrice);

        $this->updateLatestDailyPriceInStation($station, $embeddedDailyPrice);

        return $dailyPrice;
    }

    private function updateOpeningPrice(Station $station, DailyPrice $dailyPrice): ?DailyPrice
    {
        $previousPrice = $this->createQueryBuilder()
            ->field('station.referencedStation')
            ->equals($station)
            ->field('fuel')
            ->equals($dailyPrice->fuel)
            ->field('day')
            ->lt($dailyPrice->day)
            ->sort('day', -1)
            ->getQuery()
            ->getSingleResult();

        if ($previousPrice !== null && ! $previousPrice instanceof DailyPrice) {
            throw new UnexpectedValueException('Expected query to return a hydrated DailyPrice document.');
        }

        $updatedPrice = $this->createQueryBuilder()
            ->findAndUpdate()
            ->returnNew()
            ->field('id')->equals($dailyPrice->id)
            ->pipeline(
                $previousPrice
                    ? self::getUpdateOpeningPricePipeline($previousPrice->closingPrice)
                    : [['$set' => ['openingPrice' => null]]],
            )
            ->getQuery()
            ->execute();

        if ($updatedPrice === null) {
            return null;
        }

        if (! $updatedPrice instanceof DailyPrice) {
            throw new UnexpectedValueException('Expected findAndUpdate() to return a hydrated DailyPrice document.');
        }

        return $updatedPrice;
    }

    private static function getDailyPriceUpdatePipeline(Expression\ResolvesToObject $priceDocument): Pipeline
    {
        return new Pipeline(
            self::upsertEmptyDocument(),
            Stage::set(
                prices: self::appendPriceToPricesArray($priceDocument),
                closingPrice: self::getPrice($priceDocument),
                lowestPrice: self::conditionallyUpdateLowestPrice($priceDocument),
                highestPrice: self::conditionallyUpdateHighestPrice($priceDocument),
            ),
            PriceReport::computeWeightedAverage(),
            PriceReport::computeChanges(),
        );
    }

    private static function getUpdateOpeningPricePipeline(float|Expression\ResolvesToNumber $closingPrice): Pipeline
    {
        return new Pipeline(
            Stage::set(
                prices: self::updateOpeningPriceInFirstPriceDocument($closingPrice),
                openingPrice: $closingPrice,
            ),
            PriceReport::computeWeightedAverage(),
            PriceReport::computeChanges(),
        );
    }

    private static function getPrice(Expression\ResolvesToObject $priceDocument): Expression\ResolvesToDouble
    {
        return Expression::getField('price', $priceDocument);
    }

    private static function appendPriceToPricesArray(Expression\ResolvesToObject $priceDocument): ExpressionInterface
    {
        $latestPrice = self::getPrice(Expression::last(Expression::arrayFieldPath('prices')));

        return Expression::concatArrays(
            Expression::arrayFieldPath('prices'),
            [
                Expression::mergeObjects(
                    $priceDocument,
                    object(
                        previousPrice: $latestPrice,
                        change: Expression::subtract(
                            self::getPrice($priceDocument),
                            $latestPrice,
                        ),
                    ),
                ),
            ],
        );
    }

    /** @param Closure(ExpressionInterface, ExpressionInterface): ExpressionInterface $comparisonExpression */
    private static function conditionallyUpdatePrice(Expression\ResolvesToObject $priceDocument, Closure $comparisonExpression, string $fieldPrefix): ExpressionInterface
    {
        return Expression::cond(
            Expression::or(
                Expression::eq(Expression::fieldPath($fieldPrefix), null),
                $comparisonExpression(
                    self::getPrice($priceDocument),
                    Expression::doubleFieldPath($fieldPrefix . '.price'),
                ),
            ),
            $priceDocument,
            Expression::fieldPath($fieldPrefix),
        );
    }

    private static function conditionallyUpdateHighestPrice(Expression\ResolvesToObject $priceDocument): ExpressionInterface
    {
        return self::conditionallyUpdatePrice($priceDocument, Expression::gt(...), 'highestPrice');
    }

    private static function conditionallyUpdateLowestPrice(Expression\ResolvesToObject $priceDocument): ExpressionInterface
    {
        return self::conditionallyUpdatePrice($priceDocument, Expression::lt(...), 'lowestPrice');
    }

    private static function upsertEmptyDocument(): ReplaceWithStage
    {
        return Stage::replaceWith(
            Expression::mergeObjects(
                object(
                    prices: [],
                    lowestPrice: null,
                    highestPrice: null,
                ),
                Expression::variable('ROOT'),
            ),
        );
    }

    private static function updateOpeningPriceInFirstPriceDocument(float|Expression\ResolvesToNumber $closingPrice): Expression\LetOperator
    {
        return Expression::let(
            [
                'firstPrice' => Expression::first(Expression::arrayFieldPath('prices')),
                'remainingPrices' => Expression::slice(
                    Expression::arrayFieldPath('prices'),
                    Expression::subtract(1, Expression::size(Expression::arrayFieldPath('prices'))),
                ),
            ],
            Expression::concatArrays(
                [
                    Expression::mergeObjects(
                        Expression::variable('firstPrice'),
                        object(
                            previousPrice: $closingPrice,
                            change: Expression::subtract(
                                self::getPrice(Expression::variable('firstPrice')),
                                $closingPrice,
                            ),
                        ),
                    ),
                ],
                Expression::variable('remainingPrices'),
            ),
        );
    }

    public function updateLatestDailyPriceInStation(Station $station, EmbeddedDailyPrice $embeddedDailyPrice): void
    {
        // Update latestPrice and latestPrices embedded documents in the Station
        $this->getDocumentManager()
            ->createQueryBuilder(Station::class)
            ->updateOne()
            ->field('id')->equals($station->id)
            ->pipeline(
                PriceReport::updateLatestPriceInStation(
                    fuel: $embeddedDailyPrice->fuel,
                    embeddedDailyPrice: Expression::variable('priceDocument'),
                ),
            )
            ->getQuery([
                'let' => ['priceDocument' => $this->codec->encode($embeddedDailyPrice)],
            ])
            ->execute();
    }
}
