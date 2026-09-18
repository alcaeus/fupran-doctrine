<?php

declare(strict_types=1);

namespace App\Repository;

use Countable;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use MongoDB\Collection;

use function max;

/**
 * @template TDocumentClass of object
 * @template-extends ServiceDocumentRepository<TDocumentClass>
 */
abstract class AbstractRepository extends ServiceDocumentRepository implements Countable
{
    /** @return int<0, max> */
    public function count(): int
    {
        return max(0, $this->getDocumentCollection()->estimatedDocumentCount());
    }

    public function getDocumentCollection(): Collection
    {
        return $this->dm->getDocumentCollection($this->documentName);
    }
}
