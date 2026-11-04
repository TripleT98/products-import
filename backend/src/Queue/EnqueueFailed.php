<?php

declare(strict_types=1);

namespace App\Queue;

use RuntimeException;
use Throwable;

final class EnqueueFailed extends RuntimeException
{
    public function __construct(public readonly string $jobId, Throwable $previous)
    {
        parent::__construct('Import publication failed; the job ID has been retained.', 0, $previous);
    }
}
