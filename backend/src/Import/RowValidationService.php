<?php

declare(strict_types=1);

namespace App\Import;

use Generator;

final class RowValidationService
{
    /** @param iterable<WorkbookRow> $rows @return Generator<int, RowResult> */
    public function validate(iterable $rows): Generator
    {
        $seen = [];
        $validator = new RowValidator();
        foreach ($rows as $row) {
            try {
                $code = CellNormalizer::code($row->field('externalCode'));
            } catch (RowValueException) {
                $code = null;
            }
            if ($code !== null && isset($seen['code:' . $code])) {
                yield new RowResult($row->rowNumber, null, [new RowIssue('error', 'DUPLICATE_EXTERNAL_CODE', 'Повтор внешнего кода после первой валидной строки.', $row->rowNumber, $code, 'externalCode')]);
                continue;
            }
            $result = $validator->validate($row);
            if ($result === null) {
                continue;
            }
            if ($result->dto !== null) {
                // Reserve only after full row validation, including attributes; warnings do not invalidate it.
                $seen['code:' . $result->dto->externalCode] = true;
            }
            yield $result;
        }
    }
}
