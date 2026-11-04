<?php

declare(strict_types=1);

namespace App\Image;

use CurlHandle;
use finfo;

final class ImageDownloader
{
    private ImageLimits $limits;
    private ImageUrlPolicy $policy;

    public function __construct(private ImageStorage $storage, ?ImageLimits $limits = null, ?ImageUrlPolicy $policy = null)
    {
        $this->limits = $limits ?? ImageLimits::fromEnvironment();
        $this->policy = $policy ?? new ImageUrlPolicy();
    }

    public function download(string $url): ImageDownloadResult
    {
        $deadline = hrtime(true) + $this->limits->totalTimeoutMs * 1000000;
        try {
            $current = $url;
            $seen = [];
            for ($redirects = 0; ; ++$redirects) {
                if (isset($seen[$current])) {
                    throw new ImageDownloadException(ImageWarningCode::RedirectRejected);
                }
                $seen[$current] = true;
                $target = $this->policy->resolve($current, $this->remaining($deadline));
                $response = $this->fetch($target, $this->remaining($deadline));
                if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                    if ($redirects >= $this->limits->maxRedirects || $response['redirect'] === '') {
                        throw new ImageDownloadException(ImageWarningCode::RedirectRejected);
                    }
                    $current = $response['redirect'];
                    continue;
                }
                if ($response['status'] !== 200) {
                    throw new ImageDownloadException(ImageWarningCode::HttpError);
                }
                $image = $this->decode($response['body'], $response['type']);
                try {
                    return ImageDownloadResult::success($this->storage->save($url, $image, $this->limits->maxBytes));
                } finally {
                    imagedestroy($image);
                }
            }
        } catch (ImageDownloadException $exception) {
            return ImageDownloadResult::failure($url, $exception->issue);
        }
    }

    private function remaining(int $deadline): int
    {
        $remaining = (int) (($deadline - hrtime(true)) / 1000000);
        if ($remaining < 1) {
            throw new ImageDownloadException(ImageWarningCode::Timeout);
        }
        return $remaining;
    }

    /** @param array{url: string, host: string, port: int, ip: string} $target
     * @return array{status: int, body: string, type: string, redirect: string}
     */
    private function fetch(array $target, int $remainingMs): array
    {
        $body = '';
        $headerBytes = 0;
        $oversized = false;
        $handle = curl_init($target['url']);
        $pin = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
        // Numeric URLs connect directly to the checked literal. Older libcurl cannot parse IPv6 HOST in RESOLVE.
        $resolves = filter_var(trim($target['host'], '[]'), FILTER_VALIDATE_IP) ? [] : [$target['host'] . ':' . $target['port'] . ':' . $pin];
        curl_setopt_array($handle, [
            CURLOPT_RESOLVE => $resolves,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_NETRC => false,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_CONNECTTIMEOUT_MS => min($this->limits->connectTimeoutMs, $remainingMs), CURLOPT_TIMEOUT_MS => $remainingMs,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_HTTP09_ALLOWED => false,
            CURLOPT_HTTPHEADER => ['Accept: image/png,image/jpeg,image/gif,image/webp', 'Accept-Encoding: identity'],
            CURLOPT_USERAGENT => 'ProductImport-Images/1.0',
            CURLOPT_HEADERFUNCTION => function (CurlHandle $handle, string $header) use (&$headerBytes, &$oversized): int {
                $headerBytes += strlen($header);
                if ($headerBytes > $this->limits->maxHeaderBytes || (preg_match('/^Content-Length:\s*(\d+)/i', $header, $match) && (float) $match[1] > $this->limits->maxBytes)) {
                    $oversized = true;
                    return 0;
                }
                if (preg_match('/^Content-Encoding:\s*(\S+)/i', $header, $match) && strtolower($match[1]) !== 'identity') {
                    return 0;
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => function (CurlHandle $handle, string $chunk) use (&$body, &$oversized): int {
                if (strlen($body) + strlen($chunk) > $this->limits->maxBytes) {
                    $oversized = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($handle);
            $errno = curl_errno($handle);
            if ($oversized) {
                throw new ImageDownloadException(ImageWarningCode::TooLarge);
            }
            if ($ok === false) {
                throw new ImageDownloadException($errno === CURLE_OPERATION_TIMEDOUT ? ImageWarningCode::Timeout : ImageWarningCode::DownloadFailed);
            }
            if (inet_pton((string) curl_getinfo($handle, CURLINFO_PRIMARY_IP)) !== inet_pton($target['ip'])) {
                throw new ImageDownloadException(ImageWarningCode::AddressRejected);
            }
            return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $body,
                'type' => strtolower(trim(explode(';', (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE))[0])),
                'redirect' => (string) curl_getinfo($handle, CURLINFO_REDIRECT_URL)];
        } finally {
            curl_close($handle);
        }
    }

    private function decode(string $body, string $declared): \GdImage
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($body);
        $size = @getimagesizefromstring($body);
        if (!in_array($declared, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)
            || $mime !== $declared || $size === false || $size['mime'] !== $mime) {
            throw new ImageDownloadException(ImageWarningCode::InvalidContent);
        }
        if ($size[0] < 1 || $size[1] < 1 || $size[0] > $this->limits->maxDimension || $size[1] > $this->limits->maxDimension
            || $size[0] * $size[1] > $this->limits->maxPixels) {
            throw new ImageDownloadException(ImageWarningCode::TooLarge);
        }
        $image = @imagecreatefromstring($body);
        if ($image === false) {
            throw new ImageDownloadException(ImageWarningCode::InvalidContent);
        }
        imagesavealpha($image, true);
        return $image;
    }
}
