<?php

declare(strict_types=1);

namespace App\Import;

use JsonSerializable;

final class ValidationSummary implements JsonSerializable
{
    public int $rowCount = 0;
    public ?int $firstRow = null;
    public ?int $lastRow = null;
    private int $valid = 0;
    private int $invalid = 0;
    private int $attributes = 0;
    private int $candidates = 0;
    private int $errors = 0;
    private int $warnings = 0;
    /** @var array<string, true> */
    private array $urls = [];
    /** @var array<string, int> */
    private array $discounts = [];
    /** @var array<string, int> */
    private array $issueCodes = [];

    /** @param iterable<RowResult> $results */
    public function consume(iterable $results): void
    {
        foreach ($results as $result) {
            ++$this->rowCount;
            $this->firstRow ??= $result->rowNumber;
            $this->lastRow = $result->rowNumber;
            foreach ($result->issues as $issue) {
                if ($issue->severity === 'error') {
                    ++$this->errors;
                } else {
                    ++$this->warnings;
                }
                $this->issueCodes[$issue->code] = ($this->issueCodes[$issue->code] ?? 0) + 1;
            }
            if ($result->dto === null) {
                ++$this->invalid;
                continue;
            }
            ++$this->valid;
            $dto = $result->dto;
            $this->attributes += count($dto->attributes);
            $this->candidates += count($dto->imageCandidates);
            $this->discounts[$dto->discount] = ($this->discounts[$dto->discount] ?? 0) + 1;
            foreach ($dto->imageCandidates as $url) {
                $this->urls['url:' . $url] = true;
            }
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['validRows' => $this->valid, 'invalidRows' => $this->invalid, 'attributeCount' => $this->attributes,
            'imageCandidateCount' => $this->candidates, 'uniqueImageUrls' => count($this->urls),
            'discounts' => (object) $this->discounts, 'errors' => $this->errors, 'warnings' => $this->warnings, 'issuesByCode' => (object) $this->issueCodes];
    }
}
