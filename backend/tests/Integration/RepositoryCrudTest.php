<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Product;
use App\Entity\ProductAttribute;
use App\Entity\ProductImage;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class RepositoryCrudTest extends StorageTestCase
{
    public function testCreateReadUpdateDeleteThroughRepositories(): void
    {
        $products = $this->em->getRepository(Product::class);
        $attributes = $this->em->getRepository(ProductAttribute::class);
        $images = $this->em->getRepository(ProductImage::class);
        $product = new Product('000007', 'Before', '100.00', '75.00', '25.00', 'Description', new DateTimeImmutable('2020-01-01T00:00:00Z'));
        $products->save($product);
        $attribute = new ProductAttribute($product, 'Размер', '0');
        $attributes->save($attribute);
        $image = new ProductImage($product, 'https://example.invalid/one.png', '/images/one.png', 0);
        $images->save($image);
        $id = $product->getId();
        $attributeId = $attribute->getId();
        $imageId = $image->getId();
        $this->em->clear();
        $loaded = $products->findByExternalCode('000007');
        self::assertNotNull($loaded);
        self::assertSame($id, $loaded->getId());
        self::assertSame('Before', $loaded->getName());
        $attribute = $attributes->find($attributeId);
        $image = $images->find($imageId);
        self::assertNotNull($attribute);
        self::assertNotNull($image);
        self::assertSame($loaded, $attribute->getProduct());
        self::assertSame($loaded, $image->getProduct());
        $createdAt = $loaded->getCreatedAt();
        $loaded->updateDetails('After', 'Changed', '120.50', '150.00', '-24.48');
        $attribute->update('Цвет', 'синий');
        $image->update('https://example.invalid/two.png', '/images/two.png', 2);
        $products->save($loaded, false);
        $attributes->save($attribute, false);
        $images->save($image);
        $this->em->clear();
        $loaded = $products->find($id);
        self::assertNotNull($loaded);
        self::assertSame(['After', 'Changed', '120.50', '150.00', '-24.48'], [$loaded->getName(), $loaded->getDescription(), $loaded->getPrice(), $loaded->getPurchasePrice(), $loaded->getDiscount()]);
        self::assertEquals($createdAt, $loaded->getCreatedAt());
        self::assertGreaterThan($createdAt, $loaded->getUpdatedAt());
        $attribute = $attributes->find($attributeId);
        $image = $images->find($imageId);
        self::assertNotNull($attribute);
        self::assertNotNull($image);
        self::assertSame(['Цвет', 'синий'], [$attribute->getKey(), $attribute->getValue()]);
        self::assertSame(['https://example.invalid/two.png', '/images/two.png', 2], [$image->getUrl(), $image->getPath(), $image->getPosition()]);
        // Initialize inverse collections before deletion to check both in-memory and DB state.
        self::assertCount(1, $loaded->getAttributes());
        self::assertCount(1, $loaded->getImages());
        $attributes->remove($attribute);
        $images->remove($image);
        self::assertCount(0, $loaded->getAttributes());
        self::assertCount(0, $loaded->getImages());
        $this->em->clear();
        self::assertNull($attributes->find($attributeId));
        self::assertNull($images->find($imageId));
        $loaded = $products->find($id);
        self::assertNotNull($loaded);
        $products->remove($loaded);
        $this->em->clear();
        self::assertNull($products->find($id));
    }

    public function testRepositoryParentDeletionCascades(): void
    {
        $product = new Product('cascade', 'Cascade', '1.00', '0.00', '100.00');
        new ProductAttribute($product, 'key', 'value');
        new ProductImage($product, 'https://example.invalid/a', '/images/a.png');
        $products = $this->em->getRepository(Product::class);
        $products->save($product);
        $id = $product->getId();
        $this->em->clear();
        $loaded = $products->find($id);
        self::assertNotNull($loaded);
        $products->remove($loaded);
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(ProductAttribute::class)->count([]));
        self::assertSame(0, $this->em->getRepository(ProductImage::class)->count([]));
    }

    public function testOrphanRemovalPersistsCollectionChanges(): void
    {
        $product = new Product('orphan', 'Orphan', '1.00', '0.00', '100.00');
        $attribute = new ProductAttribute($product, 'key', 'value');
        $image = new ProductImage($product, 'https://example.invalid/a', '/images/a.png');
        $products = $this->em->getRepository(Product::class);
        $products->save($product);
        $product->removeAttribute($attribute);
        $product->removeImage($image);
        $products->save($product);
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(ProductAttribute::class)->count([]));
        self::assertSame(0, $this->em->getRepository(ProductImage::class)->count([]));
        self::assertSame(1, $products->count([]));
    }

    #[DataProvider('duplicateKinds')]
    public function testRepositoryWritesRespectUniqueConstraints(string $kind): void
    {
        $product = new Product('unique', 'Unique', '1.00', '0.00', '100.00');
        new ProductAttribute($product, 'key', 'value');
        new ProductImage($product, 'https://example.invalid/a', '/images/a.png');
        $this->em->getRepository(Product::class)->save($product);
        $this->expectException(UniqueConstraintViolationException::class);
        match ($kind) {
            'product' => $this->em->getRepository(Product::class)->save(new Product('unique', 'Duplicate', '2.00', '0.00', '100.00')),
            'attribute' => $this->em->getRepository(ProductAttribute::class)->save(new ProductAttribute($product, 'key', 'other')),
            default => $this->em->getRepository(ProductImage::class)->save(new ProductImage($product, 'https://example.invalid/a', '/images/other.png')),
        };
    }

    public static function duplicateKinds(): array
    {
        return [['product'], ['attribute'], ['image']];
    }
}
