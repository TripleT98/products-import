<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Product;
use App\Entity\ProductImage;
use App\Infrastructure\DoctrineFactory;
use Closure;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class ProductWriter
{
    /** @param null|Closure(): EntityManagerInterface $entityManagers Factory returns a fresh, owned session. */
    public function __construct(private ?Closure $entityManagers = null, private int $maxAttempts = 3)
    {
        if ($maxAttempts < 1 || $maxAttempts > 3) {
            throw new InvalidArgumentException('Product writes allow one to three attempts.');
        }
    }

    /** @param list<PreparedImage> $preparedImages */
    public function write(RowDto $row, array $preparedImages, ?ProductWriteCheckpoint $checkpoint = null): ProductWriteResult
    {
        $prepared = [];
        foreach ($preparedImages as $image) {
            if (!in_array($image->url, $row->imageCandidates, true) || isset($prepared['url:' . $image->url])) {
                throw new InvalidArgumentException('Prepared image URLs must be unique and present in the row candidates.');
            }
            $prepared['url:' . $image->url] = $image;
        }
        for ($attempt = 1; $attempt <= $this->maxAttempts; ++$attempt) {
            $em = $this->entityManagers === null ? DoctrineFactory::entityManager() : ($this->entityManagers)();
            $connection = $em->getConnection();
            if (!$em->isOpen() || $connection->isTransactionActive()) {
                // Do not roll back/close a caller's existing transaction on contract misuse.
                throw new LogicException('ProductWriter requires a fresh open EntityManager without an active transaction.');
            }
            try {
                $written = $em->wrapInTransaction(function (EntityManagerInterface $em) use ($row, $prepared, $checkpoint, $attempt): ProductWriteResult {
                    $previous = $checkpoint?->begin($em, $row);
                    if ($previous !== null) {
                        return $previous;
                    }
                    $products = $em->getRepository(Product::class);
                    $product = $products->findByExternalCodeForUpdate($row->externalCode);
                    $created = $product === null;
                    $images = $em->getRepository(ProductImage::class);
                    $oldPaths = $product === null ? [] : $images->pathsForProduct($product);
                    $images->lockPaths(array_merge($oldPaths, array_values(array_map(static fn (PreparedImage $image): string => $image->path, $prepared))));
                    if ($product === null) {
                        $product = new Product($row->externalCode, $row->name, $row->price, $row->purchasePrice, $row->discount, $row->description);
                    } else {
                        $product->updateDetails($row->name, $row->description, $row->price, $row->purchasePrice, $row->discount);
                    }
                    $products->save($product, false);
                    $products->replaceAttributes($product, $row->attributes);
                    // This flush is still inside the outer transaction. Image failure rolls it all back.
                    $em->flush();
                    $products->synchronizeImages($product, $row->imageCandidates, $prepared);
                    $em->flush();
                    $result = new ProductWriteResult($product->getId(), $created, $attempt, array_values(array_diff($oldPaths, $images->pathsForProduct($product))));
                    $checkpoint?->record($em, $result);
                    return $result;
                });
                return $written;
            } catch (Throwable $exception) {
                if ($attempt === $this->maxAttempts || (!$exception instanceof UniqueConstraintViolationException && !$exception instanceof RetryableException)) {
                    throw new ProductWriteException($attempt, $exception);
                }
                // wrapInTransaction rolled back/closed the failed session. Retry the whole row with a new one.
            } finally {
                $em->close();
                $connection->close();
            }
        }
        throw new LogicException('Unreachable product write state.');
    }
}
