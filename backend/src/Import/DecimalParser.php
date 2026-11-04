<?php

declare(strict_types=1);

namespace App\Import;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\NumberSyntax;

final class DecimalParser
{
    // Resource bound on normalized decimal precision, far above XLSX monetary precision.
    public const MAX_DIGITS = 4096;

    public function parse(CellData $cell, string $field): BigDecimal
    {
        $text = CellNormalizer::text($cell, $field);
        if ($text === '') {
            throw new RowValueException('MISSING_REQUIRED_VALUE', $field, 'Обязательное значение отсутствует.');
        }
        if ($cell->type === 'n') {
            $valid = preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $text);
            $syntax = NumberSyntax::SCIENTIFIC;
        } else {
            $valid = preg_match('/^-?(?:[0-9]+|[0-9]{1,3}(?:[ \x{00A0}\x{202F}][0-9]{3})+)(?:[.,][0-9]+)?$/uD', $text);
            $text = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], $text);
            $syntax = NumberSyntax::DECIMAL;
        }
        if ($valid !== 1) {
            throw new RowValueException($field === 'purchasePrice' ? 'INVALID_PURCHASE_PRICE' : 'INVALID_PRICE', $field, 'Некорректное десятичное число.');
        }
        try {
            return BigDecimal::parse($text, $syntax, self::MAX_DIGITS)->strippedOfTrailingZeros();
        } catch (NumberFormatException) {
            throw new RowValueException('DECIMAL_OUT_OF_RANGE', $field, 'Слишком большое десятичное представление.');
        }
    }
}
