<?php

namespace App\Repository;

use Countable;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use MongoDB\Collection;

abstract class AbstractRepository extends ServiceDocumentRepository implements Countable
{
    public function count(): int
    {
        return $this->getDocumentCollection()->estimatedDocumentCount();
    }

    public function getDocumentCollection(): Collection
    {
        return $this->dm->getDocumentCollection($this->documentName);
    }
}
