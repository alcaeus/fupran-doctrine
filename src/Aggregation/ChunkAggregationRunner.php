<?php

declare(strict_types=1);

namespace App\Aggregation;

use App\Repository\DailyPriceRepository;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Exception\Exception as DriverException;

use function App\measure;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;

/** Aggregates chunks of stations from a queue until it is empty, reporting the outcome of each one. */
final class ChunkAggregationRunner
{
    /** Counters in serverStatus' metrics.query that count spill events, as opposed to the bytes and records spilled */
    private const array SPILL_COUNTERS = ['spills', 'spillToDisk', 'hashLookupSpills'];

    public function __construct(private readonly DailyPriceRepository $dailyPriceRepository)
    {
    }

    /** @param (callable(ChunkResult): void)|null $onChunk */
    public function run(Collection $importCollection, Collection $aggregated, ChunkQueue $queue, ?callable $onChunk = null): void
    {
        while (($chunk = $queue->claim()) !== null) {
            $spillsBefore = $this->getSpillCount($aggregated);

            [$seconds] = measure(fn () => $this->dailyPriceRepository->aggregatePriceReportsByDay(
                $importCollection,
                $aggregated,
                $chunk['stations'],
            ));

            $spillsAfter = $this->getSpillCount($aggregated);
            $documents = $aggregated->countDocuments(['station._id' => ['$in' => $chunk['stations']]]);

            $queue->complete($chunk['id']);

            if ($onChunk !== null) {
                $onChunk(new ChunkResult(
                    $chunk['id'],
                    count($chunk['stations']),
                    $documents,
                    $seconds,
                    $spillsBefore !== null && $spillsAfter !== null ? $spillsAfter - $spillsBefore : null,
                ));
            }
        }
    }

    /** Sums the server's counters for operations that spilled to disk; null if the server does not report them. */
    private function getSpillCount(Collection $collection): ?int
    {
        try {
            $status = (new Database($collection->getManager(), 'admin'))
                ->command(['serverStatus' => 1], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']])
                ->toArray()[0] ?? null;
        } catch (DriverException) {
            return null;
        }

        $metrics = is_array($status) ? ($status['metrics'] ?? null) : null;
        $queryMetrics = is_array($metrics) ? ($metrics['query'] ?? null) : null;

        return is_array($queryMetrics) ? self::sumSpillCounters($queryMetrics) : null;
    }

    /** @param array<array-key, mixed> $metrics */
    private static function sumSpillCounters(array $metrics): int
    {
        $sum = 0;
        foreach ($metrics as $name => $value) {
            if (is_array($value)) {
                $sum += self::sumSpillCounters($value);
            } elseif (is_int($value) && is_string($name) && in_array($name, self::SPILL_COUNTERS, true)) {
                $sum += $value;
            }
        }

        return $sum;
    }
}
