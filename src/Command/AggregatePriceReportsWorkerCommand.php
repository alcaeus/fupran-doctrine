<?php

declare(strict_types=1);

namespace App\Command;

use App\Aggregation\UuidRange;
use App\Document\Station;
use App\Repository\DailyPriceRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use UnexpectedValueException;

use function filter_var;
use function is_string;
use function sprintf;

use const FILTER_VALIDATE_INT;

/**
 * Internal worker for `app:import:price-reports --jobs=N`; not meant to be run by hand.
 *
 * Runs the pre-aggregation for stations in one slice of the UUID space: optionally groups
 * raw price reports into the aggregated collection, then merges the aggregated price reports
 * into the DailyPrice collection and sets the latest prices on the slice's stations.
 */
#[AsCommand(
    name: 'app:import:price-reports-aggregate-worker',
    description: 'Internal: aggregate price reports for a slice of stations',
    hidden: true,
)]
final class AggregatePriceReportsWorkerCommand extends Command
{
    public function __construct(
        private readonly DailyPriceRepository $dailyPriceRepository,
        private readonly DocumentManager $documentManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('aggregated-collection', InputArgument::REQUIRED, 'Name of the collection for pre-aggregated price reports')
            ->addArgument('part', InputArgument::REQUIRED, 'Zero-based index of the slice of stations to process')
            ->addArgument('parts', InputArgument::REQUIRED, 'Number of slices the stations are split into')
            ->addOption('import-collection', null, InputOption::VALUE_REQUIRED, 'Name of the collection with raw price reports; if omitted, only the second step is run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $range = UuidRange::part($this->getIntArgument($input, 'part'), $this->getIntArgument($input, 'parts'));

        $database = $this->documentManager->getDocumentDatabase(Station::class);
        $aggregated = $database->selectCollection($this->getStringValue($input->getArgument('aggregated-collection')));

        $importCollection = $input->getOption('import-collection');
        if ($importCollection !== null) {
            $this->dailyPriceRepository->aggregatePriceReportsByDay(
                $database->selectCollection($this->getStringValue($importCollection)),
                $aggregated,
                $range,
            );
        }

        $this->dailyPriceRepository->mergeAggregatedPriceReports($aggregated, $range);

        /* Stations are independent of each other, so once the prices of this slice of stations are merged
         * their latest prices can be set without waiting for the other workers.
         */
        $this->dailyPriceRepository->updateLatestStationPrices($range);

        return Command::SUCCESS;
    }

    private function getIntArgument(InputInterface $input, string $name): int
    {
        $value = filter_var($input->getArgument($name), FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new UnexpectedValueException(sprintf('Expected "%s" argument to be an integer.', $name));
        }

        return $value;
    }

    private function getStringValue(mixed $value): string
    {
        if (! is_string($value)) {
            throw new UnexpectedValueException('Expected a string value.');
        }

        return $value;
    }
}
