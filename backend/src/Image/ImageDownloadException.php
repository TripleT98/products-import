<?php

declare(strict_types=1);

namespace App\Image;

use RuntimeException;

/** Internal control flow; public warnings contain no transport/server details. */
final class ImageDownloadException extends RuntimeException
{
    public function __construct(public readonly ImageWarningCode $issue)
    {
        parent::__construct($issue->message());
    }
}
