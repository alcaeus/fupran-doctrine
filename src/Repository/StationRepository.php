<?php

declare(strict_types=1);

namespace App\Repository;

use App\Document\NearbyStation;
use App\Document\Station;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\ODM\MongoDB\Aggregation\Builder as AggregationBuilder;
use Doctrine\ODM\MongoDB\Query\Builder as QueryBuilder;
use Iterator;

/** @extends AbstractRepository<Station> */
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
            ->sort('score', ['$meta' => 'searchScore']);
        $builder
            ->match()
                ->field('visible')->equals(true);

        return $builder;
    }

    public function listByPostCode(string $postCode): QueryBuilder
    {
        return $this->createQueryBuilder()
            ->field('visible')->equals(true)
            ->field('address.postCode')->equals($postCode);
    }

    /** @return Iterator<int, NearbyStation> */
    public function findClosestStations(Station $station): Iterator
    {
        return $this->createAggregationBuilder()
            ->hydrate(NearbyStation::class)
            ->geoNear($station->location)
                ->field('_id')
                ->notEqual($station->id)
                ->field('visible')
                ->equals(true)
                ->distanceField('distance')
                ->maxDistance(5000)
            ->sort('distance', 1)
            ->limit(5)
            ->getAggregation()
            ->getIterator();
    }
}
