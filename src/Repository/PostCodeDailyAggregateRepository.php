<?php

declare(strict_types=1);

namespace App\Repository;

use App\Aggregation\PriceReport;
use App\Document\PostCodeDailyAggregate;
use App\Fuel;
use DateTimeImmutable;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Builder\Accumulator;
use MongoDB\Builder\Expression;
use MongoDB\Builder\Pipeline;
use MongoDB\Builder\Query;
use MongoDB\Builder\Stage;

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
     * @return array<string, float> Weighted average price of the day, rounded to the three decimals of a fuel price, by post code
     */
    public function getWeightedAveragePrices(Fuel $fuel, DateTimeImmutable $day): array
    {
        $aggregates = $this->getDocumentCollection()->find(
            ['fuel' => $fuel->value, 'day' => new UTCDateTime($day)],
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
}
