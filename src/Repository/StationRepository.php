<?php

declare(strict_types=1);

namespace App\Repository;

use App\Document\DailyPrice;
use App\Document\Station;
use App\Fuel;
use DateTimeImmutable;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\ODM\MongoDB\Aggregation\Builder as AggregationBuilder;
use Doctrine\ODM\MongoDB\Query\Builder as QueryBuilder;
use Doctrine\ODM\MongoDB\Types\Type;

class StationRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Station::class);
    }

    public function createSearchPipeline(string $query): AggregationBuilder
    {
        $builder = $this->createAggregationBuilder();
        $builder
            ->hydrate(Station::class)
            ->search()
                ->index('station')
                ->compound()
                    ->should(1)
                        ->autocomplete('name', $query)
                        ->autocomplete('address.street', $query)
                        ->autocomplete('address.city', $query)
                        ->autocomplete('address.postCode', $query)
            ->sort('score', 'searchScore');

        return $builder;
    }

    public function listByPostCode(string $postCode): QueryBuilder
    {
        return $this->createQueryBuilder()
            ->field('address.postCode')->equals($postCode);
    }

    public function reportPrice(
        Station $station,
        Fuel $fuel,
        float $price,
        DateTimeImmutable $date,
    ): DailyPrice {
        $document = $this->getDocumentCollection()->
            findOneAndUpdate(
                [
                    'station._id' => Type::getType('binaryUuid')->convertToDatabaseValue($station->id),
                    'fuel' => $fuel->value,
                    'day' => $date, // TODO: Use day
                ],
                [], // TODO: Update
            );

        $this->createQueryBuilder()
            ->updateOne()
            ->field('station._id')->equals(Type::getType('binaryUuid')->convertToDatabaseValue($station->id))
            ->field('fuel')->equals($fuel->value)
            ->field('day')->equals($date) // TODO: Use day
            ->field('prices')->push()
            ->set('latestPrices.$.closingPrice', $price)
            ->set('latestPrice.closingPrice', $price)
            ->set('latestPrice.day', $date)
            ->getQuery()
            ->execute();
    }
}
