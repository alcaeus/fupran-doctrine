<?php

declare(strict_types=1);

namespace App\Repository;

use App\Aggregation\PriceReport;
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

use function preg_quote;
use function round;

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
     * Combines the post codes of a region like {@see getRegionalWeightedAveragePrices()}, with the lowest and highest
     * price of any of them. Aggregates without a station count are ignored.
     *
     * @return array{weightedAveragePrice: float, lowestPrice: float, highestPrice: float, stationCount: int}|null Null if no post code in the region has prices
     */
    public function getRegionAggregate(Fuel $fuel, DateTimeImmutable $day, string $region): ?array
    {
        $aggregates = $this->getDocumentCollection()->aggregate(
            new Pipeline(
                Stage::match(
                    fuel: $fuel->value,
                    day: new UTCDateTime($day),
                    postCode: new Regex('^' . preg_quote($region, '/')),
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
