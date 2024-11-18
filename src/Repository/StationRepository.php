<?php

namespace App\Repository;

use App\Document\Station;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\ODM\MongoDB\Aggregation\Builder;

class StationRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Station::class);
    }

    public function createSearchPipeline(string $query): Builder
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
}
