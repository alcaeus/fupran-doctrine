<?php

namespace App\Command;

use App\Import\PriceReportImporter;
use App\Repository\PriceReportRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use function file_exists;
use function is_dir;
use function is_file;
use function microtime;
use function sprintf;

#[AsCommand(
    name: 'app:import-price-reports',
    description: 'Import price reports from a file or directory',
)]
class ImportPriceReportsCommand extends Command
{
    public function __construct(
        private readonly PriceReportImporter $importer,
        private readonly PriceReportRepository $priceReports,
    ) {
        parent::__construct(null);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fileOrDirectory', InputArgument::REQUIRED, 'Path to the file to be imported')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear all price reports before import')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fileOrDirectory = $input->getArgument('fileOrDirectory');

        if ($input->getOption('clear')) {
            $output->write('Clearing existing price reports...');
            $this->priceReports->createQueryBuilder()
                ->remove()
                ->getQuery()
                ->execute();
            $output->writeln('Done');
        }

        if (!file_exists($fileOrDirectory)) {
            $io->error(sprintf('File or directory "%s" does not exist', $fileOrDirectory));
            return Command::FAILURE;
        }

        $start = microtime(true);
        if (is_file($fileOrDirectory)) {
            $result = $this->importer->importFile($fileOrDirectory, $output);
        } elseif (is_dir($fileOrDirectory)) {
            $result = $this->importer->importDirectory($fileOrDirectory, $output);
        } else {
            $io->error(sprintf('Cannot import file "%s"', $fileOrDirectory));

            return Command::FAILURE;
        }
        $end = microtime(true);

        $io->success(sprintf('Done in %.5fs: %d inserted, %d updated.', $end - $start, $result->numInserted, $result->numUpdated));

        return Command::SUCCESS;
    }
}
