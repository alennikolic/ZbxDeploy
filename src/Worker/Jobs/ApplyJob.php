<?php
// src/Worker/Jobs/ApplyJob.php

declare(strict_types=1);

namespace ZbxDeploy\Worker\Jobs;

use ZbxDeploy\Core\Catalog;
use ZbxDeploy\Core\Config;
use ZbxDeploy\Core\Database;
use ZbxDeploy\Core\JobQueue;
use ZbxDeploy\Worker\AnsibleRunner;

/**
 * Apply: bring each host in line with the roles assigned to it.
 *
 * Ordering and version resolution happen here, in PHP, not in Ansible. The
 * catalogue knows which role comes before which, and the zabbix_versions table
 * knows which release package belongs to this host's Ubuntu codename - the
 * playbook just executes what it is handed.
 */
final class ApplyJob
{
    /** @param array<string, mixed> $job */
    public function handle(array $job): bool
    {
        $jobId = (int) $job['id'];
        $checkMode = (bool) $job['check_mode'];
        $params = json_decode((string) ($job['params'] ?? '{}'), true) ?: [];
        $hostIds = array_map('intval', $params['host_ids'] ?? []);

        if ($hostIds === []) {
            JobQueue::log($jobId, 'No host_ids in job parameters.', 'system');

            return false;
        }

        $hosts = $this->loadHosts($hostIds);
        if ($hosts === []) {
            JobQueue::log($jobId, 'None of the requested hosts exist any more.', 'system');

            return false;
        }

        $hostRoles = [];
        $usableHosts = [];

        foreach ($hosts as $host) {
            $plan = $this->planFor($jobId, $host);
            if ($plan === null) {
                continue;
            }

            $hostRoles[AnsibleRunner::inventoryName((string) $host['hostname'])] = $plan;
            $usableHosts[] = $host;
        }

        if ($usableHosts === []) {
            JobQueue::log($jobId, 'Nothing to apply: no host passed its preconditions.', 'system');

            return false;
        }

        $resultsDir = sprintf('%s/results/job-%d', Config::dataDir(), $jobId);
        if (!is_dir($resultsDir) && !mkdir($resultsDir, 0700, true) && !is_dir($resultsDir)) {
            JobQueue::log($jobId, 'Could not create results directory.', 'system');

            return false;
        }

        $exitCode = (new AnsibleRunner())->run(
            $jobId,
            $usableHosts,
            'apply.yml',
            [
                'zbxdeploy_host_roles'  => $hostRoles,
                'zbxdeploy_results_dir' => $resultsDir,
            ],
            $checkMode
        );

        $this->recordResults($jobId, $usableHosts, $resultsDir, $checkMode);
        $this->cleanup($resultsDir);

        return $exitCode === 0;
    }

    /**
     * Build the ordered role list for one host, or null if the host cannot be
     * deployed to yet. Every rejection is explained in the job log.
     *
     * @param array<string, mixed> $host
     * @return list<array{role: string, params: array<string, mixed>}>|null
     */
    private function planFor(int $jobId, array $host): ?array
    {
        $hostname = (string) $host['hostname'];

        $assigned = Database::all(
            "SELECT * FROM host_roles WHERE host_id = :id AND desired_state = 'present'",
            ['id' => $host['id']]
        );

        if ($assigned === []) {
            JobQueue::log($jobId, sprintf('%s: no roles assigned, skipping.', $hostname), 'system');

            return null;
        }

        if ($host['last_probe_at'] === null) {
            JobQueue::log($jobId, sprintf('%s: never probed. Run a probe before applying roles.', $hostname), 'system');

            return null;
        }

        // Order is a property of the catalogue, not of the database rows.
        usort(
            $assigned,
            static fn (array $a, array $b): int => Catalog::order((string) $a['role']) <=> Catalog::order((string) $b['role'])
        );

        $facts = [
            'os_family'   => $host['os_family'],
            'os_version'  => $host['os_version'],
            'os_codename' => $host['os_codename'],
        ];

        $plan = [];

        foreach ($assigned as $row) {
            $roleId = (string) $row['role'];
            $manifest = Catalog::get($roleId);

            if ($manifest === null) {
                JobQueue::log($jobId, sprintf(
                    '%s: role "%s" is assigned but no longer exists in the catalogue, skipping it.',
                    $hostname,
                    $roleId
                ), 'system');
                continue;
            }

            foreach ((array) $manifest['requires_facts'] as $fact) {
                if (empty($facts[$fact])) {
                    JobQueue::log($jobId, sprintf(
                        '%s: role "%s" needs %s, which this host has not reported. Probe it again.',
                        $hostname,
                        $roleId,
                        $fact
                    ), 'system');

                    return null;
                }
            }

            $roleParams = json_decode((string) ($row['params'] ?? '{}'), true) ?: [];

            if ($roleId === 'zabbix-repo') {
                $release = $this->resolveRelease($jobId, $host);
                if ($release === null) {
                    return null;
                }
                $roleParams['repo_deb_url'] = $release['repo_deb_url'];
                $roleParams['zabbix_version'] = $release['zabbix_version'];
            }

            $plan[] = [
                'role'   => (string) $manifest['ansible_role'],
                'params' => $roleParams,
            ];
        }

        if ($plan === []) {
            return null;
        }

        JobQueue::log($jobId, sprintf(
            '%s: will apply %s',
            $hostname,
            implode(' → ', array_column($plan, 'role'))
        ), 'system');

        return $plan;
    }

