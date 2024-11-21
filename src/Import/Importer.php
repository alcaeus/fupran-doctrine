<?php

declare(strict_types=1);

namespace App\Import;

use Closure;
use MongoDB\Collection;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\WriteResult;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\Finder\Finder;

use function array_combine;
use function count;
use function fclose;
use function fgetcsv;
use function fopen;
use function is_file;
use function is_string;
use function microtime;
use function sprintf;

abstract class Importer
{
    public function __construct(
        public readonly Collection $collection,
    ) {
    }

    abstract protected function storeDocument(BulkWrite $bulk, array $data): void;

    /** @param string|list<string> $fileOrDirectory */
    final public function import(string|array $fileOrDirectory, ?StyleInterface $style = null): ImportResult
    {
        if (is_string($fileOrDirectory) && is_file($fileOrDirectory)) {
            return $this->importFile($fileOrDirectory, $style);
        }

        $finder = new Finder();
        $finder
            ->in($fileOrDirectory)
            ->files()
            ->name('*.csv');

        $style?->progressStart($finder->count());

        $result = new ImportResult();
        foreach ($finder as $file) {
            $result = $result->withResult($this->importFile($file->getRealPath()));
            $style?->progressAdvance();
        }

        $style?->progressFinish();

        return $result;
    }

    private function importFile(string $file, ?StyleInterface $style = null): ImportResult
    {
        $style?->writeln(sprintf('Importing file "%s"', $file));

        $resource = fopen($file, 'r');
        if (! $resource) {
            throw ImportException::fileNotReadable($file);
        }

        $bulk = new BulkWrite(['ordered' => false]);

        try {
            $headers = fgetcsv($resource);

            $readTime = $this->measureTime(
                function () use ($resource, $bulk, $headers): void {
                    while ($row = fgetcsv($resource)) {
                        $this->storeDocument($bulk, array_combine($headers, $row));
                    }
                },
            );

            $style?->writeln(sprintf('Read %d records in %.5f s, importing now', $bulk->count(), $readTime));

            $importResult = null;
            $importTime = $this->measureTime(
                function () use (&$importResult, $bulk): void {
                    $importResult = count($bulk)
                        ? ImportResult::fromWriteResult($this->executeBulkWrite($bulk))
                        : new ImportResult(0, 0);
                },
            );

            $style?->writeln(sprintf(
                'Done in %.5f s; %d records inserted, %d updated, %d skipped.',
                $importTime,
                $importResult->numInserted,
                $importResult->numUpdated,
                $importResult->numSkipped,
            ));

            return $importResult;
        } finally {
            fclose($resource);
        }
    }

    protected function getNamespace(): string
    {
        return sprintf('%s.%s', $this->collection->getDatabaseName(), $this->collection->getCollectionName());
    }

    private function executeBulkWrite(BulkWrite $bulk): WriteResult
    {
        return $this->collection->getManager()->executeBulkWrite($this->getNamespace(), $bulk);
    }

    private function measureTime(Closure $closure): float
    {
        $start = microtime(true);
        $closure();

        return microtime(true) - $start;
    }
}
