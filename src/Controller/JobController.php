<?php
// src/Controller/JobController.php

declare(strict_types=1);

namespace ZbxDeploy\Controller;

use ZbxDeploy\Core\JobQueue;
use ZbxDeploy\Core\View;

final class JobController
{
    public function index(): void
    {
        View::send('jobs/index.twig', ['jobs' => JobQueue::recent(100)]);
    }

    public function show(string $id): void
    {
        $job = JobQueue::find((int) $id);
        if ($job === null) {
            http_response_code(404);
            View::send('error.twig', ['title' => 'Not found', 'message' => 'No such job.']);

            return;
        }

        View::send('jobs/show.twig', [
            'job'    => $job,
            'params' => json_decode((string) ($job['params'] ?? '{}'), true) ?: [],
        ]);
    }

    /**
     * Log tail consumed by the job page. Returns only lines newer than the
     * id the browser already has, so polling stays cheap.
     */
    public function logs(string $id): void
    {
        $jobId = (int) $id;
        $after = (int) ($_GET['after'] ?? 0);

        $job = JobQueue::find($jobId);
        if ($job === null) {
            View::json(['error' => 'not found'], 404);

            return;
        }

        $lines = JobQueue::logsAfter($jobId, $after);

        View::json([
            'status'   => $job['status'],
            'exitCode' => $job['exit_code'],
            'finished' => in_array($job['status'], ['success', 'failed', 'canceled'], true),
            'lines'    => $lines,
            'lastId'   => $lines === [] ? $after : (int) $lines[array_key_last($lines)]['id'],
        ]);
    }
}
