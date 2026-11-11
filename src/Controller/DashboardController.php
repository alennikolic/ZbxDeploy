<?php
// src/Controller/DashboardController.php

declare(strict_types=1);

namespace ZbxDeploy\Controller;

use ZbxDeploy\Core\Database;
use ZbxDeploy\Core\JobQueue;
use ZbxDeploy\Core\View;

final class DashboardController
{
    public function index(): void
    {
        $environments = Database::all(
            'SELECT e.*,
                    (SELECT COUNT(*) FROM hosts h WHERE h.environment_id = e.id) AS host_count
             FROM environments e
             ORDER BY e.name'
        );

        $stats = [
            'hosts'       => (int) Database::scalar('SELECT COUNT(*) FROM hosts'),
            'unprobed'    => (int) Database::scalar('SELECT COUNT(*) FROM hosts WHERE last_probe_at IS NULL'),
            'unreachable' => (int) Database::scalar('SELECT COUNT(*) FROM hosts WHERE reachable = 0'),
            'running'     => (int) Database::scalar("SELECT COUNT(*) FROM jobs WHERE status IN ('queued','running')"),
        ];

        $versions = Database::all(
            'SELECT * FROM zabbix_versions WHERE is_supported = 1 ORDER BY zabbix_version DESC, os_version DESC'
        );

        View::send('dashboard.twig', [
            'environments' => $environments,
            'stats'        => $stats,
            'versions'     => $versions,
            'jobs'         => JobQueue::recent(10),
        ]);
    }
}
