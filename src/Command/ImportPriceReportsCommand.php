<?php

declare(strict_types=1);

namespace App\Command;

use App\Aggregation\ParallelPriceReportAggregator;
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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use UnexpectedValueException;

use function App\measure;
use function array_values;
use function count;
use function filter_var;
use function is_array;
use function is_string;
use function iterator_to_array;
use function sprintf;
use function uniqid;

use const FILTER_VALIDATE_INT;

#[AsCommand(
    name: 'app:import:price-reports',
    description: 'Import price reports from a file or directory',
)]
class ImportPriceReportsCommand extends Command
{
    private readonly Database $database;
    private Collection $collection;
    private Collection $updatedDays;
    /** @var list<UTCDateTime>|null Days with new or updated prices; null if unknown, in which case everything is recomputed */
    private ?array $affectedDays = null;
    private bool $latestPricesUpdated = false;

    public function __construct(
        private readonly PriceReportFileImporter $importer,
        private readonly ParallelFileImporter $parallelImporter,
        private readonly ParallelPriceReportAggregator $parallelAggregator,
        private readonly DailyPriceRepository $dailyPriceRepository,
        private readonly DailyAggregateRepository $dailyAggregateRepository,
        DocumentManager $documentManager,
    ) {
        parent::__construct(null);

        $this->database = $documentManager->getDocumentDatabase(Station::class);
        $this->collection = $this->database
            ->selectCollection(uniqid('aggregatedPriceReports_'));
        $this->updatedDays = $this->database
            ->selectCollection(uniqid('updatedDays_'));
    }

    protected function configure(): void
    {
        $this
            ->addArgument(name: 'fileOrDirectory', mode: InputArgument::IS_ARRAY, description: 'Path to the file to be imported')
            ->addOption(name: 'recover', mode: InputOption::VALUE_NONE, description: 'Recover from a failure during a previous import')
            ->addOption(name: 'jobs', shortcut: 'j', mode: InputOption::VALUE_REQUIRED, description: 'Number of worker processes to import files with', default: 1)
            ->addOption(name: 'clear', mode: InputOption::VALUE_NONE, description: 'Clear all existing prices');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $jobs = $this->getJobsOption($input);

        // Recover from an import that previously failed
        if ($input->getOption('recover')) {
            $this->attemptRecovery($jobs, $io);
        } else {
            if ($input->getOption('clear')) {
                $io->write('Clearing existing price data...');
                [$time] = measure($this->clearExistingPriceReports(...));
                $io->writeln(sprintf('Done in %.5fs', $time));
            }

            try {
                $this->importDataAndPreAggregate($this->getFileOrDirectoryArgument($input), $jobs, $io);
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
        foreach ($this->getCollectionNames('updatedDays_') as $leftover) {
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

    private function getJobsOption(InputInterface $input): int
    {
        $jobs = filter_var($input->getOption('jobs'), FILTER_VALIDATE_INT);
        if ($jobs === false || $jobs < 1) {
            throw new InvalidArgumentException('Expected "jobs" option to be a positive integer.');
        }

        return $jobs;
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
    private function importDataAndPreAggregate(array $fileOrDirectory, int $jobs, SymfonyStyle $io): void
    {
        if (! $fileOrDirectory) {
            $io->writeln('No files specified for import, only recomputing aggregates for existing data.');

            return;
        }

        $io->writeln('Importing price reports...');
        [$time, $result] = measure(function () use ($fileOrDirectory, $jobs, $io) {
            $files = $this->importer->findFiles($fileOrDirectory);

            // A single file cannot be split up, so there is no point in paying for worker startup
            if ($jobs === 1 || count($files) < 2) {
                return $this->importer->import($fileOrDirectory, $io);
            }

            return $this->parallelImporter->import($files, $this->importer->collection->getCollectionName(), $jobs, $io);
        });

        $io->writeln(sprintf('Import took %.5fs: %d inserted, %d updated.', $time, $result->numInserted, $result->numUpdated));

        $this->runPostImportAggregations($this->importer->collection, $jobs, $io);
    }

    private function aggregatePriceReportsByDay(Collection $importCollection, SymfonyStyle $io): void
    {
        $io->write('Aggregating imported price reports for further processing...');

        [$time] = measure(
            fn () => $this->dailyPriceRepository->aggregatePriceReportsByDay($importCollection, $this->collection),
        );

        $importCollection->drop();

        $io->writeln(sprintf('Done in %.5fs.', $time));
    }

    private function addOpeningPriceAndMergeIntoPrices(SymfonyStyle $io): void
    {
        $this->createAggregatedIndex($io);

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
        $io->write('Checking and potentially creating index on import collection...');

        [$time] = measure(
            fn () => $this->collection->createIndex(['station._id' => 1, 'fuel' => 1, 'day' => 1]),
        );

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
        if ($jobs > 1) {
            $this->runPostImportAggregationsInParallel($importCollection, $jobs, $io);

            return;
        }

        if ($importCollection) {
            $this->aggregatePriceReportsByDay($importCollection, $io);
        }

        $this->addOpeningPriceAndMergeIntoPrices($io);
    }

    private function runPostImportAggregationsInParallel(?Collection $importCollection, int $jobs, SymfonyStyle $io): void
    {
        /* The workers run the second step for their stations as soon as they are done with the first,
         * so the index it needs has to exist up front. Building it on the (still empty) collection is
         * cheap, and the workers maintain it as they merge their results.
         */
        $this->createAggregatedIndex($io);

        $io->write(sprintf('Aggregating price reports, merging into prices collection and setting latest station prices using %d worker processes...', $jobs));

        [$time] = measure(
            fn () => $this->parallelAggregator->aggregate(
                $importCollection?->getCollectionName(),
                $this->collection->getCollectionName(),
                $this->updatedDays->getCollectionName(),
                $jobs,
            ),
        );

        $this->latestPricesUpdated = true;
        $this->collectAffectedDays();

        // Only drop the temporary collections once all workers succeeded, so a failed run can be recovered
        $importCollection?->drop();
        $this->collection->drop();
        $this->updatedDays->drop();

        $io->writeln(sprintf('Done in %.5fs.', $time));
    }
}
