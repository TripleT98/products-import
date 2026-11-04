<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\ApplicationFactory;
use App\Import\Job\ImportJobService;
use App\Import\WorkbookLimits;
use App\Infrastructure\DoctrineFactory;
use App\Queue\ImportPublisher;
use App\Queue\UploadStorage;
use App\Service\ImportUploadService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use ZipArchive;

final class ImportUploadTest extends TestCase
{
    private Connection $admin;
    private Connection $db;
    private EntityManager $em;
    private array $params;
    private string $database;
    private string $directory;
    private string $source;
    private string $token;
    private int $owner;
    private App $app;
    private ImportJobService $jobs;
    private UploadRecordingBus $bus;

    protected function setUp(): void
    {
        $this->admin = DoctrineFactory::connection();
        $this->database = 'product_import_test_upload_' . bin2hex(random_bytes(6));
        $this->admin->executeStatement('CREATE DATABASE ' . $this->database);
        $this->params = $this->admin->getParams();
        $this->params['dbname'] = $this->database;
        $this->db = DriverManager::getConnection($this->params);
        MigrationSupport::migrate($this->db);
        $this->owner = (int) $this->db->fetchOne("INSERT INTO users(username,password_hash) VALUES ('upload-owner',?) RETURNING id", [password_hash('test-password', PASSWORD_BCRYPT)]);
        $this->directory = '/tmp/t16-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->source = $this->directory . '/source.xlsx';
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([['Внешний код','Наименование','Цена: Цена продажи','Закупочная цена'],['000001','Товар','100','75'],['000002','Товар2','200','150']]);
        (new Xlsx($book))->save($this->source);
        $book->disconnectWorksheets();
        $params = $this->params;
        $this->jobs = new ImportJobService(static fn (): EntityManagerInterface => DoctrineFactory::entityManager(DriverManager::getConnection($params)));
        $this->bus = new UploadRecordingBus();
        $this->app = $this->application();
        $login = (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/login')->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream('{"username":"upload-owner","password":"test-password"}'));
        $response = $this->app->handle($login);
        self::assertSame(200, $response->getStatusCode());
        $this->token = json_decode((string)$response->getBody(), true, flags:JSON_THROW_ON_ERROR)['accessToken'];
    }

    private function application(?WorkbookLimits $limits = null, ?UploadStorage $storage = null): App
    {
        if (isset($this->em)) {
            $this->em->close();
            $this->em->getConnection()->close();
        }
        $this->em = DoctrineFactory::entityManager(DriverManager::getConnection($this->params));
        $storage ??= new UploadStorage($this->directory);
        $publisher = new ImportPublisher($this->jobs, $storage, $this->bus);
        $params = $this->params;
        return ApplicationFactory::create($this->em, uploads: new ImportUploadService($storage, $limits ?? new WorkbookLimits(), $publisher->enqueueSaved(...)), limiter: new \App\Service\ImportRateLimiter(static fn (): EntityManagerInterface => DoctrineFactory::entityManager(DriverManager::getConnection($params)), 100));
    }

    protected function tearDown(): void
    {
        $this->em->close();
        $this->em->getConnection()->close();
        $this->db->close();
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }rmdir($this->directory);
        $deadline = microtime(true) + 2;
        while ((int)$this->admin->fetchOne('SELECT COUNT(*) FROM pg_stat_activity WHERE datname=?', [$this->database]) > 0 && microtime(true) < $deadline) {
            usleep(20000);
        }
        try {
            $this->admin->executeStatement('DROP DATABASE '.$this->database);
        } finally {
            $this->admin->close();
        }
    }

    private function upload(?string $bytes = null, string $filename = 'input.xlsx', int $error = UPLOAD_ERR_OK, ?int $reportedSize = null): UploadedFile
    {
        $bytes ??= file_get_contents($this->source);
        return new UploadedFile((new StreamFactory())->createStream($bytes), $filename, 'untrusted/client-mime', $reportedSize ?? strlen($bytes), $error);
    }