    /**
     * Find the release package for this host: the environment decides the
     * Zabbix version, the host decides the Ubuntu codename.
     *
     * @param array<string, mixed> $host
     * @return array<string, mixed>|null
     */
    private function resolveRelease(int $jobId, array $host): ?array
    {
        $release = Database::one(
            'SELECT v.* FROM zabbix_versions v
             JOIN environments e ON e.id = :env
             WHERE v.zabbix_version = e.zabbix_version
               AND v.os_codename = :codename
               AND v.is_supported = 1
             LIMIT 1',
            ['env' => $host['environment_id'], 'codename' => $host['os_codename']]
        );

        if ($release === null) {
            JobQueue::log($jobId, sprintf(
                '%s: no supported entry in zabbix_versions for Zabbix %s on "%s". '
                . 'Add the row (and verify the repository URL against zabbix.com/download) before applying.',
                $host['hostname'],
                Database::scalar('SELECT zabbix_version FROM environments WHERE id = :id', ['id' => $host['environment_id']]),
                $host['os_codename']
            ), 'system');

            return null;
        }

        return $release;
    }

    /**
     * @param list<array<string, mixed>> $hosts
     */
    private function recordResults(int $jobId, array $hosts, string $resultsDir, bool $checkMode): void
    {
        foreach ($hosts as $host) {
            $path = $resultsDir . '/' . AnsibleRunner::inventoryName((string) $host['hostname']) . '.json';

            if (!is_file($path)) {
                Database::run(
                    "UPDATE host_roles SET last_status = 'failed'
                     WHERE host_id = :id AND desired_state = 'present'",
                    ['id' => $host['id']]
                );
                JobQueue::log($jobId, sprintf('%s: did not complete.', $host['hostname']), 'system');
                continue;
            }

            if ($checkMode) {
                JobQueue::log($jobId, sprintf(
                    '%s: check mode finished, nothing was changed and no state recorded.',
                    $host['hostname']
                ), 'system');
                continue;
            }

            $result = json_decode((string) file_get_contents($path), true);
            $applied = is_array($result) ? (array) ($result['applied'] ?? []) : [];

            $zabbixVersion = (string) Database::scalar(
                'SELECT zabbix_version FROM environments WHERE id = :id',
                ['id' => $host['environment_id']]
            );

            foreach ($applied as $roleId) {
                $row = Database::one(
                    'SELECT * FROM host_roles WHERE host_id = :id AND role = :role',
                    ['id' => $host['id'], 'role' => $roleId]
                );
                if ($row === null) {
                    continue;
                }

                $params = json_decode((string) ($row['params'] ?? '{}'), true) ?: [];

                Database::run(
                    "UPDATE host_roles
                     SET last_status = 'applied', applied_at = NOW(), applied_hash = :hash
                     WHERE id = :id",
                    [
                        'hash' => Catalog::fingerprint((string) $roleId, $params, $zabbixVersion),
                        'id'   => $row['id'],
                    ]
                );
            }

            JobQueue::log($jobId, sprintf(
                '%s: applied %s',
                $host['hostname'],
                $applied === [] ? 'nothing' : implode(', ', $applied)
            ), 'system');
        }
    }

    /**
     * @param list<int> $hostIds
     * @return list<array<string, mixed>>
     */
    private function loadHosts(array $hostIds): array
    {
        $placeholders = implode(',', array_fill(0, count($hostIds), '?'));

        return Database::all(
            "SELECT h.*, c.ssh_user, c.auth_type, c.secret, c.become_method, c.become_password
             FROM hosts h
             LEFT JOIN credentials c ON c.id = h.credential_id
             WHERE h.id IN ({$placeholders})
             ORDER BY h.hostname",
            $hostIds
        );
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
