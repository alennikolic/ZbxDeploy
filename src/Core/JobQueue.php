<?php

declare(strict_types=1);

namespace ZbxDeploy\Core;

use PDO;

/**
 * MySQL-backed job queue. Workers claim rows with SELECT ... FOR UPDATE
 * SKIP LOCKED, so running several worker containers is safe without Redis.
 */
final class JobQueue
{
    /** @param array<string, mixed> $params */
    public static function enqueue(
        string $type,
        string $title,
        array $params = [],
        ?int $environmentId = null,
        bool $checkMode = false,
        string $createdBy = 'system',
        ?int $parentJobId = null
    ): int {
        Database::run(
            'INSERT INTO jobs (environment_id, parent_job_id, type, title, params, check_mode, created_by)
             VALUES (:env, :parent, :type, :title, :params, :check_mode, :created_by)',
            [
                'env'        => $environmentId,
                'parent'     => $parentJobId,
                'type'       => $type,
                'title'      => $title,
                'params'     => json_encode($params, JSON_UNESCAPED_SLASHES),
                'check_mode' => $checkMode ? 1 : 0,
                'created_by' => $createdBy,
            ]
        );

        return Database::lastInsertId();
    }

    /**
     * Atomically take the oldest queued job.
     *
     * @return array<string, mixed>|null
     */
    public static function claim(string $workerId): ?array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM jobs WHERE status = :status ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $stmt->execute(['status' => 'queued']);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($job === false) {
                $pdo->commit();

                return null;
            }

            $update = $pdo->prepare(
                'UPDATE jobs SET status = :status, worker_id = :worker, started_at = NOW() WHERE id = :id'
            );
            $update->execute([
                'status' => 'running',
                'worker' => $workerId,
                'id'     => $job['id'],
            ]);

            $pdo->commit();

            $job['status'] = 'running';

            return $job;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function finish(int $jobId, bool $success, ?int $exitCode = null): void
    {
        Database::run(
            'UPDATE jobs SET status = :status, exit_code = :exit_code, finished_at = NOW() WHERE id = :id',
            [
                'status'    => $success ? 'success' : 'failed',
                'exit_code' => $exitCode,
                'id'        => $jobId,
            ]
        );
    }

    public static function log(int $jobId, string $line, string $stream = 'stdout'): void
    {
        // Ansible output is already line-oriented; keep rows small and readable.
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            return;
        }

        Database::run(
            'INSERT INTO job_logs (job_id, stream, line) VALUES (:job_id, :stream, :line)',
            ['job_id' => $jobId, 'stream' => $stream, 'line' => $line]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function logsAfter(int $jobId, int $afterId, int $limit = 500): array
    {
        return Database::all(
            'SELECT id, stream, line, created_at FROM job_logs
             WHERE job_id = :job_id AND id > :after ORDER BY id ASC LIMIT ' . $limit,
            ['job_id' => $jobId, 'after' => $afterId]
        );
    }

    /** @return array<string, mixed>|null */
    public static function find(int $jobId): ?array
    {
        return Database::one('SELECT * FROM jobs WHERE id = :id', ['id' => $jobId]);
    }

    /** @return list<array<string, mixed>> */
    public static function recent(int $limit = 50): array
    {
        return Database::all(
            'SELECT j.*, e.name AS environment_name
             FROM jobs j
             LEFT JOIN environments e ON e.id = j.environment_id
             ORDER BY j.id DESC LIMIT ' . $limit
        );
    }

    /**
     * Jobs left in "running" by a worker that died are not really running.
     * Called once at worker startup.
     */
    public static function reapStale(int $olderThanMinutes = 120): int
    {
        $minutes = max(1, $olderThanMinutes);
        $stmt = Database::run(
            "UPDATE jobs SET status = 'failed', finished_at = NOW()
             WHERE status = 'running' AND started_at < (NOW() - INTERVAL {$minutes} MINUTE)"
        );

        return $stmt->rowCount();
    }
}
