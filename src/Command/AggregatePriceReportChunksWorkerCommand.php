<?php

declare(strict_types=1);

namespace App\Command;

use App\Aggregation\ChunkAggregationRunner;
use App\Aggregation\ChunkQueue;
use App\Aggregation\ChunkResult;
use App\Document\Station;
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
 * Groups the raw price reports of chunks of stations, taken from the queue until it is empty. Writes one
 * JSON line with a ChunkResult to stdout for every finished chunk.
 */
#[AsCommand(
    name: 'app:import:price-reports-chunk-worker',
    description: 'Internal: group price reports for chunks of stations taken from a queue',
    hidden: true,
)]
final class AggregatePriceReportChunksWorkerCommand extends Command
{
    public function __construct(
        private readonly ChunkAggregationRunner $runner,
        private readonly DocumentManager $documentManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('import-collection', InputArgument::REQUIRED, 'Name of the collection with raw price reports')
            ->addArgument('aggregated-collection', InputArgument::REQUIRED, 'Name of the collection for pre-aggregated price reports')
            ->addArgument('queue-collection', InputArgument::REQUIRED, 'Name of the collection with chunks of stations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $database = $this->documentManager->getDocumentDatabase(Station::class);

        $this->runner->run(
            $database->selectCollection($this->getString($input->getArgument('import-collection'))),
            $database->selectCollection($this->getString($input->getArgument('aggregated-collection'))),
            new ChunkQueue($database->selectCollection($this->getString($input->getArgument('queue-collection')))),
            static function (ChunkResult $result) use ($output): void {
                $output->writeln($result->toJson(), OutputInterface::OUTPUT_RAW);
            },
        );

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
