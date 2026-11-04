<?php

declare(strict_types=1);

namespace App\Image;

use App\Entity\ProductImage;
use App\Infrastructure\DoctrineFactory;
use Closure;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Throwable;

final class ImageFileCleaner
{
    /** @param null|Closure(): EntityManagerInterface $entityManagers Same database as ProductWriter; fresh owned sessions. */
    public function __construct(private ImageStorage $storage, private ?Closure $entityManagers = null)
    {
    }

    /** @param list<string> $paths @return list<string> Paths whose cleanup could not be verified/completed. */
    public function clean(array $paths): array
    {
        $failed = [];
        foreach (array_unique($paths) as $path) {
            $em = null;
            $owned = false;
            try {
                $em = $this->entityManagers === null ? DoctrineFactory::entityManager() : ($this->entityManagers)();
                $connection = $em->getConnection();
                if (!$em->isOpen() || $connection->isTransactionActive()) {
                    throw new LogicException('ImageFileCleaner requires a fresh session without an active transaction.');
                }
                $owned = true;
                // A new snapshot after a waited path lock must see the writer's committed references.
                $connection->setTransactionIsolation(TransactionIsolationLevel::READ_COMMITTED);
                $em->wrapInTransaction(function (EntityManagerInterface $em) use ($path): void {
                    $images = $em->getRepository(ProductImage::class);
                    $images->lockPaths([$path]);
                    if (!$images->isPathReferenced($path) && !$this->storage->removeUnreferenced($path)) {
                        throw new ImageDownloadException(ImageWarningCode::StorageFailed);
                    }
                });
            } catch (Throwable) {
                // Never delete when reference verification fails; never turn a successful product commit into a retry.
                $failed[] = $path;
            } finally {
                if ($owned && $em !== null) {
                    $em->close();
                    $em->getConnection()->close();
                }
            }
        }
        return $failed;
    }
}
