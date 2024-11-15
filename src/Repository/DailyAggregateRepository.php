<?php

namespace App\Repository;

use App\Document\DailyAggregate;
use App\Document\DailyPrice;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;

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
}
