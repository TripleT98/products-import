<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Import\PreparedImage;
use App\Import\ProductWriteException;
use App\Import\ProductWriter;
use App\Import\RowDto;
use App\Infrastructure\DoctrineFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Real committed transactions in a new database per test, never application data. */
final class ProductWriterTest extends TestCase
{
    private Connection $admin;
    private Connection $database;
    private string $name;
    private array $params;
    private ProductWriter $writer;

    protected function setUp(): void
    {
        $this->admin = DoctrineFactory::connection();
        $this->name = 'product_import_test_writer_' . bin2hex(random_bytes(6));
        $this->admin->executeStatement('CREATE DATABASE ' . $this->name);
        $this->params = $this->admin->getParams();
        $this->params['dbname'] = $this->name;
        $this->database = DriverManager::getConnection($this->params);
        MigrationSupport::migrate($this->database);
        $params = $this->params;
        $this->writer = new ProductWriter(static fn (): EntityManagerInterface => DoctrineFactory::entityManager(DriverManager::getConnection($params)));
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $deadline = microtime(true) + 2;
        while ((int) $this->admin->fetchOne('SELECT COUNT(*) FROM pg_stat_activity WHERE datname = ?', [$this->name]) > 0 && microtime(true) < $deadline) {
            usleep(20000);
        }
        try {
            $this->admin->executeStatement('DROP DATABASE ' . $this->name);
        } finally {
            $this->admin->close();
        }
    }

    /** @param list<array{key: string, value: string}> $attributes @param list<string> $urls */
    private function row(string $code = '000001', string $name = 'First', array $attributes = [['key' => 'Размер', 'value' => '0']], array $urls = ['https://example.test/a.png'], string $price = '100.00', string $purchase = '75.00'): RowDto
    {
        return new RowDto(2, $code, $name, 'Description ' . $name, $price, $purchase, $price, $purchase, '25.00', $attributes, $urls);
    }

    private function image(string $url = 'https://example.test/a.png', string $path = '/images/a.png'): PreparedImage
    {
        return new PreparedImage($url, $path);
    }

    private function state(string $code = '000001'): array
    {
        $product = $this->database->fetchAssociative('SELECT * FROM products WHERE external_code = ?', [$code]);
        if ($product === false) {
            return [];
        }
        return ['product' => $product,
            'attributes' => $this->database->fetchAllAssociative('SELECT * FROM product_attributes WHERE product_id = ? ORDER BY key', [$product['id']]),
            'images' => $this->database->fetchAllAssociative('SELECT * FROM product_images WHERE product_id = ? ORDER BY position,id', [$product['id']]),
        ];
    }

    public function testFirstInsertAndRepeatedUpdatePreserveIdentityWithoutDuplicates(): void
    {
        $row = $this->row();
        $first = $this->writer->write($row, [$this->image()]);
        self::assertTrue($first->created);
        self::assertSame(1, $first->attempts);
        $before = $this->state();
        self::assertSame('000001', $before['product']['external_code']);
        self::assertSame('100.00', $before['product']['price']);
        self::assertSame('0', $before['attributes'][0]['value']);
        $repeat = $this->writer->write($row, [$this->image()]);
        self::assertFalse($repeat->created);
        self::assertSame($first->productId, $repeat->productId);
        $after = $this->state();
        self::assertSame($before['product']['created_at'], $after['product']['created_at']);
        self::assertSame($before['attributes'], $after['attributes']);
        self::assertSame($before['images'], $after['images']);
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM products'));
    }

