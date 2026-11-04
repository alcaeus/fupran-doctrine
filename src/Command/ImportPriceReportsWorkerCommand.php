<?php

declare(strict_types=1);

namespace App\Command;

use App\FileImport\ImportResult;
use App\FileImport\PriceReportFileImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UnexpectedValueException;

use function array_filter;
use function array_values;
use function explode;
use function is_string;
use function stream_get_contents;
use function trim;

use const PHP_EOL;
use const STDIN;

/**
 * Internal worker for `app:import:price-reports --jobs=N`; not meant to be run by hand.
 *
 * Reads newline-separated file paths from stdin and writes one JSON line per imported
 * file to stdout, which the parent process uses to track progress and sum up results.
 */
#[AsCommand(
    name: 'app:import:price-reports-worker',
    description: 'Internal: import price report files into a given temporary collection',
    hidden: true,
)]
final class ImportPriceReportsWorkerCommand extends Command
{
    public function __construct(private readonly PriceReportFileImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('collection', InputArgument::REQUIRED, 'Name of the temporary collection to import into');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $collection = $input->getArgument('collection');
        if (! is_string($collection)) {
            throw new UnexpectedValueException('Expected "collection" argument to be a string.');
        }

        $this->importer->useCollection($collection);

        $files = array_filter(explode(PHP_EOL, trim((string) stream_get_contents(STDIN))));

        $this->importer->importFiles(
            array_values($files),
            onFileImported: static function (string $file, ImportResult $result) use ($output): void {
                $output->writeln($result->toJson($file), OutputInterface::OUTPUT_RAW);
            },
        );

        return Command::SUCCESS;
    }
}
