<?php

declare(strict_types=1);

namespace App\Import;

final readonly class WorkbookRow
{
    /** @param array<string, CellData> $cells */
    public function __construct(public int $rowNumber, public array $cells, public HeaderMap $mapping)
    {
    }

    public function field(string $name): CellData
    {
        $column = $this->mapping->fields[$name] ?? null;
        return $column === null ? new CellData() : ($this->cells[$column] ?? new CellData());
    }
}