    public function testUpdateReplacesEntireSetsAndPreservesOldCopyForCurrentFailedUrl(): void
    {
        $a = 'https://example.test/a.png';
        $b = 'https://example.test/b.png';
        $c = 'https://example.test/c.png';
        $failed = 'https://example.test/not-prepared.png';
        $id = $this->writer->write($this->row(attributes: [['key' => 'keep', 'value' => 'old'], ['key' => 'remove', 'value' => 'old']], urls: [$a, $b]), [$this->image($a), $this->image($b, '/images/b.png')])->productId;
        $this->database->executeStatement("UPDATE products SET created_at='2020-01-01T00:00:00Z',updated_at='2020-01-01T00:00:00Z' WHERE id=?", [$id]);
        $before = $this->state();
        $result = $this->writer->write($this->row(name: 'After', attributes: [['key' => 'keep', 'value' => 'new'], ['key' => 'added', 'value' => '0']], urls: [$b, $c, $failed], price: '200.00', purchase: '150.00'), [$this->image($c, '/images/c.png')]);
        self::assertFalse($result->created);
        self::assertSame($id, $result->productId);
        $after = $this->state();
        self::assertSame(['After', '200.00', '150.00', '25.00'], [$after['product']['name'], $after['product']['price'], $after['product']['purchase_price'], $after['product']['discount']]);
        self::assertSame($before['product']['created_at'], $after['product']['created_at']);
        self::assertGreaterThan($before['product']['updated_at'], $after['product']['updated_at']);
        self::assertSame(['added', 'keep'], array_column($after['attributes'], 'key'));
        self::assertSame(['0', 'new'], array_column($after['attributes'], 'value'));
        self::assertSame([$b, $c], array_column($after['images'], 'url'));
        self::assertSame(['/images/b.png', '/images/c.png'], array_column($after['images'], 'path'));
        self::assertSame([0, 1], array_column($after['images'], 'position'));
        self::assertSame($before['images'][1]['id'], $after['images'][0]['id']);
        $this->writer->write($this->row(urls: [$b, $c], attributes: []), [$this->image($b, '/images/b-new.png'), $this->image($c, '/images/c.png')]);
        $last = $this->state();
        self::assertSame([], $last['attributes']);
        self::assertSame('/images/b-new.png', $last['images'][0]['path']);
        $this->writer->write($this->row(attributes: [], urls: []), []);
        self::assertSame([], $this->state()['images']);
    }

    public function testMissingPreparedFileDoesNotCreateAFakeImageRecord(): void
    {
        $result = $this->writer->write($this->row(), []);
        self::assertTrue($result->created);
        self::assertSame([], $this->state()['images']);
    }

