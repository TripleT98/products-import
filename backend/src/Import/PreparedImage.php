<?php

declare(strict_types=1);

namespace App\Import;

use InvalidArgumentException;

/** Descriptor of an already prepared public file. No filesystem or network access. */
final readonly class PreparedImage
{
    public function __construct(public string $url, public string $path)
    {
        $decoded = rawurldecode($path);
        if (!preg_match('~^/images/[^\s]+$~uD', $path) || str_contains($decoded, '\\')
            || in_array('..', explode('/', $decoded), true) || in_array('.', explode('/', $decoded), true)
            || preg_match('/[\x00-\x1f\x7f?#]/', $decoded)) {
            throw new InvalidArgumentException('Prepared image requires a public /images/ path.');
        }
    }
}
