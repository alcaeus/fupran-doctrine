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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function microtime;
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
            ->addArgument('fileOrDirectory', InputArgument::REQUIRED, 'Path to the file to be imported')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fileOrDirectory = $input->getArgument('fileOrDirectory');

        $io->write('Importing price reports...');

        try {
            $start = microtime(true);
            $result = $this->importer->import($fileOrDirectory);
            $end = microtime(true);

            $io->writeln(sprintf('Done in %.5fs: %d inserted, %d updated.', $end - $start, $result->numInserted, $result->numUpdated));
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

        $start = microtime(true);
        // TODO: iterator_to_array becomes obsolete in mongodb/mongodb 2.0
        $this->importer->collection->aggregate(iterator_to_array($pipeline));
        $end = microtime(true);

        $io->writeln(sprintf('Done in %.5fs.', $end - $start));
    }

    private function addOpeningPriceAndMergeIntoPrices(SymfonyStyle $io): void
    {
        $io->write('Adding opening price for each day and merge into prices collection...');

        $pipeline = new Pipeline(
            PriceReport::addOpeningPrice(),
            Stage::merge(
                into: $this->dailyPriceRepository->getDocumentCollection()->getCollectionName(),
                whenMatched: 'keepExisting',
            ),
        );

        $start = microtime(true);
        // TODO: iterator_to_array becomes obsolete in mongodb/mongodb 2.0
        $this->collection->aggregate(iterator_to_array($pipeline));
        $end = microtime(true);

        $io->writeln(sprintf('Done in %.5fs.', $end - $start));
    }

    private function addMissingOpeningPrices(SymfonyStyle $io): void
    {
        $io->write('Adding opening price for records previously imported...');

        $pipeline = new Pipeline(
            PriceReport::addMissingOpeningPrices(),
            Stage::project(openingPrice: true),
            Stage::merge($this->dailyPriceRepository->getDocumentCollection()->getCollectionName()),
        );

        $start = microtime(true);
        // TODO: iterator_to_array becomes obsolete in mongodb/mongodb 2.0
        $this
            ->dailyPriceRepository
            ->getDocumentCollection()
            ->aggregate(iterator_to_array($pipeline));
        $end = microtime(true);

        $io->writeln(sprintf('Done in %.5fs.', $end - $start));
    }
}
