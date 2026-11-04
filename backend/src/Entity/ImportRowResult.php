<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ImportRowResultRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** Read model; atomic state changes are performed by its Repository. */
#[ORM\Entity(repositoryClass: ImportRowResultRepository::class, readOnly: true)]
#[ORM\Table(name: 'import_row_results')]
class ImportRowResult
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ImportJob::class)]
    #[ORM\JoinColumn(name: 'job_id', nullable: false, onDelete: 'CASCADE')]
    public readonly ImportJob $job;

    #[ORM\Id]
    #[ORM\Column(name: 'row_number', type: 'integer')]
    public readonly int $rowNumber;

    #[ORM\Column(type: 'string', length: 16)]
    public readonly string $outcome;

    #[ORM\Column(name: 'product_id', type: 'integer', nullable: true)]
    public readonly ?int $productId;

    #[ORM\Column(type: 'integer')]
    public readonly int $attempts;

    #[ORM\Column(name: 'obsolete_paths', type: 'json', options: ['jsonb' => true])]
    public readonly array $obsoletePaths;

    #[ORM\Column(name: 'cleanup_pending_paths', type: 'json', options: ['jsonb' => true])]
    public readonly array $cleanupPendingPaths;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    public readonly DateTimeImmutable $createdAt;

    public function getRowNumber(): int
    {
        return $this->rowNumber;
    }
}
