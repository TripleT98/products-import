<?php

declare(strict_types=1);

namespace App\Import;

use JsonSerializable;

final readonly class HeaderMap implements JsonSerializable
{
    /** @param array<string, string> $fields @param array<string, string> $attributes @param array<string, string> $ignored */
    public function __construct(public array $fields, public array $attributes, public array $ignored)
    {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['fields' => $this->fields, 'attributes' => $this->attributes, 'ignored' => $this->ignored];
    }
}
