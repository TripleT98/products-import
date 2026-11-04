<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\ApplicationFactory;
use App\Entity\User;
use App\Fixtures\CatalogFixtures;
use App\Security\AuthenticatedUser;
use App\Security\JwtConfig;
use App\Security\TokenService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class AuthTest extends StorageTestCase
{
    private const SECRET = 'test-only-signing-key-at-least-32-bytes-extended-to-64-bytes-for-tests';
    private App $app;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $data = CatalogFixtures::dataset()['users'][0];
        $user = new User($data['username'], $data['hash']);
        $this->em->getRepository(User::class)->save($user);
        self::assertNotNull($user->getId());
        $this->userId = $user->getId();
        $this->app = ApplicationFactory::create($this->em, new TokenService(new JwtConfig(self::SECRET, 'test-issuer', 'test-audience', 3600)));
        // Test-only route: executes the same default middleware as future private API routes.
        $this->app->get('/api/_test/private', static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $user = $request->getAttribute('authUser');
            if (!$user instanceof AuthenticatedUser) {
                throw new RuntimeException('Missing verified identity.');
            }
            $response->getBody()->write(json_encode(['id' => $user->id, 'username' => $user->username], JSON_THROW_ON_ERROR));
            return $response;
        });
    }

    private function request(string $method, string $path, string $body = '', ?string $contentType = null, string|array|null $authorization = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)->withBody((new StreamFactory())->createStream($body));
        if ($contentType !== null) {
            $request = $request->withHeader('Content-Type', $contentType);
        }
        if ($authorization !== null) {
            $request = $request->withHeader('Authorization', $authorization);
        }
        $response = $this->app->handle($request);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $response->getHeaderLine('X-Request-ID'));
        return $response;
    }

    private function login(string $username = CatalogFixtures::USERNAME, string $password = CatalogFixtures::PASSWORD): ResponseInterface
    {
        return $this->request('POST', '/api/auth/login', json_encode(['username' => $username, 'password' => $password], JSON_THROW_ON_ERROR), 'application/json; charset=utf-8');
    }

    public function testSuccessfulLoginReturnsContractAndUsableToken(): void
    {
        $response = $this->login();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['accessToken', 'tokenType', 'expiresIn', 'user'], array_keys($data));
        self::assertSame('Bearer', $data['tokenType']);
        self::assertSame(3600, $data['expiresIn']);
        self::assertSame(['id' => $this->userId, 'username' => CatalogFixtures::USERNAME], $data['user']);
        $claims = JWT::decode($data['accessToken'], new Key(self::SECRET, 'HS256'));
        self::assertSame((string) $this->userId, $claims->sub);
        self::assertSame('test-issuer', $claims->iss);
        self::assertSame('test-audience', $claims->aud);
        self::assertSame(3600, $claims->exp - $claims->iat);
        $private = $this->request('GET', '/api/_test/private', authorization: 'Bearer ' . $data['accessToken']);
        self::assertSame(200, $private->getStatusCode());
        self::assertSame($data['user'], json_decode((string) $private->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testBadCredentialsAndMissingTokenHaveIdenticalSafeResponses(): void
    {
        $responses = [$this->login(password: 'wrong-password'), $this->login(username: 'unknown-user'), $this->request('GET', '/api/_test/private')];
        $body = (string) $responses[0]->getBody();
        foreach ($responses as $response) {
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame($body, (string) $response->getBody());
        }
        self::assertSame(['error' => ['code' => 'UNAUTHORIZED', 'message' => 'Требуется вход.', 'details' => []]], json_decode($body, true, flags: JSON_THROW_ON_ERROR));
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidLoginInputs(string $body, ?string $type, int $status): void
    {
        $response = $this->request('POST', '/api/auth/login', $body, $type);
        self::assertSame($status, $response->getStatusCode());
        self::assertStringNotContainsString('trace', (string) $response->getBody());
    }

    public static function invalidInputs(): array
    {
        return [
            ['{', 'application/json', 400], ['[]', 'application/json', 400], ['null', 'application/json', 400],
            ['{}', 'application/json', 400], ['{"username":"u","password":""}', 'application/json', 400],
            ['{"username":7,"password":"p"}', 'application/json', 400],
            ['{"username":"u","password":"p","extra":true}', 'application/json', 400],
            ['username=u&password=p', 'application/x-www-form-urlencoded', 415], ['{}', null, 415],
        ];
    }

    #[DataProvider('badTokenKinds')]
    public function testInvalidTokensAreRejected(string $kind): void
    {
        $now = time();
        $claims = ['sub' => (string) $this->userId, 'iat' => $now, 'exp' => $now + 3600, 'iss' => 'test-issuer', 'aud' => 'test-audience'];
        $secret = self::SECRET;
        $algorithm = 'HS256';
        switch ($kind) {
            case 'expired': $claims['iat'] = $now - 3601;
                $claims['exp'] = $now - 1;
                break;
            case 'future': $claims['iat'] = $now + 60;
                $claims['exp'] = $now + 3600;
                break;
            case 'issuer': $claims['iss'] = 'other';
                break;
            case 'audience': $claims['aud'] = 'other';
                break;
            case 'no-exp': unset($claims['exp']);
                break;
            case 'no-iat': unset($claims['iat']);
                break;
            case 'no-sub': unset($claims['sub']);
                break;
            case 'numeric-sub': $claims['sub'] = $this->userId;
                break;
            case 'zero-sub': $claims['sub'] = '0';
                break;
            case 'long-ttl': $claims['exp'] = $now + 3601;
                break;
            case 'signature': $secret = str_repeat('other-key', 8);
                break;
            case 'algorithm': $algorithm = 'HS512';
                break;
        }
        $token = JWT::encode($claims, $secret, $algorithm);
        if ($kind === 'malformed') {
            $token = 'not.a.jwt';
        }
        if ($kind === 'unsigned') {
            $token = JWT::urlsafeB64Encode('{"alg":"none","typ":"JWT"}') . '.' . JWT::urlsafeB64Encode(json_encode($claims, JSON_THROW_ON_ERROR)) . '.';
        }
        $response = $this->request('GET', '/api/_test/private', authorization: 'Bearer ' . $token);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHORIZED', json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
        self::assertStringNotContainsString($token, (string) $response->getBody());
    }

    public static function badTokenKinds(): array
    {
        return array_map(static fn (string $kind): array => [$kind], ['expired', 'future', 'issuer', 'audience', 'no-exp', 'no-iat', 'no-sub', 'numeric-sub', 'zero-sub', 'long-ttl', 'signature', 'algorithm', 'malformed', 'unsigned']);
    }

    public function testDeletedUserCannotUsePreviouslyValidToken(): void
    {
        $login = json_decode((string) $this->login()->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $user = $this->em->getRepository(User::class)->find($this->userId);
        self::assertNotNull($user);
        $this->em->getRepository(User::class)->remove($user);
        self::assertSame(401, $this->request('GET', '/api/_test/private', authorization: 'Bearer ' . $login['accessToken'])->getStatusCode());
    }

    public function testMalformedMultipleAndQueryHeadersCannotAuthenticate(): void
    {
        $login = json_decode((string) $this->login()->getBody(), true, flags: JSON_THROW_ON_ERROR);
        foreach (['Basic abc', 'Bearer', ['Bearer ' . $login['accessToken'], 'Bearer ' . $login['accessToken']]] as $header) {
            self::assertSame(401, $this->request('GET', '/api/_test/private', authorization: $header)->getStatusCode());
        }
        self::assertSame(401, $this->request('GET', '/api/_test/private?accessToken=' . $login['accessToken'])->getStatusCode());
    }

    public function testEncodedPathDoesNotBypassDefaultProtection(): void
    {
        self::assertSame(401, $this->request('GET', '/%61pi/_test/private')->getStatusCode());
    }

    public function testRequestIdIsServerGeneratedAndLogsContainNoSecrets(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'auth-log-');
        self::assertNotFalse($log);
        $previous = ini_set('error_log', $log);
        self::assertNotFalse($previous);
        try {
            $this->app->post('/api/_test/failure', static function (): never {
                throw new RuntimeException('secret-password secret-signing-key secret-db-dsn secret-path secret-token');
            });
            $login = json_decode((string) $this->login()->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/_test/failure')
                ->withHeader('Authorization', 'Bearer ' . $login['accessToken'])
                ->withHeader('X-Request-ID', 'attacker-controlled-id')
                ->withBody((new StreamFactory())->createStream('secret-password'));
            $response = $this->app->handle($request);
            self::assertSame(500, $response->getStatusCode());
            $id = $response->getHeaderLine('X-Request-ID');
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $id);
            $logs = file_get_contents($log);
            self::assertIsString($logs);
            self::assertStringContainsString($id, $logs);
            foreach (['secret-password', 'secret-signing-key', 'secret-db-dsn', 'secret-path', 'secret-token', $login['accessToken'], 'attacker-controlled-id'] as $secret) {
                self::assertStringNotContainsString($secret, $logs . (string) $response->getBody());
            }
            self::assertSame(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Внутренняя ошибка.', 'details' => []]], json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            ini_set('error_log', $previous);
            unlink($log);
        }
    }
    public function testDatabaseOutageReturnsSafe503(): void
    {
        $parameters = $this->connection->getParams();
        $parameters['port'] = 1;
        $connection = \Doctrine\DBAL\DriverManager::getConnection($parameters);
        $em = \App\Infrastructure\DoctrineFactory::entityManager($connection);
        try {
            $this->app = ApplicationFactory::create($em);
            $response = $this->login();
            self::assertSame(503, $response->getStatusCode());
            self::assertSame('SERVICE_UNAVAILABLE', json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
            self::assertStringNotContainsString('SQL', (string) $response->getBody());
            self::assertStringNotContainsString('password', (string) $response->getBody());
        } finally {
            $connection->close();
            $em->close();
        }
    }

    public function testLoginWithMissingSigningKeyReturnsSafe500(): void
    {
        $original = getenv('JWT_SECRET');
        putenv('JWT_SECRET');
        try {
            $this->app = ApplicationFactory::create($this->em);
            $response = $this->login();
            self::assertSame(500, $response->getStatusCode());
            self::assertStringNotContainsString('JWT', (string) $response->getBody());
            self::assertSame('INTERNAL_ERROR', json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
        } finally {
            putenv($original === false ? 'JWT_SECRET' : 'JWT_SECRET=' . $original);
        }
    }
}
