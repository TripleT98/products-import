<?php

declare(strict_types=1);

namespace App\Import;

use JsonSerializable;

/** Internal issue; the persistent/public issue ID is assigned in later storage tasks. */
final readonly class RowIssue implements JsonSerializable
{
    public function __construct(public string $severity, public string $code, public string $message, public int $rowNumber, public ?string $externalCode, public string $field)
    {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
