<?php

declare(strict_types=1);

namespace App\Import\Job;

use App\Entity\ImportJob;
use App\Entity\ImportIssue;
use App\Entity\ImportRowResult;
use App\Infrastructure\DoctrineFactory;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;

final class ImportJobService
{
    /** @param null|Closure(): EntityManagerInterface $entityManagers */
    public function __construct(private ?Closure $entityManagers = null, private int $leaseSeconds = 60)
    {
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('Lease duration must be between 1 and 3600 seconds.');
        }
    }

    public function create(int $ownerId, string $fileName): string
    {
        if ($ownerId < 1 || !preg_match('/^[a-f0-9]{32}\.xlsx$/D', $fileName)) {
            throw new InvalidArgumentException('Use an authenticated owner and a generated private upload name.');
        }
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        $id = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
        $this->transaction(static fn (EntityManagerInterface $em) => $em->getRepository(ImportJob::class)->create($id, $ownerId, $fileName));
        return $id;
    }

    public function claim(string $id): ?ImportLease
    {
        self::validateId($id);
        $token = bin2hex(random_bytes(16));
        $claimed = $this->transaction(fn (EntityManagerInterface $em): bool => $em->getRepository(ImportJob::class)->claim($id, $token, $this->leaseSeconds));
        return $claimed ? new ImportLease($id, $token) : null;
    }

    public function heartbeat(ImportLease $lease): void
    {
        $this->transaction(fn (EntityManagerInterface $em) => $em->getRepository(ImportJob::class)->heartbeat($lease, $this->leaseSeconds));
    }

    public function dispatchStatus(string $id): ?string
    {
        self::validateId($id);
        return $this->transaction(static fn (EntityManagerInterface $em): ?string => $em->getRepository(ImportJob::class)->dispatchStatus($id));
    }

    public function fileForLease(ImportLease $lease): string
    {
        return $this->transaction(static fn (EntityManagerInterface $em): string => $em->getRepository(ImportJob::class)->lockLease($lease)['file_name']);
    }

    public function releaseForRetry(ImportLease $lease): void
    {
        $this->transaction(static fn (EntityManagerInterface $em) => $em->getRepository(ImportJob::class)->releaseForRetry($lease));
    }

    public function setTotal(ImportLease $lease, int $total): void
    {
        if ($total < 0) {
            throw new InvalidArgumentException('Total rows cannot be negative.');
        }
        $this->transaction(static fn (EntityManagerInterface $em) => $em->getRepository(ImportJob::class)->setTotal($lease, $total));
    }

    public function complete(ImportLease $lease): string
    {
        return $this->transaction(static fn (EntityManagerInterface $em): string => $em->getRepository(ImportJob::class)->finish($lease));
    }

    public function fail(ImportLease $lease, ImportFatalCode $reason): void
    {
        $this->failWithReason($lease->jobId, $lease, $reason);
    }

    public function failQueuedPublication(string $id): void
    {
        self::validateId($id);
        $this->failWithReason($id, null, ImportFatalCode::PublishFailed);
    }

    private function failWithReason(string $id, ?ImportLease $lease, ImportFatalCode $reason): void
    {
        $this->transaction(static function (EntityManagerInterface $em) use ($id, $lease, $reason): void {
            $em->getRepository(ImportJob::class)->fail($id, $lease, $reason->value, $reason->message());
            $em->getRepository(ImportIssue::class)->insertIssues($id, [new ImportIssueData('error', $reason->value, $reason->message(), null)]);
        });
    }

    /** @param list<ImportIssueData> $issues */
    public function recordFailure(ImportLease $lease, int $rowNumber, array $issues): bool
    {
        return $this->transaction(static fn (EntityManagerInterface $em): bool => $em->getRepository(ImportRowResult::class)->record($lease, $rowNumber, null, $issues));
    }

    /** @param list<ImportIssueData> $issues */
    public function checkpoint(ImportLease $lease, int $rowNumber, array $issues = []): JobRowCheckpoint
    {
        if ($rowNumber < 2) {
            throw new InvalidArgumentException('A data row starts at physical row 2.');
        }
        return new JobRowCheckpoint($lease, $rowNumber, $issues, fn () => $this->heartbeat($lease));
    }

    /** @param list<ImportIssueData> $issues @param list<string> $paths */
    public function noteCleanup(ImportLease $lease, int $rowNumber, array $issues, array $paths): void
    {
        $this->transaction(static fn (EntityManagerInterface $em) => $em->getRepository(ImportRowResult::class)->noteCleanup($lease, $rowNumber, $issues, $paths));
    }

    public function rowResult(ImportLease $lease, int $rowNumber): ?array
    {
        return $this->transaction(static function (EntityManagerInterface $em) use ($lease, $rowNumber): ?array {
            $em->getRepository(ImportJob::class)->lockLease($lease);
            return $em->getRepository(ImportRowResult::class)->findResult($lease->jobId, $rowNumber);
        });
    }

    public function findForOwner(string $id, int $ownerId): ?ImportJobView
    {
        self::validateId($id);
        return $this->transaction(static function (EntityManagerInterface $em) use ($id, $ownerId): ?ImportJobView {
            $record = $em->getRepository(ImportJob::class)->forOwner($id, $ownerId);
            return $record === null ? null : new ImportJobView($record);
        });
    }

    public function issuesForOwner(string $id, int $ownerId, int $page = 1, int $limit = 50): ?array
    {
        self::validateId($id);
        if ($page < 1 || $page > 2147483647 || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Invalid issue pagination.');
        }
        return $this->transaction(static function (EntityManagerInterface $em) use ($id, $ownerId, $page, $limit): ?array {
            if ($em->getRepository(ImportJob::class)->forOwner($id, $ownerId) === null) {
                return null;
            }
            return $em->getRepository(ImportIssue::class)->page($id, $page, $limit);
        });
    }

    private static function validateId(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id)) {
            throw new InvalidArgumentException('Invalid import job ID.');
        }
    }

    private function transaction(Closure $operation): mixed
    {
        $em = $this->entityManagers === null ? DoctrineFactory::entityManager() : ($this->entityManagers)();
        if (!$em->isOpen() || $em->getConnection()->isTransactionActive()) {
            throw new LogicException('ImportJobService requires a fresh owned session without an active transaction.');
        }
        try {
            return $em->wrapInTransaction($operation);
        } finally {
            $em->close();
            $em->getConnection()->close();
        }
    }
}
