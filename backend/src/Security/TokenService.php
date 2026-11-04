<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\ApiException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

final class TokenService
{
    public function __construct(private ?JwtConfig $configuration = null)
    {
    }

    private function configuration(): JwtConfig
    {
        return $this->configuration ??= JwtConfig::fromEnvironment();
    }

    /** @return array{accessToken: string, tokenType: string, expiresIn: int} */
    public function issue(int $userId): array
    {
        $config = $this->configuration();
        $now = time();
        return [
            'accessToken' => JWT::encode(['sub' => (string) $userId, 'iat' => $now, 'exp' => $now + $config->ttl, 'iss' => $config->issuer, 'aud' => $config->audience], $config->secret, 'HS256'),
            'tokenType' => 'Bearer',
            'expiresIn' => $config->ttl,
        ];
    }

    public function subject(string $token): int
    {
        $config = $this->configuration();
        try {
            $claims = JWT::decode($token, new Key($config->secret, 'HS256'));
            $now = time();
            if (!isset($claims->sub, $claims->iat, $claims->exp, $claims->iss, $claims->aud)
                || !is_string($claims->sub) || !preg_match('/^[1-9][0-9]{0,9}$/D', $claims->sub)
                || (int) $claims->sub > 2147483647
                || !is_int($claims->iat) || !is_int($claims->exp)
                || $claims->iat > $now || $claims->exp <= $now || $claims->exp <= $claims->iat
                || $claims->exp - $claims->iat > $config->ttl
                || $claims->iss !== $config->issuer || $claims->aud !== $config->audience) {
                throw ApiException::unauthorized();
            }
            return (int) $claims->sub;
        } catch (Throwable) {
            // Decoder messages may contain token fragments; never propagate or log them.
            throw ApiException::unauthorized();
        }
    }
}
