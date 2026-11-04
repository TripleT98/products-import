<?php

declare(strict_types=1);

namespace App\Command;

use App\Queue\ImportQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'imports:queue-stats', description: 'Show ready message counts for imports and failed queues.')]
final class ImportQueueStatsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->writeln(json_encode((new ImportQueue())->stats(), JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        } catch (Throwable) {
            $output->writeln('{"error":"IMPORT_QUEUE_UNAVAILABLE"}', OutputInterface::OUTPUT_RAW);
            return Command::FAILURE;
        }
    }
}
