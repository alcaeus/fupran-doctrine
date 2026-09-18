<?php

declare(strict_types=1);

namespace App\Doctrine;

use Countable;
use Doctrine\ODM\MongoDB\Aggregation\Builder;
use Doctrine\ODM\MongoDB\Iterator\Iterator;
use IteratorAggregate;
use Traversable;

use function ceil;
use function is_array;
use function is_int;
use function max;

/** @template-implements IteratorAggregate<int, mixed> */
final class AggregationPaginator implements IteratorAggregate, Countable
{
    /** @var int<0, max> */
    private int $pageCount;

    /** @param positive-int $page */
    public function __construct(
        private Builder $aggregation,
        public readonly int $page,
        public readonly int $perPage = 24,
    ) {
    }

    /** @return int<0, max> */
    public function count(): int
    {
        return $this->pageCount ??= $this->getNumberOfPages();
    }

    public function getIterator(): Traversable
    {
        return $this->getResultsForCurrentPage();
    }

    /** @return int<0, max> */
    private function getNumberOfPages(): int
    {
        $builder = clone $this->aggregation;

        $results = $builder
            ->hydrate(null)
            ->count('numDocuments')
            ->getAggregation()
            ->getIterator();

        $firstResult = $results->current();
        $numResults = is_array($firstResult) ? $firstResult['numDocuments'] ?? 0 : 0;
        if (! is_int($numResults)) {
            $numResults = 0;
        }

        return max(0, (int) ceil($numResults / $this->perPage));
    }

    /** @return Iterator<mixed> */
    private function getResultsForCurrentPage(): Iterator
    {
        $builder = clone $this->aggregation;
        $builder
            ->skip(($this->page - 1) * $this->perPage)
            ->limit($this->perPage);

        return $builder->getAggregation()->getIterator();
    }
}
