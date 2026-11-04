<?php

declare(strict_types=1);

namespace App\Queue;

use InvalidArgumentException;

final readonly class ImportMessage
{
    public function __construct(public string $jobId)
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $jobId)) {
            throw new InvalidArgumentException('Invalid import message ID.');
        }
    }
}
