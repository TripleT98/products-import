<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\ApiException;
use App\Import\WorkbookException;
use App\Import\WorkbookLimits;
use App\Import\WorkbookReader;
use App\Queue\EnqueueFailed;
use App\Queue\ImportQueue;
use App\Queue\UploadStorage;
use App\Queue\UploadTooLarge;
use Closure;
use finfo;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

final readonly class ImportUploadService
{
    /** @param null|Closure(string, int): string $publishSaved Lazy, same private storage and database as this service. */
    public function __construct(private UploadStorage $storage, private WorkbookLimits $limits, private ?Closure $publishSaved = null)
    {
    }

    public static function fromEnvironment(): self
    {
        return new self(UploadStorage::fromEnvironment(), WorkbookLimits::fromEnvironment());
    }

    /** @return array{jobId: string, status: string} */
    public function accept(ServerRequestInterface $request, int $ownerId): array
    {
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        if ($type !== 'multipart/form-data') {
            throw new ApiException(415, 'UNSUPPORTED_FILE_TYPE', 'Требуется XLSX в multipart/form-data.');
        }
        $files = $request->getUploadedFiles();
        // PHP discards both $_FILES and $_POST when post_max_size is exceeded.
        $postMax = self::phpBytes((string) ini_get('post_max_size'));
        $length = $request->getHeaderLine('Content-Length');
        if ($files === [] && $postMax > 0 && ctype_digit($length) && (float) $length > $postMax) {
            throw self::tooLarge();
        }
        $body = $request->getParsedBody();
        if (array_keys($files) !== ['file'] || !$files['file'] instanceof UploadedFileInterface || ($body !== null && $body !== [])) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Требуется один файл в поле file без дополнительных полей.');
        }
        $upload = $files['file'];
        if (in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw self::tooLarge();
        }
        if (in_array($upload->getError(), [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true)) {
            throw self::unavailable();
        }
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Файл не загружен полностью.');
        }
        if (!preg_match('/\.xlsx$/iD', $upload->getClientFilename() ?? '')) {
            throw new ApiException(415, 'UNSUPPORTED_FILE_TYPE', 'Требуется XLSX.');
        }
        if ($upload->getSize() !== null && $upload->getSize() > $this->limits->maxFileBytes) {
            throw self::tooLarge();
        }
        $name = null;
        try {
            $name = $this->storage->copyStream($upload->getStream(), $this->limits->maxFileBytes);
            $path = $this->storage->readable($name);
            if (filesize($path) === 0) {
                throw new ApiException(422, 'INVALID_WORKBOOK', 'Некорректная книга XLSX.');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
            if (!in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], true)) {
                throw new ApiException(415, 'UNSUPPORTED_FILE_TYPE', 'Требуется XLSX.');
            }
            (new WorkbookReader($this->limits))->validateUpload($path);
            $id = $this->publishSaved === null ? (new ImportQueue())->publisher->enqueueSaved($name, $ownerId) : ($this->publishSaved)($name, $ownerId);
            return ['jobId' => $id, 'status' => 'queued'];
        } catch (EnqueueFailed) {
            // A durable job owns this copy. Keep its publication failure for inspection, never return false 202.
            throw self::unavailable();
        } catch (Throwable $error) {
            if ($name !== null) {
                $this->storage->removeOwnedCopy($name);
            }
            throw match (true) {
                $error instanceof ApiException => $error,
                $error instanceof UploadTooLarge => self::tooLarge(),
                $error instanceof WorkbookException => new ApiException(422, 'INVALID_WORKBOOK', 'Некорректная книга XLSX или превышены её ограничения.'),
                default => self::unavailable(),
            };
        }
    }

    private static function tooLarge(): ApiException
    {
        return new ApiException(413, 'UPLOAD_TOO_LARGE', 'Превышен лимит файла.');
    }

    private static function unavailable(): ApiException
    {
        return new ApiException(503, 'SERVICE_UNAVAILABLE', 'Сервис временно недоступен.');
    }

    private static function phpBytes(string $value): int
    {
        $last = strtolower(substr(trim($value), -1));
        return (int) $value * match ($last) {
            'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1
        };
    }
}
