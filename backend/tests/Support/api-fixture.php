<?php

declare(strict_types=1);

use App\Infrastructure\DoctrineFactory;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require dirname(__DIR__).'/bootstrap.php';
$db = DoctrineFactory::connection();
$action = $argv[1] ?? '';
if ($action === 'prepare') {
    $db->executeStatement('INSERT INTO users(username,password_hash) VALUES (?,?)', ['other-owner',password_hash('worker-test-password', PASSWORD_BCRYPT)]);
    $book = IOFactory::load('/tmp/t16-source.xlsx');
    // A controlled slow URL makes intermediate committed counters observable over HTTP.
    $book->getActiveSheet()->setCellValue('F2', 'http://fixture.test:18080/slow');
    $book->getActiveSheet()->setCellValue('F4', 'http://fixture.test:18080/slow,http://fixture.test:18080/404');
    (new Xlsx($book))->save('/tmp/t16-source.xlsx');
    $book->disconnectWorksheets();
} elseif ($action === 'expire') {
    $db->executeStatement("UPDATE import_rate_limits SET window_started_at=statement_timestamp()-interval '61 seconds'");
} else {
    throw new RuntimeException('Unknown API fixture action.');
}
$db->close();
echo '{"ok":true}';
