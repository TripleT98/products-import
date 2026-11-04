<?php

declare(strict_types=1);

namespace App\Queue;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final readonly class QueueServices implements ContainerInterface
{
    /** @param array<string, object> $services */
    public function __construct(private array $services)
    {
    }

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new class ('Unknown queue service.') extends RuntimeException implements NotFoundExceptionInterface {};
        }
        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
