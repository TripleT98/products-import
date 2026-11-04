<?php

declare(strict_types=1);

namespace App\Image;

use App\Import\PreparedImage;

final readonly class ImageDownloadResult
{
    private function __construct(public ?PreparedImage $image, public ?ImageWarning $warning)
    {
    }

    public static function success(PreparedImage $image): self
    {
        return new self($image, null);
    }

    public static function failure(string $url, ImageWarningCode $code): self
    {
        return new self(null, new ImageWarning($url, $code));
    }
}
