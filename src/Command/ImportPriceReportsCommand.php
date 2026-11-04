<?php

declare(strict_types=1);

namespace App\Command;

use App\Aggregation\ChunkAggregationRunner;
use App\Aggregation\ChunkQueue;
use App\Aggregation\ChunkResult;
use App\Aggregation\ParallelPriceReportAggregator;
use App\Aggregation\StationChunker;
use App\Document\Station;
use App\FileImport\ImportException;
use App\FileImport\ParallelFileImporter;
use App\FileImport\PriceReportFileImporter;
use App\Repository\DailyAggregateRepository;
use App\Repository\DailyPriceRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use InvalidArgumentException;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Exception\CommandException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use UnexpectedValueException;

use function App\measure;
use function array_filter;
use function array_map;
use function array_sum;
use function array_values;
use function count;
use function is_array;
use function is_numeric;
use function is_string;
use function iterator_to_array;
use function max;
use function min;
use function sprintf;
use function uniqid;

#[AsCommand(
    name: 'app:import:price-reports',
    description: 'Import price reports from a file or directory',
)]
class ImportPriceReportsCommand extends Command
{
    private const int INDEX_OPTIONS_CONFLICT = 85;
    private const int INDEX_KEY_SPECS_CONFLICT = 86;

    private readonly Database $database;
    private Collection $collection;
    private Collection $updatedDays;
    private readonly Collection $stationCollection;
    /** @var list<UTCDateTime>|null Days with new or updated prices; null if unknown, in which case everything is recomputed */
    private ?array $affectedDays = null;
    private bool $latestPricesUpdated = false;

    public function __construct(
        private readonly PriceReportFileImporter $importer,
        private readonly ParallelFileImporter $parallelImporter,
        private readonly ParallelPriceReportAggregator $parallelAggregator,
        private readonly DailyPriceRepository $dailyPriceRepository,
        private readonly DailyAggregateRepository $dailyAggregateRepository,
        private readonly StationChunker $stationChunker,
        private readonly ChunkAggregationRunner $chunkRunner,
        DocumentManager $documentManager,
    ) {
        parent::__construct(null);

        $this->database = $documentManager->getDocumentDatabase(Station::class);
        $this->collection = $this->database
            ->selectCollection(uniqid('aggregatedPriceReports_'));
        $this->updatedDays = $this->database
            ->selectCollection(uniqid('updatedDays_'));
        $this->stationCollection = $documentManager->getDocumentCollection(Station::class);
    }

