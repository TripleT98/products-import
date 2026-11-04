<?php

declare(strict_types=1);

namespace App\Command;

use App\Image\ImageUrlPolicy;
use App\Queue\ImportQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'imports:work', description: 'Consume import jobs from RabbitMQ through Symfony Messenger.')]
final class WorkImportsCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after current message when the time limit is reached.', '3600');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Message limit, zero means unlimited.', '0');
        $this->addOption('test-image-origin', null, InputOption::VALUE_REQUIRED, 'Exact loopback fixture origin; isolated APP_ENV=test only.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $time = (string) $input->getOption('time-limit');
            $limit = (string) $input->getOption('limit');
            if (!ctype_digit($time) || (int) $time < 1 || (int) $time > 86400 || !ctype_digit($limit) || strlen($limit) > 6) {
                throw new \InvalidArgumentException('Invalid worker limits.');
            }
            $origin = $input->getOption('test-image-origin');
            $policy = null;
            if ($origin !== null) {
                if (getenv('APP_ENV') !== 'test' || getenv('DB_HOST') !== 'db-test' || getenv('POSTGRES_DB') !== 'product_import_test' || getenv('POSTGRES_USER') !== 'product_import_test') {
                    throw new \LogicException('Only isolated tests may allow the fixture image origin.');
                }
                $policy = ImageUrlPolicy::forTestServer((string) $origin);
            }
            (new ImportQueue($policy))->run((int) $time, (int) $limit);
            return Command::SUCCESS;
        } catch (Throwable) {
            $output->writeln('{"error":"IMPORT_WORKER_FAILED"}', OutputInterface::OUTPUT_RAW);
            return Command::FAILURE;
        }
    }
}
