<?php

declare(strict_types=1);

use App\Import\Job\ImportJobService;
use App\Infrastructure\DoctrineFactory;
use Doctrine\DBAL\DriverManager;

require dirname(__DIR__) . '/bootstrap.php';
$database = getenv('T14_TEST_DATABASE') ?: '';
if (!preg_match('/^product_import_test_job_[a-f0-9]{12}$/D', $database)) {
    throw new RuntimeException('Only private T14 test databases are allowed.');
}
$params = DoctrineFactory::connection()->getParams();
$params['dbname'] = $database;
$service = new ImportJobService(static function () use ($params) {
    $connection = DriverManager::getConnection($params);
    $connection->executeStatement("SET lock_timeout='15s'");
    $connection->executeStatement("SET statement_timeout='20s'");
    return DoctrineFactory::entityManager($connection);
});
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$lease = $service->claim($input['id']);
echo json_encode($lease, JSON_THROW_ON_ERROR);
