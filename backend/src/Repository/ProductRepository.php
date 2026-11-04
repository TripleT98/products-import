<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\ProductAttribute;
use App\Entity\ProductImage;
use App\Import\PreparedImage;
use Doctrine\DBAL\LockMode;
use InvalidArgumentException;
use App\Dto\ProductFilter;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<Product> */
class ProductRepository extends EntityRepository
{
    public function findByExternalCode(string $externalCode): ?Product
    {
        return $this->findOneBy(['externalCode' => $externalCode]);
    }
    public function save(Product $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Product $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
    /** @return array{items: list<Product>, total: int} */
    public function searchPage(ProductFilter $filter): array
    {
        $query = $this->createQueryBuilder('p');
        if ($filter->name !== '') {
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filter->name) . '%';
            $query->andWhere("LOWER(p.name) LIKE LOWER(:name) ESCAPE '!'")->setParameter('name', $pattern);
        }
        if ($filter->priceMin !== null) {
            $query->andWhere('p.price >= :min')->setParameter('min', $filter->priceMin);
        }
        if ($filter->priceMax !== null) {
            $query->andWhere('p.price <= :max')->setParameter('max', $filter->priceMax);
        }
        // Clone before pagination: identical predicates, no association joins and no N+1 in list DTO.
        $total = (int) (clone $query)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
        $items = $query->orderBy('p.id', 'ASC')
            ->setFirstResult(($filter->page - 1) * $filter->limit)
            ->setMaxResults($filter->limit)->getQuery()->getResult();
        return ['items' => $items, 'total' => $total];
    }
    public function findByExternalCodeForUpdate(string $code): ?Product
    {
        return $this->createQueryBuilder('p')->where('p.externalCode = :code')->setParameter('code', $code)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
    }

    /** @param list<array{key: string, value: string}> $attributes */
    public function replaceAttributes(Product $product, array $attributes): void
    {
        $wanted = [];
        foreach ($attributes as $attribute) {
            $key = 'key:' . $attribute['key'];
            if (isset($wanted[$key])) {
                throw new InvalidArgumentException('Validated attributes must have unique keys.');
            }
            $wanted[$key] = $attribute;
        }
        foreach ($product->getAttributes()->toArray() as $existing) {
            $key = 'key:' . $existing->getKey();
            if (isset($wanted[$key])) {
                $existing->update($existing->getKey(), $wanted[$key]['value']);
                unset($wanted[$key]);
            } else {
                $product->removeAttribute($existing);
            }
        }
        foreach ($wanted as $attribute) {
            new ProductAttribute($product, $attribute['key'], $attribute['value']);
        }
    }

    /** @param list<string> $candidates @param array<string, PreparedImage> $prepared */
    public function synchronizeImages(Product $product, array $candidates, array $prepared): void
    {
        $wanted = [];
        foreach ($candidates as $position => $url) {
            $key = 'url:' . $url;
            if (!isset($wanted[$key])) {
                $wanted[$key] = ['url' => $url, 'position' => $position];
            }
        }
        foreach ($product->getImages()->toArray() as $existing) {
            $key = 'url:' . $existing->getUrl();
            if (!isset($wanted[$key])) {
                $product->removeImage($existing);
                continue;
            }
            // A current URL without a new prepared file keeps its previous working copy.
            $path = isset($prepared[$key]) ? $prepared[$key]->path : $existing->getPath();
            $existing->update($existing->getUrl(), $path, $wanted[$key]['position']);
            unset($wanted[$key]);
        }
        foreach ($wanted as $key => $image) {
            if (isset($prepared[$key])) {
                new ProductImage($product, $image['url'], $prepared[$key]->path, $image['position']);
            }
        }
    }
}
