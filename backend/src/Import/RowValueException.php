<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;

final class RowValueException extends RuntimeException
{
    public function __construct(public readonly string $issueCode, public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
