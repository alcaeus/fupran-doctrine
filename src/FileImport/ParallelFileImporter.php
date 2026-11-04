<?php

declare(strict_types=1);

namespace App\FileImport;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

use function array_fill;
use function array_search;
use function array_slice;
use function count;
use function explode;
use function filesize;
use function implode;
use function max;
use function min;
use function sprintf;
use function str_contains;
use function usort;

use const PHP_EOL;

/**
 * Fans a price report import out into a fixed number of worker processes.
 *
 * Each worker is started once and imports its whole share of the files, so the cost of
 * booting the kernel and connecting to MongoDB is paid once per worker rather than once
 * per file. Raw price reports are plain inserts into the same temporary collection, so
 * the order in which workers write them does not matter.
 */
final class ParallelFileImporter
{
    public function __construct(private readonly WorkerProcessFactory $processFactory)
    {
    }

    /** @param list<string> $files */
    public function import(array $files, string $collectionName, int $jobs, ?SymfonyStyle $style = null): ImportResult
    {
        $partitions = $this->partition($files, $jobs);

        $style?->writeln(sprintf('Importing %d files using %d worker processes...', count($files), count($partitions)));
        $style?->progressStart(count($files), ProgressBar::FORMAT_VERY_VERBOSE);

        $result = new ImportResult();
        $buffers = [];
        $processes = [];
        $errors = [];

        foreach ($partitions as $index => $partition) {
            $buffers[$index] = '';
            $process = $this->processFactory->create('app:import:price-reports-worker', [$collectionName], implode(PHP_EOL, $partition));
            $process->start(static function (string $type, string $data) use ($index, &$buffers, &$result, &$errors, $style): void {
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
                    $result = $result->withResult(ImportResult::fromJson($line));
                    $style?->progressAdvance();
                }
            });
            $processes[$index] = $process;
        }

        WorkerPool::wait($processes, 'Import', 50_000, static fn (int $index): ?string => $errors[$index] ?? null);

        $style?->progressFinish();

        return $result;
    }

    /**
     * Distributes files over the given number of partitions, always adding the next-largest
     * file to the partition with the least bytes assigned. This keeps workers finishing at
     * roughly the same time without any coordination while they run.
     *
     * @param list<string> $files
     *
     * @return list<list<string>>
     */
    private function partition(array $files, int $jobs): array
    {
        if ($files === []) {
            return [];
        }

        $sized = [];
        foreach ($files as $file) {
            $sized[] = [$file, (int) filesize($file)];
        }

        usort($sized, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        $partitions = array_fill(0, max(1, min($jobs, count($files))), []);
        $load = array_fill(0, count($partitions), 0);

        foreach ($sized as [$file, $size]) {
            $target = array_search(min($load), $load, true);
            $partitions[$target][] = $file;
            $load[$target] += $size;
        }

        // Largest files are handed out first, so every partition receives at least one file
        return $partitions;
    }
}
