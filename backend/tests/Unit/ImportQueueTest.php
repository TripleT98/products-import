<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Command\EnqueueImportCommand;
use App\Command\WorkImportsCommand;
use App\Queue\ImportMessage;
use App\Queue\ImportMessageSerializer;
use App\Queue\UploadStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

final class ImportQueueTest extends TestCase
{
    private const string ID = '123e4567-e89b-42d3-a456-426614174000';

    public function testWireEnvelopeContainsOnlyVersionedIdAndAllowlistedRetryMetadata(): void
    {
        $serializer = new ImportMessageSerializer();
        $message = new ImportMessage(self::ID);
        $envelope = new Envelope($message, [new RedeliveryStamp(2),new DelayStamp(500),
            new SentToFailureTransportStamp('imports'),new ErrorDetailsStamp('PrivateException', 500, 'private-password-and-path')]);
        $encoded = $serializer->encode($envelope);
        self::assertSame(['jobId' => self::ID], json_decode($encoded['body'], true));
        self::assertSame(['type' => 'import-job.v1','retry-count' => '2','failed-from' => 'imports'], $encoded['headers']);
        self::assertStringNotContainsString('private', json_encode($encoded));
        $decoded = $serializer->decode($encoded);
        self::assertSame(self::ID, $decoded->getMessage()->jobId);
        self::assertSame(2, RedeliveryStamp::getRetryCountFromEnvelope($decoded));
        self::assertNotNull($decoded->last(SentToFailureTransportStamp::class));
        self::assertNull($decoded->last(ErrorDetailsStamp::class));
        self::assertNull($decoded->last(DelayStamp::class));
    }

    public static function malformed(): iterable
    {
        yield 'PHP serialization is not accepted' => ['O:8:"stdClass":0:{}',['type' => 'import-job.v1']];
        yield 'missing type' => [json_encode(['jobId' => self::ID]),[]];
        yield 'wrong version' => [json_encode(['jobId' => self::ID]),['type' => 'import-job.v2']];
        yield 'extra body' => [json_encode(['jobId' => self::ID,'path' => '/private']),['type' => 'import-job.v1']];
        yield 'invalid id' => ['{"jobId":"../private"}',['type' => 'import-job.v1']];
        yield 'too many retries' => [json_encode(['jobId' => self::ID]),['type' => 'import-job.v1','retry-count' => '999']];
        yield 'retry wrong type' => [json_encode(['jobId' => self::ID]),['type' => 'import-job.v1','retry-count' => ['private']]];
    }

    #[DataProvider('malformed')]
    public function testMalformedMessagesCannotBecomePhpObjects(string $body, array $headers): void
    {
        $this->expectException(MessageDecodingFailedException::class);
        (new ImportMessageSerializer())->decode(['body' => $body,'headers' => $headers]);
    }

    public function testPrivateLocalCopyHasSafeNameAndPermissionsAndSourceIsUnchanged(): void
    {
        $directory = '/tmp/t15-storage-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $source = $directory.'/source.xlsx';
        file_put_contents($source, 'trusted artificial local bytes');
        $hash = hash_file('sha256', $source);
        try {
            $storage = new UploadStorage($directory);
            $name = $storage->localCopy($source);
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.xlsx$/D', $name);
            $copy = $storage->readable($name);
            self::assertSame($hash, hash_file('sha256', $copy));
            self::assertSame('600', decoct(fileperms($copy) & 0777));
            $storage->removeOwnedCopy($name);
            self::assertFileDoesNotExist($copy);
            self::assertSame($hash, hash_file('sha256', $source));
            try {
                $storage->path('../outside.xlsx');
                self::fail('Traversal accepted');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('storage', $error->getMessage());
            }
        } finally {
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }rmdir($directory);
        }
    }

    public function testTestImageExceptionIsUnavailableInProductionAndInvalidOwnerIsRejected(): void
    {
        $before = getenv('APP_ENV');
        putenv('APP_ENV=production');
        try {
            $worker = new CommandTester(new WorkImportsCommand());
            self::assertSame(1, $worker->execute(['--test-image-origin' => 'http://fixture.test:18080']));
            self::assertStringContainsString('IMPORT_WORKER_FAILED', $worker->getDisplay());
        } finally {
            putenv('APP_ENV='.$before);
        }
        $enqueue = new CommandTester(new EnqueueImportCommand());
        self::assertSame(1, $enqueue->execute(['path' => '/not-opened.xlsx','--owner' => 'invalid']));
        self::assertSame(['error' => 'IMPORT_ENQUEUE_FAILED','jobId' => null], json_decode(trim($enqueue->getDisplay()), true));
    }
}
