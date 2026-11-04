<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

final class DoctrineFactory
{
    public static function connection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => getenv('DB_HOST') ?: 'db',
            'port' => (int) (getenv('DB_PORT') ?: '5432'),
            'dbname' => getenv('POSTGRES_DB') ?: 'product_import',
            'user' => getenv('POSTGRES_USER') ?: '',
            'password' => getenv('POSTGRES_PASSWORD') ?: '',
            'serverVersion' => '18.6',
        ]);
    }

    public static function entityManager(?Connection $connection = null): EntityManager
    {
        $connection ??= self::connection();
        $connection->getConfiguration()->setSchemaAssetsFilter(
            static fn (string $name): bool => $name !== 'doctrine_migration_versions'
        );
        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            [dirname(__DIR__) . '/Entity'],
            isDevMode: getenv('APP_ENV') === 'test',
        );
        $configuration->enableNativeLazyObjects(true);

        return new EntityManager($connection, $configuration);
    }
}
