<?php
// src/Worker/Worker.php

declare(strict_types=1);

namespace ZbxDeploy\Worker;

use ZbxDeploy\Core\Catalog;
use ZbxDeploy\Core\Config;
use ZbxDeploy\Core\Database;
use ZbxDeploy\Core\JobQueue;
use ZbxDeploy\Worker\Jobs\ApplyJob;
use ZbxDeploy\Worker\Jobs\ProbeJob;

/**
 * Long-running process that drains the job queue. One job at a time per
 * worker; run more worker containers if you need parallelism.
 */
final class Worker
{
    private string $workerId;
    private bool $shouldStop = false;

    /** @var array<string, class-string> */
    private const HANDLERS = [
        'probe' => ProbeJob::class,
        'apply' => ApplyJob::class,
    ];

    public function __construct()
    {
        $this->workerId = gethostname() . ':' . getmypid();
    }

    public function run(): void
    {
        $this->installSignalHandlers();

        Database::waitUntilReady();
        $this->out('Worker ' . $this->workerId . ' started.');

        $reaped = JobQueue::reapStale(Config::int('JOB_STALE_MINUTES', 120));
        if ($reaped > 0) {
            $this->out(sprintf('Marked %d stale running job(s) as failed.', $reaped));
        }

        $idleSleep = max(1, Config::int('WORKER_POLL_SECONDS', 3));

        while (!$this->shouldStop) {
            try {
                $job = JobQueue::claim($this->workerId);
            } catch (\Throwable $e) {
                $this->out('Queue error: ' . $e->getMessage());
                sleep($idleSleep);
                continue;
            }

            if ($job === null) {
                sleep($idleSleep);
                $this->dispatchSignals();
                continue;
            }

            $this->process($job);
            $this->dispatchSignals();
        }

        $this->out('Worker stopped.');
    }

    /** @param array<string, mixed> $job */
    private function process(array $job): void
    {
        $jobId = (int) $job['id'];
        $type = (string) $job['type'];

        // Manifests are files on disk and may have appeared since this process
        // started; re-read them rather than trusting a cache from an earlier job.
        Catalog::reset();

        $this->out(sprintf('Job #%d (%s) started.', $jobId, $type));
        JobQueue::log($jobId, sprintf('Picked up by %s', $this->workerId), 'system');

        if (!isset(self::HANDLERS[$type])) {
            JobQueue::log($jobId, 'No handler registered for job type: ' . $type, 'system');
            JobQueue::finish($jobId, false, 1);

            return;
        }

        $handlerClass = self::HANDLERS[$type];

        try {
            /** @var object{handle: callable} $handler */
            $handler = new $handlerClass();
            $success = (bool) $handler->handle($job);
            JobQueue::finish($jobId, $success, $success ? 0 : 1);
            $this->out(sprintf('Job #%d finished: %s', $jobId, $success ? 'success' : 'failed'));
        } catch (\Throwable $e) {
            JobQueue::log($jobId, 'Unhandled error: ' . $e->getMessage(), 'system');
            if (Config::isDebug()) {
                JobQueue::log($jobId, $e->getTraceAsString(), 'system');
            }
            JobQueue::finish($jobId, false, 1);
            $this->out(sprintf('Job #%d crashed: %s', $jobId, $e->getMessage()));
        }
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = function (int $signal): void {
            $this->out('Received signal ' . $signal . ', finishing current job then stopping.');
            $this->shouldStop = true;
        };

        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    private function out(string $message): void
    {
        fwrite(STDOUT, sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL));
    }
}
