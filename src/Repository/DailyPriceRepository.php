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

    public function getLast30DaysDieselForStation(Station $station)
    {
        return $this->getLast30DaysForStationAndFuel($station, Fuel::Diesel);
    }

    public function getLast30DaysE5ForStation(Station $station)
    {
        return $this->getLast30DaysForStationAndFuel($station, Fuel::E5);
    }

    public function getLast30DaysE10ForStation(Station $station)
    {
        return $this->getLast30DaysForStationAndFuel($station, Fuel::E10);
    }

    private function getLast30DaysForStationAndFuel(Station $station, Fuel $fuel)
    {
        return $this->createQueryBuilder()
            ->field('station._id')
            ->equals(Type::getType('binaryUuid')->convertToDatabaseValue($station->id))
            ->field('fuel')
            ->equals($fuel)
            ->sort('day', -1)
            ->limit(30)
            ->getQuery()
            ->execute();
    }
}
