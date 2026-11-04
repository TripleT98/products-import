<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportRowResult;
use App\Entity\ImportJob;
use App\Entity\ImportIssue;
use App\Import\Job\ImportIssueData;
use App\Import\Job\ImportLease;
use App\Import\Job\ImportStateException;
use App\Import\ProductWriteResult;
use Doctrine\ORM\EntityRepository;
use InvalidArgumentException;

/** @extends EntityRepository<ImportRowResult> */
class ImportRowResultRepository extends EntityRepository
{
    public function findResult(string $id, int $rowNumber): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative('SELECT * FROM import_row_results WHERE job_id=? AND row_number=?', [$id, $rowNumber]);
        return $row === false ? null : $row;
    }

    public function receipt(array $row): ProductWriteResult
    {
        if ($row['outcome'] === 'failed') {
            throw new ImportStateException('A failed row result is already final; skip it during resume.');
        }
        return new ProductWriteResult((int) $row['product_id'], $row['outcome'] === 'created', (int) $row['attempts'], json_decode($row['obsolete_paths'], true, flags: JSON_THROW_ON_ERROR));
    }

    /** @param list<ImportIssueData> $issues */
    public function record(ImportLease $lease, int $rowNumber, ?ProductWriteResult $result, array $issues): bool
    {
        $jobs = $this->getEntityManager()->getRepository(ImportJob::class);
        $job = $jobs->lockLease($lease);
        if ($this->findResult($lease->jobId, $rowNumber) !== null) {
            return false;
        }
        if ($rowNumber <= (int) $job['last_row_number'] || ($job['total_rows'] !== null && (int) $job['processed_rows'] >= (int) $job['total_rows'])) {
            throw new ImportStateException('Rows must append in physical order within the known total.');
        }
        $hasError = false;
        foreach ($issues as $issue) {
            if ($issue->rowNumber !== $rowNumber || ($result !== null && $issue->severity !== 'warning')) {
                throw new InvalidArgumentException('Issues must match their row and successful rows may only have warnings.');
            }
            $hasError = $hasError || $issue->severity === 'error';
        }
        if ($rowNumber < 2 || ($result === null && !$hasError)) {
            throw new InvalidArgumentException('A failed row requires an error issue.');
        }
        $outcome = $result === null ? 'failed' : ($result->created ? 'created' : 'updated');
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO import_row_results
            (job_id,row_number,outcome,product_id,attempts,obsolete_paths,cleanup_pending_paths,created_at) VALUES (?,?,?,?,?,?,?,clock_timestamp())',
            [$lease->jobId, $rowNumber, $outcome, $result?->productId, $result->attempts ?? 0,
                json_encode($result->obsoleteImagePaths ?? [], JSON_THROW_ON_ERROR), '[]']
        );
        $warnings = $this->getEntityManager()->getRepository(ImportIssue::class)->insertIssues($lease->jobId, $issues);
        $jobs->addResultCounters($lease->jobId, $outcome, $rowNumber, $warnings);
        return true;
    }

    /** @param list<ImportIssueData> $issues @param list<string> $paths */
    public function noteCleanup(ImportLease $lease, int $rowNumber, array $issues, array $paths): void
    {
        $jobs = $this->getEntityManager()->getRepository(ImportJob::class);
        $jobs->lockLease($lease);
        if ($this->findResult($lease->jobId, $rowNumber) === null) {
            throw new ImportStateException('Cleanup needs a durable row result.');
        }
        foreach ($issues as $issue) {
            if ($issue->severity !== 'warning' || $issue->rowNumber !== $rowNumber) {
                throw new InvalidArgumentException('Only row warnings can be appended after commit.');
            }
        }
        foreach ($paths as $path) {
            if (!preg_match('~^/images/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.(?:png|jpe?g|gif|webp)$~D', $path)) {
                throw new InvalidArgumentException('Pending cleanup requires safe public image paths.');
            }
        }
        $warnings = $this->getEntityManager()->getRepository(ImportIssue::class)->insertIssues($lease->jobId, $issues);
        $jobs->addWarnings($lease->jobId, $warnings);
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE import_row_results SET cleanup_pending_paths=? WHERE job_id=? AND row_number=?',
            [json_encode(array_values(array_unique($paths)), JSON_THROW_ON_ERROR), $lease->jobId, $rowNumber]
        );
    }
}
