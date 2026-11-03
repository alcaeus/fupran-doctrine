<?php

declare(strict_types=1);

namespace App\Aggregation;

use App\FileImport\WorkerProcessFactory;
use RuntimeException;

use function array_filter;
use function sprintf;
use function usleep;

/**
 * Runs the price report pre-aggregation in a number of worker processes, each handling an equally
 * sized slice of the UUID space. Stations do not depend on each other in these stages, so every
 * worker can run both aggregation steps for its stations without waiting for the others.
 *
 * The raw price report collection deliberately has no index on the station: every worker scans it
 * in full and filters by its slice. A benchmark with 3M reports showed that this scan costs well
 * under a second per worker, which is less than building the index serially after the import
 * (about 4.5 s) and about as much as maintaining it during the import would add.
 */
final class ParallelPriceReportAggregator
{
    public function __construct(private readonly WorkerProcessFactory $processFactory)
    {
    }

    public function aggregate(?string $importCollectionName, string $aggregatedCollectionName, string $updatedDaysCollectionName, int $jobs): void
    {
        $processes = [];
        for ($index = 0; $index < $jobs; $index++) {
            $arguments = [$aggregatedCollectionName, $updatedDaysCollectionName, (string) $index, (string) $jobs];
            if ($importCollectionName !== null) {
                $arguments[] = '--import-collection=' . $importCollectionName;
            }

            $process = $this->processFactory->create('app:import:price-reports-aggregate-worker', $arguments);
            $process->start();
            $processes[$index] = $process;
        }

        while ($running = array_filter($processes, static fn ($process): bool => $process->isRunning())) {
            foreach ($processes as $index => $process) {
                if ($process->isRunning() || $process->isSuccessful()) {
                    continue;
                }

                // Fail fast: no point in letting the other workers keep going
                foreach ($running as $other) {
                    $other->stop();
                }

                throw new RuntimeException(sprintf(
                    'Aggregation worker %d of %d failed with exit code %d: %s',
                    $index + 1,
                    $jobs,
                    $process->getExitCode() ?? -1,
                    $process->getErrorOutput(),
                ));
            }

            usleep(100_000);
        }

        foreach ($processes as $index => $process) {
            if (! $process->isSuccessful()) {
                throw new RuntimeException(sprintf(
                    'Aggregation worker %d of %d failed with exit code %d: %s',
                    $index + 1,
                    $jobs,
                    $process->getExitCode() ?? -1,
                    $process->getErrorOutput(),
                ));
            }
        }
    }
}
