<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ImportJobRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** Read model; atomic state changes are performed by its Repository. */
#[ORM\Entity(repositoryClass: ImportJobRepository::class, readOnly: true)]
#[ORM\Table(name: 'import_jobs')]
#[ORM\Index(name: 'idx_import_jobs_owner', columns: ['owner_id'])]
class ImportJob
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    public readonly string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'RESTRICT')]
    public readonly User $owner;

    #[ORM\Column(name: 'file_name', type: 'string', length: 37)]
    public readonly string $fileName;

    #[ORM\Column(type: 'string', length: 24)]
    public readonly string $status;

    #[ORM\Column(name: 'total_rows', type: 'integer', nullable: true)]
    public readonly ?int $totalRows;

    #[ORM\Column(name: 'processed_rows', type: 'integer')]
    public readonly int $processedRows;

    #[ORM\Column(name: 'created_products', type: 'integer')]
    public readonly int $createdProducts;

    #[ORM\Column(name: 'updated_products', type: 'integer')]
    public readonly int $updatedProducts;

    #[ORM\Column(name: 'failed_rows', type: 'integer')]
    public readonly int $failedRows;

    #[ORM\Column(name: 'warning_count', type: 'integer')]
    public readonly int $warningCount;

    #[ORM\Column(name: 'last_row_number', type: 'integer')]
    public readonly int $lastRowNumber;

    #[ORM\Column(name: 'lease_token', type: 'string', length: 32, nullable: true)]
    public readonly ?string $leaseToken;

    #[ORM\Column(name: 'lease_generation', type: 'integer')]
    public readonly int $leaseGeneration;

    #[ORM\Column(name: 'lease_expires_at', type: 'datetimetz_immutable', nullable: true)]
    public readonly ?DateTimeImmutable $leaseExpiresAt;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    public readonly DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'started_at', type: 'datetimetz_immutable', nullable: true)]
    public readonly ?DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    public readonly DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'finished_at', type: 'datetimetz_immutable', nullable: true)]
    public readonly ?DateTimeImmutable $finishedAt;

    #[ORM\Column(name: 'fatal_code', type: 'string', length: 64, nullable: true)]
    public readonly ?string $fatalCode;

    #[ORM\Column(name: 'fatal_message', type: 'text', nullable: true)]
    public readonly ?string $fatalMessage;

    public function getId(): string
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}
