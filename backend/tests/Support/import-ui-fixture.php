<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require dirname(__DIR__).'/bootstrap.php';
$book = new Spreadsheet();
$sheet = $book->getActiveSheet();
$sheet->fromArray(['Наименование', 'Внешний код', 'Цена: Цена продажи', 'Закупочная цена', 'Доп. поле: Размер', 'Доп. поле: Ссылки на фото']);
for ($i = 0; $i < 12; ++$i) {
    $urls = 'http://fixture.test:18080/slow';
    if ($i === 2) {
        $urls .= ',http://fixture.test:18080/404';
    }
    $sheet->fromArray(['UI row '.$i, str_pad((string)($i + 1), 6, '0', STR_PAD_LEFT), $i === 1 ? '0' : '100', '75', '0', $urls], null, 'A'.($i + 2));
}
(new Xlsx($book))->save('/tmp/import-ui.xlsx');
$book->disconnectWorksheets();
$bad = new Spreadsheet();
$bad->getActiveSheet()->fromArray([['Unknown header'], ['value']]);
(new Xlsx($bad))->save('/tmp/import-ui-fatal.xlsx');
$bad->disconnectWorksheets();
echo '{"prepared":true}';
