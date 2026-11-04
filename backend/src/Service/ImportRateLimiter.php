<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ImportRateLimit;
use App\Http\ApiException;
use App\Infrastructure\DoctrineFactory;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final readonly class ImportRateLimiter
{
    /** @param null|Closure(): EntityManagerInterface $sessions */
    public function __construct(private ?Closure $sessions = null, private int $maximum = 5, private int $seconds = 60)
    {
        if ($maximum < 1 || $maximum > 10000 || $seconds < 1 || $seconds > 3600) {
            throw new InvalidArgumentException('Invalid upload rate configuration.');
        }
    }

    public static function fromEnvironment(): self
    {
        $max = getenv('IMPORT_RATE_LIMIT') ?: '5';
        $seconds = getenv('IMPORT_RATE_WINDOW_SECONDS') ?: '60';
        if (!ctype_digit($max) || !ctype_digit($seconds)) {
            throw new InvalidArgumentException('Invalid upload rate configuration.');
        }
        return new self(maximum: (int) $max, seconds: (int) $seconds);
    }

    public function consume(int $ownerId): void
    {
        $em = $this->sessions === null ? DoctrineFactory::entityManager() : ($this->sessions)();
        try {
            $result = $em->wrapInTransaction(function (EntityManagerInterface $em) use ($ownerId): array {
                $em->getConnection()->executeStatement("SET LOCAL lock_timeout = '2s'");
                $em->getConnection()->executeStatement("SET LOCAL statement_timeout = '3s'");
                return $em->getRepository(ImportRateLimit::class)->consume($ownerId, $this->maximum, $this->seconds);
            });
        } finally {
            $em->close();
            $em->getConnection()->close();
        }
        if (!$result['allowed']) {
            throw new ApiException(429, 'IMPORT_RATE_LIMITED', 'Слишком много загрузок. Повторите позже.', $result['retryAfter']);
        }
    }
}
