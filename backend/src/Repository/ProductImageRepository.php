<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductImage;
use App\Entity\Product;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<ProductImage> */
class ProductImageRepository extends EntityRepository
{
    /** @return list<string> */
    public function pathsForProduct(Product $product): array
    {
        return array_values(array_unique(array_map(static fn (ProductImage $image): string => $image->getPath(), $product->getImages()->toArray())));
    }

    /** @param list<string> $paths */
    public function lockPaths(array $paths): void
    {
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $this->getEntityManager()->getConnection()->executeQuery(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['product-import:image:' . $path]
            )->free();
        }
    }

    public function isPathReferenced(string $path): bool
    {
        return (int) $this->createQueryBuilder('i')->select('COUNT(i.id)')->where('i.path = :path')
            ->setParameter('path', $path)->getQuery()->getSingleScalarResult() > 0;
    }

    public function save(ProductImage $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ProductImage $entity, bool $flush = true): void
    {
        $entity->getProduct()->removeImage($entity);
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
