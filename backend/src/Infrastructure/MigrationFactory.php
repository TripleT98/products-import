<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;

final class MigrationFactory
{
    public static function create(?Connection $connection = null): DependencyFactory
    {
        return DependencyFactory::fromConnection(
            new ConfigurationArray([
                'migrations_paths' => ['Migrations' => dirname(__DIR__, 2) . '/migrations'],
                'table_storage' => ['table_name' => 'doctrine_migration_versions'],
                'all_or_nothing' => true,
                'transactional' => true,
                'check_database_platform' => true,
            ]),
            new ExistingConnection($connection ?? DoctrineFactory::connection()),
        );
    }
}
