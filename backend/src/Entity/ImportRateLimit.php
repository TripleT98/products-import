<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ImportRateLimitRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImportRateLimitRepository::class, readOnly: true)]
#[ORM\Table(name: 'import_rate_limits')]
class ImportRateLimit
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    public readonly User $owner;

    #[ORM\Column(name: 'window_started_at', type: 'datetimetz_immutable')]
    public readonly DateTimeImmutable $windowStartedAt;

    #[ORM\Column(type: 'integer')]
    public readonly int $attempts;
}
