<?php

declare(strict_types=1);

namespace App\FileImport;

use RuntimeException;
use Symfony\Component\Process\Process;

use function array_filter;
use function count;
use function sprintf;
use function usleep;

/**
 * Waits for started worker processes, failing fast: as soon as one of them fails, all others are stopped.
 */
final class WorkerPool
{
    /**
     * A process only has its output read while it is being polled or waited on, so all workers are polled
     * instead of waited on one by one. Otherwise the output of every worker but the first would stay unread
     * until the first is done.
     *
     * @param array<int, Process>                 $processes
     * @param (callable(int): (string|null))|null $getErrorOutput Returns the error output collected for a worker, if any
     */
    public static function wait(array $processes, string $name, int $pollInterval = 100_000, ?callable $getErrorOutput = null): void
    {
        while (array_filter($processes, static fn (Process $process): bool => $process->isRunning())) {
            foreach ($processes as $index => $process) {
                if (! $process->isRunning() && ! $process->isSuccessful()) {
                    foreach ($processes as $other) {
                        $other->stop();
                    }

                    throw self::workerFailed($name, $index, $process, $getErrorOutput, count($processes));
                }
            }

            usleep($pollInterval);
        }

        // Read whatever output remains once the workers are done
        foreach ($processes as $index => $process) {
            $process->wait();

            if (! $process->isSuccessful()) {
                throw self::workerFailed($name, $index, $process, $getErrorOutput, count($processes));
            }
        }
    }

    /** @param (callable(int): (string|null))|null $getErrorOutput */
    private static function workerFailed(string $name, int $index, Process $process, ?callable $getErrorOutput, int $total): RuntimeException
    {
        return new RuntimeException(sprintf(
            '%s worker %d of %d failed with exit code %d: %s',
            $name,
            $index + 1,
            $total,
            $process->getExitCode() ?? -1,
            ($getErrorOutput !== null ? $getErrorOutput($index) : null) ?? $process->getErrorOutput(),
        ));
    }
}
