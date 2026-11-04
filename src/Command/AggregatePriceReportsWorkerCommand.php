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
use Symfony\Component\Console\Output\OutputInterface;
use UnexpectedValueException;

use function is_string;

/**
 * Internal worker for `app:import:price-reports --jobs=N`; not meant to be run by hand.
 *
 * Runs the second step of the pre-aggregation for stations in one slice of the UUID space: merges the
 * aggregated price reports into the DailyPrice collection, connects them to previously imported days
 * and sets the latest prices on the slice's stations.
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
            ->addArgument('updated-days-collection', InputArgument::REQUIRED, 'Name of the collection for previously imported days that had their opening price updated')
            ->addArgument('part', InputArgument::REQUIRED, 'Zero-based index of the slice of stations to process')
            ->addArgument('parts', InputArgument::REQUIRED, 'Number of slices the stations are split into');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $range = UuidRange::part((int) $this->getString($input->getArgument('part')), (int) $this->getString($input->getArgument('parts')));

        $database = $this->documentManager->getDocumentDatabase(Station::class);
        $aggregated = $database->selectCollection($this->getString($input->getArgument('aggregated-collection')));
        $updatedDays = $database->selectCollection($this->getString($input->getArgument('updated-days-collection')));

        $this->dailyPriceRepository->resolveBoundaryOpeningPrices($aggregated, $range);
        $this->dailyPriceRepository->mergeAggregatedPriceReports($aggregated, $range);
        $this->dailyPriceRepository->updateOpeningPricesFollowingBatch($aggregated, $updatedDays, $range);

        /* Stations are independent of each other, so once the prices of this slice of stations are merged
         * and connected to their neighbouring days, their latest prices can be set without waiting for the
         * other workers.
         */
        $this->dailyPriceRepository->updateLatestStationPrices($range);

        return Command::SUCCESS;
    }

    private function getString(mixed $value): string
    {
        if (! is_string($value)) {
            throw new UnexpectedValueException('Expected a string value.');
        }

        return $value;
    }
}
