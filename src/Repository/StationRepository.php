<?php

namespace App\Repository;

use App\Document\Station;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;

class StationRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Station::class);
    }
}
