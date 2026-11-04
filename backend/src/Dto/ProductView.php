<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Product;
use DateTimeZone;
use JsonSerializable;
use LogicException;

final readonly class ProductView implements JsonSerializable
{
    /** @param array<string, mixed> $data */
    private function __construct(private array $data)
    {
    }

    public static function fromProduct(Product $product, bool $detail = false): self
    {
        $utc = new DateTimeZone('UTC');
        $data = [
            'id' => $product->getId(), 'externalCode' => $product->getExternalCode(),
            'name' => $product->getName(), 'description' => $product->getDescription(),
            'price' => $product->getPrice(), 'purchasePrice' => $product->getPurchasePrice(),
            'discount' => $product->getDiscount() === '-0.00' ? '0.00' : $product->getDiscount(),
            'createdAt' => $product->getCreatedAt()->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            'updatedAt' => $product->getUpdatedAt()->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
        ];
        if ($detail) {
            $data['attributes'] = [];
            foreach ($product->getAttributes() as $attribute) {
                $data['attributes'][] = ['id' => $attribute->getId(), 'key' => $attribute->getKey(), 'value' => $attribute->getValue()];
            }
            $data['images'] = [];
            foreach ($product->getImages() as $image) {
                $path = $image->getPath();
                $segments = explode('/', rawurldecode($path));
                if (!preg_match('~^/images/[^\s]+$~uD', $path) || str_contains(rawurldecode($path), '\\') || in_array('..', $segments, true) || in_array('.', $segments, true)) {
                    throw new LogicException('Invalid public image path.');
                }
                $data['images'][] = ['id' => $image->getId(), 'url' => $image->getUrl(), 'path' => $image->getPath(), 'position' => $image->getPosition()];
            }
        }
        return new self($data);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
