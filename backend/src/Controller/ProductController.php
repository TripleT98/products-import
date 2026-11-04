<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ProductFilter;
use App\Service\ProductQueryService;
use JsonSerializable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ProductController
{
    public function __construct(private ProductQueryService $products)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, $this->products->page(ProductFilter::fromQueryString($request->getUri()->getQuery())));
    }

    /** @param array{id: string} $args */
    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->json($response, $this->products->detail(ProductFilter::id($args['id'])));
    }

    private function json(ResponseInterface $response, JsonSerializable $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
