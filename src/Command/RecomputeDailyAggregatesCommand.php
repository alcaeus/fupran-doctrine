<?php

declare(strict_types=1);

namespace App\Command;

use App\DateRange;
use App\FileImport\WorkerProcessFactory;
use App\Repository\DailyAggregateRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use MongoDB\BSON\UTCDateTime;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

use function App\measure;
use function array_chunk;
use function count;
use function filter_var;
use function is_string;
use function min;
use function sprintf;
use function usleep;

use const FILTER_VALIDATE_INT;

#[AsCommand(
    name: 'app:data:compute:daily-aggregates',
    description: 'Computes daily aggregates from prices',
)]
class RecomputeDailyAggregatesCommand extends Command
{
    /** Number of days that are recomputed at once; the progress is updated after each batch */
    private const int BATCH_SIZE = 30;

    public function __construct(
        private readonly DailyAggregateRepository $dailyAggregateRepository,
        private readonly WorkerProcessFactory $processFactory,
    ) {
        parent::__construct(null);
    }

    protected function configure(): void
    {
        $this
            ->addOption(name: 'from', mode: InputOption::VALUE_REQUIRED, description: 'First day to recompute, formatted as ' . DateRange::FORMAT . ' (defaults to the first day with prices)')
            ->addOption(name: 'to', mode: InputOption::VALUE_REQUIRED, description: 'Last day to recompute, formatted as ' . DateRange::FORMAT . ' (defaults to the last day with prices)')
            ->addOption(name: 'jobs', shortcut: 'j', mode: InputOption::VALUE_REQUIRED, description: 'Number of worker processes to recompute with', default: 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $from = $this->getDayOption($input, 'from');
        $to = $this->getDayOption($input, 'to');
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('The "from" date must not be after the "to" date.');
        }

        $jobs = $this->getJobs($input);

        $days = $this->dailyAggregateRepository->getDaysWithPrices($from, $to);
        if ($days === []) {
            $io->writeln('There are no days with prices to recompute.');

            return Command::SUCCESS;
        }

        $batches = array_chunk($days, self::BATCH_SIZE);
        $jobs = min($jobs, count($batches));

        $io->writeln(sprintf('Recomputing daily aggregates of %d days%s...', count($days), $jobs > 1 ? sprintf(' using %d worker processes', $jobs) : ''));
        $io->progressStart(count($days));

        [$time] = measure(
            $jobs > 1
                ? fn () => $this->recomputeInParallel($batches, $jobs, $io)
                : fn () => $this->recomputeSequentially($batches, $io),
        );

        $io->progressFinish();
        $io->writeln(sprintf('Done in %.5fs.', $time));

        return Command::SUCCESS;
    }

    /** @param list<list<UTCDateTime>> $batches */
    private function recomputeSequentially(array $batches, SymfonyStyle $io): void
    {
        foreach ($batches as $batch) {
            $this->dailyAggregateRepository->recomputeDailyAggregates($batch);
            $io->progressAdvance(count($batch));
        }
    }

    /**
     * Keeps up to $jobs workers busy with batches of days, so progress can be reported as batches complete
     *
     * @param list<list<UTCDateTime>> $batches Each sorted, oldest first
     */
    private function recomputeInParallel(array $batches, int $jobs, SymfonyStyle $io): void
    {
        /** @var array<int, Process> $running */
        $running = [];
        $next = 0;

        while ($next < count($batches) || $running !== []) {
            while ($next < count($batches) && count($running) < $jobs) {
                $batch = $batches[$next];
                // The workers are this command, restricted to a batch of days
                $process = $this->processFactory->create($this->getName() ?? '', [
                    '--from=' . $batch[0]->toDateTime()->format(DateRange::FORMAT),
                    '--to=' . $batch[count($batch) - 1]->toDateTime()->format(DateRange::FORMAT),
                ]);
                $process->start();
                $running[$next++] = $process;
            }

            foreach ($running as $index => $process) {
                if ($process->isRunning()) {
                    continue;
                }

                if (! $process->isSuccessful()) {
                    foreach ($running as $other) {
                        $other->stop();
                    }

                    throw new RuntimeException(sprintf(
                        'Daily aggregate worker failed with exit code %d: %s',
                        $process->getExitCode() ?? -1,
                        $process->getErrorOutput(),
                    ));
                }

                unset($running[$index]);
                $io->progressAdvance(count($batches[$index]));
            }

            usleep(50_000);
        }
    }

    private function getDayOption(InputInterface $input, string $name): ?DateTimeImmutable
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('Expected "%s" option to be a string.', $name));
        }

        return DateRange::parseDay($value, $name);
    }

    private function getJobs(InputInterface $input): int
    {
        $jobs = filter_var($input->getOption('jobs'), FILTER_VALIDATE_INT);
        if ($jobs === false || $jobs < 1) {
            throw new InvalidArgumentException('Expected "jobs" option to be a positive integer.');
        }

        return $jobs;
    }
}
