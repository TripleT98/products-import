<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;
use Throwable;

final class ProductWriteException extends RuntimeException
{
    public const ISSUE_CODE = 'PRODUCT_WRITE_FAILED';

    public function __construct(public readonly int $attempts, Throwable $previous)
    {
        parent::__construct('Не удалось атомарно записать товар.', 0, $previous);
    }
}
