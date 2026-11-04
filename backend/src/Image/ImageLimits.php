<?php

declare(strict_types=1);

namespace App\Image;

use InvalidArgumentException;

final readonly class ImageLimits
{
    public function __construct(
        public int $maxBytes = 5242880,
        public int $connectTimeoutMs = 3000,
        public int $totalTimeoutMs = 10000,
        public int $maxRedirects = 3,
        public int $maxPixels = 12000000,
        public int $maxDimension = 10000,
        public int $maxHeaderBytes = 16384,
    ) {
        foreach (get_object_vars($this) as $property => $value) {
            if ($value < ($property === 'maxRedirects' ? 0 : 1) || $value > 100000000) {
                throw new InvalidArgumentException('Invalid image limits.');
            }
        }
        if ($maxRedirects > 10) {
            throw new InvalidArgumentException('At most ten redirects are supported.');
        }
    }

    public static function fromEnvironment(): self
    {
        $defaults = new self();
        $values = [];
        foreach (['maxBytes' => 'IMAGE_MAX_BYTES', 'connectTimeoutMs' => 'IMAGE_CONNECT_TIMEOUT_MS',
            'totalTimeoutMs' => 'IMAGE_TOTAL_TIMEOUT_MS', 'maxRedirects' => 'IMAGE_MAX_REDIRECTS',
            'maxPixels' => 'IMAGE_MAX_PIXELS', 'maxDimension' => 'IMAGE_MAX_DIMENSION',
            'maxHeaderBytes' => 'IMAGE_MAX_HEADER_BYTES'] as $property => $variable) {
            $raw = getenv($variable);
            if ($raw !== false && (!ctype_digit($raw) || strlen($raw) > 9)) {
                throw new InvalidArgumentException('Invalid image limit configuration.');
            }
            $values[$property] = $raw === false ? $defaults->$property : (int) $raw;
        }
        return new self(...$values);
    }
}
