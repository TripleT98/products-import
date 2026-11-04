<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\DoctrineFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

abstract class StorageTestCase extends TestCase
{
    protected EntityManager $em;
    protected Connection $connection;

    public static function setUpBeforeClass(): void
    {
        $connection = DoctrineFactory::connection();
        MigrationSupport::migrate($connection);
        $connection->close();
    }

    protected function setUp(): void
    {
        $this->em = DoctrineFactory::entityManager();
        $this->connection = $this->em->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->em->close();
        $this->connection->close();
    }
}
