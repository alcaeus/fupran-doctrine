<?php

declare(strict_types=1);

namespace App\Doctrine;

use Countable;
use Doctrine\ODM\MongoDB\Iterator\Iterator;
use Doctrine\ODM\MongoDB\Query\Builder;
use IteratorAggregate;
use Traversable;
use UnexpectedValueException;

use function ceil;
use function is_int;
use function max;

/** @template-implements IteratorAggregate<int, mixed> */
final class QueryPaginator implements IteratorAggregate, Countable
{
    /** @var int<0, max> */
    private int $pageCount;

    /** @param positive-int $page */
    public function __construct(
        private Builder $query,
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
        $builder = clone $this->query;

        $numResults = $builder
            ->hydrate(false)
            ->count()
            ->getQuery()
            ->execute();

        if (! is_int($numResults)) {
            throw new UnexpectedValueException('Expected a count query to return an integer.');
        }

        return max(0, (int) ceil($numResults / $this->perPage));
    }

    /** @return Iterator<mixed> */
    private function getResultsForCurrentPage(): Iterator
    {
        $builder = clone $this->query;
        $builder
            ->skip(($this->page - 1) * $this->perPage)
            ->limit($this->perPage);

        return $builder->getQuery()->getIterator();
    }
}
