<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Image\ImageDownloader;
use App\Image\ImageFileCleaner;
use App\Image\ImageStorage;
use App\Image\ImageUrlPolicy;
use App\Import\PreparedImage;
use App\Import\ProductWriter;
use App\Import\RowDto;
use App\Import\RowImportService;
use App\Infrastructure\DoctrineFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** Real HTTP files + real committed PostgreSQL, with an owned database and directory per test. */
final class RowImportServiceTest extends TestCase
{
    private Connection $admin;
    private Connection $database;
    private array $params;
    private string $name;
    private string $directory;
    private string $log;
    private string $origin;
    /** @var resource */
    private $server;
    private array $pipes;
    private ProductWriter $writer;
    private ImageStorage $storage;
    private ImageFileCleaner $cleaner;
    private RowImportService $service;

    protected function setUp(): void
    {
        $this->admin = DoctrineFactory::connection();
        $this->name = 'product_import_test_writer_' . bin2hex(random_bytes(6));
        $this->admin->executeStatement('CREATE DATABASE ' . $this->name);
        $this->params = $this->admin->getParams();
        $this->params['dbname'] = $this->name;
        $this->database = DriverManager::getConnection($this->params);
        MigrationSupport::migrate($this->database);
        $this->directory = '/tmp/t13-row-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->log = $this->directory . '/requests';
        $server = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Support/image-server.php', $this->log],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($server);
        $this->server = $server;
        $this->pipes = $pipes;
        stream_set_timeout($pipes[1], 3);
        $port = trim(fgets($pipes[1]) ?: '');
        self::assertMatchesRegularExpression('/^[1-9][0-9]{0,4}$/D', $port);
        $this->origin = 'http://fixture.test:' . $port;
        $params = $this->params;
        $sessions = static fn (): EntityManagerInterface => DoctrineFactory::entityManager(DriverManager::getConnection($params));
        $this->writer = new ProductWriter($sessions);
        $this->storage = new ImageStorage($this->directory);
        $this->cleaner = new ImageFileCleaner($this->storage, $sessions);
        $this->service = new RowImportService($this->downloader(), $this->writer, $this->cleaner);
    }

    private function downloader(): ImageDownloader
    {
        return new ImageDownloader($this->storage, policy: ImageUrlPolicy::forTestServer($this->origin));
    }

