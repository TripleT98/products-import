<?php

declare(strict_types=1);

namespace App\Import;

use App\Image\ImageDownloader;
use App\Image\ImageFileCleaner;
use App\Import\Job\ImportStateException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\RetryableException;
use Throwable;

/** One validated row, optionally with a durable transaction checkpoint. No queue or HTTP orchestration. */
final class RowImportService
{
    public function __construct(private ImageDownloader $downloader, private ProductWriter $writer, private ImageFileCleaner $cleaner)
    {
    }

    public function import(RowDto $row, ?ProductWriteCheckpoint $checkpoint = null): RowImportResult
    {
        $prepared = $issues = [];
        $write = null;
        try {
            foreach (array_unique($row->imageCandidates) as $url) {
                $checkpoint?->heartbeat();
                $download = $this->downloader->download($url);
                if ($download->image !== null) {
                    $prepared[] = $download->image;
                } elseif ($download->warning !== null) {
                    $issues[] = new RowIssue(
                        'warning',
                        $download->warning->code->value,
                        $download->warning->message,
                        $row->rowNumber,
                        $row->externalCode,
                        'images'
                    );
                }
            }
            // No network operation is inside the writer transaction, including its bounded retries.
            $checkpoint?->heartbeat();
            $write = $this->writer->write($row, $prepared, $checkpoint?->withIssues($issues));
        } catch (ProductWriteException $error) {
            $cause = $error->getPrevious();
            // Infrastructure failures and lost fencing rights must retry the message,
            // never become a permanent failed row in the immutable ledger.
            if ($cause instanceof ImportStateException || $cause instanceof ConnectionException || $cause instanceof RetryableException
                || ($cause instanceof DriverException && in_array(substr($cause->getSQLState() ?? '', 0, 2), ['08', '40', '53', '57', '58'], true))) {
                $this->cleaner->clean(array_map(static fn (PreparedImage $image): string => $image->path, $prepared));
                throw $cause;
            }
            $issues[] = new RowIssue(
                'error',
                ProductWriteException::ISSUE_CODE,
                $error->getMessage(),
                $row->rowNumber,
                $row->externalCode,
                'product'
            );
        } catch (Throwable $error) {
            $this->cleaner->clean(array_map(static fn (PreparedImage $image): string => $image->path, $prepared));
            throw $error;
        }
        $paths = array_merge(array_map(static fn (PreparedImage $image): string => $image->path, $prepared), $write->obsoleteImagePaths ?? []);
        $pending = $this->cleaner->clean($paths);
        foreach ($pending as $unusedPath) {
            $issues[] = new RowIssue(
                'warning',
                'IMAGE_CLEANUP_FAILED',
                'Не удалось удалить неиспользуемый файл изображения.',
                $row->rowNumber,
                $row->externalCode,
                'images'
            );
        }
        return new RowImportResult($write, $issues, $pending);
    }
}
