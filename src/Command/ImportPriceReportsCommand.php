<?php

declare(strict_types=1);

namespace App\Command;

use App\Aggregation\PriceReport;
use App\Document\Station;
use App\Import\ImportException;
use App\Import\PriceReportImporter;
use App\Repository\DailyAggregateRepository;
use App\Repository\DailyPriceRepository;
use App\Repository\StationRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\Regex;
use MongoDB\Builder\Pipeline;
use MongoDB\Builder\Stage;
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
use function is_array;
use function is_string;
use function iterator_to_array;
use function sprintf;
use function uniqid;

#[AsCommand(
    name: 'app:import:price-reports',
    description: 'Import price reports from a file or directory',
)]
class ImportPriceReportsCommand extends Command
{
    private readonly Database $database;
    private Collection $collection;

    public function __construct(
        private readonly PriceReportImporter $importer,
        private readonly DailyPriceRepository $dailyPriceRepository,
        private readonly DailyAggregateRepository $dailyAggregateRepository,
        private readonly StationRepository $stationRepository,
        DocumentManager $documentManager,
    ) {
        parent::__construct(null);

        $this->database = $documentManager->getDocumentDatabase(Station::class);
        $this->collection = $this->database
            ->selectCollection(uniqid('aggregatedPriceReports_'));
    }

    protected function configure(): void
    {
        $this
            ->addArgument(name: 'fileOrDirectory', mode: InputArgument::IS_ARRAY, description: 'Path to the file to be imported')
            ->addOption(name: 'recover', mode: InputOption::VALUE_NONE, description: 'Recover from a failure during a previous import')
            ->addOption(name: 'clear', mode: InputOption::VALUE_NONE, description: 'Clear all existing prices');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Recover from an import that previously failed
        if ($input->getOption('recover')) {
            $this->attemptRecovery($io);
        } else {
            if ($input->getOption('clear')) {
                $io->write('Clearing existing price data...');
                [$time] = measure($this->clearExistingPriceReports(...));
                $io->writeln(sprintf('Done in %.5fs', $time));
            }

            try {
                $this->importDataAndPreAggregate($this->getFileOrDirectoryArgument($input), $io);
            } catch (ImportException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        // TODO: Add openingPrice field where it does not exist (happens when multiple imports are done).
        // A previous attempt at this (looking up the previous day's closingPrice via a $lookup into
        // DailyPrice) was too slow and was removed; see git history for the removed implementation.

        // TODO: Get days that were updated to only recompute changed data
        $this->computeDailyAggregates($io);

        $this->updateLatestStationPrices($io);

        return Command::SUCCESS;
    }

    private function attemptRecovery(SymfonyStyle $io): void
    {
        // Check if there is a priceReportImport collection - this means that step 1 was completed, but the
        // pre-aggregation was not.
        $collectionName = $this->selectRecoveryCollection('priceReportImport_', $io);
        if ($collectionName) {
            $io->writeln(sprintf('Recovering existing price import from collection %s.', $collectionName));
            $this->runPostImportAggregations($this->database->getCollection($collectionName), $io);

            return;
        }

        // Check if there is a aggregatedPriceReports_ collection. If so, then the second step of merging data into
        // the DailyPrice collection failed. Resume from there.
        $collectionName = $this->selectRecoveryCollection('aggregatedPriceReports_', $io);

        if ($collectionName) {
            $io->writeln(sprintf('Recovering existing pre-aggregation from collection %s...', $collectionName));
            $this->collection = $this->database->getCollection($collectionName);
            $this->runPostImportAggregations(null, $io);
        }
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
    private function importDataAndPreAggregate(array $fileOrDirectory, SymfonyStyle $io): void
    {
        if (! $fileOrDirectory) {
            $io->writeln('No files specified for import, only recomputing aggregates for existing data.');

            return;
        }

        $io->writeln('Importing price reports...');
        [$time, $result] = measure(
            fn () => $this->importer->import($fileOrDirectory, $io),
        );

        $io->writeln(sprintf('Import took %.5fs: %d inserted, %d updated.', $time, $result->numInserted, $result->numUpdated));

        $this->runPostImportAggregations($this->importer->collection, $io);
    }

    private function aggregatePriceReportsByDay(Collection $importCollection, SymfonyStyle $io): void
    {
        $io->write('Aggregating imported price reports for further processing...');

        $pipeline = new Pipeline(
            PriceReport::aggregatePriceReportsByDay(),
            Stage::merge($this->collection->getCollectionName()),
        );

        [$time] = measure(
            static fn () => $importCollection->aggregate($pipeline),
        );

        $importCollection->drop();

        $io->writeln(sprintf('Done in %.5fs.', $time));
    }

    private function addOpeningPriceAndMergeIntoPrices(SymfonyStyle $io): void
    {
        $io->write('Adding opening price for each day and merge into prices collection...');

        $pipeline = new Pipeline(
            PriceReport::aggregatePriceData(),
            Stage::merge(
                into: $this->dailyPriceRepository->getDocumentCollection()->getCollectionName(),
                whenMatched: 'keepExisting',
            ),
        );

        [$time] = measure(
            fn () => $this->collection->aggregate($pipeline),
        );

        $this->collection->drop();

        $io->writeln(sprintf('Done in %.5fs.', $time));
    }

    private function computeDailyAggregates(SymfonyStyle $io): void
    {
        $io->write('Recomputing daily aggregates...');

        [$time] = measure(
            $this->dailyAggregateRepository->recomputeDailyAggregates(...),
        );

        $io->writeln(sprintf('Done in %.5fs.', $time));
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

        $pipeline = new Pipeline(
            PriceReport::getLatestPriceReportsPerStation(),
            Stage::merge(
                into: $this->stationRepository->getDocumentCollection()->getCollectionName(),
                on: '_id',
            ),
        );

        [$time] = measure(
            fn () => $this
                ->dailyPriceRepository
                ->getDocumentCollection()
                ->aggregate($pipeline),
        );

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

    private function runPostImportAggregations(?Collection $importCollection, SymfonyStyle $io): void
    {
        if ($importCollection) {
            $this->aggregatePriceReportsByDay($importCollection, $io);
        }

        $this->addOpeningPriceAndMergeIntoPrices($io);
    }
}
