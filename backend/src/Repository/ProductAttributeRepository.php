<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductAttribute;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<ProductAttribute> */
class ProductAttributeRepository extends EntityRepository
{
    public function save(ProductAttribute $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ProductAttribute $entity, bool $flush = true): void
    {
        $entity->getProduct()->removeAttribute($entity);
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
