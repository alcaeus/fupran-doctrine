<?php

declare(strict_types=1);

namespace App\Import;

use Closure;
use DirectoryIterator;
use MongoDB\Collection;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\WriteResult;
use Symfony\Component\Console\Style\StyleInterface;

use function array_combine;
use function count;
use function fclose;
use function fgetcsv;
use function file_exists;
use function fopen;
use function is_dir;
use function is_file;
use function microtime;
use function sprintf;

abstract class Importer
{
    public function __construct(
        public readonly Collection $collection,
    ) {
    }

    abstract protected function storeDocument(BulkWrite $bulk, array $data): void;

    final public function import(string $fileOrDirectory, ?StyleInterface $style = null): ImportResult
    {
        if (! file_exists($fileOrDirectory)) {
            throw ImportException::fileNotFound($fileOrDirectory);
        }

        return match (true) {
            is_file($fileOrDirectory) => $this->importFile($fileOrDirectory, $style),
            is_dir($fileOrDirectory) => $this->importDirectory($fileOrDirectory, $style),
            default => throw ImportException::cannotImportFile($fileOrDirectory),
        };
    }

    final public function importDirectory(string $directory, ?StyleInterface $style = null): ImportResult
    {
        $result = new ImportResult();

        $iterator = new DirectoryIterator($directory);
        foreach ($iterator as $file) {
            if ($file->isDot()) {
                continue;
            }

            if ($file->isDir()) {
                $result = $result->withResult($this->importDirectory($file->getPathname(), $style));

                continue;
            }

            if ($file->getExtension() !== 'csv') {
                continue;
            }

            $result = $result->withResult($this->importFile($file->getPathname(), $style));
        }

        return $result;
    }

    final public function importFile(string $file, ?StyleInterface $style = null): ImportResult
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
