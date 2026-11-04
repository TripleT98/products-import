<?php

declare(strict_types=1);

namespace App\Dto;

use JsonSerializable;

final readonly class ProductPage implements JsonSerializable
{
    /** @param list<ProductView> $items */
    public function __construct(private array $items, private ProductFilter $filter, private int $total)
    {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['items' => $this->items, 'pagination' => [
            'page' => $this->filter->page, 'limit' => $this->filter->limit, 'total' => $this->total,
            'totalPages' => intdiv($this->total + $this->filter->limit - 1, $this->filter->limit),
        ]];
    }
}
