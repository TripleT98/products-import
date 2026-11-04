<?php

declare(strict_types=1);

namespace App\Import;

use Brick\Math\Exception\MathException;

final class CellNormalizer
{
    public static function trim(string $value): string
    {
        return preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $value) ?? $value;
    }

    public static function text(CellData $cell, string $field, bool $attribute = false): string
    {
        if ($cell->type === 'f') {
            throw new RowValueException('FORMULA_NOT_ALLOWED', $field, 'Формула в импортируемом поле не допускается.');
        }
        if ($cell->type === 'e' || ($cell->type === 'b' && !$attribute) || (is_bool($cell->value) && !$attribute)) {
            throw new RowValueException(self::invalidCode($field, $attribute), $field, 'Недопустимый тип значения.');
        }
        $value = is_bool($cell->value) ? ($cell->value ? 'TRUE' : 'FALSE') : ($cell->value ?? '');
        if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new RowValueException(self::invalidCode($field, $attribute), $field, 'Недопустимое текстовое значение.');
        }
        return self::trim($value);
    }

    public static function code(CellData $cell): string
    {
        $value = self::text($cell, 'externalCode');
        if ($value === '') {
            throw new RowValueException('MISSING_REQUIRED_VALUE', 'externalCode', 'Обязательное значение отсутствует.');
        }
        if ($cell->type === 'n') {
            $format = $cell->numberFormat;
            if (strcasecmp($format, 'General') !== 0 && !preg_match('/^0+$/D', $format)) {
                throw new RowValueException('INVALID_EXTERNAL_CODE', 'externalCode', 'Неоднозначный числовой формат внешнего кода.');
            }
            try {
                $value = (string) (new DecimalParser())->parse($cell, 'externalCode')->toBigInteger();
            } catch (MathException | RowValueException) {
                throw new RowValueException('INVALID_EXTERNAL_CODE', 'externalCode', 'Внешний код числовой ячейки должен быть целым.');
            }
            if (strlen($format) > 255) {
                throw new RowValueException('VALUE_TOO_LONG', 'externalCode', 'Внешний код превышает 255 символов.');
            }
            if (preg_match('/^0+$/D', $format)) {
                $negative = str_starts_with($value, '-');
                $value = ($negative ? '-' : '') . str_pad(ltrim($value, '-'), strlen($format), '0', STR_PAD_LEFT);
            }
        }
        if (mb_strlen($value, 'UTF-8') > 255) {
            throw new RowValueException('VALUE_TOO_LONG', 'externalCode', 'Внешний код превышает 255 символов.');
        }
        return $value;
    }

    private static function invalidCode(string $field, bool $attribute): string
    {
        if ($attribute) {
            return 'INVALID_ATTRIBUTE_VALUE';
        }
        return match ($field) {
            'externalCode' => 'INVALID_EXTERNAL_CODE', 'name' => 'INVALID_NAME', 'description' => 'INVALID_DESCRIPTION',
            'price' => 'INVALID_PRICE', 'purchasePrice' => 'INVALID_PURCHASE_PRICE', default => 'INVALID_VALUE',
        };
    }
}
