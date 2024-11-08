<?php

namespace App\Repository;

use App\Document\Station;
use Countable;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use MongoDB\Collection;

class StationsRepository extends ServiceDocumentRepository implements Countable
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Station::class);
    }

    public function count(): int
    {
        return $this->getDocumentCollection()->estimatedDocumentCount();
    }

    private function getDocumentCollection(): Collection
    {
        return $this->dm->getDocumentCollection($this->documentName);
    }
}