    protected function tearDown(): void
    {
        proc_terminate($this->server, 9);
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($this->server);
        // No symlink traversal: all test-owned paths stay under this random root.
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink() || !$entry->isDir()) {
                unlink($entry->getPathname());
            } else {
                rmdir($entry->getPathname());
            }
        }
        rmdir($this->directory);
        $this->database->close();
        $deadline = microtime(true) + 2;
        while ((int) $this->admin->fetchOne('SELECT COUNT(*) FROM pg_stat_activity WHERE datname=?', [$this->name]) > 0 && microtime(true) < $deadline) {
            usleep(20000);
        }
        try {
            $this->admin->executeStatement('DROP DATABASE ' . $this->name);
        } finally {
            $this->admin->close();
        }
    }

    /** @param list<string> $paths */
    private function row(string $code = '000001', array $paths = ['/ok', '/404', '/jpeg'], string $name = 'First'): RowDto
    {
        return new RowDto(
            5,
            $code,
            $name,
            'Description ' . $name,
            '100',
            '75',
            '100.00',
            '75.00',
            '25.00',
            [['key' => 'Размер', 'value' => '0']],
            array_map(fn (string $path): string => $this->origin . $path, $paths)
        );
    }

    private function state(string $code = '000001'): array
    {
        $product = $this->database->fetchAssociative('SELECT * FROM products WHERE external_code=?', [$code]);
        if ($product === false) {
            return [];
        }
        return ['product' => $product,
            'attributes' => $this->database->fetchAllAssociative('SELECT * FROM product_attributes WHERE product_id=? ORDER BY id', [$product['id']]),
            'images' => $this->database->fetchAllAssociative('SELECT * FROM product_images WHERE product_id=? ORDER BY position,id', [$product['id']])];
    }

    private function file(string $path): string
    {
        return $this->directory . '/' . substr($path, strlen('/images/'));
    }

    private function failImageInsert(): void
    {
        $this->database->executeStatement(<<<'SQL'
CREATE FUNCTION t13_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF EXISTS (SELECT 1 FROM products WHERE id=NEW.product_id AND name='Fail') THEN
  IF NOT EXISTS (SELECT 1 FROM product_attributes WHERE product_id=NEW.product_id AND value='0') THEN
   RAISE EXCEPTION 'wrong failure phase';
  END IF;
  RAISE EXCEPTION 'private injected image failure';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->database->executeStatement('CREATE TRIGGER t13_fail BEFORE INSERT ON product_images FOR EACH ROW EXECUTE FUNCTION t13_fail()');
    }

    public function testSeveralImagesOneWarningRepeatNoDuplicatesAndOldFilesDeletedAfterCommit(): void
    {
        $row = $this->row(paths: ['/ok', '/404', '/jpeg', '/ok']);
        $result = $this->service->import($row);
        self::assertTrue($result->write->created);
        self::assertCount(1, $result->issues);
        $issue = $result->issues[0];
        self::assertSame(['warning', 'IMAGE_HTTP_ERROR', 5, '000001', 'images'], [$issue->severity, $issue->code, $issue->rowNumber, $issue->externalCode, $issue->field]);
        $first = $this->state();
        self::assertSame('0', $first['attributes'][0]['value']);
        self::assertSame([0, 2], array_column($first['images'], 'position'));
        self::assertCount(2, $first['images']);
        foreach ($first['images'] as $image) {
            self::assertSame('image/png', mime_content_type($this->file($image['path'])));
        }
        self::assertSame(['/ok', '/404', '/jpeg'], file($this->log, FILE_IGNORE_NEW_LINES));
        $repeat = $this->service->import($row);
        self::assertFalse($repeat->write->created);
        self::assertSame($result->write->productId, $repeat->write->productId);
        $after = $this->state();
        self::assertSame($first['product']['created_at'], $after['product']['created_at']);
        self::assertSame(array_column($first['images'], 'id'), array_column($after['images'], 'id'));
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM products'));
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM product_attributes'));
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM product_images'));
        self::assertCount(2, glob($this->directory . '/*.png'));
        foreach ($first['images'] as $image) {
            self::assertFileDoesNotExist($this->file($image['path']));
        }
        self::assertSame([], glob($this->directory . '/.staging/*'));
    }

    public function testFailedCurrentUrlKeepsPreviousWorkingCopyAndAbsentUrlsAreRemoved(): void
    {
        $this->service->import($this->row(paths: ['/switchable', '/ok']));
        $first = $this->state();
        $old = $this->file($first['images'][0]['path']);
        $hash = hash_file('sha256', $old);
        file_put_contents($this->log . '.fail', 'fail');
        $result = $this->service->import($this->row(paths: ['/switchable'], name: 'Updated'));
        self::assertFalse($result->write->created);
        self::assertSame('IMAGE_HTTP_ERROR', $result->issues[0]->code);
        $after = $this->state();
        self::assertSame('Updated', $after['product']['name']);
        self::assertSame([$first['images'][0]], $after['images']);
        self::assertSame($hash, hash_file('sha256', $old));
        self::assertFileDoesNotExist($this->file($first['images'][1]['path']));
        $empty = $this->service->import($this->row(paths: ['/404']));
        self::assertNotNull($empty->write);
        self::assertSame([], $this->state()['images']);
        self::assertFileDoesNotExist($old);
        self::assertCount(0, glob($this->directory . '/*.png'));
    }

    public function testInsertRollbackRemovesAllPreparedFilesAndNextRowWorks(): void
    {
        $this->failImageInsert();
        $result = $this->service->import($this->row(paths: ['/ok', '/jpeg'], name: 'Fail'));
        self::assertNull($result->write);
        self::assertSame('PRODUCT_WRITE_FAILED', $result->issues[0]->code);
        self::assertSame('error', $result->issues[0]->severity);
        self::assertStringNotContainsString('private injected', $result->issues[0]->message);
        self::assertSame([], $this->state());
        self::assertSame([], glob($this->directory . '/*.png'));
        self::assertSame([], glob($this->directory . '/.staging/*'));
        self::assertTrue($this->service->import($this->row(code: 'next', paths: ['/ok']))->write->created);
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM products'));
    }

    public function testUpdateRollbackRestoresGraphAndPreservesFileSharedWithAnotherProduct(): void
    {
        $this->service->import($this->row(paths: ['/ok']));
        $before = $this->state();
        $path = $before['images'][0]['path'];
        $this->writer->write($this->row(code: 'other', paths: ['/ok']), [new PreparedImage($this->origin . '/ok', $path)]);
        $other = $this->state('other');
        $hash = hash_file('sha256', $this->file($path));
        $this->failImageInsert();
        $failed = $this->service->import($this->row(paths: ['/ok', '/jpeg'], name: 'Fail'));
        self::assertNull($failed->write);
        self::assertSame($before, $this->state());
        self::assertSame($other, $this->state('other'));
        self::assertSame($hash, hash_file('sha256', $this->file($path)));
        self::assertCount(1, glob($this->directory . '/*.png'));
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM product_images'));
    }

    public function testObsoleteSharedFileIsDeletedOnlyAfterLastReferenceDisappears(): void
    {
        $this->service->import($this->row(paths: ['/ok']));
        $path = $this->state()['images'][0]['path'];
        $this->writer->write($this->row(code: 'other', paths: ['/jpeg']), [new PreparedImage($this->origin . '/jpeg', $path)]);
        $this->service->import($this->row(paths: []));
        self::assertFileExists($this->file($path));
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM product_images WHERE path=?', [$path]));
        $this->service->import($this->row(code: 'other', paths: []));
        self::assertFileDoesNotExist($this->file($path));
        self::assertSame([], glob($this->directory . '/*.png'));
    }

    public function testRetryDoesNotRepeatDownloadsOrStartNetworkInsideTransaction(): void
    {
        $this->database->executeStatement('CREATE SEQUENCE t13_attempt');
        $this->database->executeStatement("CREATE FUNCTION t13_conflict() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF nextval('t13_attempt')=1 THEN RAISE EXCEPTION 'forced unique' USING ERRCODE='23505'; END IF; RETURN NEW; END $$");
        $this->database->executeStatement('CREATE TRIGGER t13_conflict BEFORE INSERT ON products FOR EACH ROW EXECUTE FUNCTION t13_conflict()');
        $attempts = 0;
        $params = $this->params;
        $writer = new ProductWriter(function () use ($params, &$attempts): EntityManagerInterface {
            ++$attempts;
            self::assertSame(['/ok', '/jpeg'], file($this->log, FILE_IGNORE_NEW_LINES), 'Every download must finish before creation of a writer session, including retries');
            return DoctrineFactory::entityManager(DriverManager::getConnection($params));
        });
        $result = (new RowImportService($this->downloader(), $writer, $this->cleaner))->import($this->row(paths: ['/ok', '/jpeg']));
        self::assertSame(2, $result->write->attempts);
        self::assertSame(2, $attempts);
        self::assertSame([], $result->issues);
        self::assertCount(2, glob($this->directory . '/*.png'));
    }

    public function testRollbackCleanupKeepsNewFileAlreadyReferencedByAnotherProduct(): void
    {
        $this->failImageInsert();
        $params = $this->params;
        $writer = new ProductWriter(function () use ($params): EntityManagerInterface {
            $files = glob($this->directory . '/*.png');
            self::assertCount(2, $files);
            $path = '/images/' . basename($files[0]);
            $this->writer->write($this->row(code: 'other', paths: ['/ok']), [new PreparedImage($this->origin . '/ok', $path)]);
            return DoctrineFactory::entityManager(DriverManager::getConnection($params));
        });
        $result = (new RowImportService($this->downloader(), $writer, $this->cleaner))->import($this->row(paths: ['/ok', '/jpeg'], name: 'Fail'));
        self::assertNull($result->write);
        self::assertSame([], $this->state());
        $other = $this->state('other');
        self::assertFileExists($this->file($other['images'][0]['path']));
        self::assertCount(1, glob($this->directory . '/*.png'));
    }

    public function testCleanupFailureRetainsFileAndDoesNotHideCommittedProduct(): void
    {
        $this->service->import($this->row(paths: ['/ok']));
        $path = $this->state()['images'][0]['path'];
        $unavailable = new ImageFileCleaner($this->storage, static function (): EntityManagerInterface {
            throw new RuntimeException('private DB unavailable');
        });
        $result = (new RowImportService($this->downloader(), $this->writer, $unavailable))->import($this->row(paths: []));
        self::assertNotNull($result->write);
        self::assertSame('IMAGE_CLEANUP_FAILED', $result->issues[0]->code);
        self::assertSame('warning', $result->issues[0]->severity);
        self::assertSame([$path], $result->cleanupPendingPaths);
        self::assertSame([], $this->state()['images']);
        self::assertFileExists($this->file($path), 'Unknown references must never cause a deletion');
        self::assertSame([], $this->cleaner->clean($result->cleanupPendingPaths));
        self::assertFileDoesNotExist($this->file($path));
    }

    public function testUnsafeCleanupPathAndSymlinkAreRefusedWithoutDeletingTarget(): void
    {
        file_put_contents($this->directory . '/keep.png', 'keep');
        symlink($this->directory . '/keep.png', $this->directory . '/alias.png');
        $paths = ['/images/../keep.png', '/images/%2e%2e/keep.png', '/images/alias.png', '/images/.staging/private.png'];
        self::assertSame($paths, $this->cleaner->clean($paths));
        self::assertSame('keep', file_get_contents($this->directory . '/keep.png'));
        self::assertSame([], $this->cleaner->clean(['/images/missing.png']));
    }

    public function testCleanupWaitsForUncommittedWriterAndSeesItsReferencesAfterCommit(): void
    {
        $this->service->import($this->row(paths: ['/ok']));
        $path = $this->state()['images'][0]['path'];
        $gate = random_int(100000000, 999999999);
        $this->database->executeStatement("CREATE FUNCTION t13_gate() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.external_code='during-cleanup' THEN PERFORM pg_advisory_xact_lock(" . $gate . "); END IF; RETURN NEW; END $$");
        $this->database->executeStatement('CREATE TRIGGER t13_gate BEFORE INSERT ON products FOR EACH ROW EXECUTE FUNCTION t13_gate()');
        $this->database->executeQuery('SELECT pg_advisory_lock(CAST(? AS bigint))', [$gate])->free();
        $children = [];
        $unlocked = false;
        try {
            $spawn = static function (string $script, array $environment, array $payload) use (&$children): void {
                $process = proc_open(
                    [PHP_BINARY, dirname(__DIR__) . '/Support/' . $script],
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                    $pipes,
                    env_vars: array_merge(getenv(), $environment)
                );
                self::assertIsResource($process);
                $children[] = ['process' => $process, 'pipes' => $pipes];
                fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
            };
            $spawn(
                'write-product.php',
                ['T11_TEST_DATABASE' => $this->name],
                ['row' => get_object_vars($this->row(code: 'during-cleanup', paths: ['/ok'])),
                    'images' => [['url' => $this->origin . '/ok', 'path' => $path]]]
            );
            $waitForLocks = function (int $expected): void {
                $deadline = microtime(true) + 10;
                do {
                    $waiting = (int) $this->database->fetchOne("SELECT COUNT(*) FROM pg_locks WHERE locktype='advisory' AND NOT granted AND database=(SELECT oid FROM pg_database WHERE datname=?)", [$this->name]);
                    if ($waiting === $expected) {
                        break;
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline);
                self::assertSame($expected, $waiting, 'Timed out waiting for real PostgreSQL path/gate locks');
            };
            $waitForLocks(1); // Writer holds the image-path lock and waits at its product INSERT trigger.
            $this->database->executeStatement("DELETE FROM products WHERE external_code='000001'");
            self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM product_images WHERE path=?', [$path]));
            $spawn('clean-images.php', ['T13_TEST_DATABASE' => $this->name, 'T13_IMAGE_DIR' => $this->directory], [$path]);
            $waitForLocks(2); // Cleaner cannot use the temporarily empty committed reference set.
            self::assertFileExists($this->file($path));
            $this->database->executeQuery('SELECT pg_advisory_unlock(CAST(? AS bigint))', [$gate])->free();
            $unlocked = true;
            $statuses = [];
            $deadline = microtime(true) + 10;
            while (count($statuses) < 2 && microtime(true) < $deadline) {
                foreach ($children as $i => $child) {
                    if (!isset($statuses[$i])) {
                        $status = proc_get_status($child['process']);
                        if (!$status['running']) {
                            $statuses[$i] = $status['exitcode'];
                        }
                    }
                }
                if (count($statuses) < 2) {
                    usleep(20000);
                }
            }
            self::assertCount(2, $statuses);
            $outputs = [];
            foreach ($children as $i => $child) {
                $output = stream_get_contents($child['pipes'][1]);
                $errors = stream_get_contents($child['pipes'][2]);
                self::assertSame(0, $statuses[$i], $errors);
                $outputs[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
            self::assertTrue($outputs[0]['created']);
            self::assertSame([], $outputs[1]);
            self::assertFileExists($this->file($path));
            self::assertSame([$path], array_column($this->state('during-cleanup')['images'], 'path'));
        } finally {
            if (!$unlocked) {
                $this->database->executeQuery('SELECT pg_advisory_unlock(CAST(? AS bigint))', [$gate])->free();
            }
            foreach ($children as $child) {
                if (proc_get_status($child['process'])['running']) {
                    proc_terminate($child['process'], 9);
                }
                foreach ($child['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($child['process']);
            }
        }
    }


    public function testJobCheckpointPersistsWarningsAndHeartbeatsBetweenHttpDownloads(): void
    {
        $owner = (int) $this->database->fetchOne("INSERT INTO users(username,password_hash) VALUES ('t14-owner','test-only-hash') RETURNING id");
        $params = $this->params;
        $events = [];
        $jobs = new \App\Import\Job\ImportJobService(function () use ($params, &$events): EntityManagerInterface {
            $events[] = file_exists($this->log) ? count(file($this->log)) : 0;
            return DoctrineFactory::entityManager(DriverManager::getConnection($params));
        }, 120);
        $id = $jobs->create($owner, str_repeat('a', 32) . '.xlsx');
        $lease = $jobs->claim($id);
        $jobs->setTotal($lease, 1);
        $events = [];
        $result = $this->service->import($this->row(paths: ['/ok', '/404']), $jobs->checkpoint($lease, 5));
        self::assertNotNull($result->write);
        self::assertSame([0, 1, 2], $events, 'Renew the lease outside transactions before each HTTP call and after preparation');
        self::assertSame('created', $jobs->rowResult($lease, 5)['outcome']);
        self::assertSame(1, $jobs->findForOwner($id, $owner)->jsonSerialize()['warningCount']);
        self::assertSame('IMAGE_HTTP_ERROR', $jobs->issuesForOwner($id, $owner)['items'][0]['code']);
        self::assertSame('completed_with_errors', $jobs->complete($lease));
        self::assertCount(1, glob($this->directory . '/*.png'));
    }

    #[DataProvider('systemFailures')]
    public function testSystemFailureDoesNotBecomePermanentRowErrorAndTheSameRowCanRetry(string $state): void
    {
        $owner = (int) $this->database->fetchOne("INSERT INTO users(username,password_hash) VALUES ('t18-owner','test-only-hash') RETURNING id");
        $params = $this->params;
        $jobs = new \App\Import\Job\ImportJobService(static fn (): EntityManagerInterface => DoctrineFactory::entityManager(DriverManager::getConnection($params)));
        $id = $jobs->create($owner, str_repeat('a', 32) . '.xlsx');
        $lease = $jobs->claim($id);
        $jobs->setTotal($lease, 1);
        $this->database->executeStatement('CREATE SEQUENCE t18_attempt');
        $this->database->executeStatement("CREATE FUNCTION t18_connection_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF nextval('t18_attempt')=1 THEN RAISE EXCEPTION 'private infrastructure failure' USING ERRCODE='".$state."'; END IF; RETURN NEW; END $$");
        $this->database->executeStatement('CREATE TRIGGER t18_connection_failure BEFORE INSERT ON import_row_results FOR EACH ROW EXECUTE FUNCTION t18_connection_failure()');
        try {
            $this->service->import($this->row(paths: ['/ok']), $jobs->checkpoint($lease, 5));
            self::fail('Infrastructure failure was incorrectly swallowed as a row result.');
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            self::assertSame($state, $error->getSQLState());
        }
        self::assertSame([], $this->state());
        self::assertNull($jobs->rowResult($lease, 5));
        self::assertSame(0, $jobs->findForOwner($id, $owner)->jsonSerialize()['processedRows']);
        self::assertSame(0, $jobs->issuesForOwner($id, $owner)['pagination']['total']);
        self::assertSame([], glob($this->directory.'/*.png'));
        self::assertSame([], glob($this->directory.'/.staging/*'));
        $result = $this->service->import($this->row(paths: ['/ok']), $jobs->checkpoint($lease, 5));
        self::assertNotNull($result->write);
        self::assertSame([], $result->issues);
        self::assertSame('created', $this->database->fetchOne('SELECT outcome FROM import_row_results WHERE job_id=? AND row_number=5', [$id]));
        self::assertSame('completed', $jobs->complete($lease));
        self::assertCount(1, glob($this->directory.'/*.png'));
    }

    public static function systemFailures(): array
    {
        return [['08006'], ['53000'], ['57014'], ['58000']];
    }

    public function testJobCheckpointFailureRollsBackProductAndCleansRealDownloadedFiles(): void
    {
        $owner = (int) $this->database->fetchOne("INSERT INTO users(username,password_hash) VALUES ('t14-owner','test-only-hash') RETURNING id");
        $params = $this->params;
        $jobs = new \App\Import\Job\ImportJobService(static fn (): EntityManagerInterface => DoctrineFactory::entityManager(DriverManager::getConnection($params)));
        $id = $jobs->create($owner, str_repeat('a', 32) . '.xlsx');
        $lease = $jobs->claim($id);
        $jobs->setTotal($lease, 1);
        $this->database->executeStatement("CREATE FUNCTION t14_reject_receipt() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'receipt failure'; END $$");
        $this->database->executeStatement('CREATE TRIGGER t14_reject_receipt BEFORE INSERT ON import_row_results FOR EACH ROW EXECUTE FUNCTION t14_reject_receipt()');
        $result = $this->service->import($this->row(paths: ['/ok', '/jpeg']), $jobs->checkpoint($lease, 5));
        self::assertNull($result->write);
        self::assertSame([], $this->state());
        self::assertNull($jobs->rowResult($lease, 5));
        self::assertSame(0, $jobs->findForOwner($id, $owner)->jsonSerialize()['processedRows']);
        self::assertSame([], glob($this->directory . '/*.png'));
        self::assertSame([], glob($this->directory . '/.staging/*'));
    }

}
