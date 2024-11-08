<?php

namespace App\Import;

use Closure;
use DirectoryIterator;
use MongoDB\Collection;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\WriteResult;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function microtime;

abstract class Importer
{
    public function __construct(
        private readonly Collection $collection,
    ) {}

    abstract protected function storeDocument(BulkWrite $bulk, array $data): void;

    final public function importDirectory(string $directory, ?OutputInterface $output = null): ImportResult
    {
        $result = new ImportResult();

        $iterator = new DirectoryIterator($directory);
        foreach ($iterator as $file) {
            if ($file->isDot()) {
                continue;
            }

            if ($file->isDir()) {
                $result = $result->withResult($this->importDirectory($file->getPathname(), $output));

                continue;
            }

            if ($file->getExtension() !== 'csv') {
                continue;
            }

            $result = $result->withResult($this->importFile($file->getPathname(), $output));
        }

        return $result;
    }

    final public function importFile(string $file, ?OutputInterface $output = null): ImportResult
    {
        $output?->writeln(sprintf('Importing file "%s"', $file));

        $resource = fopen($file, 'r');
        if (!$resource) {
            throw new \RuntimeException(sprintf('Could not read file "%s"', $file));
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

            $output?->writeln(sprintf('Read %d records in %.5f s, importing now', $bulk->count(), $readTime));

            $importResult = null;
            $importTime = $this->measureTime(
                function () use (&$importResult, $bulk): void {
                    $importResult = count($bulk)
                        ? ImportResult::fromWriteResult($this->executeBulkWrite($bulk))
                        : new ImportResult(0, 0);
                },
            );

            $output?->writeln(sprintf(
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
