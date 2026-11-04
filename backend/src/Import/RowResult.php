<?php

declare(strict_types=1);

namespace App\Import;

final readonly class RowResult
{
    /** @param list<RowIssue> $issues */
    public function __construct(public int $rowNumber, public ?RowDto $dto, public array $issues)
    {
    }
}
