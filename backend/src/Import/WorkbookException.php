<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;
use Throwable;

final class WorkbookException extends RuntimeException
{
    public function __construct(public readonly string $issueCode, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function invalid(?Throwable $previous = null): self
    {
        return new self('INVALID_WORKBOOK', 'Некорректная или неподдерживаемая книга XLSX.', $previous);
    }

    public static function limit(): self
    {
        return new self('WORKBOOK_LIMIT_EXCEEDED', 'Превышен лимит чтения XLSX.');
    }
}
