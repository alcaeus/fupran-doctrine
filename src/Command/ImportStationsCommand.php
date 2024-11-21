<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\ImportException;
use App\Import\StationImporter;
use App\Repository\StationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
        private readonly StationRepository $stations,
    ) {
        parent::__construct(null);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fileOrDirectory', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Path to the files or directories to be imported')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear all stations before import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('clear')) {
            $this->stations->createQueryBuilder()
                ->remove()
                ->getQuery()
                ->execute();
        }

        try {
            $start = microtime(true);
            $result = $this->importer->import($input->getArgument('fileOrDirectory'), $io);
            $end = microtime(true);

            $io->success(sprintf('Done in %.5fs: %d inserted, %d updated.', $end - $start, $result->numInserted, $result->numUpdated));
        } catch (ImportException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