    private function request(array $files, mixed $body = null, bool $auth = true, string $type = 'multipart/form-data; boundary=unit-test', ?string $length = null): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/imports')->withHeader('Content-Type', $type)->withUploadedFiles($files)->withParsedBody($body);
        if ($auth) {
            $request = $request->withHeader('Authorization', 'Bearer '.$this->token);
        }
        if ($length !== null) {
            $request = $request->withHeader('Content-Length', $length);
        }
        $response = $this->app->handle($request);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $response->getHeaderLine('X-Request-ID'));
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        return $response;
    }

    private function copies(): array
    {
        return array_values(array_filter(glob($this->directory.'/*.xlsx') ?: [], static fn (string $path): bool => preg_match('/\/[a-f0-9]{32}\.xlsx$/D', $path) === 1));
    }

    private function assertRejected(\Psr\Http\Message\ResponseInterface $response, int $status, string $code): void
    {
        self::assertSame($status, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true, flags:JSON_THROW_ON_ERROR);
        self::assertSame($code, $data['error']['code']);
        self::assertSame([], $data['error']['details']);
        self::assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) FROM import_jobs'));
        self::assertSame([], $this->bus->sent);
        self::assertSame([], $this->copies());
    }

    public function testAcceptedFileHasAuthenticatedOwnerPrivateRandomNameAndNoSynchronousProductWrites(): void
    {
        $before = hash_file('sha256', $this->source);
        $response = $this->request(['file' => $this->upload(filename:'../../outside.XLSX')]);
        self::assertSame(202, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true, flags:JSON_THROW_ON_ERROR);
        self::assertSame(['jobId','status'], array_keys($data));
        self::assertSame('queued', $data['status']);
        self::assertSame($data['jobId'], $this->bus->sent[0]->getMessage()->jobId);
        self::assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) FROM products'));
        $job = $this->db->fetchAssociative('SELECT * FROM import_jobs WHERE id=?', [$data['jobId']]);
        self::assertSame($this->owner, $job['owner_id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.xlsx$/D', $job['file_name']);
        $copy = $this->directory.'/'.$job['file_name'];
        self::assertSame($before, hash_file('sha256', $copy));
        self::assertSame($before, hash_file('sha256', $this->source));
        self::assertSame('600', decoct(fileperms($copy) & 0777));
        self::assertNull($this->jobs->findForOwner($data['jobId'], $this->owner + 1));
        self::assertStringNotContainsString('outside', (string)$response->getBody());
    }

    public static function malformedForms(): iterable
    {
        yield 'missing file' => ['missing'];
        yield 'wrong field' => ['other'];
        yield 'nested file' => ['nested'];
        yield 'extra file' => ['extra'];
        yield 'forged owner' => ['owner'];
    }

    #[DataProvider('malformedForms')]
    public function testMalformedFormsCannotEnqueue(string $kind): void
    {
        $files = match($kind) {
            'missing' => [],'other' => ['other' => $this->upload()],'nested' => ['file' => [$this->upload()]],'extra' => ['file' => $this->upload(),'other' => $this->upload()],default => ['file' => $this->upload()]
        };
        $this->assertRejected($this->request($files, $kind === 'owner' ? ['ownerId' => 999] : null), 400, 'INVALID_REQUEST');
    }

    public function testAuthenticationAndContentTypeAreRequired(): void
    {
        $this->assertRejected($this->request(['file' => $this->upload()], auth:false), 401, 'UNAUTHORIZED');
        $this->assertRejected($this->request([], type:'application/json'), 415, 'UNSUPPORTED_FILE_TYPE');
    }

    public function testActualMimeAndExtensionAreCheckedWithoutTrustingClientMime(): void
    {
        $this->assertRejected($this->request(['file' => $this->upload(filename:'input.xls')]), 415, 'UNSUPPORTED_FILE_TYPE');
        $this->assertRejected($this->request(['file' => $this->upload(bytes:'<html>not XLSX</html>')]), 415, 'UNSUPPORTED_FILE_TYPE');
        $this->assertRejected($this->request(['file' => $this->upload(bytes:'')]), 422, 'INVALID_WORKBOOK');
    }

    public function testActualStreamSizeEnforcesLimitEvenWhenReportedSizeLiesAndPhpDiscardIsDetected(): void
    {
        $this->app = $this->application(new WorkbookLimits(maxFileBytes:100));
        $this->assertRejected($this->request(['file' => $this->upload(bytes:str_repeat('x', 101), reportedSize:1)]), 413, 'UPLOAD_TOO_LARGE');
        $this->assertRejected($this->request(['file' => $this->upload(error:UPLOAD_ERR_INI_SIZE)]), 413, 'UPLOAD_TOO_LARGE');
        $this->assertRejected($this->request([], length:'999999999'), 413, 'UPLOAD_TOO_LARGE');
    }

    public function testUploadErrorsAndStorageFailureHaveSafeResponses(): void
    {
        foreach ([UPLOAD_ERR_PARTIAL,UPLOAD_ERR_NO_FILE] as $error) {
            $this->assertRejected($this->request(['file' => $this->upload(error:$error)]), 400, 'INVALID_REQUEST');
        }
        $this->assertRejected($this->request(['file' => $this->upload(error:UPLOAD_ERR_CANT_WRITE)]), 503, 'SERVICE_UNAVAILABLE');
        $this->app = $this->application(storage:new UploadStorage($this->directory.'/missing'));
        $this->assertRejected($this->request(['file' => $this->upload()]), 503, 'SERVICE_UNAVAILABLE');
    }

    public function testZipLimitsEncryptionAndDamagedContainerAreRejectedBeforePublish(): void
    {
        $this->app = $this->application(new WorkbookLimits(maxZipEntries:1));
        $this->assertRejected($this->request(['file' => $this->upload()]), 422, 'INVALID_WORKBOOK');
        $this->app = $this->application(new WorkbookLimits(maxDataRows:1));
        $this->assertRejected($this->request(['file' => $this->upload()]), 422, 'INVALID_WORKBOOK');
        $this->app = $this->application(new WorkbookLimits(maxUncompressedBytes:100));
        $this->assertRejected($this->request(['file' => $this->upload()]), 422, 'INVALID_WORKBOOK');
        $this->app = $this->application();
        $this->assertRejected($this->request(['file' => $this->upload(bytes:"PK\x03\x04broken")]), 422, 'INVALID_WORKBOOK');
        $encrypted = $this->directory.'/encrypted.xlsx';
        copy($this->source, $encrypted);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($encrypted));
        $zip->setPassword('test-only');
        self::assertTrue($zip->setEncryptionName('xl/workbook.xml', ZipArchive::EM_AES_256));
        $zip->close();
        $this->assertRejected($this->request(['file' => $this->upload(bytes:file_get_contents($encrypted))]), 422, 'INVALID_WORKBOOK');
    }

    public function testBusinessHeadersAreWorkerValidationNotHttpProductImport(): void
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->setCellValue('A1', 'Unknown business header');
        $path = $this->directory.'/headers.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $response = $this->request(['file' => $this->upload(bytes:file_get_contents($path))]);
        self::assertSame(202, $response->getStatusCode());
        self::assertCount(1, $this->bus->sent);
    }

    public function testBrokerFailureIsNotAcceptedAndRetainsFailedJobAndPrivateCopy(): void
    {
        $this->bus->fail = true;
        $response = $this->request(['file' => $this->upload()]);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('SERVICE_UNAVAILABLE', json_decode((string)$response->getBody(), true)['error']['code']);
        $job = $this->db->fetchAssociative('SELECT * FROM import_jobs');
        self::assertSame('failed', $job['status']);
        self::assertSame('PUBLISH_FAILED', $job['fatal_code']);
        self::assertCount(1, $this->copies());
        self::assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) FROM products'));
        self::assertStringNotContainsString('private-secret', (string)$response->getBody());
    }
}

final class UploadRecordingBus implements MessageBusInterface
{
    /** @var list<Envelope> */
    public array $sent = [];
    public bool $fail = false;
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($this->fail) {
            throw new \RuntimeException('private-secret-broker-credentials');
        }
        $envelope = Envelope::wrap($message, $stamps);
        $this->sent[] = $envelope;
        return $envelope;
    }
}
