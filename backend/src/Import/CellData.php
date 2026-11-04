<?php

declare(strict_types=1);

namespace App\Import;

final readonly class CellData
{
    public function __construct(public string|bool|null $value = null, public string $type = 'null', public string $numberFormat = 'General')
    {
    }

    public function isEmpty(): bool
    {
        return $this->type !== 'f' && ($this->value === null || (is_string($this->value) && preg_match('/^\s*$/uD', $this->value) === 1));
    }
}
