<?php

declare(strict_types=1);

namespace App\Fixtures;

use App\Entity\Product;
use App\Entity\ProductAttribute;
use App\Entity\ProductImage;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final class CatalogFixtures
{
    public const USERNAME = 'fixture-user';
    public const PASSWORD = 'password';
    // Public test credential, never a production account.
    private const HASH = '$2y$10$VyO8iBFaEfiziaHo/fY0Kupa880K/JGzxO2sE2XlEs6U2IowEqNZ2';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return array{products: list<array<string, mixed>>, users: list<array<string, string>>} */
    public static function dataset(): array
    {
        $products = [];
        for ($i = 1; $i <= 48; ++$i) {
            $image = ($i - 1) % 3 + 1;
            $products[] = [
                'code' => sprintf('FIX-%04d', $i),
                'name' => ['Футболка', 'Платье', 'Жакет'][($i - 1) % 3] . sprintf(' %02d', $i),
                'description' => 'Искусственный товар для локальной разработки ' . $i,
                'price' => ($i * 100) . '.00',
                'purchasePrice' => ($i * 75) . '.00',
                'discount' => '25.00',
                'createdAt' => '2026-01-01 00:00:00+00:00',
                'updatedAt' => '2026-01-01 00:00:00+00:00',
                'attributes' => [['key' => 'Размер', 'value' => (string) (40 + $i % 8)], ['key' => 'Серия', 'value' => (string) ($i % 4)]],
                'images' => [['url' => 'https://fixtures.invalid/t06-' . $image . '.png', 'path' => '/images/fixtures/t06-' . $image . '.png', 'position' => 0]],
            ];
        }
        return ['products' => $products, 'users' => [['username' => self::USERNAME, 'hash' => self::HASH]]];
    }

    /** @return array{products: list<array<string, mixed>>, users: list<array<string, string>>} */
    private function snapshot(): array
    {
        $products = [];
        foreach ($this->em->getRepository(Product::class)->findBy([], ['externalCode' => 'ASC']) as $product) {
            $attributes = [];
            foreach ($product->getAttributes() as $attribute) {
                $attributes[] = ['key' => $attribute->getKey(), 'value' => $attribute->getValue()];
            }
            $images = [];
            foreach ($product->getImages() as $image) {
                $images[] = ['url' => $image->getUrl(), 'path' => $image->getPath(), 'position' => $image->getPosition()];
            }
            $products[] = [
                'code' => $product->getExternalCode(), 'name' => $product->getName(), 'description' => $product->getDescription(),
                'price' => $product->getPrice(), 'purchasePrice' => $product->getPurchasePrice(), 'discount' => $product->getDiscount(),
                'createdAt' => $product->getCreatedAt()->format('Y-m-d H:i:sP'), 'updatedAt' => $product->getUpdatedAt()->format('Y-m-d H:i:sP'),
                'attributes' => $attributes, 'images' => $images,
            ];
        }
        $users = [];
        foreach ($this->em->getRepository(User::class)->findBy([], ['username' => 'ASC']) as $user) {
            $users[] = ['username' => $user->getUsername(), 'hash' => $user->getPasswordHash()];
        }
        return ['products' => $products, 'users' => $users];
    }

    public function load(string $imageDirectory): string
    {
        if (!in_array(getenv('APP_ENV'), ['test', 'development'], true)) {
            throw new RuntimeException('Fixtures require explicit APP_ENV=test or development.');
        }
        return $this->em->wrapInTransaction(function () use ($imageDirectory): string {
            $expected = self::dataset();
            $actual = $this->snapshot();
            $empty = $actual === ['products' => [], 'users' => []];
            if (!$empty && $actual !== $expected) {
                throw new RuntimeException('Refusing to overwrite a nonempty or modified database. Use a separate empty demo/test database.');
            }
            $this->copyImages($imageDirectory);
            if (!$empty) {
                return 'already loaded';
            }
            $repository = $this->em->getRepository(Product::class);
            foreach ($expected['products'] as $row) {
                $product = new Product($row['code'], $row['name'], $row['price'], $row['purchasePrice'], $row['discount'], $row['description'], new DateTimeImmutable($row['createdAt']));
                foreach ($row['attributes'] as $attribute) {
                    new ProductAttribute($product, $attribute['key'], $attribute['value']);
                }
                foreach ($row['images'] as $image) {
                    new ProductImage($product, $image['url'], $image['path'], $image['position']);
                }
                $repository->save($product, false);
            }
            foreach ($expected['users'] as $user) {
                $this->em->getRepository(User::class)->save(new User($user['username'], $user['hash']), false);
            }
            return 'loaded';
        });
    }

    private function copyImages(string $directory): void
    {
        $target = rtrim($directory, '/\\') . '/fixtures';
        $sources = glob(dirname(__DIR__, 2) . '/fixtures/images/*.png');
        if ($sources === false || count($sources) !== 3) {
            throw new RuntimeException('Fixture image assets are missing.');
        }
        // Preflight all collisions before writing anything; never overwrite another file.
        foreach ($sources as $source) {
            $destination = $target . '/' . basename($source);
            if (file_exists($destination) && hash_file('sha256', $destination) !== hash_file('sha256', $source)) {
                throw new RuntimeException('Fixture image path already contains a different file.');
            }
        }
        if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
            throw new RuntimeException('Cannot create fixture image directory.');
        }
        foreach ($sources as $source) {
            $destination = $target . '/' . basename($source);
            if (!file_exists($destination) && !copy($source, $destination)) {
                throw new RuntimeException('Cannot copy fixture image.');
            }
        }
    }
}
