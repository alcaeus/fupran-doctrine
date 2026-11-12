<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\DateRange;
use App\Document\CompoundDailyAggregate;
use App\Repository\DailyAggregateRepository;
use App\Repository\PostCodeDailyAggregateRepository;
use DateTimeImmutable;

use function array_values;

/** The aggregates of all of Germany or of an area (a region or a post code), which have the same shape for both */
class AreaAggregates
{
    public function __construct(
        private readonly DailyAggregateRepository $germany,
        private readonly PostCodeDailyAggregateRepository $postCodes,
    ) {
    }

    /** @return list<CompoundDailyAggregate> Newest day first */
    public function getHistory(?string $area, DateRange $range): array
    {
        if ($area === null) {
            return array_values($this->germany->getCompoundDailyAggregates($range)->toArray());
        }

        return $this->postCodes->getCompoundDailyAggregates($area, $range);
    }

    public function findForDay(?string $area, DateTimeImmutable $day): ?CompoundDailyAggregate
    {
        if ($area === null) {
            return $this->germany->findCompoundAggregateForDay($day);
        }

        return $this->getHistory($area, new DateRange($day, $day, 1))[0] ?? null;
    }
}
