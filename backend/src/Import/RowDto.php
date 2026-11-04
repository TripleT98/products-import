<?php

declare(strict_types=1);

namespace App\Import;

final readonly class RowDto
{
    /** @param list<array{key: string, value: string}> $attributes @param list<string> $imageCandidates */
    public function __construct(
        public int $rowNumber,
        public string $externalCode,
        public string $name,
        public string $description,
        public string $sale,
        public string $purchase,
        public string $price,
        public string $purchasePrice,
        public string $discount,
        public array $attributes,
        public array $imageCandidates,
    ) {
    }
}
