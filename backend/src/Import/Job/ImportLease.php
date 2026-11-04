<?php

declare(strict_types=1);

namespace App\Import\Job;

final readonly class ImportLease
{
    public function __construct(public string $jobId, public string $token)
    {
    }
}
