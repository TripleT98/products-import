<?php

declare(strict_types=1);

namespace App\Import\Job;

use App\Entity\ImportJob;
use App\Entity\ImportRowResult;
use App\Import\ProductWriteCheckpoint;
use App\Import\ProductWriteResult;
use Doctrine\ORM\EntityManagerInterface;
use App\Import\RowDto;
use InvalidArgumentException;
use Closure;

final readonly class JobRowCheckpoint implements ProductWriteCheckpoint
{
    /** @param list<ImportIssueData> $issues */
    public function __construct(public ImportLease $lease, public int $rowNumber, private array $issues = [], private ?Closure $renew = null)
    {
    }

    public function withIssues(array $issues): ProductWriteCheckpoint
    {
        return new self($this->lease, $this->rowNumber, array_merge($this->issues, array_map(ImportIssueData::fromRowIssue(...), $issues)), $this->renew);
    }

    public function heartbeat(): void
    {
        if ($this->renew !== null) {
            ($this->renew)();
        }
    }

    public function begin(EntityManagerInterface $em, RowDto $row): ?ProductWriteResult
    {
        if ($row->rowNumber !== $this->rowNumber) {
            throw new InvalidArgumentException('The checkpoint must belong to the DTO physical row.');
        }
        $em->getRepository(ImportJob::class)->lockLease($this->lease);
        $results = $em->getRepository(ImportRowResult::class);
        $previous = $results->findResult($this->lease->jobId, $this->rowNumber);
        return $previous === null ? null : $results->receipt($previous);
    }

    public function record(EntityManagerInterface $em, ProductWriteResult $result): void
    {
        $em->getRepository(ImportRowResult::class)->record($this->lease, $this->rowNumber, $result, $this->issues);
    }
}
