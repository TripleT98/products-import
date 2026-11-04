<?php

declare(strict_types=1);

use App\Image\ImageFileCleaner;
use App\Image\ImageStorage;
use App\Infrastructure\DoctrineFactory;
use Doctrine\DBAL\DriverManager;

require dirname(__DIR__) . '/bootstrap.php';
$database = getenv('T13_TEST_DATABASE') ?: '';
$directory = getenv('T13_IMAGE_DIR') ?: '';
if (!preg_match('/^product_import_test_writer_[a-f0-9]{12}$/D', $database)
    || !preg_match('~^/tmp/t13-row-[a-f0-9]{12}$~D', $directory)) {
    throw new RuntimeException('Only private T13 test databases/directories are allowed.');
}
$params = DoctrineFactory::connection()->getParams();
$params['dbname'] = $database;
$cleaner = new ImageFileCleaner(new ImageStorage($directory), static function () use ($params) {
    $connection = DriverManager::getConnection($params);
    $connection->executeStatement("SET lock_timeout='15s'");
    return DoctrineFactory::entityManager($connection);
});
echo json_encode($cleaner->clean(json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
