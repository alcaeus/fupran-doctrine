<?php

declare(strict_types=1);

namespace App\Aggregation;

use App\FileImport\WorkerPool;
use App\FileImport\WorkerProcessFactory;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

use function array_slice;
use function explode;
use function max;
use function min;
use function str_contains;
use function str_starts_with;

use const PHP_EOL;

/**
 * Runs the price report aggregation in a number of worker processes.
 *
 * Grouping the raw reports (see aggregateChunks()) is distributed in small chunks of stations that workers
 * pull from a queue. The following steps run per slice of the UUID space (see aggregate()): stations do not
 * depend on each other there, so every worker can run them for its stations without waiting for the others.
 */
final class ParallelPriceReportAggregator
{
    public function __construct(private readonly WorkerProcessFactory $processFactory)
    {
    }

    /**
     * @param int $totalStations Number of stations in the queue, which is the target of the progress bar
     *
     * @return list<ChunkResult>
     */
    public function aggregateChunks(string $importCollectionName, string $aggregatedCollectionName, ChunkQueue $queue, int $chunks, int $jobs, int $totalStations, ?SymfonyStyle $style = null): array
    {
        $style?->progressStart($totalStations);

        $results = [];
        $buffers = [];
        $errors = [];
        $processes = [];

        // Workers report results as JSON lines; anything else on stdout (e.g. a PHP notice) is not a result
        $handleLine = static function (string $line) use (&$results, $style): void {
            if (! str_starts_with($line, '{')) {
                return;
            }

            $result = ChunkResult::fromJson($line);
            $results[] = $result;
            $style?->progressAdvance($result->stations);
        };

        // There is no point in starting more workers than there are chunks
        for ($index = 0; $index < max(1, min($jobs, $chunks)); $index++) {
            $buffers[$index] = '';
            $process = $this->processFactory->create(
                'app:import:price-reports-chunk-worker',
                [$importCollectionName, $aggregatedCollectionName, $queue->getCollectionName()],
            );
            $process->start(static function (string $type, string $data) use ($index, &$buffers, &$errors, $handleLine): void {
                if ($type === Process::ERR) {
                    $errors[$index] = ($errors[$index] ?? '') . $data;

                    return;
                }

                // Output arrives in arbitrary chunks, so only act on complete lines
                $buffers[$index] .= $data;
                if (! str_contains($buffers[$index], PHP_EOL)) {
                    return;
                }

                $lines = explode(PHP_EOL, $buffers[$index]);
                $buffers[$index] = array_slice($lines, -1)[0];

                foreach (array_slice($lines, 0, -1) as $line) {
                    $handleLine($line);
                }
            });
            $processes[$index] = $process;
        }

        WorkerPool::wait($processes, 'Chunk aggregation', 50_000, static fn (int $index): ?string => $errors[$index] ?? null);

        // A final line without a line break has not been handled yet
        foreach ($buffers as $buffer) {
            $handleLine($buffer);
        }

        $style?->progressFinish();

        return $results;
    }

    public function aggregate(string $aggregatedCollectionName, string $updatedDaysCollectionName, int $jobs): void
    {
        $processes = [];
        for ($index = 0; $index < $jobs; $index++) {
            $process = $this->processFactory->create(
                'app:import:price-reports-aggregate-worker',
                [$aggregatedCollectionName, $updatedDaysCollectionName, (string) $index, (string) $jobs],
            );
            $process->start();
            $processes[$index] = $process;
        }

        WorkerPool::wait($processes, 'Aggregation');
    }
}
