<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\DoctrineFactory;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Tools\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class MigrationLifecycleTest extends TestCase
{
    public function testUpDownAndRepeatedUpOnSeparateEmptyDatabase(): void
    {
        $admin = DoctrineFactory::connection();
        $database = 'product_import_test_rollback_' . bin2hex(random_bytes(6));
        $admin->executeStatement('CREATE DATABASE ' . $database);
        $parameters = $admin->getParams();
        $parameters['dbname'] = $database;
        $connection = DriverManager::getConnection($parameters);
        try {
            self::assertSame([], $connection->createSchemaManager()->listTableNames());
            MigrationSupport::migrate($connection);
            $em = DoctrineFactory::entityManager(DriverManager::getConnection($parameters));
            try {
                self::assertCount(8, $em->getMetadataFactory()->getAllMetadata());
                $validator = new SchemaValidator($em);
                self::assertSame([], $validator->validateMapping());
                self::assertTrue($validator->schemaInSyncWithMetadata());
            } finally {
                $em->getConnection()->close();
                $em->close();
            }
            MigrationSupport::migrate($connection, 'first');
            self::assertSame(['doctrine_migration_versions'], $connection->createSchemaManager()->listTableNames());
            MigrationSupport::migrate($connection);
            self::assertTrue($connection->createSchemaManager()->tablesExist([
                'products', 'product_attributes', 'product_images', 'users', 'import_jobs', 'import_row_results', 'import_issues', 'import_rate_limits',
            ]));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM products'));
        } finally {
            $connection->close();
            $admin->executeStatement('DROP DATABASE ' . $database);
            $admin->close();
        }
    }
}
