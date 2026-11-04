<?php

declare(strict_types=1);

namespace App\Import;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

final readonly class ChunkFilter implements IReadFilter
{
    public function __construct(private int $first, private int $last)
    {
    }

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row >= $this->first && $row <= $this->last;
    }
}
