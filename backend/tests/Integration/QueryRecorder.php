<?php

declare(strict_types=1);

namespace Tests\Integration;

use Psr\Log\AbstractLogger;
use Stringable;

final class QueryRecorder extends AbstractLogger
{
    /** @var list<string> */
    private array $sql = [];

    public function reset(): void
    {
        $this->sql = [];
    }

    /** @return list<string> */
    public function statements(): array
    {
        return $this->sql;
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql']) && is_string($context['sql'])) {
            $this->sql[] = $context['sql'];
        }
    }
}
