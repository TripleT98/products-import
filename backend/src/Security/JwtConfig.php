<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final readonly class JwtConfig
{
    public function __construct(public string $secret, public string $issuer, public string $audience, public int $ttl)
    {
        if (strlen($secret) < 32 || $issuer === '' || $audience === '' || $ttl < 1 || $ttl > 86400) {
            throw new RuntimeException('Invalid JWT configuration.');
        }
    }

    public static function fromEnvironment(): self
    {
        $ttl = getenv('JWT_TTL_SECONDS');
        if ($ttl !== false && !ctype_digit($ttl)) {
            throw new RuntimeException('Invalid JWT configuration.');
        }
        return new self(
            getenv('JWT_SECRET') ?: '',
            getenv('JWT_ISSUER') ?: 'product-import',
            getenv('JWT_AUDIENCE') ?: 'product-import-web',
            $ttl === false ? 3600 : (int) $ttl,
        );
    }
}
