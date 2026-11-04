<?php

declare(strict_types=1);

namespace App\Image;

use App\Import\PreparedImage;
use GdImage;

/** Owns only preparation artifacts, never removes previously published/shared images. */
final class ImageStorage
{
    public function __construct(private string $directory)
    {
    }

    public static function fromEnvironment(): self
    {
        return new self(getenv('IMAGE_DIR') ?: '/var/app/images');
    }

    /** Caller must hold the database path lock and have checked all image references. Missing files are a no-op. */
    public function removeUnreferenced(string $publicPath): bool
    {
        $root = realpath($this->directory);
        if ($root === false || $root !== rtrim($this->directory, '/') || is_link($this->directory)
            || !preg_match('~^/images/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.(?:png|jpe?g|gif|webp)$~D', $publicPath)) {
            return false;
        }
        $file = $root . '/' . substr($publicPath, strlen('/images/'));
        if (is_link($file)) {
            return false;
        }
        if (!file_exists($file)) {
            return true;
        }
        if (!is_file($file) || realpath($file) !== $file) {
            return false;
        }
        return @unlink($file);
    }

    public function save(string $url, GdImage $image, int $maxBytes): PreparedImage
    {
        $root = realpath($this->directory);
        if ($root === false || $root !== rtrim($this->directory, '/') || is_link($this->directory) || !is_dir($root)) {
            throw new ImageDownloadException(ImageWarningCode::StorageFailed);
        }
        $staging = $root . '/.staging';
        if ((!is_dir($staging) && !@mkdir($staging, 0700)) || is_link($staging) || realpath($staging) !== $staging) {
            throw new ImageDownloadException(ImageWarningCode::StorageFailed);
        }
        $temporary = @tempnam($staging, 'img-');
        if ($temporary === false) {
            throw new ImageDownloadException(ImageWarningCode::StorageFailed);
        }
        try {
            // Decode/re-encode strips trailing scripts/metadata and never uses remote names/extensions.
            if (!@imagepng($image, $temporary) || !@chmod($temporary, 0644)) {
                throw new ImageDownloadException(ImageWarningCode::StorageFailed);
            }
            if (filesize($temporary) > $maxBytes) {
                throw new ImageDownloadException(ImageWarningCode::TooLarge);
            }
            $name = bin2hex(random_bytes(16)) . '.png';
            // Same-filesystem hard link publishes a complete file atomically, without overwriting any name.
            if (!@link($temporary, $root . '/' . $name)) {
                throw new ImageDownloadException(ImageWarningCode::StorageFailed);
            }
            return new PreparedImage($url, '/images/' . $name);
        } finally {
            @unlink($temporary);
        }
    }
}
