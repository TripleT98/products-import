<?php

declare(strict_types=1);

namespace App\Import;

use Closure;
use Generator;

final readonly class Workbook
{
    /** @param Closure(): Generator<int, WorkbookRow> $rows */
    public function __construct(public string $sheetName, public HeaderMap $mapping, public int $dataRowCount, public string $sha256, private Closure $rows)
    {
    }

    /** @return Generator<int, WorkbookRow> */
    public function rows(): Generator
    {
        return ($this->rows)();
    }
}
