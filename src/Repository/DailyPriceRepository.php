<?php

declare(strict_types=1);

namespace App\Repository;

use App\Aggregation\PriceReport;
use App\Codec\MetadataCodecFactory;
use App\Document\DailyPrice;
use App\Document\EmbeddedDailyPrice;
use App\Document\Price;
use App\Document\Station;
use App\Fuel;
use Closure;
use DateTimeImmutable;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\ODM\MongoDB\Iterator\Iterator;
use MongoDB\Builder\BuilderEncoder;
use MongoDB\Builder\Expression;
use MongoDB\Builder\Pipeline;
use MongoDB\Builder\Stage;
use MongoDB\Builder\Stage\ReplaceWithStage;
use MongoDB\Builder\Type\ExpressionInterface;

use function count;
use function MongoDB\object;

class DailyPriceRepository extends AbstractRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly BuilderEncoder $encoder,
        private readonly MetadataCodecFactory $codec,
    ) {
        parent::__construct($registry, DailyPrice::class);
    }

    public function getLatestPricesForStation(Station $station): Iterator
    {
        return $this->createQueryBuilder()
            ->field('station.referencedStation')
            ->equals($station->id)
            ->sort('day', -1)
            ->limit(count(Fuel::cases()))
            ->getQuery()
            ->execute();
    }

    public function getLast30DaysDieselForStation(Station $station): Iterator
    {
        return $this->getLast30DaysForStationAndFuel($station, Fuel::Diesel);
    }

    public function getLast30DaysE5ForStation(Station $station): Iterator
    {
        return $this->getLast30DaysForStationAndFuel($station, Fuel::E5);
    }

    public function getLast30DaysE10ForStation(Station $station): Iterator
    {
        return $this->getLast30DaysForStationAndFuel($station, Fuel::E10);
    }

    private function getLast30DaysForStationAndFuel(Station $station, Fuel $fuel): Iterator
    {
        return $this->createQueryBuilder()
            ->field('station.referencedStation')
            ->equals($station)
            ->field('fuel')
            ->equals($fuel)
            ->sort('day', -1)
            ->limit(30)
            ->getQuery()
            ->execute();
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
            ->pipeline(
                // TODO: encode call can be removed once PHPLIB supports pipeline updates in findOneAndUpdate
                $this->encoder->encode(self::getDailyPriceUpdatePipeline(Expression::variable('priceDocument'))),
            )
            ->getQuery([
                'let' => ['priceDocument' => $encodedPriceDocument],
            ])
            ->execute();

        if (! isset($dailyPrice->station->name)) {
            // An empty name indicates that we've just created a new DailyPrice document
            // Copy over station details, then update the opening price
            $dailyPrice->station->refreshData();
            $this->dm->flush();

            $dailyPrice = $this->updateOpeningPrice($station, $dailyPrice);
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

        // TODO: encode call can be removed once PHPLIB supports pipeline updates in findOneAndUpdate
        $update = $previousPrice
            ? $this->encoder->encode(self::getUpdateOpeningPricePipeline($previousPrice->closingPrice))
            : ['$set' => ['openingPrice' => null]];

        return $this->createQueryBuilder()
            ->findAndUpdate()
            ->returnNew()
            ->field('id')->equals($dailyPrice->id)
            ->pipeline($update)
            ->getQuery()
            ->execute();
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
        );
    }

    private static function getUpdateOpeningPricePipeline(float $closingPrice): Pipeline
    {
        return new Pipeline(
            Stage::set(
                prices: self::updateOpeningPriceInFirstPriceDocument($closingPrice),
                openingPrice: $closingPrice,
            ),
            PriceReport::computeWeightedAverage(),
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

    private static function updateOpeningPriceInFirstPriceDocument(float $closingPrice): Expression\LetOperator
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
