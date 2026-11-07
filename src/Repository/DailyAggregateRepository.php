<?php

declare(strict_types=1);

namespace App\Repository;

use App\Aggregation\PriceReport;
use App\DateRange;
use App\Document\CompoundDailyAggregate;
use App\Document\DailyAggregate;
use App\Document\Partial\AbstractDailyPrice;
use DateTimeImmutable;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\ODM\MongoDB\Iterator\Iterator;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Builder\Pipeline;
use MongoDB\Builder\Stage;

use function array_values;
use function iterator_to_array;
use function usort;

/** @extends AbstractRepository<DailyAggregate> */
class DailyAggregateRepository extends AbstractRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly DailyPriceRepository $dailyPriceRepository,
    ) {
        parent::__construct($registry, DailyAggregate::class);
    }

    /** @return Iterator<DailyAggregate> */
    public function getAggregateForDailyPrice(AbstractDailyPrice $dailyPrice): Iterator
    {
        return $this->createQueryBuilder()
            ->field('day')->equals($dailyPrice->day)
            ->field('fuel')->equals($dailyPrice->fuel)
            ->limit(1)
            ->getQuery()
            ->getIterator();
    }

    public function getLatestCompoundAggregate(): CompoundDailyAggregate
    {
        $builder = $this->createAggregationBuilder();
        $results = $builder
            ->hydrate(CompoundDailyAggregate::class)
            ->group()
                ->field('_id')->expression('$day')
                ->field('fuels')->push([
                    'k' => '$fuel',
                    'v' => '$$ROOT',
                ])
            ->sort(['_id' => -1])
            ->limit(1)
            ->replaceWith(
                $builder->expr()->mergeObjects(
                    ['day' => '$_id'],
                    $builder->expr()->arrayToObject('$fuels'),
                ),
            )
            ->getAggregation()
            ->getIterator();

        /** @var array<int, CompoundDailyAggregate> $aggregates */
        $aggregates = iterator_to_array($results);

        return $aggregates[0];
    }

    /** The aggregates of all fuels on a day, if there are any */
    public function findCompoundAggregateForDay(DateTimeImmutable $day): ?CompoundDailyAggregate
    {
        /** @var array<int, CompoundDailyAggregate> $aggregates */
        $aggregates = $this->getCompoundDailyAggregates(new DateRange($day, $day, 1))->toArray();

        return $aggregates[0] ?? null;
    }

    /**
     * Days with prices, oldest first, optionally limited to an inclusive range
     *
     * @return list<UTCDateTime>
     */
    public function getDaysWithPrices(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        $range = [];
        if ($from !== null) {
            $range['$gte'] = new UTCDateTime($from);
        }

        if ($to !== null) {
            $range['$lte'] = new UTCDateTime($to);
        }

        $filter = $range === [] ? [] : ['day' => $range];

        /** @var list<UTCDateTime> $days */
        $days = array_values($this->dailyPriceRepository->getDocumentCollection()->distinct('day', $filter));
        usort($days, static fn (UTCDateTime $a, UTCDateTime $b): int => $a->toDateTime() <=> $b->toDateTime());

        return $days;
    }

    /** @param list<UTCDateTime>|null $days Restricts the computation to these days; all days if omitted */
    public function recomputeDailyAggregates(?array $days = null): void
    {
        $pipeline = new Pipeline(
            PriceReport::computeDailyAggregates($days),
            Stage::merge(
                $this->getDocumentCollection()->getCollectionName(),
                on: ['day', 'fuel'],
                whenMatched: 'replace',
            ),
        );

        $this->dailyPriceRepository
            ->getDocumentCollection()
            ->aggregate($pipeline);
    }

    /** @return Iterator<CompoundDailyAggregate> */
    public function getCompoundDailyAggregates(DateRange $range): Iterator
    {
        return $this->createAggregationBuilder()
            ->hydrate(CompoundDailyAggregate::class)
            ->match()
                ->field('day')
                    ->lte($range->to)
                    ->gte($range->from)
            ->group()
                ->field('_id')->expression('$day')
                ->field('fuels')->push([
                    'k' => '$fuel',
                    'v' => '$$ROOT',
                ])
            ->sort(['_id' => -1])
            ->replaceWith(
                $this->createAggregationBuilder()->expr()->mergeObjects(
                    ['day' => '$_id'],
                    $this->createAggregationBuilder()->expr()->arrayToObject('$fuels'),
                ),
            )
            ->getAggregation()
            ->getIterator();
    }
}
