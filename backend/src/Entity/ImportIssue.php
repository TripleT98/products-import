<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ImportIssueRepository;
use Doctrine\ORM\Mapping as ORM;

/** Read model; atomic state changes are performed by its Repository. */
#[ORM\Entity(repositoryClass: ImportIssueRepository::class, readOnly: true)]
#[ORM\Table(name: 'import_issues')]
#[ORM\UniqueConstraint(name: 'uniq_import_issues_key', columns: ['job_id', 'issue_key'])]
#[ORM\Index(name: 'idx_import_issues_page', columns: ['job_id', 'id'])]
class ImportIssue
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    public readonly int $id;

    #[ORM\ManyToOne(targetEntity: ImportJob::class)]
    #[ORM\JoinColumn(name: 'job_id', nullable: false, onDelete: 'CASCADE')]
    public readonly ImportJob $job;

    #[ORM\Column(name: 'issue_key', type: 'string', length: 64)]
    public readonly string $issueKey;

    #[ORM\Column(type: 'string', length: 8)]
    public readonly string $severity;

    #[ORM\Column(type: 'string', length: 64)]
    public readonly string $code;

    #[ORM\Column(type: 'text')]
    public readonly string $message;

    #[ORM\Column(name: 'row_number', type: 'integer', nullable: true)]
    public readonly ?int $rowNumber;

    #[ORM\Column(name: 'external_code', type: 'string', length: 255, nullable: true)]
    public readonly ?string $externalCode;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    public readonly ?string $field;

    public function getId(): int
    {
        return $this->id;
    }
}
