<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Product;
use App\Entity\ProductAttribute;
use App\Entity\ProductImage;
use App\Entity\User;
use App\Infrastructure\DoctrineFactory;
use App\Repository\ProductAttributeRepository;
use App\Repository\ProductImageRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    private EntityManager $em;
    private Connection $connection;

    public static function setUpBeforeClass(): void
    {
        $connection = DoctrineFactory::connection();
        MigrationSupport::migrate($connection);
        $connection->close();
    }

    protected function setUp(): void
    {
        $this->em = DoctrineFactory::entityManager();
        $this->connection = $this->em->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->em->close();
        $this->connection->close();
    }

    private function product(string $code = '000123'): Product
    {
        $product = new Product($code, 'Товар', '12.50', '15.00', '-20.00');
        $this->em->persist($product);
        $this->em->flush();
        return $product;
    }

    public function testOrmMappingRelationshipsRepositoriesAndExactDecimalHydration(): void
    {
        $product = new Product('000123', 'Товар', '9999999999999999.99', '0.00', '-9999999999999999.99');
        new ProductAttribute($product, 'Размер', '0');
        new ProductImage($product, 'https://example.test/a.png', '/images/a.png', 1);
        $user = new User('test-user', 'test-only-hash');
        $this->em->persist($product);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        $repository = $this->em->getRepository(Product::class);
        self::assertInstanceOf(ProductRepository::class, $repository);
        $loaded = $repository->findByExternalCode('000123');
        self::assertInstanceOf(Product::class, $loaded);
        self::assertIsInt($loaded->getId());
        self::assertSame('000123', $loaded->getExternalCode());
        self::assertSame('9999999999999999.99', $loaded->getPrice());
        self::assertSame('0.00', $loaded->getPurchasePrice());
        self::assertSame('-9999999999999999.99', $loaded->getDiscount());
        self::assertInstanceOf(DateTimeImmutable::class, $loaded->getCreatedAt());
        self::assertCount(1, $loaded->getAttributes());
        self::assertInstanceOf(ProductAttribute::class, $loaded->getAttributes()->first());
        self::assertInstanceOf(ProductImage::class, $loaded->getImages()->first());
        self::assertSame('0', $loaded->getAttributes()->first()->getValue());
        self::assertSame($loaded, $loaded->getImages()->first()->getProduct());
        self::assertSame('/images/a.png', $loaded->getImages()->first()->getPath());
        self::assertInstanceOf(ProductAttributeRepository::class, $this->em->getRepository(ProductAttribute::class));
        self::assertInstanceOf(ProductImageRepository::class, $this->em->getRepository(ProductImage::class));
        $users = $this->em->getRepository(User::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $loadedUser = $users->findByUsername('test-user');
        self::assertNotNull($loadedUser);
        self::assertSame('test-only-hash', $loadedUser->getPasswordHash());
    }

    public function testExternalCodeUniquenessIsEnforcedByDatabase(): void
    {
        $this->product();
        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->executeStatement("INSERT INTO products (external_code,name,description,price,purchase_price,discount,created_at,updated_at)
            VALUES ('000123','Duplicate','',1,0,0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
    }

    #[DataProvider('childTables')]
    public function testForeignKeysRejectMissingProduct(string $table, array $values): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->connection->insert($table, ['product_id' => 2147483647] + $values);
    }

    public static function childTables(): array
    {
        return [
            ['product_attributes', ['key' => 'Размер', 'value' => '0']],
            ['product_images', ['url' => 'https://example.test/a.png', 'path' => '/images/a.png', 'position' => 0]],
        ];
    }

    #[DataProvider('childTables')]
    public function testChildUniquenessIsScopedToProduct(string $table, array $values): void
    {
        $first = $this->product('first');
        $second = $this->product('second');
        $this->connection->insert($table, ['product_id' => $first->getId()] + $values);
        $this->connection->insert($table, ['product_id' => $second->getId()] + $values);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->insert($table, ['product_id' => $first->getId()] + $values);
    }

    public function testDatabaseCascadeRemovesBothChildTables(): void
    {
        $product = $this->product();
        $this->connection->insert('product_attributes', ['product_id' => $product->getId(), 'key' => 'Размер', 'value' => '0']);
        $this->connection->insert('product_images', ['product_id' => $product->getId(), 'url' => 'https://example.test/a.png', 'path' => '/images/a.png', 'position' => 0]);
        $this->connection->delete('products', ['id' => $product->getId()]);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM product_attributes'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM product_images'));
    }

    #[DataProvider('invalidDecimals')]
    public function testNumericLimitsAndChecks(string $column, string $value, string $sqlState): void
    {
        $product = $this->product();
        try {
            $this->connection->executeStatement('UPDATE products SET ' . $column . ' = :value WHERE id = :id', ['value' => $value, 'id' => $product->getId()]);
            self::fail('Invalid numeric value accepted');
        } catch (DriverException $exception) {
            self::assertSame($sqlState, $exception->getSQLState());
        }
    }

    public static function invalidDecimals(): array
    {
        return [
            ['price', '10000000000000000.00', '22003'],
            ['purchase_price', '10000000000000000.00', '22003'],
            ['discount', '10000000000000000.00', '22003'],
            ['discount', '-10000000000000000.00', '22003'],
            ['price', '0.00', '23514'],
            ['price', '-0.01', '23514'],
            ['purchase_price', '-0.01', '23514'],
            ['price', 'NaN', '23514'],
            ['purchase_price', 'NaN', '23514'],
            ['discount', 'NaN', '23514'],
        ];
    }

    public function testUsernameUniquenessIsEnforcedByDatabase(): void
    {
        $this->connection->insert('users', ['username' => 'same', 'password_hash' => 'test-only-hash']);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->insert('users', ['username' => 'same', 'password_hash' => 'other-test-hash']);
    }

    public function testIndexedFieldLimitsAcceptMaximumValues(): void
    {
        $product = $this->product(str_repeat('Ж', 255));
        $this->connection->insert('product_attributes', ['product_id' => $product->getId(), 'key' => str_repeat('Ж', 255), 'value' => '0']);
        $this->connection->insert('product_images', ['product_id' => $product->getId(), 'url' => str_repeat('a', 2048), 'path' => '/images/a.png', 'position' => 0]);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM product_images'));
    }

    #[DataProvider('invalidStorageValues')]
    public function testStorageLimits(string $table, array $values, string $sqlState): void
    {
        $product = $this->product();
        try {
            $this->connection->insert($table, ['product_id' => $product->getId()] + $values);
            self::fail('Invalid storage value accepted');
        } catch (DriverException $exception) {
            self::assertSame($sqlState, $exception->getSQLState());
        }
    }

    public static function invalidStorageValues(): array
    {
        return [
            ['product_attributes', ['key' => str_repeat('a', 256), 'value' => '0'], '22001'],
            ['product_attributes', ['key' => ' ', 'value' => '0'], '23514'],
            ['product_images', ['url' => str_repeat('Ж', 1025), 'path' => '/images/a.png', 'position' => 0], '23514'],
            ['product_images', ['url' => 'https://example.test/a', 'path' => '/images/a.png', 'position' => -1], '23514'],
        ];
    }

    public function testDatabaseScaleAndHalfUpRounding(): void
    {
        $product = new Product('round', 'Rounding', '1.005', '0', '-33.335');
        $this->em->persist($product);
        $this->em->flush();
        $id = $product->getId();
        $this->em->clear();
        $loaded = $this->em->find(Product::class, $id);
        self::assertNotNull($loaded);
        self::assertSame('1.01', $loaded->getPrice());
        self::assertSame('0.00', $loaded->getPurchasePrice());
        self::assertSame('-33.34', $loaded->getDiscount());
        self::assertSame([['numeric_precision' => 18, 'numeric_scale' => 2]], $this->connection->fetchAllAssociative(
            "SELECT numeric_precision,numeric_scale FROM information_schema.columns WHERE table_name='products' AND column_name='price'"
        ));
    }
}
