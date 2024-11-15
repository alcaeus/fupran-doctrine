<?php

namespace App\Command;

use App\Aggregation\PriceReport;
use App\Document\Station;
use App\Import\ImportException;
use App\Import\PriceReportImporter;
use App\Repository\DailyPriceRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\Builder\Pipeline;
use MongoDB\Builder\Stage;
use MongoDB\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function App\measure;
use function iterator_to_array;
use function sprintf;
use function uniqid;

#[AsCommand(
    name: 'app:import-price-reports',
    description: 'Import price reports from a file or directory',
)]
class ImportPriceReportsCommand extends Command
{
    private readonly Collection $collection;

    public function __construct(
        private readonly PriceReportImporter $importer,
        private readonly DailyPriceRepository $dailyPriceRepository,
        DocumentManager $documentManager,
    ) {
        parent::__construct(null);

        $this->collection = $documentManager
            ->getDocumentDatabase(Station::class)
            ->selectCollection(uniqid('aggregatedPriceReports_'));
    }

    protected function configure(): void
    {
        $this
            ->addArgument(name: 'fileOrDirectory', mode: InputArgument::REQUIRED, description: 'Path to the file to be imported')
            ->addOption(name: 'clear', mode: InputOption::VALUE_NONE, description: 'Clear all existing prices')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fileOrDirectory = $input->getArgument('fileOrDirectory');

        if ($input->getOption('clear')) {
            $io->write('Clearing existing price data...');
            [$time] = measure(
                fn () => $this->dailyPriceRepository->createQueryBuilder()
                    ->remove()
                    ->getQuery()
                    ->execute()
            );
            $io->writeln(sprintf('Done in %.5fs', $time));
        }

        $io->write('Importing price reports...');

        try {
            [$time, $result] = measure(
                fn () => $this->importer->import($fileOrDirectory),
            );

            $io->writeln(sprintf('Done in %.5fs: %d inserted, %d updated.', $time, $result->numInserted, $result->numUpdated));
        } catch (ImportException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->aggregatePriceReportsByDay($io);

        // Clean up
        $this->importer->collection->drop();

        $this->addOpeningPriceAndMergeIntoPrices($io);

        $this->collection->drop();

        // TODO: Add openingPrice field where it does not exist (happens when multiple imports are done)
        // This is currently slooooow
        // $this->addMissingOpeningPrices($io);

        return Command::SUCCESS;
    }

    private function aggregatePriceReportsByDay(SymfonyStyle $io): void
    {
        $io->write('Aggregating imported price reports for further processing...');

        $pipeline = new Pipeline(
            PriceReport::aggregatePriceReportsByDay(),
            Stage::merge($this->collection->getCollectionName()),
        );

        [$time] = measure(
            // TODO: iterator_to_array becomes obsolete in mongodb/mongodb 2.0
            fn () => $this->importer->collection->aggregate(iterator_to_array($pipeline)),
        );

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
            // TODO: iterator_to_array becomes obsolete in mongodb/mongodb 2.0
            fn () => $this->collection->aggregate(iterator_to_array($pipeline)),
        );

        $io->writeln(sprintf('Done in %.5fs.', $time));
    }

    private function addMissingOpeningPrices(SymfonyStyle $io): void
    {
        $io->write('Adding opening price for records previously imported...');

        $pipeline = new Pipeline(
            PriceReport::addMissingOpeningPrices(),
            Stage::project(openingPrice: true),
            Stage::merge($this->dailyPriceRepository->getDocumentCollection()->getCollectionName()),
        );

        [$time] = measure(
            // TODO: iterator_to_array becomes obsolete in mongodb/mongodb 2.0
            fn () => $this
                ->dailyPriceRepository
                ->getDocumentCollection()
                ->aggregate(iterator_to_array($pipeline)),
        );

        $io->writeln(sprintf('Done in %.5fs.', $time));
    }
}
