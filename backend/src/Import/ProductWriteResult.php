<?php

declare(strict_types=1);

namespace App\Import;

final readonly class ProductWriteResult
{
    /** @param list<string> $obsoleteImagePaths */
    public function __construct(public int $productId, public bool $created, public int $attempts, public array $obsoleteImagePaths = [])
    {
    }
}
