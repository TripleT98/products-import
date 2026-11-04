<?php

declare(strict_types=1);

namespace App\Command;

use App\Queue\EnqueueFailed;
use App\Queue\ImportQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'imports:enqueue', description: 'Copy a trusted local XLSX into private storage and publish its job ID. No product import in this process.')]
final class EnqueueImportCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED);
        $this->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Existing user ID.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $owner = (string) $input->getOption('owner');
            if (!ctype_digit($owner) || (int) $owner < 1) {
                throw new \InvalidArgumentException('An owner is required.');
            }
            $id = (new ImportQueue())->publisher->enqueueLocal((string) $input->getArgument('path'), (int) $owner);
            $output->writeln(json_encode(['jobId' => $id, 'status' => 'queued'], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        } catch (Throwable $error) {
            $output->writeln(json_encode(['error' => 'IMPORT_ENQUEUE_FAILED', 'jobId' => $error instanceof EnqueueFailed ? $error->jobId : null], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            return Command::FAILURE;
        }
    }
}