    protected function configure(): void
    {
        $this
            ->addArgument(name: 'fileOrDirectory', mode: InputArgument::IS_ARRAY, description: 'Path to the file to be imported')
            ->addOption(name: 'recover', mode: InputOption::VALUE_NONE, description: 'Recover from a failure during a previous import')
            ->addOption(name: 'jobs', shortcut: 'j', mode: InputOption::VALUE_REQUIRED, description: 'Number of worker processes to import files and aggregate price reports with', default: 1)
            ->addOption(name: 'import-jobs', mode: InputOption::VALUE_REQUIRED, description: 'Number of worker processes to import files with (defaults to --jobs)')
            ->addOption(name: 'aggregation-jobs', mode: InputOption::VALUE_REQUIRED, description: 'Number of worker processes to aggregate price reports with (defaults to --jobs)')
            ->addOption(name: 'clear', mode: InputOption::VALUE_NONE, description: 'Clear all existing prices');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $jobs = $this->getJobsOption($input, 'jobs', 1);
        $importJobs = $this->getJobsOption($input, 'import-jobs', $jobs);
        $aggregationJobs = $this->getJobsOption($input, 'aggregation-jobs', $jobs);

        // Recover from an import that previously failed
        if ($input->getOption('recover')) {
            $this->attemptRecovery($aggregationJobs, $io);
        } else {
            if ($input->getOption('clear')) {
                $io->write('Clearing existing price data...');
                [$time] = measure($this->clearExistingPriceReports(...));
                $io->writeln(sprintf('Done in %.5fs', $time));
            }

            try {
                $this->importDataAndPreAggregate($this->getFileOrDirectoryArgument($input), $importJobs, $aggregationJobs, $io);
            } catch (ImportException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        $this->computeDailyAggregates($io);

        // The aggregation workers already set the latest prices of their stations
        if (! $this->latestPricesUpdated) {
            $this->updateLatestStationPrices($io);
        }

        return Command::SUCCESS;
    }

    private function attemptRecovery(int $jobs, SymfonyStyle $io): void
    {
        // Check if there is a priceReportImport collection - this means that step 1 was completed, but the
        // pre-aggregation was not.
        $collectionName = $this->selectRecoveryCollection('priceReportImport_', $io);

        // Leftovers of a failed run are recreated when the steps are repeated
        foreach ([...$this->getCollectionNames('updatedDays_'), ...$this->getCollectionNames('aggregationQueue_')] as $leftover) {
            $this->database->dropCollection($leftover);
        }

        if ($collectionName) {
            $io->writeln(sprintf('Recovering existing price import from collection %s.', $collectionName));
            $this->runPostImportAggregations($this->database->getCollection($collectionName), $jobs, $io);

            return;
        }

        // Check if there is a aggregatedPriceReports_ collection. If so, then the second step of merging data into
        // the DailyPrice collection failed. Resume from there.
        $collectionName = $this->selectRecoveryCollection('aggregatedPriceReports_', $io);

        if ($collectionName) {
            $io->writeln(sprintf('Recovering existing pre-aggregation from collection %s...', $collectionName));
            $this->collection = $this->database->getCollection($collectionName);
            $this->runPostImportAggregations(null, $jobs, $io);
        }
    }

    private function getJobsOption(InputInterface $input, string $name, int $default): int
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return $default;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException(sprintf('Expected "%s" option to be a positive integer.', $name));
        }

        return (int) $value;
    }

    /** @return list<string> */
    private function getFileOrDirectoryArgument(InputInterface $input): array
    {
        $fileOrDirectory = $input->getArgument('fileOrDirectory');
        if (! is_array($fileOrDirectory)) {
            throw new UnexpectedValueException('Expected "fileOrDirectory" argument to be an array.');
        }

        foreach ($fileOrDirectory as $value) {
            if (! is_string($value)) {
                throw new UnexpectedValueException('Expected "fileOrDirectory" argument to be an array of strings.');
            }
        }

        return array_values($fileOrDirectory);
    }

    /** @param list<string> $fileOrDirectory */
    private function importDataAndPreAggregate(array $fileOrDirectory, int $importJobs, int $aggregationJobs, SymfonyStyle $io): void
    {
        if (! $fileOrDirectory) {
            $io->writeln('No files specified for import, only recomputing aggregates for existing data.');

            return;
        }

        $io->writeln('Importing price reports...');
        [$time, $result] = measure(function () use ($fileOrDirectory, $importJobs, $io) {
            $files = $this->importer->findFiles($fileOrDirectory);

            // A single file cannot be split up, so there is no point in paying for worker startup
            if ($importJobs === 1 || count($files) < 2) {
                return $this->importer->import($fileOrDirectory, $io);
            }

            return $this->parallelImporter->import($files, $this->importer->collection->getCollectionName(), $importJobs, $io);
        });

        $io->writeln(sprintf('Import took %.5fs: %d inserted, %d updated.', $time, $result->numInserted, $result->numUpdated));

        $this->runPostImportAggregations($this->importer->collection, $aggregationJobs, $io);
    }

