<?php

declare(strict_types=1);

namespace Infrastructure;

/**
 * Simple persistent job queue backed by SQLite.
 *
 * Features:
 * - Job types: arbitrary string
 * - Payload: JSON-serialized array
 * - States: pending, running, completed, failed
 * - Max retries configurable per job
 * - Job claiming with lease (prevent double-processing)
 * - Automatic recovery: jobs stuck in "running" state on worker restart are reset
 * - TTL-based cleanup of completed/failed jobs
 * - Scoped to (optional) store_id for tenant isolation
 */
final readonly class JobQueue
{
    private const MAX_RETRIES = 3;
    private const CLAIM_TIMEOUT = 60; // seconds before a claimed job is considered stuck
    private const CLEANUP_AFTER = 3600; // remove completed/failed jobs after 1 hour

    public function __construct(
        private Database $db,
        private string $queueName = 'default',
    ) {
        // Cleanup stale claims on construction
        $this->db->execute(
            'UPDATE jobs SET status = :pending WHERE queue = :queue AND status = :running AND claimed_at < :timeout',
            [
                'pending' => 'pending',
                'queue' => $this->queueName,
                'running' => 'running',
                'timeout' => time() - self::CLAIM_TIMEOUT,
            ],
        );

        // Periodic cleanup
        $this->db->execute(
            'DELETE FROM jobs WHERE queue = :queue AND status IN (:completed, :failed) AND completed_at < :cleanup',
            [
                'queue' => $this->queueName,
                'completed' => 'completed',
                'failed' => 'failed',
                'cleanup' => time() - self::CLEANUP_AFTER,
            ],
        );
    }

    /**
     * Push a new job to the queue.
     *
     * @param string $type Job type (e.g., 'verify_install')
     * @param array<string, mixed> $payload Job data
     * @param string|null $storeId Optional store scope
     * @return int Job ID
     */
    public function push(string $type, array $payload = [], ?string $storeId = null): int
    {
        $this->db->execute(
            'INSERT INTO jobs (queue, type, payload, store_id, status, created_at, retries_left)
             VALUES (:queue, :type, :payload, :store_id, :status, :created_at, :retries_left)',
            [
                'queue' => $this->queueName,
                'type' => $type,
                'payload' => json_encode($payload, \JSON_THROW_ON_ERROR),
                'store_id' => $storeId,
                'status' => 'pending',
                'created_at' => time(),
                'retries_left' => self::MAX_RETRIES,
            ],
        );

        return (int) $this->db->getPdo()->lastInsertId();
    }

    /**
     * Claim the next pending job.
     *
     * Атомарно: UPDATE … WHERE … RETURNING с учётом scheduled_at.
     * Зависшие running-задачи сбрасываются в pending на старте (в конструкторе).
     *
     * @return array|null Job data or null if none available
     */
    public function claim(): ?array
    {
        $job = $this->db->fetch(
            'UPDATE jobs
             SET status = :running, claimed_at = :now
             WHERE id = (
                 SELECT id FROM jobs
                 WHERE queue = :queue
                   AND status = :pending
                   AND scheduled_at <= :now_sched
                 ORDER BY priority DESC, created_at ASC
                 LIMIT 1
             )
             RETURNING *',
            [
                'running' => 'running',
                'now' => time(),
                'queue' => $this->queueName,
                'pending' => 'pending',
                'now_sched' => time(),
            ],
        );

        if ($job === null) {
            return null;
        }

        $job['payload'] = json_decode($job['payload'], true, 512, \JSON_THROW_ON_ERROR);

        return $job;
    }

    /**
     * Mark job as completed.
     */
    public function complete(int $jobId): void
    {
        $this->db->execute(
            'UPDATE jobs SET status = :completed, completed_at = :now WHERE id = :id',
            ['completed' => 'completed', 'now' => time(), 'id' => $jobId],
        );
    }

    /**
     * Mark job as failed. Retries if retries_left > 0.
     *
     * @return bool True if the job will be retried
     */
    public function fail(int $jobId, string $error = ''): bool
    {
        $job = $this->db->fetch('SELECT * FROM jobs WHERE id = :id', ['id' => $jobId]);

        if ($job === null) {
            return false;
        }

        $retriesLeft = (int) ($job['retries_left'] ?? 0) - 1;

        if ($retriesLeft > 0) {
            // Schedule for retry with backoff
            $backoff = (self::MAX_RETRIES - $retriesLeft) * 10; // 10s, 20s...
            $this->db->execute(
                'UPDATE jobs SET status = :pending, retries_left = :retries, error = :error,
                 scheduled_at = :scheduled, claimed_at = NULL
                 WHERE id = :id',
                [
                    'pending' => 'pending',
                    'retries' => $retriesLeft,
                    'error' => mb_substr($error, 0, 1000),
                    'scheduled' => time() + $backoff,
                    'id' => $jobId,
                ],
            );
            return true;
        }

        // Final failure
        $this->db->execute(
            'UPDATE jobs SET status = :failed, error = :error, completed_at = :now WHERE id = :id',
            ['failed' => 'failed', 'error' => mb_substr($error, 0, 1000), 'now' => time(), 'id' => $jobId],
        );

        return false;
    }
}