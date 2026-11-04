<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
#[ORM\UniqueConstraint(name: 'uniq_products_external_code', columns: ['external_code'])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'external_code', type: Types::STRING, length: 255)]
    private string $externalCode;

    #[ORM\Column(type: Types::TEXT)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 2)]
    private string $price;

    #[ORM\Column(name: 'purchase_price', type: Types::DECIMAL, precision: 18, scale: 2)]
    private string $purchasePrice;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 2)]
    private string $discount;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<int, ProductAttribute> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductAttribute::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['key' => 'ASC'])]
    private Collection $attributes;

    /** @var Collection<int, ProductImage> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductImage::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $images;

    public function __construct(string $externalCode, string $name, string $price, string $purchasePrice, string $discount, string $description = '', ?DateTimeImmutable $createdAt = null)
    {
        $this->externalCode = $externalCode;
        $this->name = $name;
        $this->description = $description;
        $this->price = $price;
        $this->purchasePrice = $purchasePrice;
        $this->discount = $discount;
        $this->createdAt = $createdAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
        $this->attributes = new ArrayCollection();
        $this->images = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getExternalCode(): string
    {
        return $this->externalCode;
    }
    public function getName(): string
    {
        return $this->name;
    }
    public function getDescription(): string
    {
        return $this->description;
    }
    public function getPrice(): string
    {
        return $this->price;
    }
    public function getPurchasePrice(): string
    {
        return $this->purchasePrice;
    }
    public function getDiscount(): string
    {
        return $this->discount;
    }
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, ProductAttribute> */
    public function getAttributes(): Collection
    {
        return $this->attributes;
    }

    /** @return Collection<int, ProductImage> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addAttribute(ProductAttribute $attribute): void
    {
        if ($attribute->getProduct() !== $this) {
            throw new InvalidArgumentException('Attribute belongs to another product.');
        }
        if (!$this->attributes->contains($attribute)) {
            $this->attributes->add($attribute);
        }
    }

    public function addImage(ProductImage $image): void
    {
        if ($image->getProduct() !== $this) {
            throw new InvalidArgumentException('Image belongs to another product.');
        }
        if (!$this->images->contains($image)) {
            $this->images->add($image);
        }
    }
    public function updateDetails(string $name, string $description, string $price, string $purchasePrice, string $discount): void
    {
        $this->name = $name;
        $this->description = $description;
        $this->price = $price;
        $this->purchasePrice = $purchasePrice;
        $this->discount = $discount;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function removeAttribute(ProductAttribute $attribute): void
    {
        $this->attributes->removeElement($attribute);
    }

    public function removeImage(ProductImage $image): void
    {
        $this->images->removeElement($image);
    }
}
