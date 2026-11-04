<?php

declare(strict_types=1);

namespace App\Import\Job;

use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;

/** Explicit public projection: no owner, upload name, token or checkpoint in JSON. */
final readonly class ImportJobView implements JsonSerializable
{
    public function __construct(private array $record)
    {
    }

    public function jsonSerialize(): array
    {
        $r = $this->record;
        $view = ['id' => $r['id'], 'status' => $r['status']];
        foreach (['totalRows' => 'total_rows', 'processedRows' => 'processed_rows', 'createdProducts' => 'created_products',
            'updatedProducts' => 'updated_products', 'failedRows' => 'failed_rows', 'warningCount' => 'warning_count'] as $key => $column) {
            $view[$key] = $r[$column] === null ? null : (int) $r[$column];
        }
        foreach (['createdAt' => 'created_at', 'startedAt' => 'started_at', 'updatedAt' => 'updated_at', 'finishedAt' => 'finished_at'] as $key => $column) {
            $view[$key] = $r[$column] === null ? null : (new DateTimeImmutable($r[$column]))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        $view['fatalError'] = $r['fatal_code'] === null ? null : ['code' => $r['fatal_code'], 'message' => $r['fatal_message']];
        return $view;
    }
}
