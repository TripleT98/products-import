<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }

    public static function unauthorized(): self
    {
        return new self(401, 'UNAUTHORIZED', 'Требуется вход.');
    }
}
