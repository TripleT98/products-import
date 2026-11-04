<?php

declare(strict_types=1);

use App\Import\Job\ImportJobService;
use App\Infrastructure\DoctrineFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require dirname(__DIR__).'/bootstrap.php';
$book = new Spreadsheet();
$sheet = $book->getActiveSheet();
$sheet->fromArray(['Наименование', 'Внешний код', 'Цена: Цена продажи', 'Закупочная цена', 'Доп. поле: Размер', 'Доп. поле: Ссылки на фото']);
for ($i = 0; $i < 20; ++$i) {
    $urls = 'http://fixture.test:18080/slow';
    if ($i === 0) {
        $urls .= ',http://fixture.test:18080/404';
    }
    $sheet->fromArray(['Report row '.$i, 'R'.str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT), $i >= 1 && $i <= 12 ? '0' : '100', '75', '0', $urls], null, 'A'.($i + 2));
}
(new Xlsx($book))->save('/tmp/report-ui.xlsx');
$book->disconnectWorksheets();
$db = DoctrineFactory::connection();
$owner = (int)$db->fetchOne('INSERT INTO users(username,password_hash) VALUES (?,?) RETURNING id', ['report-other-owner', password_hash('not-used', PASSWORD_BCRYPT)]);
$foreign = (new ImportJobService())->create($owner, str_repeat('a', 32).'.xlsx');
$db->close();
echo json_encode(['foreignJobId' => $foreign], JSON_THROW_ON_ERROR);
