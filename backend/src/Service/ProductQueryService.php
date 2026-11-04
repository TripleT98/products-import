<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ProductFilter;
use App\Dto\ProductPage;
use App\Dto\ProductView;
use App\Entity\Product;
use App\Http\ApiException;
use App\Repository\ProductRepository;

final class ProductQueryService
{
    public function __construct(private ProductRepository $products)
    {
    }

    public function page(ProductFilter $filter): ProductPage
    {
        $result = $this->products->searchPage($filter);
        return new ProductPage(array_map(static fn (Product $product): ProductView => ProductView::fromProduct($product), $result['items']), $filter, $result['total']);
    }

    public function detail(int $id): ProductView
    {
        $product = $this->products->find($id);
        if ($product === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Ресурс не найден.');
        }
        return ProductView::fromProduct($product, true);
    }
}
