<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Image\ImageDownloadException;
use App\Image\ImageLimits;
use App\Image\ImageUrlPolicy;
use App\Image\ImageWarningCode;
use App\Image\SystemDnsResolver;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageUrlPolicyTest extends TestCase
{
    public static function addresses(): iterable
    {
        foreach (['127.0.0.1', '127.1.2.3', '0.0.0.0', '10.2.3.4', '100.100.100.200', '169.254.169.254',
            '172.16.1.1', '172.31.255.255', '192.168.1.1', '192.0.0.1', '192.0.2.1', '198.18.0.1',
            '198.51.100.1', '203.0.113.1', '224.0.0.1', '255.255.255.255', '168.63.129.16',
            '::', '::1', '::ffff:127.0.0.1', '::ffff:8.8.8.8', 'fc00::1', 'fd00::1', 'fe80::1', 'ff02::1',
            '64:ff9b::a00:1', '2001::1', '2001:db8::1', '2002:7f00:1::', '3fff::1', 'not-an-ip'] as $ip) {
            yield $ip => [$ip, false];
        }
        foreach (['8.8.8.8', '1.1.1.1', '93.184.216.34', '2606:4700:4700::1111', '2001:4860:4860::8888'] as $ip) {
            yield $ip => [$ip, true];
        }
    }

    #[DataProvider('addresses')]
    public function testPublicAddressClassification(string $ip, bool $allowed): void
    {
        self::assertSame($allowed, ImageUrlPolicy::isPublicAddress($ip));
    }

    public static function invalidUrls(): iterable
    {
        foreach (['file:///etc/passwd', 'ftp://example.com/a', 'gopher://example.com/a', 'http://user:pass@example.com/a',
            'http://example.com/a#fragment', 'http://127.1/a', 'http://2130706433/a', 'http://0x7f000001/a',
            'http://0177.0.0.1/a', 'http://localhost/a', 'http://example.com:8080/a', 'http://example.com/%0d%0aHeader',
            'http://example.com/with space', 'http://example.com\\@127.0.0.1/a', 'http://[fe80::1%25eth0]/a'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('invalidUrls')]
    public function testRejectedSyntaxNeverResolvesDns(string $url): void
    {
        $policy = new ImageUrlPolicy(static function (): array {
            self::fail('DNS must not run for a rejected URL');
        });
        $this->expectException(ImageDownloadException::class);
        $policy->resolve($url, 100);
    }

    public function testAllDnsAnswersAreCheckedAndEachHopIsResolvedAgain(): void
    {
        foreach ([['8.8.8.8', '10.0.0.1'], ['::ffff:127.0.0.1'], ['169.254.169.254']] as $answers) {
            try {
                (new ImageUrlPolicy(static fn (): array => $answers))->resolve('https://example.com/image', 100);
                self::fail('Dangerous DNS answer accepted');
            } catch (ImageDownloadException $error) {
                self::assertSame(ImageWarningCode::AddressRejected, $error->issue);
            }
        }
        $calls = 0;
        $policy = new ImageUrlPolicy(static function () use (&$calls): array {
            return ++$calls === 1 ? ['8.8.8.8'] : ['127.0.0.1'];
        });
        self::assertSame('8.8.8.8', $policy->resolve('https://example.com/first', 100)['ip']);
        try {
            $policy->resolve('https://example.com/redirect', 100);
            self::fail('Rebound redirect accepted');
        } catch (ImageDownloadException $error) {
            self::assertSame(ImageWarningCode::AddressRejected, $error->issue);
        }
        self::assertSame(2, $calls);
    }

    public function testEmptyDnsResponseIsTyped(): void
    {
        try {
            (new ImageUrlPolicy(static fn (): array => []))->resolve('https://example.com/a', 100);
            self::fail('Empty DNS accepted');
        } catch (ImageDownloadException $error) {
            self::assertSame(ImageWarningCode::DnsFailed, $error->issue);
        }
    }

    public function testRealDnsProcessIsBounded(): void
    {
        $started = hrtime(true);
        try {
            (new SystemDnsResolver())->resolve('not-existing.invalid', 50);
            self::fail('Invalid DNS unexpectedly resolved');
        } catch (ImageDownloadException $error) {
            self::assertContains($error->issue, [ImageWarningCode::DnsFailed, ImageWarningCode::Timeout]);
        }
        self::assertLessThan(1500, (hrtime(true) - $started) / 1000000);
    }

    public function testLocalExceptionCannotBeCreatedInProduction(): void
    {
        $before = getenv('APP_ENV');
        putenv('APP_ENV=production');
        try {
            $this->expectException(LogicException::class);
            ImageUrlPolicy::forTestServer('http://fixture.test:12345');
        } finally {
            putenv('APP_ENV=' . $before);
        }
    }

    public function testInvalidLimitConfigurationFailsClosed(): void
    {
        foreach ([['maxBytes' => 0], ['totalTimeoutMs' => -1], ['maxRedirects' => 11]] as $values) {
            try {
                new ImageLimits(...$values);
                self::fail('Invalid limits accepted');
            } catch (InvalidArgumentException $error) {
                self::assertContains($error->getMessage(), ['Invalid image limits.', 'At most ten redirects are supported.']);
            }
        }
        $before = getenv('IMAGE_MAX_BYTES');
        putenv('IMAGE_MAX_BYTES=invalid');
        try {
            $this->expectException(InvalidArgumentException::class);
            ImageLimits::fromEnvironment();
        } finally {
            $before === false ? putenv('IMAGE_MAX_BYTES') : putenv('IMAGE_MAX_BYTES=' . $before);
        }
    }
}
