<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportJob;
use App\Import\Job\ImportLease;
use App\Import\Job\ImportStateException;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<ImportJob> */
class ImportJobRepository extends EntityRepository
{
    public function dispatchStatus(string $id): ?string
    {
        $status = $this->getEntityManager()->getConnection()->fetchOne('SELECT status FROM import_jobs WHERE id=?', [$id]);
        return $status === false ? null : (string) $status;
    }

    public function releaseForRetry(ImportLease $lease): void
    {
        $this->getEntityManager()->getConnection()->executeStatement("UPDATE import_jobs SET lease_expires_at=clock_timestamp()-interval '1 second',updated_at=clock_timestamp() WHERE id=? AND lease_token=? AND status='processing'", [$lease->jobId, $lease->token]);
    }

    public function create(string $id, int $ownerId, string $fileName): void
    {
        $this->getEntityManager()->getConnection()->executeStatement("INSERT INTO import_jobs
            (id,owner_id,file_name,status,processed_rows,created_products,updated_products,failed_rows,warning_count,last_row_number,lease_generation,created_at,updated_at)
            VALUES (?,?,?,'queued',0,0,0,0,0,1,0,clock_timestamp(),clock_timestamp())", [$id, $ownerId, $fileName]);
    }

    public function forOwner(string $id, int $ownerId): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative('SELECT * FROM import_jobs WHERE id=? AND owner_id=?', [$id, $ownerId]);
        return $row === false ? null : $row;
    }

    public function claim(string $id, string $token, int $seconds): bool
    {
        $db = $this->getEntityManager()->getConnection();
        $row = $db->fetchOne("SELECT id FROM import_jobs WHERE id=? AND
            (status='queued' OR (status='processing' AND lease_expires_at <= clock_timestamp())) FOR UPDATE SKIP LOCKED", [$id]);
        if ($row === false) {
            return false;
        }
        $db->executeStatement("UPDATE import_jobs SET status='processing',lease_token=?,lease_generation=lease_generation+1,
            lease_expires_at=clock_timestamp()+(? * interval '1 second'),started_at=COALESCE(started_at,clock_timestamp()),updated_at=clock_timestamp() WHERE id=?", [$token, $seconds, $id]);
        return true;
    }

    public function lockLease(ImportLease $lease): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative("SELECT * FROM import_jobs WHERE id=? AND lease_token=? AND status='processing' AND lease_expires_at > clock_timestamp() FOR UPDATE", [$lease->jobId, $lease->token]);
        if ($row === false) {
            throw new ImportStateException('The import lease is missing, expired or no longer owns the job.');
        }
        return $row;
    }

    public function heartbeat(ImportLease $lease, int $seconds): void
    {
        $this->lockLease($lease);
        $this->getEntityManager()->getConnection()->executeStatement("UPDATE import_jobs SET lease_expires_at=clock_timestamp()+(? * interval '1 second'),updated_at=clock_timestamp() WHERE id=?", [$seconds, $lease->jobId]);
    }

    public function setTotal(ImportLease $lease, int $total): void
    {
        $row = $this->lockLease($lease);
        if ($total < (int) $row['processed_rows'] || ($row['total_rows'] !== null && (int) $row['total_rows'] !== $total)) {
            throw new ImportStateException('Import total is immutable and cannot be below processed rows.');
        }
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE import_jobs SET total_rows=?,updated_at=clock_timestamp() WHERE id=?', [$total, $lease->jobId]);
    }

    public function finish(ImportLease $lease): string
    {
        $row = $this->lockLease($lease);
        if ($row['total_rows'] === null || (int) $row['total_rows'] !== (int) $row['processed_rows']) {
            throw new ImportStateException('Cannot finish before all known rows have results.');
        }
        $status = (int) $row['failed_rows'] > 0 || (int) $row['warning_count'] > 0 ? 'completed_with_errors' : 'completed';
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE import_jobs SET status=?,finished_at=clock_timestamp(),updated_at=clock_timestamp(),lease_expires_at=NULL WHERE id=?', [$status, $lease->jobId]);
        return $status;
    }

    public function fail(string $id, ?ImportLease $lease, string $code, string $message): void
    {
        $db = $this->getEntityManager()->getConnection();
        if ($lease !== null) {
            $this->lockLease($lease);
        } elseif ($db->fetchOne("SELECT id FROM import_jobs WHERE id=? AND status='queued' FOR UPDATE", [$id]) === false) {
            throw new ImportStateException('Only queued publication failure can fail without an active lease.');
        }
        $db->executeStatement("UPDATE import_jobs SET status='failed',fatal_code=?,fatal_message=?,finished_at=clock_timestamp(),updated_at=clock_timestamp(),lease_expires_at=NULL WHERE id=?", [$code, $message, $id]);
    }

    public function addResultCounters(string $id, string $outcome, int $rowNumber, int $warnings): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE import_jobs SET processed_rows=processed_rows+1,
            created_products=created_products+?,updated_products=updated_products+?,failed_rows=failed_rows+?,warning_count=warning_count+?,last_row_number=?,updated_at=clock_timestamp() WHERE id=?',
            [(int) ($outcome === 'created'), (int) ($outcome === 'updated'), (int) ($outcome === 'failed'), $warnings, $rowNumber, $id]
        );
    }

    public function addWarnings(string $id, int $warnings): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE import_jobs SET warning_count=warning_count+?,updated_at=clock_timestamp() WHERE id=?', [$warnings, $id]);
    }
}
