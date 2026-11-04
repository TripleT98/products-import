<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportRateLimit;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<ImportRateLimit> */
class ImportRateLimitRepository extends EntityRepository
{
    /** @return array{allowed: bool, retryAfter: int} */
    public function consume(int $ownerId, int $maximum, int $seconds): array
    {
        $db = $this->getEntityManager()->getConnection();
        // The upsert locks one owner row; no read/check/write race between FPM processes.
        $row = $db->fetchAssociative(<<<'SQL'
INSERT INTO import_rate_limits(owner_id,window_started_at,attempts)
VALUES (?,date_trunc('second',statement_timestamp()),1)
ON CONFLICT(owner_id) DO UPDATE SET
 attempts = CASE WHEN import_rate_limits.window_started_at + make_interval(secs => ?) <= statement_timestamp()
 THEN 1 ELSE LEAST(import_rate_limits.attempts + 1, ?) END,
 window_started_at = CASE WHEN import_rate_limits.window_started_at + make_interval(secs => ?) <= statement_timestamp()
 THEN date_trunc('second',statement_timestamp()) ELSE import_rate_limits.window_started_at END
RETURNING attempts, GREATEST(1,CEIL(EXTRACT(EPOCH FROM (window_started_at + make_interval(secs => ?) - statement_timestamp()))))::INT AS wait
SQL, [$ownerId, $seconds, $maximum + 1, $seconds, $seconds]);
        return ['allowed' => (int) $row['attempts'] <= $maximum, 'retryAfter' => (int) $row['wait']];
    }
}
