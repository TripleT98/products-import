<?php

declare(strict_types=1);

use App\Infrastructure\DoctrineFactory;

require dirname(__DIR__).'/bootstrap.php';
$db = DoctrineFactory::connection();
if (getenv('DB_HOST') !== 'db-test' || getenv('POSTGRES_DB') !== 'product_import_test'
    || (int)$db->fetchOne('SELECT COUNT(*) FROM products') !== 0
    || (int)$db->fetchOne('SELECT COUNT(*) FROM import_jobs') !== 0
    || $db->fetchFirstColumn('SELECT username FROM users') !== ['worker-fixture']) {
    throw new RuntimeException('Requires the fresh isolated docs test bootstrap.');
}
$db->executeStatement("DELETE FROM users WHERE username='worker-fixture'");
$db->close();
echo '{"readyForCatalogFixtures":true}';