    private function failImageTrigger(): void
    {
        $this->database->executeStatement(<<<'SQL'
CREATE FUNCTION t11_image_failure() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.url = 'https://example.test/fail.png' THEN
  IF NOT EXISTS (SELECT 1 FROM products WHERE id=NEW.product_id AND name='Changed')
     OR NOT EXISTS (SELECT 1 FROM product_attributes WHERE product_id=NEW.product_id AND key='new' AND value='new') THEN
   RAISE EXCEPTION 'wrong injection stage';
  END IF;
  RAISE EXCEPTION 'injected after phase one';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->database->executeStatement('CREATE TRIGGER t11_image_failure BEFORE INSERT ON product_images FOR EACH ROW EXECUTE FUNCTION t11_image_failure()');
    }

    public function testFailureAfterProductAndAttributesRollsBackUpdateThenNextRowWorks(): void
    {
        $this->writer->write($this->row(), [$this->image()]);
        $before = $this->state();
        $this->failImageTrigger();
        try {
            $this->writer->write($this->row(name: 'Changed', attributes: [['key' => 'new', 'value' => 'new']], urls: ['https://example.test/fail.png'], price: '200.00', purchase: '150.00'), [$this->image('https://example.test/fail.png', '/images/fail.png')]);
            self::fail('Injected failure was ignored');
        } catch (ProductWriteException $exception) {
            self::assertSame(1, $exception->attempts);
            self::assertStringContainsString('injected after phase one', $exception->getPrevious()->getMessage());
            self::assertStringNotContainsString('injected', $exception->getMessage());
        }
        self::assertSame($before, $this->state());
        $next = $this->writer->write($this->row(code: 'next'), [$this->image()]);
        self::assertTrue($next->created);
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM products'));
    }

    public function testFailureAfterInitialInsertLeavesNoPartialProductOrChildren(): void
    {
        $this->failImageTrigger();
        try {
            $this->writer->write($this->row(name: 'Changed', attributes: [['key' => 'new', 'value' => 'new']], urls: ['https://example.test/fail.png']), [$this->image('https://example.test/fail.png', '/images/fail.png')]);
            self::fail('Injected failure was ignored');
        } catch (ProductWriteException $exception) {
            self::assertStringContainsString('injected after phase one', $exception->getPrevious()->getMessage());
        }
        self::assertSame([], $this->state());
        foreach (['products', 'product_attributes', 'product_images'] as $table) {
            self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM ' . $table));
        }
        self::assertTrue($this->writer->write($this->row(code: 'after-failed-insert'), [$this->image()])->created);
    }

    public function testRetryLimitUsesFreshSessionsAndWriterRemainsUsable(): void
    {
        $this->database->executeStatement(<<<'SQL'
CREATE FUNCTION t11_force_unique() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.external_code = 'force-retry' THEN
  RAISE EXCEPTION 'forced unique conflict' USING ERRCODE='23505';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->database->executeStatement('CREATE TRIGGER t11_force_unique BEFORE INSERT ON products FOR EACH ROW EXECUTE FUNCTION t11_force_unique()');
        $params = $this->params;
        $sessions = [];
        $writer = new ProductWriter(static function () use ($params, &$sessions): EntityManagerInterface {
            $session = DoctrineFactory::entityManager(DriverManager::getConnection($params));
            $sessions[] = $session;
            return $session;
        });
        try {
            $writer->write($this->row(code: 'force-retry'), [$this->image()]);
            self::fail('Permanent conflict was ignored');
        } catch (ProductWriteException $exception) {
            self::assertSame(3, $exception->attempts);
        }
        self::assertCount(3, $sessions);
        self::assertCount(3, array_unique(array_map(spl_object_id(...), $sessions)));
        foreach ($sessions as $session) {
            self::assertFalse($session->isOpen());
            self::assertFalse($session->getConnection()->isConnected());
        }
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM products'));
        self::assertTrue($writer->write($this->row(code: 'after-retry-limit'), [])->created);
    }

    public function testPreparedDescriptorsCannotAttachUnexpectedOrDuplicateUrls(): void
    {
        foreach ([[$this->image('https://example.test/unrequested.png')], [$this->image(), $this->image()]] as $images) {
            try {
                $this->writer->write($this->row(), $images);
                self::fail('Inconsistent descriptors accepted');
            } catch (InvalidArgumentException) {
                self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM products'));
            }
        }
        self::assertTrue($this->writer->write($this->row(), [$this->image()])->created);
    }

    public function testConcurrentCreationRetriesRealUniqueConflictWithoutDuplicates(): void
    {
        $gate = random_int(100000000, 999999999);
        $this->database->executeStatement('CREATE FUNCTION t11_insert_gate() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_advisory_xact_lock_shared(' . $gate . '); RETURN NEW; END $$');
        $this->database->executeStatement('CREATE TRIGGER t11_insert_gate BEFORE INSERT ON products FOR EACH ROW EXECUTE FUNCTION t11_insert_gate()');
        $this->database->executeQuery('SELECT pg_advisory_lock(CAST(? AS bigint))', [$gate])->free();
        $children = [];
        $unlocked = false;
        try {
            foreach (['A', 'B'] as $label) {
                $row = $this->row(code: 'concurrent', name: 'Concurrent ' . $label, attributes: [['key' => 'writer', 'value' => $label]]);
                $pipes = [];
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/write-product.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, env_vars: getenv() + ['T11_TEST_DATABASE' => $this->name]);
                self::assertIsResource($process);
                $children[] = ['process' => $process, 'pipes' => $pipes];
                fwrite($pipes[0], json_encode(['row' => get_object_vars($row), 'images' => [['url' => 'https://example.test/a.png', 'path' => '/images/' . $label . '.png']]], JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
            }
            $deadline = microtime(true) + 10;
            do {
                $waiting = (int) $this->database->fetchOne("SELECT COUNT(*) FROM pg_locks WHERE locktype='advisory' AND NOT granted AND objid=CAST(? AS oid) AND database=(SELECT oid FROM pg_database WHERE datname=?)", [$gate, $this->name]);
                if ($waiting === 2) {
                    break;
                }
                foreach ($children as $child) {
                    self::assertTrue(proc_get_status($child['process'])['running'], 'Writer exited before the concurrency gate');
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting, 'Both processes must have selected the missing row before INSERT.');
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
            self::assertCount(2, $statuses, 'Concurrent writers timed out');
            $results = [];
            foreach ($children as $i => $child) {
                $output = stream_get_contents($child['pipes'][1]);
                $errors = stream_get_contents($child['pipes'][2]);
                self::assertSame(0, $statuses[$i], $errors);
                $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
            self::assertSame($results[0]['productId'], $results[1]['productId']);
            $attempts = array_column($results, 'attempts');
            sort($attempts);
            self::assertSame([1, 2], $attempts, 'Loser of actual UNIQUE conflict must retry with a fresh session.');
            self::assertNotSame($results[0]['created'], $results[1]['created']);
            $last = $results[0]['created'] ? 'B' : 'A';
            $final = $this->state('concurrent');
            self::assertSame('Concurrent ' . $last, $final['product']['name']);
            self::assertSame([$last], array_column($final['attributes'], 'value'));
            self::assertSame(['/images/' . $last . '.png'], array_column($final['images'], 'path'));
            foreach (['products', 'product_attributes', 'product_images'] as $table) {
                self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM ' . $table));
            }
        } finally {
            if (!$unlocked) {
                $this->database->executeQuery('SELECT pg_advisory_unlock(CAST(? AS bigint))', [$gate])->free();
            }
            foreach ($children as $child) {
                if (is_resource($child['process'])) {
                    if (proc_get_status($child['process'])['running']) {
                        proc_terminate($child['process']);
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
    }
}
