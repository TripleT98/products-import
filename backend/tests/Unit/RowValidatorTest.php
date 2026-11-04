<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Import\CellData;
use App\Import\HeaderMapper;
use App\Import\RowDto;
use App\Import\RowResult;
use App\Import\RowValidationService;
use App\Import\RowValidator;
use App\Import\ValidationSummary;
use App\Import\WorkbookException;
use App\Import\WorkbookRow;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RowValidatorTest extends TestCase
{
    /** @param array<string, CellData|string|bool|null> $values @param array<string, CellData|string|bool|null> $extra */
    private function row(array $values = [], array $extra = [], int $number = 2): WorkbookRow
    {
        $data = $values + ['externalCode' => '000001', 'name' => ' Товар ', 'price' => '1320,00', 'purchasePrice' => '880.00', 'description' => null];
        $titles = ['externalCode' => 'Внешний код', 'name' => 'Наименование', 'price' => 'Цена: Цена продажи', 'purchasePrice' => 'Закупочная цена', 'description' => 'Описание'];
        $headers = $cells = [];
        foreach ($titles as $field => $title) {
            $column = Coordinate::stringFromColumnIndex(count($headers) + 1);
            $headers[$column] = new CellData($title, 's');
            $value = $data[$field];
            $cells[$column] = $value instanceof CellData ? $value : new CellData($value, is_bool($value) ? 'b' : ($value === null ? 'null' : 's'));
        }
        foreach ($extra as $title => $value) {
            $column = Coordinate::stringFromColumnIndex(count($headers) + 1);
            $headers[$column] = new CellData($title, 's');
            $cells[$column] = $value instanceof CellData ? $value : new CellData($value, is_bool($value) ? 'b' : ($value === null ? 'null' : 's'));
        }
        return new WorkbookRow($number, $cells, (new HeaderMapper())->map($headers));
    }

    private function checkedResult(WorkbookRow $row): RowResult
    {
        $result = (new RowValidator())->validate($row);
        self::assertInstanceOf(RowResult::class, $result);
        return $result;
    }

    private function dto(WorkbookRow $row): RowDto
    {
        $result = $this->checkedResult($row);
        self::assertNotNull($result->dto, json_encode($result->issues, JSON_THROW_ON_ERROR));
        return $result->dto;
    }

    #[DataProvider('exactAmounts')]
    public function testExactFormulaStorageRoundingAndNoNegativeZero(string $sale, string $purchase, string $price, string $storedPurchase, string $discount): void
    {
        $dto = $this->dto($this->row(['price' => $sale, 'purchasePrice' => $purchase]));
        self::assertSame([$price, $storedPurchase, $discount], [$dto->price, $dto->purchasePrice, $dto->discount]);
    }

    public static function exactAmounts(): array
    {
        return [
            ['1320,00', '880,00', '1320.00', '880.00', '33.33'],
            ['3', '1', '3.00', '1.00', '66.67'], ['3', '4', '3.00', '4.00', '-33.33'],
            ['200', '180.01', '200.00', '180.01', '10.00'], ['200', '219.99', '200.00', '219.99', '-10.00'],
            ['1.005', '0.995', '1.01', '1.00', '1.00'],
            ['0.005', '0', '0.01', '0.00', '100.00'],
            ['100', '100.000001', '100.00', '100.00', '0.00'],
            ['100', '-0.000', '100.00', '0.00', '100.00'],
            ['9999999999999999.99', '0', '9999999999999999.99', '0.00', '100.00'],
            ['9999999999999999.994', '0', '9999999999999999.99', '0.00', '100.00'],
            ['0.01', '999999999999.99', '0.01', '999999999999.99', '-9999999999999800.00'],
        ];
    }

    #[DataProvider('localizedNumbers')]
    public function testLocalizedAmountsAndNumericScientificCells(CellData $cell, string $expected): void
    {
        $dto = $this->dto($this->row(['price' => $cell, 'purchasePrice' => '0']));
        self::assertSame($expected, $dto->price);
    }

    public static function localizedNumbers(): array
    {
        return [
            [new CellData('1 320,00', 's'), '1320.00'],
            [new CellData("  1\u{00A0}320.00\u{202F}", 's'), '1320.00'],
            [new CellData("1\u{202F}320,00", 's'), '1320.00'],
            [new CellData('1,320', 's'), '1.32'],
            [new CellData('1.320E+3', 'n'), '1320.00'],
            [new CellData('.5', 'n'), '0.50'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesProduceStructuredIssuesAndNoDto(array $values, string $code, string $field): void
    {
        $result = $this->checkedResult($this->row($values, number: 17));
        self::assertNull($result->dto);
        self::assertCount(1, $result->issues);
        $issue = $result->issues[0];
        self::assertSame([$code, $field, 17, 'error'], [$issue->code, $issue->field, $issue->rowNumber, $issue->severity]);
        self::assertSame(['severity', 'code', 'message', 'rowNumber', 'externalCode', 'field'], array_keys($issue->jsonSerialize()));
        self::assertNotSame('', $issue->message);
    }

    public static function invalidValues(): array
    {
        $result = [
            [['externalCode' => ' '], 'MISSING_REQUIRED_VALUE', 'externalCode'],
            [['name' => "\u{00A0}"], 'MISSING_REQUIRED_VALUE', 'name'],
            [['price' => ''], 'MISSING_REQUIRED_VALUE', 'price'],
            [['purchasePrice' => null], 'MISSING_REQUIRED_VALUE', 'purchasePrice'],
            [['price' => '0'], 'INVALID_PRICE', 'price'], [['price' => '0.004'], 'INVALID_PRICE', 'price'],
            [['price' => '-0.01'], 'INVALID_PRICE', 'price'],
            [['purchasePrice' => '-0.004'], 'INVALID_PURCHASE_PRICE', 'purchasePrice'],
            [['price' => '9999999999999999.995'], 'DECIMAL_OUT_OF_RANGE', 'price'],
            [['purchasePrice' => '10000000000000000'], 'DECIMAL_OUT_OF_RANGE', 'purchasePrice'],
            [['price' => '0.01', 'purchasePrice' => '1000000000000.01'], 'DECIMAL_OUT_OF_RANGE', 'discount'],
            [['price' => new CellData('1E+999999999', 'n')], 'DECIMAL_OUT_OF_RANGE', 'price'],
            [['name' => true], 'INVALID_NAME', 'name'], [['price' => false], 'INVALID_PRICE', 'price'],
            [['description' => new CellData('#VALUE!', 'e')], 'INVALID_DESCRIPTION', 'description'],
            [['description' => "bad\0text"], 'INVALID_DESCRIPTION', 'description'],
            [['externalCode' => new CellData('1.5', 'n')], 'INVALID_EXTERNAL_CODE', 'externalCode'],
            [['externalCode' => new CellData('123', 'n', '000-000')], 'INVALID_EXTERNAL_CODE', 'externalCode'],
            [['externalCode' => str_repeat('Ж', 256)], 'VALUE_TOO_LONG', 'externalCode'],
            [['externalCode' => new CellData('1', 'n', str_repeat('0', 256))], 'VALUE_TOO_LONG', 'externalCode'],
        ];
        foreach (['1 23,00', '1.320,00', '1,320.00', '100 ₽', '+1', '1e3', 'NaN', 'INF', '.5', '1.', '1  000', '1\t000'] as $invalid) {
            $result[] = [['price' => $invalid], 'INVALID_PRICE', 'price'];
        }
        return $result;
    }

    #[DataProvider('formulaFields')]
    public function testFormulasInAllImportedFieldsAreErrorsWithoutCachedResult(string $field): void
    {
        $formula = new CellData('=WEBSERVICE("https://example.invalid/never-call")', 'f');
        $row = str_starts_with($field, 'attributes.')
            ? $this->row([], ['Доп. поле: ' . substr($field, 11) => $formula], 9)
            : $this->row([$field => $formula], number: 9);
        $result = $this->checkedResult($row);
        self::assertNull($result->dto);
        self::assertSame('FORMULA_NOT_ALLOWED', $result->issues[0]->code);
        self::assertSame($field, $result->issues[0]->field);
        self::assertSame($field === 'externalCode' ? null : '000001', $result->issues[0]->externalCode);
        self::assertStringNotContainsString('WEBSERVICE', $result->issues[0]->message);
    }

    public static function formulaFields(): array
    {
        return array_map(static fn (string $field): array => [$field], ['externalCode', 'name', 'description', 'price', 'purchasePrice', 'attributes.Размер', 'attributes.Ссылка на упаковку']);
    }

    public function testIdentifiersRespectTextZeroPaddingNumericFormatsAndCodeLimit(): void
    {
        self::assertSame('000001', $this->dto($this->row(['externalCode' => ' 000001 ']))->externalCode);
        self::assertSame('000123', $this->dto($this->row(['externalCode' => new CellData('123', 'n', '000000')]))->externalCode);
        self::assertSame('1000', $this->dto($this->row(['externalCode' => new CellData('1E+3', 'n')]))->externalCode);
        self::assertSame('000000', $this->dto($this->row(['externalCode' => new CellData('0', 'n', '000000')]))->externalCode);
        self::assertSame(str_repeat('Ж', 255), $this->dto($this->row(['externalCode' => str_repeat('Ж', 255)]))->externalCode);
    }

    public function testAttributesKeepZeroTrimDynamicKeysAndIgnoreUnmappedFormula(): void
    {
        $result = $this->checkedResult($this->row([], [
            'Доп. поле Размер' => ' 0 ', 'Доп. поле: Новый ключ' => ' значение ', 'Доп. поле: Пусто' => ' ',
            'Доп. поле: Флаг' => false, 'Доп. поле 0' => 'numeric-key', 'UUID' => new CellData('=1+1', 'f'),
        ]));
        self::assertNotNull($result->dto);
        self::assertSame([], $result->issues);
        self::assertSame([
            ['key' => 'Размер', 'value' => '0'], ['key' => 'Новый ключ', 'value' => 'значение'],
            ['key' => 'Флаг', 'value' => 'FALSE'], ['key' => '0', 'value' => 'numeric-key'],
        ], $result->dto->attributes);
        self::assertSame('', $result->dto->description);
        self::assertSame('Товар', $result->dto->name);
    }

    public function testAttributeErrorCellIsNotSavedAsText(): void
    {
        $result = $this->checkedResult($this->row([], ['Доп. поле: Ошибка' => new CellData('#N/A', 'e')]));
        self::assertNull($result->dto);
        self::assertSame(['INVALID_ATTRIBUTE_VALUE', 'attributes.Ошибка'], [$result->issues[0]->code, $result->issues[0]->field]);
    }

    public function testOversizedAttributeKeyIsAStructureError(): void
    {
        $this->expectException(WorkbookException::class);
        $this->row([], ['Доп. поле: ' . str_repeat('Ж', 256) => 'x']);
    }

    public function testImageOrderDedupWarningsAndOriginalSourceAttributes(): void
    {
        $photo = ' https://example.invalid/b.png, https://example.invalid/a.png, bad-url, bad-url, ftp://example.invalid/x ';
        $row = $this->row([], ['Доп. поле Ссылки на фото' => $photo, 'Доп. поле: Ссылка на упаковку' => ' https://example.invalid/a.png ']);
        $result = $this->checkedResult($row);
        self::assertNotNull($result->dto);
        self::assertSame(['https://example.invalid/a.png', 'https://example.invalid/b.png'], $result->dto->imageCandidates);
        self::assertSame(trim($photo), $result->dto->attributes[0]['value']);
        self::assertCount(2, $result->issues);
        foreach ($result->issues as $issue) {
            self::assertSame(['warning', 'IMAGE_URL_REJECTED', 2, '000001', 'images'], [$issue->severity, $issue->code, $issue->rowNumber, $issue->externalCode, $issue->field]);
        }
    }

    public function testImageLengthBoundAndNoNetworkDestinationChecksYet(): void
    {
        $prefix = 'https://example.invalid/';
        $url = $prefix . str_repeat('a', 2048 - strlen($prefix));
        $result = $this->checkedResult($this->row([], ['Доп. поле: Ссылки на фото' => $url . ',' . $url . 'a,http://127.0.0.1/a.png']));
        self::assertNotNull($result->dto);
        self::assertSame([$url, 'http://127.0.0.1/a.png'], $result->dto->imageCandidates);
        self::assertCount(1, $result->issues);
    }

    public function testFirstFullyValidRowReservesCodeAndStreamContinuesAndResetsPerFile(): void
    {
        $rows = [
            $this->row(['price' => '0'], number: 2),
            $this->row([], ['Доп. поле: Ошибка' => new CellData('=1+1', 'f')], 3),
            $this->row([], ['Доп. поле: Ссылки на фото' => 'bad-url'], 4),
            $this->row(['price' => '0'], number: 5),
            $this->row(['externalCode' => '000002'], number: 8),
        ];
        $service = new RowValidationService();
        $results = iterator_to_array($service->validate($rows));
        self::assertSame([2, 3, 4, 5, 8], array_map(static fn (RowResult $r): int => $r->rowNumber, $results));
        self::assertSame('INVALID_PRICE', $results[0]->issues[0]->code);
        self::assertSame('FORMULA_NOT_ALLOWED', $results[1]->issues[0]->code);
        self::assertNotNull($results[2]->dto);
        self::assertSame('warning', $results[2]->issues[0]->severity);
        self::assertNull($results[3]->dto);
        self::assertSame('DUPLICATE_EXTERNAL_CODE', $results[3]->issues[0]->code);
        self::assertNotNull($results[4]->dto);
        self::assertNotNull(iterator_to_array($service->validate([$rows[2]]))[0]->dto);
        $summary = new ValidationSummary();
        $summary->consume($results);
        $data = json_decode(json_encode($summary, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([2, 3, 1], [$data['validRows'], $data['invalidRows'], $data['warnings']]);
    }

    public function testFullyEmptyRowsAreSkippedButZeroIsNotEmpty(): void
    {
        $row = $this->row(['externalCode' => ' ', 'name' => ' ', 'description' => null, 'price' => null, 'purchasePrice' => null]);
        self::assertNull((new RowValidator())->validate($row));
        self::assertSame([], iterator_to_array((new RowValidationService())->validate([$row])));
        self::assertSame('0', $this->dto($this->row(['externalCode' => '0']))->externalCode);
    }
}
