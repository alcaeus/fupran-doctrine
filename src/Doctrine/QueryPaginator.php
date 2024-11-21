<?php

declare(strict_types=1);

namespace App\Doctrine;

use Countable;
use Doctrine\ODM\MongoDB\Iterator\Iterator;
use Doctrine\ODM\MongoDB\Query\Builder;
use IteratorAggregate;
use Traversable;

use function ceil;

final class QueryPaginator implements IteratorAggregate, Countable
{
    private int $pageCount;

    /** @psalm-var positive-int $page */
    public function __construct(
        private Builder $query,
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
        $builder = clone $this->query;

        return (int) ceil($builder
            ->hydrate(false)
            ->count()
            ->getQuery()
            ->execute() / $this->perPage);
    }

    private function getResultsForCurrentPage(): Iterator
    {
        $builder = clone $this->query;
        $builder
            ->skip(($this->page - 1) * $this->perPage)
            ->limit($this->perPage);

        return $builder->getQuery()->getIterator();
    }
}
