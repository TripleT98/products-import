<?php

declare(strict_types=1);

namespace App\Queue;

use App\Import\Job\ImportFatalCode;
use App\Import\Job\ImportIssueData;
use App\Import\Job\ImportJobService;
use App\Import\Job\ImportStateException;
use App\Import\RowImportService;
use App\Import\RowValidationService;
use App\Import\WorkbookException;
use App\Import\WorkbookReader;
use App\Import\WorkbookLimits;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Throwable;

final readonly class ImportHandler
{
    public function __construct(private ImportJobService $jobs, private UploadStorage $uploads, private RowImportService $rows, private int $leaseSeconds = 60)
    {
    }

    public function __invoke(ImportMessage $message): void
    {
        $lease = $this->jobs->claim($message->jobId);
        if ($lease === null) {
            if (in_array($this->jobs->dispatchStatus($message->jobId), ['queued', 'processing'], true)) {
                throw new RecoverableMessageHandlingException('Import already has an active owner.', retryDelay: ($this->leaseSeconds + 1) * 1000, forceRetry: false);
            }
            return; // Missing or terminal task: an old delivery is a no-op.
        }
        try {
            $fileName = $this->jobs->fileForLease($lease);
            try {
                $path = $this->uploads->readable($fileName);
            } catch (\RuntimeException $error) {
                if ($error instanceof ImportStateException) {
                    throw $error;
                }
                $this->jobs->fail($lease, ImportFatalCode::FileUnavailable);
                return;
            }
            $this->jobs->heartbeat($lease);
            $book = (new WorkbookReader(WorkbookLimits::fromEnvironment()))->open($path);
            $this->jobs->heartbeat($lease);
            $this->jobs->setTotal($lease, $book->dataRowCount);
            // Validation is replayed from the header to restore seen-code rules; only writes use the ledger skip.
            foreach ((new RowValidationService())->validate($book->rows()) as $row) {
                $this->jobs->heartbeat($lease);
                if ($this->jobs->rowResult($lease, $row->rowNumber) !== null) {
                    continue;
                }
                $validationIssues = array_map(ImportIssueData::fromRowIssue(...), $row->issues);
                if ($row->dto === null) {
                    $this->jobs->recordFailure($lease, $row->rowNumber, $validationIssues);
                    continue;
                }
                $result = $this->rows->import($row->dto, $this->jobs->checkpoint($lease, $row->rowNumber, $validationIssues));
                if ($result->write === null) {
                    $this->jobs->recordFailure($lease, $row->rowNumber, array_merge($validationIssues, array_map(ImportIssueData::fromRowIssue(...), $result->issues)));
                }
                $cleanup = array_values(array_filter($result->issues, static fn ($issue): bool => $issue->code === 'IMAGE_CLEANUP_FAILED'));
                $this->jobs->noteCleanup($lease, $row->rowNumber, array_map(ImportIssueData::fromRowIssue(...), $cleanup), $result->cleanupPendingPaths);
            }
            $this->jobs->complete($lease);
        } catch (WorkbookException) {
            $this->jobs->fail($lease, ImportFatalCode::InvalidWorkbook);
        } catch (Throwable $error) {
            try {
                $this->jobs->releaseForRetry($lease);
            } catch (Throwable) { /* DB may be down; the lease will expire. */
            }
            throw new RecoverableMessageHandlingException(
                'Import processing could not complete.',
                previous: $error,
                retryDelay: $error instanceof ImportStateException ? ($this->leaseSeconds + 1) * 1000 : null,
                forceRetry: false
            );
        }
    }

    public function exhausted(ImportMessage $message): void
    {
        try {
            $lease = $this->jobs->claim($message->jobId);
            if ($lease !== null) {
                $this->jobs->fail($lease, ImportFatalCode::SystemFailure);
            }
        } catch (Throwable) {
            // The failed transport retains the job ID even if its database is temporarily unavailable.
        }
    }
}
