<?php

declare(strict_types=1);

use App\Import\PreparedImage;
use App\Import\ProductWriter;
use App\Import\RowDto;
use App\Infrastructure\DoctrineFactory;
use Doctrine\DBAL\DriverManager;

require dirname(__DIR__) . '/bootstrap.php';

$database = getenv('T11_TEST_DATABASE') ?: '';
if (!preg_match('/^product_import_test_writer_[a-f0-9]{12}$/D', $database)) {
    throw new RuntimeException('Only isolated writer test databases are allowed.');
}
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$params = DoctrineFactory::connection()->getParams();
$params['dbname'] = $database;
$writer = new ProductWriter(static function () use ($params) {
    $connection = DriverManager::getConnection($params);
    $connection->executeStatement("SET lock_timeout = '15s'");
    $connection->executeStatement("SET statement_timeout = '20s'");
    return DoctrineFactory::entityManager($connection);
});
$row = new RowDto(...$payload['row']);
$images = array_map(static fn (array $image): PreparedImage => new PreparedImage($image['url'], $image['path']), $payload['images']);
$result = $writer->write($row, $images);
echo json_encode(['productId' => $result->productId, 'created' => $result->created, 'attempts' => $result->attempts], JSON_THROW_ON_ERROR);
