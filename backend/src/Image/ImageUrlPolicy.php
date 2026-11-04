<?php

declare(strict_types=1);

namespace App\Image;

use Closure;
use LogicException;

final class ImageUrlPolicy
{
    private ?string $testOrigin = null;

    /** @param null|Closure(string, int): list<string> $resolver */
    public function __construct(private ?Closure $resolver = null)
    {
    }

    /** Explicit, exact-origin loopback exception. No environment flag enables it in production. */
    public static function forTestServer(string $origin): self
    {
        if (getenv('APP_ENV') !== 'test' || !preg_match('~^https?://fixture\.test:[1-9][0-9]{0,4}$~D', $origin)
            || (int) substr($origin, strrpos($origin, ':') + 1) > 65535) {
            throw new LogicException('Local image server configuration is only available in tests.');
        }
        $policy = new self(static fn (): array => ['127.0.0.1']);
        $policy->testOrigin = $origin;
        return $policy;
    }

    /** @return array{url: string, host: string, port: int, ip: string} */
    public function resolve(string $url, int $timeoutMs): array
    {
        if (strlen($url) > 2048 || preg_match('~[\s\\\\]~', $url)
            || preg_match('/[\x00-\x20\x7f]/', rawurldecode($url)) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ImageDownloadException(ImageWarningCode::UrlRejected);
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            throw new ImageDownloadException(ImageWarningCode::UrlRejected);
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $ipHost = trim($host, '[]');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $origin = $scheme . '://' . $host . ':' . $port;
        $test = $this->testOrigin !== null && $origin === $this->testOrigin;
        if (!$test && !in_array($port, [80, 443], true)) {
            throw new ImageDownloadException(ImageWarningCode::UrlRejected);
        }
        if (filter_var($ipHost, FILTER_VALIDATE_IP)) {
            $addresses = [$ipHost];
        } else {
            // ASCII fully qualified names only; refuse alternate numeric IP spellings and local search names.
            if (strlen($host) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)) {
                throw new ImageDownloadException(ImageWarningCode::UrlRejected);
            }
            $addresses = $this->resolver === null ? (new SystemDnsResolver())->resolve($host, $timeoutMs) : ($this->resolver)($host, $timeoutMs);
        }
        if ($addresses === []) {
            throw new ImageDownloadException(ImageWarningCode::DnsFailed);
        }
        // Reject mixed public/private DNS results as well; curl receives just the selected checked address.
        foreach ($addresses as $address) {
            if (!self::isPublicAddress($address) && !($test && $address === '127.0.0.1')) {
                throw new ImageDownloadException(ImageWarningCode::AddressRejected);
            }
        }
        return ['url' => $scheme . '://' . $host . (isset($parts['port']) ? ':' . $port : '') . ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : ''), 'host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    public static function isPublicAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $blocked = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
                '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
                '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '168.63.129.16/32'];
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && self::inRange($ip, '2000::/3')) {
            $blocked = ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];
        } else {
            return false;
        }
        foreach ($blocked as $range) {
            if (self::inRange($ip, $range)) {
                return false;
            }
        }
        return true;
    }

    private static function inRange(string $ip, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $address = inet_pton($ip);
        $prefix = inet_pton($network);
        $bytes = intdiv((int) $bits, 8);
        $remainder = (int) $bits % 8;
        return substr($address, 0, $bytes) === substr($prefix, 0, $bytes)
            && ($remainder === 0 || (ord($address[$bytes]) & (255 << (8 - $remainder))) === (ord($prefix[$bytes]) & (255 << (8 - $remainder))));
    }
}
