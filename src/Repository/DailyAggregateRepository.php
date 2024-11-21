<?php

declare(strict_types=1);

namespace App\Repository;

use App\Document\CompoundDailyAggregate;
use App\Document\DailyAggregate;
use App\Document\DailyPrice;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;

use function iterator_to_array;

class DailyAggregateRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyAggregate::class);
    }

    public function getAggregateForDailyPrice(DailyPrice $dailyPrice): DailyAggregate
    {
        return $this->createQueryBuilder()
            ->field('day')->equals($dailyPrice->day)
            ->field('fuel')->equals($dailyPrice->fuel)
            ->limit(1)
            ->getQuery()
            ->getSingleResult();
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
}
