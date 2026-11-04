<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\ApplicationFactory;
use App\Entity\Product;
use App\Entity\ProductAttribute;
use App\Entity\ProductImage;
use App\Fixtures\CatalogFixtures;
use App\Infrastructure\DoctrineFactory;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class ProductApiTest extends StorageTestCase
{
    private App $app;
    private string $token;
    private string $images;
    private QueryRecorder $queries;

    protected function setUp(): void
    {
        $this->queries = new QueryRecorder();
        $config = new Configuration();
        $config->setMiddlewares([new Middleware($this->queries)]);
        $this->connection = DriverManager::getConnection(DoctrineFactory::connection()->getParams(), $config);
        $this->em = DoctrineFactory::entityManager($this->connection);
        $this->connection->beginTransaction();
        $this->images = sys_get_temp_dir() . '/t08-' . bin2hex(random_bytes(8));
        (new CatalogFixtures($this->em))->load($this->images);
        $this->em->clear();
        $this->app = ApplicationFactory::create($this->em);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/login')->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream('{"username":"fixture-user","password":"password"}'));
        $response = $this->app->handle($request);
        self::assertSame(200, $response->getStatusCode());
        $this->token = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['accessToken'];
        $this->queries->reset();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->images . '/fixtures/*.png') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->images . '/fixtures')) {
            rmdir($this->images . '/fixtures');
        }
        if (is_dir($this->images)) {
            rmdir($this->images);
        }
        parent::tearDown();
    }

    private function request(string $path, bool $authenticated = true): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        if ($authenticated) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->token);
        }
        $response = $this->app->handle($request);
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $response->getHeaderLine('X-Request-ID'));
        return $response;
    }

    private function data(string $path): array
    {
        $response = $this->request($path);
        self::assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testPagesHaveStableDistinctIdsAndCanonicalProductDtos(): void
    {
        $first = $this->data('/api/products');
        $second = $this->data('/api/products?page=2');
        self::assertSame(['page' => 1, 'limit' => 20, 'total' => 48, 'totalPages' => 3], $first['pagination']);
        self::assertCount(20, $first['items']);
        self::assertCount(20, $second['items']);
        $a = array_column($first['items'], 'id');
        $b = array_column($second['items'], 'id');
        self::assertSame([], array_intersect($a, $b));
        self::assertLessThan(min($b), max($a));
        self::assertSame($first, $this->data('/api/products'));
        $last = $this->data('/api/products?page=3');
        self::assertCount(8, $last['items']);
        $product = $first['items'][0];
        self::assertSame(['id', 'externalCode', 'name', 'description', 'price', 'purchasePrice', 'discount', 'createdAt', 'updatedAt'], array_keys($product));
        self::assertSame('FIX-0001', $product['externalCode']);
        self::assertSame('100.00', $product['price']);
        self::assertSame('75.00', $product['purchasePrice']);
        self::assertSame('25.00', $product['discount']);
        self::assertSame('2026-01-01T00:00:00Z', $product['createdAt']);
        self::assertArrayNotHasKey('attributes', $product);
        self::assertArrayNotHasKey('images', $product);
    }

    public function testCombinedCaseInsensitiveNameAndInclusivePriceBoundsAffectCount(): void
    {
        $data = $this->data('/api/products?name=' . urlencode('  фУтБоЛкА  ') . '&price_min=100&price_max=700&limit=2');
        self::assertSame(['page' => 1, 'limit' => 2, 'total' => 3, 'totalPages' => 2], $data['pagination']);
        self::assertSame(['100.00', '400.00'], array_column($data['items'], 'price'));
        $next = $this->data('/api/products?name=' . urlencode('ФУТБОЛКА') . '&price_min=100&price_max=700&page=2&limit=2');
        self::assertSame(['700.00'], array_column($next['items'], 'price'));
        $exact = $this->data('/api/products?price_min=000100.0&price_max=100.00');
        self::assertSame(1, $exact['pagination']['total']);
        self::assertSame('100.00', $exact['items'][0]['price']);
        self::assertSame(48, $this->data('/api/products?name=%20%20')['pagination']['total']);
    }

    public function testNameSearchEscapesWildcardsAndBindsUntrustedInput(): void
    {
        $repository = $this->em->getRepository(Product::class);
        $repository->save(new Product('literal', 'Literal 100%_! value', '0.01', '0.00', '-20.00'));
        $repository->save(new Product('wildcard-decoy', 'Literal 100ABC value', '0.02', '0.00', '0.00'));
        $this->em->clear();
        $literal = $this->data('/api/products?name=' . urlencode('%_!'));
        self::assertSame(1, $literal['pagination']['total']);
        self::assertSame('literal', $literal['items'][0]['externalCode']);
        $injection = $this->data('/api/products?name=' . urlencode("' OR 1=1 --"));
        self::assertSame([], $injection['items']);
        self::assertSame(0, $injection['pagination']['totalPages']);
    }

    public function testFarPageAndEmptyFilterHaveCorrectPagination(): void
    {
        $far = $this->data('/api/products?page=2147483647&limit=100');
        self::assertSame([], $far['items']);
        self::assertSame(['page' => 2147483647, 'limit' => 100, 'total' => 48, 'totalPages' => 1], $far['pagination']);
        $none = $this->data('/api/products?price_min=9999999999999999.99');
        self::assertSame([], $none['items']);
        self::assertSame(['page' => 1, 'limit' => 20, 'total' => 0, 'totalPages' => 0], $none['pagination']);
    }

    public function testDetailContainsOrderedLinksAndExactDecimalStrings(): void
    {
        $product = new Product('000001', 'Detail', '9999999999999999.99', '0.00', '-9999999999999999.99', 'Text <b>not HTML</b>');
        new ProductAttribute($product, 'Z', '0');
        new ProductAttribute($product, 'A', 'first');
        new ProductImage($product, 'https://example.invalid/late.png', '/images/late.png', 3);
        new ProductImage($product, 'https://example.invalid/early.png', '/images/early.png', 0);
        new ProductImage($product, 'https://example.invalid/tie.png', '/images/tie.png', 0);
        $this->em->getRepository(Product::class)->save($product);
        $id = $product->getId();
        $this->em->clear();
        $this->queries->reset();
        $detail = $this->data('/api/products/' . $id);
        self::assertSame('000001', $detail['externalCode']);
        self::assertSame('9999999999999999.99', $detail['price']);
        self::assertSame('0.00', $detail['purchasePrice']);
        self::assertSame('-9999999999999999.99', $detail['discount']);
        self::assertSame('Text <b>not HTML</b>', $detail['description']);
        self::assertSame(['A', 'Z'], array_column($detail['attributes'], 'key'));
        self::assertSame('0', $detail['attributes'][1]['value']);
        self::assertSame([0, 0, 3], array_column($detail['images'], 'position'));
        self::assertSame(['/images/early.png', '/images/tie.png', '/images/late.png'], array_column($detail['images'], 'path'));
        $selects = array_values(array_filter($this->queries->statements(), static fn (string $sql): bool => str_starts_with($sql, 'SELECT') && str_contains($sql, 'product')));
        self::assertCount(3, $selects, 'Detail uses one product query and two collection queries, no Cartesian fetch join.');
    }

    public function testMissingDetailAndEmptyCollections(): void
    {
        self::assertSame(404, $this->request('/api/products/2147483647')->getStatusCode());
        $product = new Product('empty', 'No relations', '1.00', '0.00', '100.00');
        $this->em->getRepository(Product::class)->save($product);
        $id = $product->getId();
        $this->em->clear();
        $detail = $this->data('/api/products/' . $id);
        self::assertSame([], $detail['attributes']);
        self::assertSame([], $detail['images']);
    }

    public function testBothRealProductRoutesRequireTokenBeforeValidation(): void
    {
        foreach (['/api/products', '/api/products/1', '/api/products/abc', '/api/products?page=bad'] as $path) {
            $response = $this->request($path, false);
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('UNAUTHORIZED', json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
        }
    }

    public function testActualSqlUsesBoundedSelectAndMatchingCountPredicates(): void
    {
        $this->queries->reset();
        $data = $this->data('/api/products?page=2&limit=5&name=' . urlencode('Футболка') . '&price_min=100&price_max=4000');
        self::assertCount(5, $data['items']);
        $selects = array_values(array_filter($this->queries->statements(), static fn (string $sql): bool => str_starts_with($sql, 'SELECT') && str_contains($sql, 'FROM products')));
        self::assertCount(2, $selects);
        self::assertStringContainsString('COUNT(', $selects[0]);
        self::assertStringContainsString('ORDER BY', $selects[1]);
        self::assertMatchesRegularExpression('/ORDER BY \w+\.id ASC LIMIT 5 OFFSET 5/', $selects[1]);
        $countWhere = explode(' WHERE ', $selects[0])[1];
        $pageWhere = explode(' ORDER BY ', explode(' WHERE ', $selects[1])[1])[0];
        self::assertSame($countWhere, $pageWhere);
        foreach ($this->queries->statements() as $sql) {
            self::assertStringNotContainsString('FROM product_attributes', $sql);
            self::assertStringNotContainsString('FROM product_images', $sql);
        }
        self::assertCount(5, $this->em->getUnitOfWork()->getIdentityMap()[Product::class], 'Only this page is hydrated, not the entire table.');
    }

    public function testInvalidStoredImagePathDoesNotExposeFilesystemPath(): void
    {
        $product = new Product('bad-path', 'Invalid image', '1.00', '0.00', '100.00');
        new ProductImage($product, 'https://example.invalid/a.png', '/private/secret-server-path/a.png');
        $this->em->getRepository(Product::class)->save($product);
        $id = $product->getId();
        $this->em->clear();
        $response = $this->request('/api/products/' . $id);
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('secret-server-path', (string) $response->getBody());
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueryAndPathReturn400(string $path): void
    {
        $response = $this->request($path);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('INVALID_REQUEST', json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
    }

    public static function invalidQueries(): array
    {
        $queries = ['page=', 'page=0', 'page=-1', 'page=1.5', 'page=1e2', 'page=2147483648', 'page=1&page=2', 'page=1&%70age=2', 'page[]=1', 'limit=', 'limit=101', 'limit=0', 'limit=2.0', 'limit=999999999999999999999', 'price_min=', 'price_max=', 'price_min=-1', 'price_min=1,2', 'price_max=1.234', 'price_min=1e3', 'price_min=10000000000000000', 'price_min=0.02&price_max=0.01', 'name=x&name=y', 'price_max=1&price_max=2', 'name=%00', 'name=%FF'];
        $paths = array_map(static fn (string $query): array => ['/api/products?' . $query], $queries);
        foreach (['0', '-1', 'abc', '1.5', '2147483648'] as $id) {
            $paths[] = ['/api/products/' . $id];
        }
        return $paths;
    }
}
