<?php

declare(strict_types=1);

namespace App\FileImport;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

use const PHP_BINARY;

/**
 * Creates processes that run a hidden worker command of this application in the same environment.
 * Environment variables, including connection settings, are inherited from the current process.
 */
final class WorkerProcessFactory
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    /** @param list<string> $arguments */
    public function create(string $command, array $arguments = [], ?string $input = null): Process
    {
        $process = new Process(
            [PHP_BINARY, $this->projectDir . '/bin/console', $command, ...$arguments, '--env=' . $this->environment, '--no-interaction'],
            $this->projectDir,
            input: $input,
        );
        $process->setTimeout(null);

        return $process;
    }
}
