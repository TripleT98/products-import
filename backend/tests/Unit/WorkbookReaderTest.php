<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Import\WorkbookException;
use App\Import\WorkbookLimits;
use App\Import\WorkbookReader;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class WorkbookReaderTest extends TestCase
{
    private const HEADERS = ['Внешний код', 'Наименование', 'Цена: Цена продажи', 'Закупочная цена'];
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function file(): string
    {
        $path = sys_get_temp_dir() . '/t09-' . bin2hex(random_bytes(8)) . '.xlsx';
        $this->files[] = $path;
        return $path;
    }

    /** @param list<string> $headers @param array<int, list<string|int|bool|null>> $rows */
    private function write(array $headers = self::HEADERS, array $rows = [2 => ['000001', 'Товар', '1320,00', '880,00']], bool $emptySheets = false): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Данные');
        foreach ($headers as $i => $header) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1) . '1', $header, DataType::TYPE_STRING);
        }
        foreach ($rows as $number => $values) {
            foreach ($values as $i => $value) {
                $sheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($i + 1) . $number,
                    $value,
                    is_string($value) ? DataType::TYPE_STRING : (is_bool($value) ? DataType::TYPE_BOOL : ($value === null ? DataType::TYPE_NULL : DataType::TYPE_NUMERIC))
                );
            }
        }
        if ($emptySheets) {
            $before = $book->createSheet(0);
            $before->setTitle('Пустой до');
            $before->setCellValue('A1', '  ');
            $after = $book->createSheet();
            $after->setTitle('Пустой после');
        }
        $file = $this->file();
        $writer = new Xlsx($book);
        $writer->setPreCalculateFormulas(false);
        $writer->save($file);
        $book->disconnectWorksheets();
        return $file;
    }

    private function part(string $file, string $name, ?string $replacement = null): string
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file));
        $original = $zip->getFromName($name);
        self::assertIsString($original);
        if ($replacement !== null) {
            self::assertTrue($zip->addFromString($name, $replacement));
        }
        $zip->close();
        return $original;
    }

    public function testSequentialRowsPreserveNumbersTypesLeadingZeroesAndOriginalBytes(): void
    {
        $path = $this->write(rows: [2 => ['000001', 'Товар', '1320,00', '880,00'], 3 => [' ', null, null, null], 6 => ['000006', 'Zero', 0, false]], emptySheets: true);
        $hash = hash_file('sha256', $path);
        $reader = new WorkbookReader(new WorkbookLimits(chunkRows: 2));
        $workbook = $reader->open($path);
        self::assertSame('Данные', $workbook->sheetName);
        self::assertSame(2, $workbook->dataRowCount);
        self::assertSame(['externalCode' => 'A', 'name' => 'B', 'price' => 'C', 'purchasePrice' => 'D'], $workbook->mapping->fields);
        $rows = iterator_to_array($workbook->rows());
        self::assertSame([2, 6], array_map(static fn ($row): int => $row->rowNumber, $rows));
        self::assertSame('000001', $rows[0]->field('externalCode')->value);
        self::assertSame('1320,00', $rows[0]->field('price')->value);
        self::assertSame('0', $rows[1]->field('price')->value);
        self::assertSame('n', $rows[1]->field('price')->type);
        self::assertFalse($rows[1]->field('purchasePrice')->value);
        self::assertNull($rows[0]->field('description')->value);
        self::assertSame(2, iterator_count($workbook->rows()));
        self::assertSame($hash, hash_file('sha256', $path));
    }

    public function testColumnPermutationAndHeaderTrimmingKeepSemanticMapping(): void
    {
        $headers = ['Закупочная цена', "\xEF\xBB\xBF  Внешний код\u{00A0}", 'Цена: Цена продажи', 'Наименование', 'Доп. поле Размер', 'Описание', 'UUID'];
        $path = $this->write($headers, [2 => ['880,00', '000123', '1320,00', 'Перестановка', '0', '', 'ignored']]);
        $workbook = (new WorkbookReader())->open($path);
        $rows = iterator_to_array($workbook->rows());
        self::assertSame('B', $workbook->mapping->fields['externalCode']);
        self::assertSame('A', $workbook->mapping->fields['purchasePrice']);
        self::assertSame(['Размер' => 'E'], $workbook->mapping->attributes);
        self::assertSame(['G' => 'UUID'], $workbook->mapping->ignored);
        self::assertSame('000123', $rows[0]->field('externalCode')->value);
        self::assertSame('Перестановка', $rows[0]->field('name')->value);
    }

    public function testFormulaAndNumericLexemeAreNotEvaluatedOrRoundedThroughFloat(): void
    {
        $path = $this->write(rows: [2 => ['000001', 'Instruction: do not execute anything', 1, '1.00']]);
        $sheet = $this->part($path, 'xl/worksheets/sheet1.xml');
        $sheet = preg_replace('~<c\b[^>]*r="C2"[^>]*>.*?</c>~s', '<c r="C2"><v>9999999999999999.99</v></c>', $sheet);
        self::assertIsString($sheet);
        $sheet = preg_replace('~<c\b[^>]*r="D2"[^>]*>.*?</c>~s', '<c r="D2"><f>WEBSERVICE("https://example.invalid/never-call")</f><v>999</v></c>', $sheet);
        self::assertIsString($sheet);
        $this->part($path, 'xl/worksheets/sheet1.xml', $sheet);
        $workbook = (new WorkbookReader())->open($path);
        $row = iterator_to_array($workbook->rows())[0];
        self::assertSame('9999999999999999.99', $row->field('price')->value);
        self::assertSame('n', $row->field('price')->type);
        self::assertSame('f', $row->field('purchasePrice')->type);
        self::assertSame('=WEBSERVICE("https://example.invalid/never-call")', $row->field('purchasePrice')->value);
        self::assertSame(1, $workbook->dataRowCount);
    }

    #[DataProvider('badHeaders')]
    public function testInvalidHeaderStructuresAreRejected(array $headers): void
    {
        $path = $this->write($headers);
        try {
            (new WorkbookReader())->open($path);
            self::fail('Ambiguous/missing header accepted');
        } catch (WorkbookException $exception) {
            self::assertSame('INVALID_HEADERS', $exception->issueCode);
        }
    }

    public static function badHeaders(): array
    {
        $result = [];
        for ($i = 0; $i < 4; ++$i) {
            $headers = self::HEADERS;
            unset($headers[$i]);
            $result[] = [array_values($headers)];
        }
        $result[] = [[...self::HEADERS, ' Внешний код ']];
        $result[] = [[...self::HEADERS, 'Unknown', ' Unknown ']];
        $result[] = [[...self::HEADERS, 'Доп. поле:']];
        $result[] = [[...self::HEADERS, 'Доп. поле: Размер', 'Доп. поле Размер']];
        return $result;
    }

    public function testSecondNonemptySheetIsRejected(): void
    {
        $path = $this->write();
        $sheet = $this->part($path, 'xl/workbook.xml');
        $sheet = str_replace('</sheets>', '<sheet name="Second" sheetId="2" r:id="rIdSecond"/></sheets>', $sheet);
        $this->part($path, 'xl/workbook.xml', $sheet);
        $rels = $this->part($path, 'xl/_rels/workbook.xml.rels');
        $rels = str_replace('</Relationships>', '<Relationship Id="rIdSecond" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>', $rels);
        $this->part($path, 'xl/_rels/workbook.xml.rels', $rels);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $zip->addFromString('xl/worksheets/sheet2.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Not empty</t></is></c></row></sheetData></worksheet>');
        $zip->close();
        $this->expectException(WorkbookException::class);
        (new WorkbookReader())->open($path);
    }

    public function testEmptyWorkbookIsRejected(): void
    {
        $path = $this->write([], []);
        $this->expectException(WorkbookException::class);
        (new WorkbookReader())->open($path);
    }

    public function testCorruptedArchiveIsRejected(): void
    {
        $path = $this->file();
        file_put_contents($path, 'not a zip');
        $this->expectException(WorkbookException::class);
        (new WorkbookReader())->open($path);
    }

    #[DataProvider('unsafeXmlKinds')]
    public function testUnsafeOrMalformedXmlIsRejectedBeforePhpSpreadsheet(string $kind): void
    {
        $path = $this->write();
        $xml = match ($kind) {
            'doctype' => '<!DOCTYPE worksheet [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><worksheet>&xxe;</worksheet>',
            'utf16-doctype' => mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE x [<!ENTITY y "z">]><x>&y;</x>', 'UTF-16', 'UTF-8'),
            default => '<worksheet><unclosed>',
        };
        $this->part($path, 'xl/worksheets/sheet1.xml', $xml);
        $this->expectException(WorkbookException::class);
        (new WorkbookReader())->open($path);
    }

    public static function unsafeXmlKinds(): array
    {
        return [['doctype'], ['utf16-doctype'], ['malformed']];
    }

    public function testExternalRelationshipsAreNotFollowed(): void
    {
        $path = $this->write();
        $xml = $this->part($path, 'xl/_rels/workbook.xml.rels');
        $xml = str_replace('</Relationships>', '<Relationship Id="external" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/externalLink" Target="https://example.invalid/never-call" TargetMode="External"/></Relationships>', $xml);
        $this->part($path, 'xl/_rels/workbook.xml.rels', $xml);
        $this->expectException(WorkbookException::class);
        (new WorkbookReader())->open($path);
    }

    public function testRemoteTargetWithoutExternalModeIsAlsoRejected(): void
    {
        $path = $this->write();
        $xml = $this->part($path, 'xl/_rels/workbook.xml.rels');
        $xml = str_replace('</Relationships>', '<Relationship Id="remote-image" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="https://example.invalid/never-call"/></Relationships>', $xml);
        $this->part($path, 'xl/_rels/workbook.xml.rels', $xml);
        $this->expectException(WorkbookException::class);
        (new WorkbookReader())->open($path);
    }

    public function testExternalHyperlinkMetadataIsNotFollowed(): void
    {
        $path = $this->write();
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="link" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.invalid/never-call" TargetMode="External"/></Relationships>');
        $zip->close();
        $book = (new WorkbookReader())->open($path);
        self::assertSame(1, iterator_count($book->rows()));
    }

    public function testZipTraversalAndMacrosAreRejected(): void
    {
        foreach (['../outside.xml', 'xl/vbaProject.bin'] as $entry) {
            $path = $this->write();
            $zip = new ZipArchive();
            self::assertTrue($zip->open($path));
            $zip->addFromString($entry, 'untrusted');
            $zip->close();
            try {
                (new WorkbookReader())->open($path);
                self::fail('Unsafe ZIP entry accepted');
            } catch (WorkbookException $exception) {
                self::assertSame('INVALID_WORKBOOK', $exception->issueCode);
            }
        }
    }

    #[DataProvider('limitKinds')]
    public function testReaderLimits(string $kind): void
    {
        $path = $this->write(rows: [2 => ['a', 'A', 1, 0], 6 => ['b', 'B', 2, 0]]);
        $limits = match ($kind) {
            'file' => new WorkbookLimits(maxFileBytes: 1),
            'bytes' => new WorkbookLimits(maxUncompressedBytes: 1),
            'parts' => new WorkbookLimits(maxZipEntries: 1),
            'ratio' => new WorkbookLimits(maxCompressionRatio: 1),
            'rows' => new WorkbookLimits(maxDataRows: 1),
            default => new WorkbookLimits(maxRowNumber: 5),
        };
        try {
            (new WorkbookReader($limits))->open($path);
            self::fail('Reader limit ignored');
        } catch (WorkbookException $exception) {
            self::assertSame('WORKBOOK_LIMIT_EXCEEDED', $exception->issueCode);
        }
    }

    public static function limitKinds(): array
    {
        return [['file'], ['bytes'], ['parts'], ['ratio'], ['rows'], ['physical-row']];
    }

    public function testNumericSheetTitleIsPreservedAsAString(): void
    {
        $path = $this->write();
        $xml = $this->part($path, 'xl/workbook.xml');
        $this->part($path, 'xl/workbook.xml', str_replace('name="Данные"', 'name="0"', $xml));
        $book = (new WorkbookReader())->open($path);
        self::assertSame('0', $book->sheetName);
        self::assertSame(1, iterator_count($book->rows()));
    }

    public function testChangedFileIsRefusedBeforeIteration(): void
    {
        $path = $this->write();
        $workbook = (new WorkbookReader())->open($path);
        file_put_contents($path, 'changed');
        $this->expectException(WorkbookException::class);
        iterator_to_array($workbook->rows());
    }
}