    private function aggregatePriceReportsByDay(Collection $importCollection, int $jobs, SymfonyStyle $io): void
    {
        // Lets the chunks read their reports in the order the grouping needs them without sorting
        $io->write('Checking and potentially creating index on import collection...');
        [$time] = measure(static fn () => $importCollection->createIndex(['station' => 1, 'date' => 1]));
        $io->writeln(sprintf('Done in %.5fs.', $time));

        $io->write('Splitting stations with price reports into chunks...');
        [$time, $chunks] = measure(fn () => $this->stationChunker->planChunks($importCollection, $this->stationCollection));
        $io->writeln(sprintf('Done in %.5fs.', $time));

        if ($chunks === []) {
            $importCollection->drop();

            return;
        }

        $queue = new ChunkQueue($this->database->selectCollection(uniqid('aggregationQueue_')));
        $queue->enqueue($chunks);

        $totalStations = array_sum(array_map(count(...), $chunks));
        $io->writeln(sprintf('Aggregating imported price reports of %d stations in %d chunks using %d worker processes...', $totalStations, count($chunks), max(1, min($jobs, count($chunks)))));

        [$time, $results] = measure(function () use ($importCollection, $queue, $chunks, $totalStations, $jobs, $io): array {
            if ($jobs > 1) {
                return $this->parallelAggregator->aggregateChunks(
                    $importCollection->getCollectionName(),
                    $this->collection->getCollectionName(),
                    $queue,
                    count($chunks),
                    $jobs,
                    $totalStations,
                    $io,
                );
            }

            $results = [];
            $io->progressStart($totalStations, ProgressBar::FORMAT_VERY_VERBOSE);
            $this->chunkRunner->run($importCollection, $this->collection, $queue, static function (ChunkResult $result) use (&$results, $io): void {
                $results[] = $result;
                $io->progressAdvance($result->stations);
            });
            $io->progressFinish();

            return $results;
        });

        // Only dropped once all chunks succeeded, so a failed run can be recovered
        $importCollection->drop();
        $queue->drop();

        $this->printChunkSummary($results, $time, $io);
    }

    /** @param list<ChunkResult> $results */
    private function printChunkSummary(array $results, float $time, SymfonyStyle $io): void
    {
        $durations = array_map(static fn (ChunkResult $result): float => $result->seconds, $results);
        $spilled = array_filter($results, static fn (ChunkResult $result): bool => $result->spills !== null && $result->spills > 0);

        $io->definitionList(
            ['Chunks' => count($results)],
            ['Stations' => array_sum(array_map(static fn (ChunkResult $result): int => $result->stations, $results))],
            ['Aggregated documents' => array_sum(array_map(static fn (ChunkResult $result): int => $result->documents, $results))],
            ['Elapsed time' => sprintf('%.2fs', $time)],
            ['Time spent in chunks' => sprintf('%.2fs (slowest: %.2fs)', array_sum($durations), $durations === [] ? 0 : max($durations))],
            ['Chunks during which the server spilled to disk' => sprintf('%d (server-wide counter, includes concurrent chunks)', count($spilled))],
        );
    }

    private function addOpeningPriceAndMergeIntoPrices(SymfonyStyle $io): void
    {
        $io->write('Adding opening price for each day and merge into prices collection...');

        [$time] = measure(function (): void {
            $this->dailyPriceRepository->resolveBoundaryOpeningPrices($this->collection);
            $this->dailyPriceRepository->mergeAggregatedPriceReports($this->collection);
            $this->dailyPriceRepository->updateOpeningPricesFollowingBatch($this->collection, $this->updatedDays);
        });

        $this->collectAffectedDays();
        $this->collection->drop();
        $this->updatedDays->drop();

        $io->writeln(sprintf('Done in %.5fs.', $time));
    }

    private function createAggregatedIndex(SymfonyStyle $io): void
    {
        $io->write('Checking and potentially creating index on aggregated collection...');

        // Unique, as chunks are merged into the collection on these fields
        try {
            [$time] = measure(
                fn () => $this->collection->createIndex(['station._id' => 1, 'fuel' => 1, 'day' => 1], ['unique' => true]),
            );
        } catch (CommandException $e) {
            if ($e->getCode() !== self::INDEX_OPTIONS_CONFLICT && $e->getCode() !== self::INDEX_KEY_SPECS_CONFLICT) {
                throw $e;
            }

            throw new RuntimeException(sprintf(
                'Collection %s was created by an earlier version and its index is not unique, so it cannot be recovered. Drop it and recover from the priceReportImport_* collection instead.',
                $this->collection->getCollectionName(),
            ), 0, $e);
        }

        $io->writeln(sprintf('Done in %.5fs.', $time));
    }

