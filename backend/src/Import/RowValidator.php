<?php

declare(strict_types=1);

namespace App\Import;

final class RowValidator
{
    public function validate(WorkbookRow $row): ?RowResult
    {
        $nonempty = false;
        foreach ($row->cells as $cell) {
            if (!$cell->isEmpty()) {
                $nonempty = true;
                break;
            }
        }
        if (!$nonempty) {
            return null;
        }
        $code = null;
        try {
            $code = CellNormalizer::code($row->field('externalCode'));
            $name = CellNormalizer::text($row->field('name'), 'name');
            if ($name === '') {
                throw new RowValueException('MISSING_REQUIRED_VALUE', 'name', 'Обязательное значение отсутствует.');
            }
            $description = CellNormalizer::text($row->field('description'), 'description');
            $parser = new DecimalParser();
            $sale = $parser->parse($row->field('price'), 'price');
            $purchase = $parser->parse($row->field('purchasePrice'), 'purchasePrice');
            $amounts = (new DecimalCalculator())->calculate($sale, $purchase);
            $attributes = (new AttributeExtractor())->extract($row);
            $images = (new ImageCandidateExtractor())->extract($attributes, $row->rowNumber, $code);
            return new RowResult($row->rowNumber, new RowDto(
                $row->rowNumber,
                $code,
                $name,
                $description,
                (string) $sale,
                (string) $purchase,
                $amounts['price'],
                $amounts['purchasePrice'],
                $amounts['discount'],
                $attributes,
                $images['urls']
            ), $images['issues']);
        } catch (RowValueException $exception) {
            return new RowResult($row->rowNumber, null, [new RowIssue('error', $exception->issueCode, $exception->getMessage(), $row->rowNumber, $code, $exception->field)]);
        }
    }
}
