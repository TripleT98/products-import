<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Image\ImageDownloader;
use App\Image\ImageLimits;
use App\Image\ImageStorage;
use App\Image\ImageUrlPolicy;
use App\Image\ImageWarningCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageDownloaderTest extends TestCase
{
    private string $directory;
    private string $log;
    private string $origin;
    /** @var resource */
    private $server;
    private array $pipes;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/t12-images-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->log = $this->directory . '/requests';
        $this->startServer();
    }

    private function startServer(bool $tls = false): void
    {
        $server = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Support/image-server.php', $this->log, $tls ? 'tls' : 'http'],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($server);
        $this->server = $server;
        $this->pipes = $pipes;
        stream_set_timeout($pipes[1], 3);
        $port = trim(fgets($pipes[1]) ?: '');
        self::assertMatchesRegularExpression('/^[1-9][0-9]{0,4}$/D', $port, 'Fixture server did not become ready');
        $this->origin = ($tls ? 'https' : 'http') . '://fixture.test:' . $port;
    }

    private function stopServer(): void
    {
        proc_terminate($this->server, 9);
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($this->server);
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        // Delete only this test's random private directory, never configured application storage.
        if (is_link($this->directory . '/.staging')) {
            unlink($this->directory . '/.staging');
        } elseif (is_dir($this->directory . '/.staging')) {
            foreach (glob($this->directory . '/.staging/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory . '/.staging');
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function downloader(?ImageLimits $limits = null, ?ImageStorage $storage = null): ImageDownloader
    {
        return new ImageDownloader($storage ?? new ImageStorage($this->directory), $limits ?? new ImageLimits(), ImageUrlPolicy::forTestServer($this->origin));
    }

    public static function formats(): iterable
    {
        yield 'png' => ['/ok'];
        yield 'jpeg' => ['/jpeg'];
        yield 'gif' => ['/gif'];
        yield 'appended script stripped' => ['/polyglot?name=../../shell.php'];
        yield 'relative redirect' => ['/redirect'];
    }

    #[DataProvider('formats')]
    public function testValidImagesBecomeCompleteSafePngFilesWithOriginalUrl(string $path): void
    {
        // fixture.test has no DNS entry; a successful request proves the checked IP was pinned.
        $before = getenv('HTTP_PROXY');
        putenv('HTTP_PROXY=http://127.0.0.1:1');
        try {
            $result = $this->downloader()->download($this->origin . $path);
        } finally {
            $before === false ? putenv('HTTP_PROXY') : putenv('HTTP_PROXY=' . $before);
        }
        self::assertNull($result->warning);
        self::assertNotNull($result->image);
        self::assertSame($this->origin . $path, $result->image->url);
        self::assertMatchesRegularExpression('~^/images/[a-f0-9]{32}\.png$~D', $result->image->path);
        $file = $this->directory . '/' . basename($result->image->path);
        self::assertSame('image/png', mime_content_type($file));
        self::assertSame([2, 2], array_slice(getimagesize($file), 0, 2));
        self::assertStringNotContainsString('untrusted-script', file_get_contents($file));
        self::assertSame([], glob($this->directory . '/.staging/*'));
        $repeat = $this->downloader()->download($this->origin . $path);
        self::assertNotSame($result->image->path, $repeat->image->path, 'Preparation never overwrites a potentially shared file');
        self::assertFileExists($file);
    }

    public static function rejectedResponses(): iterable
    {
        yield '404' => ['/404', ImageWarningCode::HttpError];
        yield 'fake declared MIME' => ['/mime', ImageWarningCode::InvalidContent];
        yield 'HTML with image MIME' => ['/fake', ImageWarningCode::InvalidContent];
        yield 'broken PNG with valid dimensions' => ['/corrupt', ImageWarningCode::InvalidContent];
        yield 'declared length limit' => ['/huge', ImageWarningCode::TooLarge];
        yield 'streaming length limit' => ['/stream', ImageWarningCode::TooLarge];
        yield 'chunked length limit' => ['/chunked', ImageWarningCode::TooLarge];
        yield 'header limit' => ['/header', ImageWarningCode::TooLarge];
        yield 'unrequested compression' => ['/encoding', ImageWarningCode::DownloadFailed];
        yield 'redirect loop' => ['/loop', ImageWarningCode::RedirectRejected];
        yield 'redirect count' => ['/chain', ImageWarningCode::RedirectRejected];
        yield 'redirect private' => ['/badredirect', ImageWarningCode::AddressRejected];
        yield 'redirect scheme' => ['/scheme', ImageWarningCode::UrlRejected];
        yield 'redirect credentials' => ['/userinfo', ImageWarningCode::UrlRejected];
        yield 'test exception does not allow another origin' => ['/otherhost', ImageWarningCode::UrlRejected];
    }

    #[DataProvider('rejectedResponses')]
    public function testFailuresAreTypedWarningsAndLeaveNoFiles(string $path, ImageWarningCode $expected): void
    {
        $result = $this->downloader(new ImageLimits(maxBytes: 1024, maxRedirects: 2))->download($this->origin . $path);
        self::assertNull($result->image);
        self::assertSame($expected, $result->warning->code);
        self::assertSame('warning', $result->warning->severity);
        self::assertSame($expected->message(), $result->warning->message);
        self::assertSame([], glob($this->directory . '/*.png'));
        self::assertSame([], glob($this->directory . '/.staging/*'));
        self::assertStringNotContainsString('/secret', file_get_contents($this->log));
        self::assertNotNull($this->downloader()->download($this->origin . '/ok')->image, 'A failed image must not poison the next download');
    }

    public function testSlowResponseTimesOutWithoutArtifact(): void
    {
        $started = hrtime(true);
        $result = $this->downloader(new ImageLimits(totalTimeoutMs: 100, connectTimeoutMs: 50))->download($this->origin . '/slow');
        self::assertSame(ImageWarningCode::Timeout, $result->warning->code);
        self::assertLessThan(1000, (hrtime(true) - $started) / 1000000);
        self::assertSame([], glob($this->directory . '/*.png'));
    }

    public function testPixelAndDimensionLimitsBeforeDecoding(): void
    {
        foreach ([new ImageLimits(maxPixels: 3), new ImageLimits(maxDimension: 1)] as $limits) {
            self::assertSame(ImageWarningCode::TooLarge, $this->downloader($limits)->download($this->origin . '/ok')->warning->code);
        }
        self::assertSame([], glob($this->directory . '/*.png'));
    }

    public function testStorageFailureDoesNotRemoveExistingFilesAndNextDownloadWorks(): void
    {
        $first = $this->downloader()->download($this->origin . '/ok')->image;
        $file = $this->directory . '/' . basename($first->path);
        $before = hash_file('sha256', $file);
        $result = $this->downloader(storage: new ImageStorage($this->directory . '/missing'))->download($this->origin . '/ok');
        self::assertSame(ImageWarningCode::StorageFailed, $result->warning->code);
        self::assertSame($before, hash_file('sha256', $file));
        self::assertNotNull($this->downloader()->download($this->origin . '/ok')->image);
    }

    public function testEncodedFileSizeIsAlsoBoundedAndStagingIsCleaned(): void
    {
        $first = $this->downloader()->download($this->origin . '/gif')->image;
        $file = $this->directory . '/' . basename($first->path);
        $limit = filesize($file) - 1;
        $result = $this->downloader(new ImageLimits(maxBytes: $limit))->download($this->origin . '/gif');
        self::assertSame(ImageWarningCode::TooLarge, $result->warning->code);
        self::assertSame([], glob($this->directory . '/.staging/*'));
        self::assertCount(1, glob($this->directory . '/*.png'));
        self::assertFileExists($file);
    }

    public function testSymlinkStagingIsRejected(): void
    {
        symlink(sys_get_temp_dir(), $this->directory . '/.staging');
        self::assertSame(ImageWarningCode::StorageFailed, $this->downloader()->download($this->origin . '/ok')->warning->code);
        self::assertSame([], glob($this->directory . '/*.png'));
    }

    public function testDefaultPolicyStillRejectsLocalServerEvenInTestEnvironment(): void
    {
        $downloader = new ImageDownloader(new ImageStorage($this->directory));
        self::assertSame(ImageWarningCode::UrlRejected, $downloader->download($this->origin . '/ok')->warning->code);
        self::assertSame(ImageWarningCode::AddressRejected, $downloader->download('http://127.0.0.1/ok')->warning->code);
        self::assertFileDoesNotExist($this->log, 'Rejected targets must never receive a request');
    }

    public function testUntrustedTlsCertificateIsRejected(): void
    {
        $this->stopServer();
        $this->startServer(tls: true);
        $result = $this->downloader()->download($this->origin . '/ok');
        self::assertSame(ImageWarningCode::DownloadFailed, $result->warning->code);
        self::assertNull($result->image);
        self::assertSame([], glob($this->directory . '/*.png'));
        self::assertFileDoesNotExist($this->log, 'TLS validation must fail before an HTTP request');
    }

}
