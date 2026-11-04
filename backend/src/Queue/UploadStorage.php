<?php

declare(strict_types=1);

namespace App\Queue;

use App\Import\WorkbookLimits;
use RuntimeException;
use Psr\Http\Message\StreamInterface;

/** Trusted local CLI source only; HTTP validation belongs to T16. */
final readonly class UploadStorage
{
    public function __construct(private string $directory)
    {
    }

    public static function fromEnvironment(): self
    {
        return new self(getenv('UPLOAD_DIR') ?: '/var/app/uploads');
    }

    public function path(string $name): string
    {
        $root = realpath($this->directory);
        if ($root === false || !is_dir($root) || $root !== rtrim($this->directory, '/') || is_link($this->directory)
            || !preg_match('/^[a-f0-9]{32}\.xlsx$/D', $name)) {
            throw new RuntimeException('Private import storage is unavailable.');
        }
        return $root . '/' . $name;
    }

    /** Bounded copy of an untrusted PSR upload stream; client size/name/MIME are never used as paths. */
    public function copyStream(StreamInterface $source, int $maxBytes): string
    {
        $name = bin2hex(random_bytes(16)) . '.xlsx';
        $path = $this->path($name);
        $output = @fopen($path, 'xb');
        if ($output === false) {
            throw new RuntimeException('Private upload storage is unavailable.');
        }
        try {
            if (!@chmod($path, 0600)) {
                throw new RuntimeException('Private upload permissions could not be set.');
            }
            if ($source->isSeekable()) {
                $source->rewind();
            }
            $bytes = 0;
            while (!$source->eof()) {
                $chunk = self::readChunk($source, min(65536, $maxBytes + 1 - $bytes));
                $bytes += strlen($chunk);
                if ($bytes > $maxBytes) {
                    throw new UploadTooLarge();
                }
                if ($chunk !== '' && @fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Unable to save the upload.');
                }
            }
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        } finally {
            fclose($output);
        }
        return $name;
    }

    private static function readChunk(StreamInterface $source, int $bytes): string
    {
        $chunk = $source->read($bytes);
        if ($chunk === '' && !$source->eof()) {
            throw new RuntimeException('Upload stream stopped before completion.');
        }
        return $chunk;
    }

    public function localCopy(string $source): string
    {
        if (!is_file($source) || is_link($source) || !is_readable($source) || filesize($source) > WorkbookLimits::fromEnvironment()->maxFileBytes) {
            throw new RuntimeException('Local import source is unavailable or too large.');
        }
        $name = bin2hex(random_bytes(16)) . '.xlsx';
        $path = $this->path($name);
        $input = @fopen($source, 'rb');
        $output = @fopen($path, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
                @unlink($path);
            }
            throw new RuntimeException('Unable to copy the local import source.');
        }
        try {
            if (stream_copy_to_stream($input, $output, WorkbookLimits::fromEnvironment()->maxFileBytes + 1) === false
                || fstat($output)['size'] > WorkbookLimits::fromEnvironment()->maxFileBytes || !chmod($path, 0600)) {
                throw new RuntimeException('Unable to prepare the local import file.');
            }
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        } finally {
            fclose($input);
            fclose($output);
        }
        return $name;
    }

    public function removeOwnedCopy(string $name): void
    {
        @unlink($this->path($name));
    }

    public function readable(string $name): string
    {
        $path = $this->path($name);
        if (is_link($path) || !is_file($path) || !is_readable($path) || realpath($path) !== $path) {
            throw new RuntimeException('Private import file is unavailable.');
        }
        return $path;
    }
}
