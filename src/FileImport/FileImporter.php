<?php

declare(strict_types=1);

namespace App\FileImport;

use Doctrine\Bundle\MongoDBBundle\APM\CommandLoggerRegistry;
use MongoDB\Collection;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\WriteResult;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

use function App\measure;
use function array_combine;
use function count;
use function fclose;
use function fgetcsv;
use function fopen;
use function in_array;
use function is_dir;
use function is_file;
use function sprintf;

abstract class FileImporter
{
    public function __construct(
        public protected(set) Collection $collection,
        #[Autowire(service: 'doctrine_mongodb.odm.command_logger_registry')]
        private readonly CommandLoggerRegistry $commandLoggerRegistry,
    ) {
    }

    /** @param array<string, string|null> $data */
    abstract protected function storeDocument(BulkWrite $bulk, array $data): void;

    /**
     * @param string|list<string> $fileOrDirectory
     *
     * @return list<string>
     */
    final public function findFiles(string|array $fileOrDirectory): array
    {
        $files = [];

        foreach ((array) $fileOrDirectory as $path) {
            if (is_file($path)) {
                $files[] = $path;

                continue;
            }

            if (! is_dir($path)) {
                throw ImportException::fileNotFound($path);
            }

            $finder = new Finder();
            $finder
                ->in($path)
                ->files()
                ->name('*.csv');

            foreach ($finder as $file) {
                $files[] = $file->getRealPath();
            }
        }

        return $files;
    }

    /** @param string|list<string> $fileOrDirectory */
    final public function import(string|array $fileOrDirectory, ?SymfonyStyle $style = null): ImportResult
    {
        $files = $this->findFiles($fileOrDirectory);

        if (count($files) === 1) {
            return $this->importFiles($files, $style, verbose: true);
        }

        $style?->writeln(sprintf('Importing %d files...', count($files)));
        $style?->progressStart(count($files), ProgressBar::FORMAT_VERY_VERBOSE);

        $result = $this->importFiles($files, onFileImported: static fn () => $style?->progressAdvance());

        $style?->progressFinish();

        return $result;
    }

    /**
     * @param list<string>                                $files
     * @param (callable(string, ImportResult): void)|null $onFileImported
     */
    final public function importFiles(array $files, ?SymfonyStyle $style = null, bool $verbose = false, ?callable $onFileImported = null): ImportResult
    {
        /* The bundle's profiler/APM command logger retains every executed command's full
         * BSON payload for the lifetime of the process - it's only cleared by the HTTP
         * profiler's per-request reset, which a console command never triggers. For a bulk
         * import that means unbounded memory growth, so switch it off for the run.
         */
        $this->commandLoggerRegistry->unregister();

        try {
            $result = new ImportResult();
            foreach ($files as $file) {
                $fileResult = $this->importFile($file, $verbose ? $style : null);
                $result = $result->withResult($fileResult);

                if ($onFileImported) {
                    $onFileImported($file, $fileResult);
                }
            }

            return $result;
        } finally {
            $this->commandLoggerRegistry->register();
        }
    }

    private function importFile(string $file, ?SymfonyStyle $style = null): ImportResult
    {
        $style?->writeln(sprintf('Importing file "%s"', $file));

        $resource = fopen($file, 'r');
        if (! $resource) {
            throw ImportException::fileNotReadable($file);
        }

        $bulk = new BulkWrite(['ordered' => false]);

        try {
            $headers = fgetcsv($resource, escape: '');
            if ($headers === false || in_array(null, $headers, true)) {
                throw ImportException::cannotImportFile($file);
            }

            [$readTime] = measure(
                function () use ($resource, $bulk, $headers): void {
                    while ($row = fgetcsv($resource, escape: '')) {
                        $this->storeDocument($bulk, array_combine($headers, $row));
                    }
                },
            );

            $style?->writeln(sprintf('Read %d records in %.5f s, importing now', $bulk->count(), $readTime));

            [$importTime, $importResult] = measure(
                fn (): ImportResult => count($bulk)
                    ? ImportResult::fromWriteResult($this->executeBulkWrite($bulk))
                    : new ImportResult(0, 0),
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
}
