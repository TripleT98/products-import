<?php

declare(strict_types=1);

namespace App\Command;

use App\Fixtures\CatalogFixtures;
use App\Infrastructure\DoctrineFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fixtures:load', description: 'Explicit demo/test fixtures; refuses nonempty modified databases.')]
final class LoadFixturesCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = DoctrineFactory::entityManager();
        try {
            $result = (new CatalogFixtures($em))->load(getenv('IMAGE_DIR') ?: '/var/app/images');
            $output->writeln('Fixtures ' . $result . ': 48 products, 96 attributes, 48 images, 1 user.');
            return Command::SUCCESS;
        } finally {
            $em->getConnection()->close();
            $em->close();
        }
    }
}
