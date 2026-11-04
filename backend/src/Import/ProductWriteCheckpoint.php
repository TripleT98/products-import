<?php

declare(strict_types=1);

namespace App\Import;

use Doctrine\ORM\EntityManagerInterface;

interface ProductWriteCheckpoint
{
    public function heartbeat(): void;
    /** @param list<RowIssue> $issues */
    public function withIssues(array $issues): self;
    public function begin(EntityManagerInterface $em, RowDto $row): ?ProductWriteResult;
    public function record(EntityManagerInterface $em, ProductWriteResult $result): void;
}
