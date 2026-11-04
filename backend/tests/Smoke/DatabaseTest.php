<?php

declare(strict_types=1);

namespace Tests\Smoke;

use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    public function testRealPostgresConnectionAndTransactionRollback(): void
    {
        $connection = new PDO(
            'pgsql:host=db-test;port=5432;dbname=product_import_test;connect_timeout=3',
            'product_import_test',
            getenv('POSTGRES_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        self::assertSame('product_import_test', $connection->query('SELECT current_database()')->fetchColumn());
        self::assertSame('product_import_test', $connection->query('SELECT current_user')->fetchColumn());

        // A session-local table exercises actual writes without creating an app schema.
        $connection->exec('CREATE TEMPORARY TABLE smoke_marker (value text NOT NULL)');
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare('INSERT INTO smoke_marker (value) VALUES (:value)');
            $statement->execute(['value' => 'transaction-probe']);
            self::assertSame('transaction-probe', $connection->query('SELECT value FROM smoke_marker')->fetchColumn());
        } finally {
            $connection->rollBack();
        }
        self::assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM smoke_marker')->fetchColumn());
    }
}
