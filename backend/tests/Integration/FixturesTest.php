<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Product;
use App\Entity\ProductAttribute;
use App\Entity\ProductImage;
use App\Entity\User;
use App\Fixtures\CatalogFixtures;
use RuntimeException;

final class FixturesTest extends StorageTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/t06-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/images', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/images/fixtures/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory . '/images/fixtures')) {
            rmdir($this->directory . '/images/fixtures');
        }
        rmdir($this->directory . '/images');
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testFixturesAreCompleteDeterministicAndRepeatDoesNotChangeIdsOrData(): void
    {
        $loader = new CatalogFixtures($this->em);
        self::assertSame('loaded', $loader->load($this->directory . '/images'));
        $this->em->clear();
        $products = $this->em->getRepository(Product::class);
        self::assertSame(48, $products->count([]));
        self::assertSame(96, $this->em->getRepository(ProductAttribute::class)->count([]));
        self::assertSame(48, $this->em->getRepository(ProductImage::class)->count([]));
        self::assertSame(1, $this->em->getRepository(User::class)->count([]));
        $before = $products->findBy([], ['externalCode' => 'ASC']);
        $ids = array_map(static fn (Product $p): ?int => $p->getId(), $before);
        self::assertCount(48, array_unique(array_map(static fn (Product $p): string => $p->getName(), $before)));
        self::assertCount(48, array_unique(array_map(static fn (Product $p): string => $p->getPrice(), $before)));
        self::assertSame('100.00', $before[0]->getPrice());
        self::assertSame('4800.00', $before[47]->getPrice());
        self::assertSame('2026-01-01T00:00:00+00:00', $before[0]->getCreatedAt()->format(DATE_ATOM));
        self::assertSame(48, $this->em->getRepository(Product::class)->count(['discount' => '25.00']));
        $user = $this->em->getRepository(User::class)->findByUsername(CatalogFixtures::USERNAME);
        self::assertNotNull($user);
        self::assertTrue(password_verify(CatalogFixtures::PASSWORD, $user->getPasswordHash()));
        self::assertSame('already loaded', $loader->load($this->directory . '/images'));
        $this->em->clear();
        self::assertSame($ids, array_map(static fn (Product $p): ?int => $p->getId(), $products->findBy([], ['externalCode' => 'ASC'])));
        self::assertSame(96, $this->em->getRepository(ProductAttribute::class)->count([]));
        foreach ($this->em->getRepository(ProductImage::class)->findAll() as $image) {
            $path = $this->directory . $image->getPath();
            self::assertFileExists($path);
            $size = getimagesize($path);
            self::assertNotFalse($size);
            self::assertSame([24, 24, IMAGETYPE_PNG], [$size[0], $size[1], $size[2]]);
        }
        $this->assertImagesOpenOverLocalHttp();
    }

    private function assertImagesOpenOverLocalHttp(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertNotFalse($address);
        fclose($socket);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-S', $address, '-t', $this->directory], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            $deadline = microtime(true) + 5;
            do {
                $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
                if ($connection !== false) {
                    fclose($connection);
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertNotFalse($connection, 'Local image server did not start');
            for ($i = 1; $i <= 3; ++$i) {
                $path = '/images/fixtures/t06-' . $i . '.png';
                $bytes = file_get_contents('http://' . $address . $path);
                self::assertSame(file_get_contents($this->directory . $path), $bytes);
                self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $bytes);
            }
        } finally {
            fclose($pipes[0]);
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testNonemptyDatabaseIsNotOverwritten(): void
    {
        $product = new Product('user-data', 'Keep me', '1.00', '0.00', '100.00');
        $this->em->getRepository(Product::class)->save($product);
        try {
            (new CatalogFixtures($this->em))->load($this->directory . '/images');
            self::fail('Nonempty database was accepted');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Refusing to overwrite', $exception->getMessage());
        }
        self::assertSame('Keep me', $this->connection->fetchOne("SELECT name FROM products WHERE external_code='user-data'"));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM products'));
        self::assertDirectoryDoesNotExist($this->directory . '/images/fixtures');
    }

    public function testModifiedFixturesAreNotReset(): void
    {
        $loader = new CatalogFixtures($this->em);
        $loader->load($this->directory . '/images');
        $product = $this->em->getRepository(Product::class)->findByExternalCode('FIX-0001');
        self::assertNotNull($product);
        $product->updateDetails('Edited', '', '99.00', '0.00', '100.00');
        $this->em->getRepository(Product::class)->save($product);
        $this->em->clear();
        $this->expectExceptionMessage('Refusing to overwrite');
        $loader->load($this->directory . '/images');
    }

    public function testDifferentExistingImageIsPreserved(): void
    {
        mkdir($this->directory . '/images/fixtures');
        $file = $this->directory . '/images/fixtures/t06-1.png';
        file_put_contents($file, 'user-file');
        try {
            (new CatalogFixtures($this->em))->load($this->directory . '/images');
            self::fail('Existing file was overwritten');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('different file', $exception->getMessage());
        }
        self::assertSame('user-file', file_get_contents($file));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM products'));
    }

    public function testProductionModeIsRefusedBeforeWrites(): void
    {
        putenv('APP_ENV=production');
        try {
            $this->expectExceptionMessage('explicit APP_ENV');
            (new CatalogFixtures($this->em))->load($this->directory . '/images');
        } finally {
            putenv('APP_ENV=test');
        }
    }
}
