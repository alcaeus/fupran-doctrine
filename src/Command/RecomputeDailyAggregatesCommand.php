<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\DailyAggregateRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function App\measure;
use function sprintf;

#[AsCommand(
    name: 'app:data:compute:daily-aggregates',
    description: 'Computes daily aggregates from prices',
)]
class RecomputeDailyAggregatesCommand extends Command
{
    public function __construct(
        private readonly DailyAggregateRepository $dailyAggregateRepository,
    ) {
        parent::__construct(null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->write('Recomputing daily aggregates...');

        [$time] = measure(
            $this->dailyAggregateRepository->recomputeDailyAggregates(...),
        );

        $io->writeln(sprintf('Done in %.5fs.', $time));

        return Command::SUCCESS;
    }
}
