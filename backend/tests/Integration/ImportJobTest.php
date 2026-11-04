<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\ImportJob;
use App\Import\Job\ImportFatalCode;
use App\Import\Job\ImportIssueData;
use App\Import\Job\ImportJobService;
use App\Import\Job\ImportStateException;
use App\Import\ProductWriteException;
use App\Import\ProductWriter;
use App\Import\RowDto;
use App\Infrastructure\DoctrineFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ImportJobTest extends TestCase
{
    private Connection $admin;
    private Connection $db;
    private array $params;
    private string $name;
    private ImportJobService $jobs;
    private ProductWriter $writer;
    private int $owner;
    private int $other;

    protected function setUp(): void
    {
        $this->admin = DoctrineFactory::connection();
        $this->name = 'product_import_test_job_' . bin2hex(random_bytes(6));
        $this->admin->executeStatement('CREATE DATABASE ' . $this->name);
        $this->params = $this->admin->getParams();
        $this->params['dbname'] = $this->name;
        $this->db = DriverManager::getConnection($this->params);
        MigrationSupport::migrate($this->db);
        $this->owner = (int) $this->db->fetchOne("INSERT INTO users(username,password_hash) VALUES ('owner','test-only-hash') RETURNING id");
        $this->other = (int) $this->db->fetchOne("INSERT INTO users(username,password_hash) VALUES ('other','test-only-hash') RETURNING id");
        $params = $this->params;
        $sessions = static fn (): EntityManagerInterface => DoctrineFactory::entityManager(DriverManager::getConnection($params));
        $this->jobs = new ImportJobService($sessions);
        $this->writer = new ProductWriter($sessions);
    }

    protected function tearDown(): void
    {
        $this->db->close();
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

    private function create(): string
    {
        return $this->jobs->create($this->owner, str_repeat('a', 32) . '.xlsx');
    }

    private function view(string $id): array
    {
        return $this->jobs->findForOwner($id, $this->owner)->jsonSerialize();
    }

    private function row(int $number = 2, string $code = '000001', string $name = 'First'): RowDto
    {
        return new RowDto($number, $code, $name, 'Description', '100', '75', '100.00', '75.00', '25.00', [['key' => 'Размер', 'value' => '0']], []);
    }

    private function error(int $number = 2, string $code = 'ROW_INVALID'): ImportIssueData
    {
        return new ImportIssueData('error', $code, 'Невалидная строка.', $number);
    }

    private function warning(int $number = 2): ImportIssueData
    {
        return new ImportIssueData('warning', 'IMAGE_HTTP_ERROR', 'Сервер изображения вернул ошибку HTTP.', $number, '000001', 'images');
    }

    public function testOwnershipPublicProjectionAndDurabilityAcrossNewServiceAndOrmRead(): void
    {
        $id = $this->create();
        self::assertMatchesRegularExpression('/^[a-f0-9-]{36}$/D', $id);
        $view = $this->view($id);
        self::assertSame('queued', $view['status']);
        self::assertNull($view['totalRows']);
        self::assertNull($view['startedAt']);
        self::assertSame(0, $view['processedRows']);
        self::assertSame(['id', 'status', 'totalRows', 'processedRows', 'createdProducts', 'updatedProducts', 'failedRows', 'warningCount', 'createdAt', 'startedAt', 'updatedAt', 'finishedAt', 'fatalError'], array_keys($view));
        self::assertStringEndsWith('Z', $view['createdAt']);
        self::assertNull($this->jobs->findForOwner($id, $this->other));
        self::assertNull($this->jobs->issuesForOwner($id, $this->other));
        self::assertNull($this->jobs->findForOwner('00000000-0000-4000-8000-000000000000', $this->owner));
        $params = $this->params;
        $newService = new ImportJobService(static fn (): EntityManagerInterface => DoctrineFactory::entityManager(DriverManager::getConnection($params)));
        self::assertSame($view, $newService->findForOwner($id, $this->owner)->jsonSerialize());
        $em = DoctrineFactory::entityManager(DriverManager::getConnection($params));
        try {
            $entity = $em->find(ImportJob::class, $id);
            self::assertSame($id, $entity->getId());
            self::assertSame('queued', $entity->getStatus());
        } finally {
            $em->close();
            $em->getConnection()->close();
        }
    }

    public function testSuccessCheckpointCountersAndReplayDoNotRepeatOrOverwriteProduct(): void
    {
        $id = $this->create();
        $lease = $this->jobs->claim($id);
        self::assertNotNull($lease);
        $this->jobs->setTotal($lease, 2);
        $row = $this->row();
        $result = $this->writer->write($row, [], $this->jobs->checkpoint($lease, 2, [$this->warning()]));
        self::assertTrue($result->created);
        $state = $this->view($id);
        self::assertSame([1,1,0,0,1], [$state['processedRows'],$state['createdProducts'],$state['updatedProducts'],$state['failedRows'],$state['warningCount']]);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT last_row_number FROM import_jobs WHERE id=?', [$id]));
        self::assertSame($result->productId, (int) $this->jobs->rowResult($lease, 2)['product_id']);
        $this->writer->write($this->row(name: 'Changed by another job'), []);
        $before = $this->db->fetchAssociative('SELECT * FROM products WHERE id=?', [$result->productId]);
        $replay = $this->writer->write($this->row(name: 'Must not be written'), [], $this->jobs->checkpoint($lease, 2, [$this->warning()]));
        self::assertSame($result->productId, $replay->productId);
        self::assertTrue($replay->created);
        self::assertSame($state, $this->view($id));
        self::assertSame($before, $this->db->fetchAssociative('SELECT * FROM products WHERE id=?', [$result->productId]));
        $updated = $this->writer->write($this->row(5, name: 'Update'), [], $this->jobs->checkpoint($lease, 5));
        self::assertFalse($updated->created);
        self::assertSame('completed_with_errors', $this->jobs->complete($lease));
        $finished = $this->view($id);
        self::assertSame([2,1,1,0,1], [$finished['processedRows'],$finished['createdProducts'],$finished['updatedProducts'],$finished['failedRows'],$finished['warningCount']]);
        self::assertNotNull($finished['startedAt']);
        self::assertNotNull($finished['finishedAt']);
        self::assertNull($this->jobs->claim($id));
    }

    public function testFailedRowsAndWarningCountsAreIdempotentAndIssuesPaginateAfterRestart(): void
    {
        $id = $this->create();
        $lease = $this->jobs->claim($id);
        $this->jobs->setTotal($lease, 2);
        $issues = [$this->error(),$this->error(code: 'PRICE_INVALID'),$this->warning(),$this->warning()];
        self::assertTrue($this->jobs->recordFailure($lease, 2, $issues));
        self::assertFalse($this->jobs->recordFailure($lease, 2, $issues));
        self::assertSame([1,1,1], [$this->view($id)['processedRows'],$this->view($id)['failedRows'],$this->view($id)['warningCount']]);
        $this->jobs->recordFailure($lease, 7, [$this->error(7)]);
        $first = $this->jobs->issuesForOwner($id, $this->owner, 1, 2);
        $second = $this->jobs->issuesForOwner($id, $this->owner, 2, 2);
        self::assertSame(['page' => 1,'limit' => 2,'total' => 4,'totalPages' => 2], $first['pagination']);
        self::assertCount(2, $second['items']);
        self::assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
        self::assertGreaterThan($first['items'][1]['id'], $second['items'][0]['id']);
        self::assertSame([], $this->jobs->issuesForOwner($id, $this->owner, 99, 2)['items']);
        self::assertSame('completed_with_errors', $this->jobs->complete($lease));
        self::assertSame(2, $this->view($id)['failedRows']);
    }

    public function testCheckpointFailureRollsBackProductAttributesReceiptIssuesAndCounters(): void
    {
        $id = $this->create();
        $lease = $this->jobs->claim($id);
        $this->jobs->setTotal($lease, 2);
        $this->db->executeStatement(<<<'SQL'
CREATE FUNCTION t14_break_checkpoint() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NEW.row_number=2 THEN
  IF NOT EXISTS (SELECT 1 FROM products WHERE external_code='000001') OR NOT EXISTS(SELECT 1 FROM product_attributes WHERE value='0') THEN
   RAISE EXCEPTION 'wrong failure phase';
  END IF;
  RAISE EXCEPTION 'checkpoint rejected after product SQL';
 END IF; RETURN NEW; END $$
SQL);
        $this->db->executeStatement('CREATE TRIGGER t14_break_checkpoint BEFORE INSERT ON import_row_results FOR EACH ROW EXECUTE FUNCTION t14_break_checkpoint()');
        try {
            $this->writer->write($this->row(), [], $this->jobs->checkpoint($lease, 2, [$this->warning()]));
            self::fail('Checkpoint failure ignored');
        } catch (ProductWriteException $error) {
            self::assertStringContainsString('checkpoint rejected after product SQL', $error->getPrevious()->getMessage());
        }
        foreach (['products','product_attributes','product_images','import_row_results','import_issues'] as $table) {
            self::assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) FROM '.$table));
        }
        self::assertSame(0, $this->view($id)['processedRows']);
        self::assertSame(1, (int)$this->db->fetchOne('SELECT last_row_number FROM import_jobs WHERE id=?', [$id]));
        $this->db->executeStatement('DROP TRIGGER t14_break_checkpoint ON import_row_results');
        $this->jobs->recordFailure($lease, 2, [$this->error()]);
        $this->writer->write($this->row(4, 'next'), [], $this->jobs->checkpoint($lease, 4));
        self::assertSame('completed_with_errors', $this->jobs->complete($lease));
    }

    public function testProductRollbackLeavesNoSuccessCheckpointAndNextProductWorks(): void
    {
        $id = $this->create();
        $lease = $this->jobs->claim($id);
        $this->jobs->setTotal($lease, 2);
        $this->db->executeStatement("CREATE FUNCTION t14_break_product() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.external_code='000001' THEN RAISE EXCEPTION 'product failure'; END IF; RETURN NEW; END $$");
        $this->db->executeStatement('CREATE TRIGGER t14_break_product BEFORE INSERT ON products FOR EACH ROW EXECUTE FUNCTION t14_break_product()');
        try {
            $this->writer->write($this->row(), [], $this->jobs->checkpoint($lease, 2));
            self::fail('Product failure ignored');
        } catch (ProductWriteException) {
            self::assertNull($this->jobs->rowResult($lease, 2));
        }
        self::assertSame(0, $this->view($id)['processedRows']);
        $this->jobs->recordFailure($lease, 2, [$this->error()]);
        $this->writer->write($this->row(3, 'next'), [], $this->jobs->checkpoint($lease, 3));
        self::assertSame([2,1,1], [$this->view($id)['processedRows'],$this->view($id)['createdProducts'],$this->view($id)['failedRows']]);
    }

    public function testExpiredLeaseCanBeReclaimedAndOldTokenCannotMutateOrWriteProducts(): void
    {
        $id = $this->create();
        $old = $this->jobs->claim($id);
        self::assertNull($this->jobs->claim($id));
        $this->jobs->heartbeat($old);
        $this->db->executeStatement("UPDATE import_jobs SET lease_expires_at=clock_timestamp()-interval '1 second' WHERE id=?", [$id]);
        $new = $this->jobs->claim($id);
        self::assertNotNull($new);
        self::assertNotSame($old->token, $new->token);
        self::assertSame(2, (int)$this->db->fetchOne('SELECT lease_generation FROM import_jobs WHERE id=?', [$id]));
        foreach ([fn () => $this->jobs->heartbeat($old),fn () => $this->jobs->setTotal($old, 1),fn () => $this->jobs->recordFailure($old, 2, [$this->error()]),fn () => $this->jobs->complete($old),fn () => $this->jobs->fail($old, ImportFatalCode::SystemFailure)] as $action) {
            try {
                $action();
                self::fail('Old fencing token accepted');
            } catch (ImportStateException $error) {
                self::assertStringContainsString('lease', $error->getMessage());
            }
        }
        try {
            $this->writer->write($this->row(), [], $this->jobs->checkpoint($old, 2));
            self::fail('Old writer accepted');
        } catch (ProductWriteException $error) {
            self::assertInstanceOf(ImportStateException::class, $error->getPrevious());
        }
        self::assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) FROM products'));
        $this->jobs->setTotal($new, 1);
        $this->writer->write($this->row(), [], $this->jobs->checkpoint($new, 2));
        self::assertSame('completed', $this->jobs->complete($new));
    }

    public function testUnknownTotalOrderingEmptyJobAndTerminalGuards(): void
    {
        $id = $this->create();
        $lease = $this->jobs->claim($id);
        try {
            $this->jobs->complete($lease);
            self::fail('Unknown total completed');
        } catch (ImportStateException $e) {
            self::assertStringContainsString('known', $e->getMessage());
        }
        $this->jobs->setTotal($lease, 0);
        self::assertSame('completed', $this->jobs->complete($lease));
        try {
            $this->jobs->heartbeat($lease);
            self::fail('Terminal renewed');
        } catch (ImportStateException $e) {
            self::assertStringContainsString('lease', $e->getMessage());
        }
        $id = $this->create();
        $lease = $this->jobs->claim($id);
        $this->jobs->recordFailure($lease, 5, [$this->error(5)]);
        foreach ([fn () => $this->jobs->setTotal($lease, 0),fn () => $this->jobs->recordFailure($lease, 3, [$this->error(3)])] as $action) {
            try {
                $action();
                self::fail('Invalid progress accepted');
            } catch (ImportStateException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        $this->jobs->setTotal($lease, 2);
        try {
            $this->jobs->setTotal($lease, 3);
            self::fail('Known total changed');
        } catch (ImportStateException $e) {
            self::assertStringContainsString('immutable', $e->getMessage());
        }
        try {
            $this->jobs->complete($lease);
            self::fail('Incomplete completed');
        } catch (ImportStateException $e) {
            self::assertStringContainsString('finish', $e->getMessage());
        }
    }

    public function testFatalFailureKeepsPartialProgressAndSafeSystemIssueAndQueuedFailure(): void
    {
        $id = $this->create();
        $lease = $this->jobs->claim($id);
        $this->writer->write($this->row(), [], $this->jobs->checkpoint($lease, 2));
        $this->jobs->fail($lease, ImportFatalCode::InvalidWorkbook);
        $view = $this->view($id);
        self::assertSame('failed', $view['status']);
        self::assertSame(1, $view['processedRows']);
        self::assertSame(['code' => 'WORKBOOK_INVALID','message' => ImportFatalCode::InvalidWorkbook->message()], $view['fatalError']);
        $issue = $this->jobs->issuesForOwner($id, $this->owner)['items'][0];
        self::assertNull($issue['rowNumber']);
        self::assertNull($issue['field']);
        self::assertNull($this->jobs->claim($id));
        $queued = $this->create();
        $this->jobs->failQueuedPublication($queued);
        self::assertSame('PUBLISH_FAILED', $this->view($queued)['fatalError']['code']);
        self::assertNull($this->jobs->claim($queued));
        try {
            $this->jobs->failQueuedPublication($id);
            self::fail('Terminal failed again');
        } catch (ImportStateException $e) {
            self::assertStringContainsString('queued', $e->getMessage());
        }
    }

    public function testCleanupWarningsAndPendingPathsPersistWithoutDuplicatingCounters(): void
    {
        $id = $this->create();
        $lease = $this->jobs->claim($id);
        $this->jobs->setTotal($lease, 1);
        $this->writer->write($this->row(), [], $this->jobs->checkpoint($lease, 2));
        $warning = new ImportIssueData('warning', 'IMAGE_CLEANUP_FAILED', 'Не удалось удалить файл.', 2, '000001', 'images');
        $paths = ['/images/'.str_repeat('b', 32).'.png'];
        $this->jobs->noteCleanup($lease, 2, [$warning], $paths);
        $this->jobs->noteCleanup($lease, 2, [$warning], $paths);
        self::assertSame(1, $this->view($id)['warningCount']);
        self::assertSame(1, $this->view($id)['processedRows']);
        self::assertSame($paths, json_decode($this->jobs->rowResult($lease, 2)['cleanup_pending_paths'], true));
        $this->jobs->noteCleanup($lease, 2, [], []);
        self::assertSame([], json_decode($this->jobs->rowResult($lease, 2)['cleanup_pending_paths'], true));
        self::assertSame('completed_with_errors', $this->jobs->complete($lease));
    }

    public function testIssueValidationAndDatabaseConstraints(): void
    {
        $id = $this->create();
        $lease = $this->jobs->claim($id);
        try {
            $this->jobs->recordFailure($lease, 2, [$this->warning()]);
            self::fail('Failure without error accepted');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('requires', $e->getMessage());
        }
        try {
            $this->jobs->create($this->owner, '../unsafe.xlsx');
            self::fail('Unsafe upload accepted');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('generated', $e->getMessage());
        }
        try {
            $this->jobs->issuesForOwner($id, $this->owner, 0);
            self::fail('Bad page accepted');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('pagination', $e->getMessage());
        }
        foreach (["UPDATE import_jobs SET processed_rows=1 WHERE id=?","UPDATE import_jobs SET status='invalid' WHERE id=?","UPDATE import_jobs SET owner_id=2147483647 WHERE id=?"] as $sql) {
            try {
                $this->db->executeStatement($sql, [$id]);
                self::fail('Database constraint missing');
            } catch (DriverException $e) {
                self::assertContains($e->getSQLState(), ['23514','23503']);
            }
        }
        self::assertSame(0, $this->view($id)['processedRows']);
        try {
            $this->writer->write($this->row(3), [], $this->jobs->checkpoint($lease, 2));
            self::fail('Wrong physical row accepted');
        } catch (ProductWriteException $e) {
            self::assertInstanceOf(InvalidArgumentException::class, $e->getPrevious());
        }
    }

    public function testTwoRealProcessesCannotClaimTheSameJobAndDeadOwnerRecovers(): void
    {
        $id = $this->create();
        $gate = random_int(100000000, 999999999);
        $this->db->executeStatement('CREATE FUNCTION t14_claim_gate() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.lease_generation=1 THEN PERFORM pg_advisory_xact_lock('.$gate.'); END IF; RETURN NEW; END $$');
        $this->db->executeStatement('CREATE TRIGGER t14_claim_gate BEFORE UPDATE ON import_jobs FOR EACH ROW EXECUTE FUNCTION t14_claim_gate()');
        $this->db->executeQuery('SELECT pg_advisory_lock(CAST(? AS bigint))', [$gate])->free();
        $children = [];
        $unlocked = false;
        try {
            for ($i = 0;$i < 2;++$i) {
                $process = proc_open([PHP_BINARY,dirname(__DIR__).'/Support/import-job.php'], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, env_vars:array_merge(getenv(), ['T14_TEST_DATABASE' => $this->name]));
                self::assertIsResource($process);
                $children[] = ['process' => $process,'pipes' => $pipes];
                fwrite($pipes[0], json_encode(['id' => $id], JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
            }
            $deadline = microtime(true) + 10;
            $statuses = [];
            do {
                $waiting = (int)$this->db->fetchOne("SELECT COUNT(*) FROM pg_locks WHERE locktype='advisory' AND NOT granted AND database=(SELECT oid FROM pg_database WHERE datname=?)", [$this->name]);
                foreach ($children as $i => $child) {
                    if (!isset($statuses[$i])) {
                        $status = proc_get_status($child['process']);
                        if (!$status['running']) {
                            $statuses[$i] = $status['exitcode'];
                        }
                    }
                }
                if ($waiting === 1 && count($statuses) === 1) {
                    break;
                }usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(1, $waiting);
            self::assertCount(1, $statuses, 'One competing claim must exit without waiting for the winning claim');
            $this->db->executeQuery('SELECT pg_advisory_unlock(CAST(? AS bigint))', [$gate])->free();
            $unlocked = true;
            $deadline = microtime(true) + 10;
            while (count($statuses) < 2 && microtime(true) < $deadline) {
                foreach ($children as $i => $child) {
                    if (!isset($statuses[$i])) {
                        $status = proc_get_status($child['process']);
                        if (!$status['running']) {
                            $statuses[$i] = $status['exitcode'];
                        }
                    }
                }usleep(20000);
            }
            self::assertCount(2, $statuses);
            $leases = [];
            foreach ($children as $i => $child) {
                $out = stream_get_contents($child['pipes'][1]);
                $err = stream_get_contents($child['pipes'][2]);
                self::assertSame(0, $statuses[$i], $err);
                $value = json_decode($out, true, flags:JSON_THROW_ON_ERROR);
                if ($value !== null) {
                    $leases[] = $value;
                }
            }
            self::assertCount(1, $leases);
            self::assertSame($leases[0]['token'], $this->db->fetchOne('SELECT lease_token FROM import_jobs WHERE id=?', [$id]));
            // Both claiming CLI processes exited. Their dead lease is reclaimable after expiry.
            $this->db->executeStatement("UPDATE import_jobs SET lease_expires_at=clock_timestamp()-interval '1 second' WHERE id=?", [$id]);
            $next = $this->jobs->claim($id);
            self::assertNotNull($next);
            self::assertNotSame($leases[0]['token'], $next->token);
            self::assertSame(2, (int)$this->db->fetchOne('SELECT lease_generation FROM import_jobs WHERE id=?', [$id]));
        } finally {
            if (!$unlocked) {
                $this->db->executeQuery('SELECT pg_advisory_unlock(CAST(? AS bigint))', [$gate])->free();
            }
            foreach ($children as $child) {
                if (proc_get_status($child['process'])['running']) {
                    proc_terminate($child['process'], 9);
                }foreach ($child['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }proc_close($child['process']);
            }
        }
    }

    public function testReclaimPreservesCommittedCheckpointAndResumesWithoutDoubleCounting(): void
    {
        $id = $this->create();
        $firstLease = $this->jobs->claim($id);
        $this->jobs->setTotal($firstLease, 2);
        $first = $this->writer->write($this->row(), [], $this->jobs->checkpoint($firstLease, 2));
        $this->db->executeStatement("UPDATE import_jobs SET lease_expires_at=clock_timestamp()-interval '1 second' WHERE id=?", [$id]);
        $params = $this->params;
        $resumed = new ImportJobService(static fn (): EntityManagerInterface => DoctrineFactory::entityManager(DriverManager::getConnection($params)));
        $secondLease = $resumed->claim($id);
        self::assertNotNull($secondLease);
        self::assertSame('created', $resumed->rowResult($secondLease, 2)['outcome']);
        $replay = $this->writer->write($this->row(name: 'Should not replace first'), [], $resumed->checkpoint($secondLease, 2));
        self::assertSame($first->productId, $replay->productId);
        self::assertSame('First', $this->db->fetchOne('SELECT name FROM products WHERE id=?', [$first->productId]));
        $this->writer->write($this->row(5, 'second'), [], $resumed->checkpoint($secondLease, 5));
        self::assertSame('completed', $resumed->complete($secondLease));
        $view = $resumed->findForOwner($id, $this->owner)->jsonSerialize();
        self::assertSame([2, 2, 0, 0], [$view['processedRows'], $view['createdProducts'], $view['updatedProducts'], $view['failedRows']]);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM import_row_results WHERE job_id=?', [$id]));
    }

}
