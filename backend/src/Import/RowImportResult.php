<?php

declare(strict_types=1);

namespace App\Import;

final readonly class RowImportResult
{
    /** @param list<RowIssue> $issues @param list<string> $cleanupPendingPaths */
    public function __construct(public ?ProductWriteResult $write, public array $issues, public array $cleanupPendingPaths = [])
    {
    }
}
