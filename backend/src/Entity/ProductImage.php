<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductImageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductImageRepository::class)]
#[ORM\Table(name: 'product_images')]
#[ORM\UniqueConstraint(name: 'uniq_product_images_url', columns: ['product_id', 'url'])]
#[ORM\Index(name: 'idx_product_images_order', columns: ['product_id', 'position', 'id'])]
class ProductImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'images')]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(type: Types::STRING, length: 2048)]
    private string $url;

    #[ORM\Column(type: Types::TEXT)]
    private string $path;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position;

    public function __construct(Product $product, string $url, string $path, int $position = 0)
    {
        $this->product = $product;
        $this->url = $url;
        $this->path = $path;
        $this->position = $position;
        $product->addImage($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getProduct(): Product
    {
        return $this->product;
    }
    public function getUrl(): string
    {
        return $this->url;
    }
    public function getPath(): string
    {
        return $this->path;
    }
    public function getPosition(): int
    {
        return $this->position;
    }
    public function update(string $url, string $path, int $position): void
    {
        $this->url = $url;
        $this->path = $path;
        $this->position = $position;
    }
}
