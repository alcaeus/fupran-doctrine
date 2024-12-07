<?php

declare(strict_types=1);

namespace App\Repository;

use App\Aggregation\PriceReport;
use App\Document\CompoundDailyAggregate;
use App\Document\DailyAggregate;
use App\Document\DailyPrice;
use App\Document\Partial\AbstractDailyPrice;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\ODM\MongoDB\Iterator\Iterator;
use MongoDB\Builder\Pipeline;
use MongoDB\Builder\Stage;

use function iterator_to_array;

class DailyAggregateRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyAggregate::class);
    }

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

        return iterator_to_array($results)[0];
    }

    public function recomputeDailyAggregates(): void
    {
        $pipeline = new Pipeline(
            PriceReport::computeDailyAggregates(),
            Stage::merge(
                $this->getDocumentCollection()->getCollectionName(),
                on: ['day', 'fuel'],
                whenMatched: 'replace',
            ),
        );

        // TODO: iterator_to_array becomes obsolete in mongodb/mongodb 2.0
        $this
            ->getDocumentManager()
            ->getRepository(DailyPrice::class)
            ->getDocumentCollection()
            ->aggregate(iterator_to_array($pipeline));
    }
}
