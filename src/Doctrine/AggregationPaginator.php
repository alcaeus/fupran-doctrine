<?php

namespace App\Doctrine;

use Countable;
use Doctrine\ODM\MongoDB\Iterator\Iterator;
use Doctrine\ODM\MongoDB\Aggregation\Builder;
use IteratorAggregate;
use Traversable;

use function ceil;
use function iterator_to_array;

final class AggregationPaginator implements IteratorAggregate, Countable
{
    private int $pageCount;

    /** @psalm-var positive-int $page */
    public function __construct(
        private Builder $aggregation,
        public readonly int $page,
        public readonly int $perPage = 24,
    ) {
    }

    public function count(): int
    {
        return $this->pageCount ??= $this->getNumberOfPages();
    }

    public function getIterator(): Traversable
    {
        return $this->getResultsForCurrentPage();
    }

    private function getNumberOfPages(): int
    {
        $builder = clone $this->aggregation;

        $results = $builder
            ->hydrate(null)
            ->count('numDocuments')
            ->getAggregation()
            ->getIterator();
        $numResults = iterator_to_array($results)[0]['numDocuments'] ?? 0;

        return (int) ceil($numResults / $this->perPage);
    }

    private function getResultsForCurrentPage(): Iterator
    {
        $builder = clone $this->aggregation;
        $builder
            ->skip(($this->page - 1) * $this->perPage)
            ->limit($this->perPage);

        return $builder->getAggregation()->getIterator();
    }
}
