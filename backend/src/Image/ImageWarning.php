<?php

declare(strict_types=1);

namespace App\Image;

final readonly class ImageWarning
{
    public string $message;
    public string $severity;

    public function __construct(public string $url, public ImageWarningCode $code)
    {
        $this->message = $code->message();
        $this->severity = 'warning';
    }
}
