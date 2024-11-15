<?php

namespace App\Repository;

use App\Document\DailyPrice;
use App\Document\Station;
use App\Fuel;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\ODM\MongoDB\Types\Type;

use function count;

class DailyPriceRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyPrice::class);
    }

    public function getLatestPricesForStation(Station $station)
    {
        return $this->createQueryBuilder()
            ->field('station._id')
            ->equals(Type::getType('binaryUuid')->convertToDatabaseValue($station->id))
            ->sort('day', -1)
            ->limit(count(Fuel::cases()))
            ->getQuery()
            ->execute();
    }
}
