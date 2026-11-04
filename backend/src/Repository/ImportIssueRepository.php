<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportIssue;
use App\Import\Job\ImportIssueData;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<ImportIssue> */
class ImportIssueRepository extends EntityRepository
{
    /** @param list<ImportIssueData> $issues @return int Number of newly inserted warnings. */
    public function insertIssues(string $jobId, array $issues): int
    {
        $warnings = 0;
        foreach ($issues as $issue) {
            $key = hash('sha256', json_encode(get_object_vars($issue), JSON_THROW_ON_ERROR));
            $id = $this->getEntityManager()->getConnection()->fetchOne(
                'INSERT INTO import_issues
                (job_id,issue_key,severity,code,message,row_number,external_code,field) VALUES (?,?,?,?,?,?,?,?) ON CONFLICT (job_id,issue_key) DO NOTHING RETURNING id',
                [$jobId, $key, $issue->severity, $issue->code, $issue->message, $issue->rowNumber, $issue->externalCode, $issue->field]
            );
            if ($id !== false && $issue->severity === 'warning') {
                ++$warnings;
            }
        }
        return $warnings;
    }

    public function page(string $jobId, int $page, int $limit): array
    {
        $db = $this->getEntityManager()->getConnection();
        $total = (int) $db->fetchOne('SELECT COUNT(*) FROM import_issues WHERE job_id=?', [$jobId]);
        $items = $db->fetchAllAssociative(
            'SELECT id,severity,code,message,row_number,external_code,field FROM import_issues WHERE job_id=? ORDER BY id ASC LIMIT ? OFFSET ?',
            [$jobId, $limit, ($page - 1) * $limit],
            [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER]
        );
        $items = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'severity' => $r['severity'], 'code' => $r['code'], 'message' => $r['message'],
            'rowNumber' => $r['row_number'] === null ? null : (int) $r['row_number'], 'externalCode' => $r['external_code'], 'field' => $r['field']], $items);
        return ['items' => $items, 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'totalPages' => (int) ceil($total / $limit)]];
    }
}
