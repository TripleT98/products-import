<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Import\RowValidationService;
use App\Import\WorkbookReader;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class RowWorkbookTest extends TestCase
{
    public function testReaderAndValidatorRoundTripPermutationFormattingAndContinuation(): void
    {
        $file = sys_get_temp_dir() . '/t10-' . bin2hex(random_bytes(8)) . '.xlsx';
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        // Price first and code third: no dependency on source column positions.
        $sheet->fromArray(['Цена: Цена продажи', 'Наименование', 'Внешний код', 'Закупочная цена', 'Доп. поле Ссылки на фото', 'Доп. поле: Ссылка на упаковку', 'Доп. поле Размер'], null, 'A1');
        foreach ([2 => [3, 1, 2], 3 => [0, '000001', 0], 5 => [0, '000002', 0], 7 => [3, '000002', 1], 9 => [3, '000003', 1], 12 => [3, '000003', 1]] as $number => [$sale, $code, $purchase]) {
            $sheet->setCellValueExplicit('A' . $number, $sale, DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit('B' . $number, 'Item', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('C' . $number, $code, is_int($code) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('D' . $number, $purchase, DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit('E' . $number, 'https://example.invalid/b.png,https://example.invalid/a.png', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('F' . $number, 'https://example.invalid/a.png', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('G' . $number, '0', DataType::TYPE_STRING);
        }
        $sheet->getStyle('C2')->getNumberFormat()->setFormatCode('000000');
        $sheet->setCellValueExplicit('G9', '=1+1', DataType::TYPE_FORMULA);
        try {
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($file);
            $book->disconnectWorksheets();
            $workbook = (new WorkbookReader())->open($file);
            $results = iterator_to_array((new RowValidationService())->validate($workbook->rows()));
            self::assertCount(6, $results);
            self::assertNotNull($results[0]->dto);
            self::assertSame('000001', $results[0]->dto->externalCode);
            self::assertSame('33.33', $results[0]->dto->discount);
            self::assertSame(['https://example.invalid/a.png', 'https://example.invalid/b.png'], $results[0]->dto->imageCandidates);
            self::assertSame(['key' => 'Размер', 'value' => '0'], $results[0]->dto->attributes[2]);
            self::assertSame('DUPLICATE_EXTERNAL_CODE', $results[1]->issues[0]->code);
            self::assertSame(3, $results[1]->issues[0]->rowNumber);
            self::assertSame('INVALID_PRICE', $results[2]->issues[0]->code);
            self::assertNotNull($results[3]->dto);
            self::assertSame('66.67', $results[3]->dto->discount);
            self::assertSame('FORMULA_NOT_ALLOWED', $results[4]->issues[0]->code);
            self::assertSame('attributes.Размер', $results[4]->issues[0]->field);
            self::assertNotNull($results[5]->dto);
            self::assertSame(12, $results[5]->dto->rowNumber);
        } finally {
            $book->disconnectWorksheets();
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