    private function computeDailyAggregates(SymfonyStyle $io): void
    {
        $io->write('Recomputing daily aggregates...');

        [$time] = measure(
            fn () => $this->dailyAggregateRepository->recomputeDailyAggregates($this->affectedDays),
        );

        $io->writeln(sprintf('Done in %.5fs.', $time));
    }

    /**
     * The days with imported prices, and those previously imported days that had their opening price
     * updated as a result, are the only ones for which daily aggregates need to be recomputed.
     */
    private function collectAffectedDays(): void
    {
        // Keyed by timestamp to remove duplicates, as UTCDateTime objects cannot be compared reliably
        /** @var array<string, UTCDateTime> $days */
        $days = [];
        foreach ([$this->collection, $this->updatedDays] as $collection) {
            foreach ($collection->distinct('day') as $day) {
                if (! $day instanceof UTCDateTime) {
                    throw new UnexpectedValueException('Expected day to be a UTCDateTime.');
                }

                $days[(string) $day] = $day;
            }
        }

        $this->affectedDays = array_values($days);
    }

    private function clearExistingPriceReports(): void
    {
        $this->dailyPriceRepository->createQueryBuilder()
            ->remove()
            ->getQuery()
            ->execute();
        $this->dailyAggregateRepository->createQueryBuilder()
            ->remove()
            ->getQuery()
            ->execute();
    }

    private function updateLatestStationPrices(SymfonyStyle $io): void
    {
        $io->write('Setting last price report for stations...');

        [$time] = measure($this->dailyPriceRepository->updateLatestStationPrices(...));

        $io->writeln(sprintf('Done in %.5fs.', $time));
    }

    /** @return list<string> */
    private function getCollectionNames(string $prefix): array
    {
        $filter = ['name' => new Regex('^' . $prefix)];

        return iterator_to_array($this->database->listCollectionNames(['filter' => $filter]), false);
    }

    private function selectRecoveryCollection(string $prefix, SymfonyStyle $io): ?string
    {
        $collections = $this->getCollectionNames($prefix);

        $selected = match (count($collections)) {
            0 => null,
            1 => $collections[0],
            default => $io->choice('Please select a collection to be recovered:', $collections),
        };

        if ($selected !== null && ! is_string($selected)) {
            throw new UnexpectedValueException('Expected the selected collection name to be a string.');
        }

        return $selected;
    }

    private function runPostImportAggregations(?Collection $importCollection, int $jobs, SymfonyStyle $io): void
    {
        /* Chunks are merged into the collection on this index, and the workers of the second step run for
         * their stations as soon as they start, so it has to exist up front. Building it on the (still empty)
         * collection is cheap, and it is maintained as results are merged.
         */
        $this->createAggregatedIndex($io);

        if ($importCollection) {
            $this->aggregatePriceReportsByDay($importCollection, $jobs, $io);
        }

        if ($jobs > 1) {
            $this->runPostImportAggregationsInParallel($jobs, $io);

            return;
        }

        $this->addOpeningPriceAndMergeIntoPrices($io);
    }

    private function runPostImportAggregationsInParallel(int $jobs, SymfonyStyle $io): void
    {
        $io->write(sprintf('Merging into prices collection and setting latest station prices using %d worker processes...', $jobs));

        [$time] = measure(
            fn () => $this->parallelAggregator->aggregate(
                $this->collection->getCollectionName(),
                $this->updatedDays->getCollectionName(),
                $jobs,
            ),
        );

        $this->latestPricesUpdated = true;
        $this->collectAffectedDays();

        // Only drop the temporary collections once all workers succeeded, so a failed run can be recovered
        $this->collection->drop();
        $this->updatedDays->drop();

        $io->writeln(sprintf('Done in %.5fs.', $time));
    }
}
