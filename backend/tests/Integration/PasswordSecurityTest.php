<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Security\JwtConfig;
use App\Security\PasswordHasher;
use App\Security\TokenService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PasswordSecurityTest extends TestCase
{
    public function testNativeHashingUsesSaltAndVerifiesWithoutTruncation(): void
    {
        $passwords = new PasswordHasher();
        $hash = $passwords->hash(str_repeat('a', 72));
        self::assertNotSame(str_repeat('a', 72), $hash);
        self::assertNotSame($hash, $passwords->hash(str_repeat('a', 72)));
        self::assertTrue($passwords->verify(str_repeat('a', 72), $hash));
        self::assertFalse($passwords->verify(str_repeat('a', 72) . 'extra', $hash));
        self::assertFalse($passwords->verify('wrong', $hash));
        self::assertFalse($passwords->verify("a\0b", $hash));
    }

    public function testHashingRefusesSilentBcryptTruncation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PasswordHasher())->hash(str_repeat('a', 73));
    }

    public function testDefaultEnvironmentConfigurationAndCustomTtl(): void
    {
        $config = JwtConfig::fromEnvironment();
        self::assertSame(3600, $config->ttl);
        self::assertSame('product-import', $config->issuer);
        self::assertSame('product-import-web', $config->audience);
        $service = new TokenService(new JwtConfig(str_repeat('test', 8), 'issuer', 'audience', 60));
        $token = $service->issue(7);
        self::assertSame(60, $token['expiresIn']);
        self::assertSame(7, $service->subject($token['accessToken']));
    }

    public function testMissingKeyFailsClosedWithoutLeakingConfiguration(): void
    {
        $original = getenv('JWT_SECRET');
        putenv('JWT_SECRET');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid JWT configuration.');
            (new TokenService())->issue(1);
        } finally {
            putenv($original === false ? 'JWT_SECRET' : 'JWT_SECRET=' . $original);
        }
    }

    public function testInvalidLifetimeFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        new JwtConfig(str_repeat('test', 8), 'issuer', 'audience', 0);
    }
}
