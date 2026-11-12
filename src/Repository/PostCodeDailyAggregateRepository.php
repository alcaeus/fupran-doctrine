<?php

declare(strict_types=1);

namespace App\Repository;

use App\Aggregation\PriceReport;
use App\DateRange;
use App\Document\CompoundDailyAggregate;
use App\Document\DailyAggregate;
use App\Document\PostCodeDailyAggregate;
use App\Fuel;
use DateTimeImmutable;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Builder\Accumulator;
use MongoDB\Builder\Expression;
use MongoDB\Builder\Pipeline;
use MongoDB\Builder\Query;
use MongoDB\Builder\Stage;

use function array_map;
use function MongoDB\object;
use function preg_quote;
use function round;
use function usort;

/** @extends AbstractRepository<PostCodeDailyAggregate> */
class PostCodeDailyAggregateRepository extends AbstractRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly DailyPriceRepository $dailyPriceRepository,
    ) {
        parent::__construct($registry, PostCodeDailyAggregate::class);
    }

    /** @param list<UTCDateTime>|null $days Restricts the computation to these days; all days if omitted */
    public function recomputeDailyAggregates(?array $days = null): void
    {
        $pipeline = new Pipeline(
            PriceReport::computeDailyAggregatesPerPostCode($days),
            Stage::merge(
                $this->getDocumentCollection()->getCollectionName(),
                on: ['day', 'fuel', 'postCode'],
                whenMatched: 'replace',
            ),
        );

        $this->dailyPriceRepository
            ->getDocumentCollection()
            ->aggregate($pipeline);
    }

    /**
     * Reads the raw documents, as hydrating a document for each of the thousands of post codes would be wasteful
     *
     * @param list<string>|null $postCodes Restricts the result to these post codes; all post codes if omitted
     *
     * @return array<string, float> Weighted average price of the day, rounded to the three decimals of a fuel price, by post code
     */
    public function getWeightedAveragePrices(Fuel $fuel, DateTimeImmutable $day, ?array $postCodes = null): array
    {
        $filter = ['fuel' => $fuel->value, 'day' => new UTCDateTime($day)];
        if ($postCodes !== null) {
            $filter['postCode'] = ['$in' => $postCodes];
        }

        $aggregates = $this->getDocumentCollection()->find(
            $filter,
            [
                'projection' => ['_id' => 0, 'postCode' => 1, 'weightedAveragePrice' => 1],
                'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            ],
        );

        $prices = [];
        /** @var array{postCode: string, weightedAveragePrice: float} $aggregate */
        foreach ($aggregates as $aggregate) {
            $prices[$aggregate['postCode']] = round($aggregate['weightedAveragePrice'], 3);
        }

        return $prices;
    }

    /**
     * Combines the post codes starting with the same digits, weighting each post code by its number of stations so the
     * result is the average over all stations in the region. Aggregates without a station count are ignored.
     *
     * @param positive-int $digits Number of leading digits of the post code that make up a region
     *
     * @return array<string, float> Weighted average price of the day, rounded to the three decimals of a fuel price, by region
     */
    public function getRegionalWeightedAveragePrices(Fuel $fuel, DateTimeImmutable $day, int $digits): array
    {
        $regions = $this->getDocumentCollection()->aggregate(
            new Pipeline(
                Stage::match(fuel: $fuel->value, day: new UTCDateTime($day), stationCount: Query::gt(0)),
                Stage::group(
                    _id: Expression::substrCP(Expression::stringFieldPath('postCode'), 0, $digits),
                    priceSum: Accumulator::sum(Expression::multiply(
                        Expression::doubleFieldPath('weightedAveragePrice'),
                        Expression::intFieldPath('stationCount'),
                    )),
                    stationCount: Accumulator::sum(Expression::intFieldPath('stationCount')),
                ),
            ),
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
        );

        $prices = [];
        /** @var array{_id: string, priceSum: float, stationCount: int} $region */
        foreach ($regions as $region) {
            $prices[$region['_id']] = round($region['priceSum'] / $region['stationCount'], 3);
        }

        return $prices;
    }

    /**
     * The history of an area (see {@see getAggregate()} for how post codes are combined), shaped like the aggregates
     * of Germany so that the same charts and cards can show it. Only the average, lowest and highest price and the
     * station count are set on the aggregates, as percentiles and changes cannot be combined from post codes. Days
     * without prices for all fuels are left out.
     *
     * @param string $postCodePrefix Two digits for a region, five for a post code; use {@see DailyAggregateRepository} for all of Germany
     *
     * @return list<CompoundDailyAggregate> Newest day first
     */
    public function getCompoundDailyAggregates(string $postCodePrefix, DateRange $range): array
    {
        $groups = $this->getDocumentCollection()->aggregate(
            new Pipeline(
                // Including all fuels allows using the index on fuel, day and post code
                Stage::match(
                    fuel: Query::in(array_map(static fn (Fuel $fuel): string => $fuel->value, Fuel::cases())),
                    day: Query::fieldQuery(Query::gte(new UTCDateTime($range->from)), Query::lte(new UTCDateTime($range->to))),
                    postCode: new Regex('^' . preg_quote($postCodePrefix, '/')),
                    stationCount: Query::gt(0),
                ),
                Stage::group(
                    _id: object(day: Expression::fieldPath('day'), fuel: Expression::fieldPath('fuel')),
                    priceSum: Accumulator::sum(Expression::multiply(
                        Expression::doubleFieldPath('weightedAveragePrice'),
                        Expression::intFieldPath('stationCount'),
                    )),
                    stationCount: Accumulator::sum(Expression::intFieldPath('stationCount')),
                    lowestPrice: Accumulator::min(Expression::doubleFieldPath('lowestPrice')),
                    highestPrice: Accumulator::max(Expression::doubleFieldPath('highestPrice')),
                ),
            ),
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
        );

        /** @var array<int, array<string, DailyAggregate>> $days Aggregates by fuel, by timestamp of the day */
        $days = [];
        /** @var array{_id: array{day: UTCDateTime, fuel: string}, priceSum: float, stationCount: int, lowestPrice: float, highestPrice: float} $group */
        foreach ($groups as $group) {
            $day = $group['_id']['day']->toDateTimeImmutable();
            $fuel = Fuel::from($group['_id']['fuel']);

            $aggregate = new DailyAggregate($day, $fuel);
            $aggregate->weightedAveragePrice = round($group['priceSum'] / $group['stationCount'], 3);
            $aggregate->lowestPrice = $group['lowestPrice'];
            $aggregate->highestPrice = $group['highestPrice'];
            $aggregate->stationCount = $group['stationCount'];

            $days[$day->getTimestamp()][$fuel->value] = $aggregate;
        }

        $compounds = [];
        foreach ($days as $timestamp => $aggregates) {
            if (! isset($aggregates[Fuel::Diesel->value], $aggregates[Fuel::E5->value], $aggregates[Fuel::E10->value])) {
                continue;
            }

            $compounds[] = new CompoundDailyAggregate(
                $aggregates[Fuel::Diesel->value]->day,
                $aggregates[Fuel::Diesel->value],
                $aggregates[Fuel::E5->value],
                $aggregates[Fuel::E10->value],
            );
        }

        usort($compounds, static fn (CompoundDailyAggregate $a, CompoundDailyAggregate $b): int => $b->day <=> $a->day);

        return $compounds;
    }

    /**
     * Combines the post codes starting with the same digits (a region, or a single post code if all digits are given)
     * like {@see getRegionalWeightedAveragePrices()}, with the lowest and highest price of any of them. Aggregates
     * without a station count are ignored.
     *
     * @return array{weightedAveragePrice: float, lowestPrice: float, highestPrice: float, stationCount: int}|null Null if no matching post code has prices
     */
    public function getAggregate(Fuel $fuel, DateTimeImmutable $day, string $postCodePrefix): ?array
    {
        $aggregates = $this->getDocumentCollection()->aggregate(
            new Pipeline(
                Stage::match(
                    fuel: $fuel->value,
                    day: new UTCDateTime($day),
                    postCode: new Regex('^' . preg_quote($postCodePrefix, '/')),
                    stationCount: Query::gt(0),
                ),
                Stage::group(
                    _id: null,
                    priceSum: Accumulator::sum(Expression::multiply(
                        Expression::doubleFieldPath('weightedAveragePrice'),
                        Expression::intFieldPath('stationCount'),
                    )),
                    stationCount: Accumulator::sum(Expression::intFieldPath('stationCount')),
                    lowestPrice: Accumulator::min(Expression::doubleFieldPath('lowestPrice')),
                    highestPrice: Accumulator::max(Expression::doubleFieldPath('highestPrice')),
                ),
            ),
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
        );

        /** @var array{priceSum: float, stationCount: int, lowestPrice: float, highestPrice: float}|false $aggregate */
        $aggregate = $aggregates->toArray()[0] ?? false;
        if ($aggregate === false) {
            return null;
        }

        return [
            'weightedAveragePrice' => round($aggregate['priceSum'] / $aggregate['stationCount'], 3),
            'lowestPrice' => $aggregate['lowestPrice'],
            'highestPrice' => $aggregate['highestPrice'],
            'stationCount' => $aggregate['stationCount'],
        ];
    }
}
