<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require dirname(__DIR__).'/bootstrap.php';
$book = new Spreadsheet();
$sheet = $book->getActiveSheet();
$sheet->fromArray(['Наименование', 'Внешний код', 'Описание', 'Цена: Цена продажи', 'Закупочная цена', 'Доп. поле: Размер', 'Доп. поле: Цвет', 'Доп. поле: Ссылки на фото']);
for ($i = 1; $i <= 25; ++$i) {
    $suffix = str_pad((string)$i, 4, '0', STR_PAD_LEFT);
    $sheet->fromArray(['E2E товар '.$suffix, 'E2E-'.$suffix, 'Первый импорт', (string)(100 + $i), '75', '0', 'Синий', $i <= 10 ? 'http://fixture.test:18080/slow' : ''], null, 'A'.($i + 1));
}
(new Xlsx($book))->save('/tmp/e2e-initial.xlsx');
$book->disconnectWorksheets();
$update = new Spreadsheet();
$update->getActiveSheet()->fromArray([
    ['Наименование', 'Внешний код', 'Описание', 'Цена: Цена продажи', 'Закупочная цена', 'Доп. поле: Размер', 'Доп. поле: Новый', 'Доп. поле: Ссылки на фото'],
    ['E2E обновлён', 'E2E-0001', 'Данные после обновления', '200', '150', 'L', 'Да', 'http://fixture.test:18080/jpeg,http://fixture.test:18080/404'],
    ['E2E новый', 'E2E-0026', 'Новая строка', '300', '225', 'M', 'Да', ''],
    ['E2E неверный', 'E2E-0027', 'Не должен сохраниться', '0', '10', 'S', 'Нет', ''],
]);
(new Xlsx($update))->save('/tmp/e2e-update.xlsx');
$update->disconnectWorksheets();
echo '{"workbooks":2,"initialRows":25,"updateRows":3}';
