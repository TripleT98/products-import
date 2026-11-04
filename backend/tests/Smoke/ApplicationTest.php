<?php

declare(strict_types=1);

namespace Tests\Smoke;

use App\ApplicationFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApplicationTest extends TestCase
{
    public function testRealSlimApplicationReturnsSafeJsonForUnknownRoute(): void
    {
        $app = ApplicationFactory::create();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/missing');
        $response = $app->handle($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $response->getHeaderLine('X-Request-ID'));
        self::assertSame('Ресурс не найден.', json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['message']);
        self::assertSame('NOT_FOUND', json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
    }

    public function testUnhandledFailureDoesNotExposeExceptionDetails(): void
    {
        $app = ApplicationFactory::create();
        $app->get('/test/failure', static function (): never {
            throw new RuntimeException('secret-sentinel-not-for-response');
        });
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/test/failure');
        $response = $app->handle($request);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('secret-sentinel', (string) $response->getBody());
        self::assertSame('INTERNAL_ERROR', json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
    }
}
