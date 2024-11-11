<?php

namespace App\Command;

use App\Import\StationImporter;
use App\Repository\StationsRepository;
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
    name: 'app:import-stations',
    description: 'Imports stations from a file or directory',
)]
class ImportStationsCommand extends Command
{
    public function __construct(
        private readonly StationImporter $importer,
        private readonly StationsRepository $stations,
    ) {
        parent::__construct(null);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fileOrDirectory', InputArgument::REQUIRED, 'Path to the file to be imported')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear all stations before import')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fileOrDirectory = $input->getArgument('fileOrDirectory');

        if ($input->getOption('clear')) {
            $this->stations->createQueryBuilder()
                ->remove()
                ->getQuery()
                ->execute();
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
