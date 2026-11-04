<?php

declare(strict_types=1);

namespace App\Import\Job;

use App\Import\RowIssue;
use InvalidArgumentException;

final readonly class ImportIssueData
{
    public function __construct(
        public string $severity,
        public string $code,
        public string $message,
        public ?int $rowNumber,
        public ?string $externalCode = null,
        public ?string $field = null
    ) {
        if (!in_array($severity, ['error', 'warning'], true) || !preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $code)
            || $message === '' || mb_strlen($message) > 2000 || ($rowNumber !== null && $rowNumber < 2)
            || ($externalCode !== null && mb_strlen($externalCode) > 255) || ($field !== null && mb_strlen($field) > 512)) {
            throw new InvalidArgumentException('Invalid import issue.');
        }
    }

    public static function fromRowIssue(RowIssue $issue): self
    {
        return new self($issue->severity, $issue->code, $issue->message, $issue->rowNumber, $issue->externalCode, $issue->field);
    }
}
