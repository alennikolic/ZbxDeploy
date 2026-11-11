<?php

declare(strict_types=1);

namespace ZbxDeploy\Worker\Jobs;

use ZbxDeploy\Core\Config;
use ZbxDeploy\Core\Database;
use ZbxDeploy\Core\JobQueue;
use ZbxDeploy\Worker\AnsibleRunner;

/**
 * Probe: reach the host over SSH, gather the facts ZbxDeploy needs before it
 * installs anything, and cache them on the host row.
 *
 * Nothing is installed or changed. This is the job you run first, and the one
 * that tells you a host is not ready before a deployment finds out the hard way.
 */
final class ProbeJob
{
    /** @param array<string, mixed> $job */
    public function handle(array $job): bool
    {
        $jobId = (int) $job['id'];
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

        $factsDir = sprintf('%s/facts/job-%d', Config::dataDir(), $jobId);
        if (!is_dir($factsDir) && !mkdir($factsDir, 0700, true) && !is_dir($factsDir)) {
            JobQueue::log($jobId, 'Could not create facts directory.', 'system');

            return false;
        }

        $exitCode = (new AnsibleRunner())->run(
            $jobId,
            $hosts,
            'probe.yml',
            ['zbxdeploy_facts_dir' => $factsDir]
        );

        $this->ingestFacts($jobId, $hosts, $factsDir);
        $this->cleanup($factsDir);

        return $exitCode === 0;
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

    /** @param list<array<string, mixed>> $hosts */
    private function ingestFacts(int $jobId, array $hosts, string $factsDir): void
    {
        foreach ($hosts as $host) {
            $safeName = preg_replace('/[^A-Za-z0-9_.\-]/', '_', (string) $host['hostname']);
            $path = $factsDir . '/' . $safeName . '.json';

            if (!is_file($path)) {
                Database::run(
                    'UPDATE hosts SET reachable = 0, last_probe_at = NOW() WHERE id = :id',
                    ['id' => $host['id']]
                );
                JobQueue::log($jobId, sprintf('%s: unreachable or probe failed.', $host['hostname']), 'system');
                continue;
            }

            $facts = json_decode((string) file_get_contents($path), true);
            if (!is_array($facts)) {
                JobQueue::log($jobId, sprintf('%s: facts file was not valid JSON.', $host['hostname']), 'system');
                continue;
            }

            Database::run(
                'UPDATE hosts
                 SET reachable = 1,
                     os_family = :os_family,
                     os_version = :os_version,
                     os_codename = :os_codename,
                     architecture = :arch,
                     facts = :facts,
                     last_probe_at = NOW()
                 WHERE id = :id',
                [
                    'os_family'   => $facts['os_family'] ?? null,
                    'os_version'  => $facts['os_version'] ?? null,
                    'os_codename' => $facts['os_codename'] ?? null,
                    'arch'        => $facts['architecture'] ?? null,
                    'facts'       => json_encode($facts, JSON_UNESCAPED_SLASHES),
                    'id'          => $host['id'],
                ]
            );

            JobQueue::log($jobId, sprintf(
                '%s: %s %s (%s), timezone %s, NTP synced: %s',
                $host['hostname'],
                $facts['os_family'] ?? '?',
                $facts['os_version'] ?? '?',
                $facts['os_codename'] ?? '?',
                $facts['timezone'] ?? '?',
                ($facts['ntp_synchronized'] ?? null) ? 'yes' : 'no'
            ), 'system');

            $this->warnAboutSupport($jobId, (string) $host['hostname'], $facts);
        }
    }

    /** @param array<string, mixed> $facts */
    private function warnAboutSupport(int $jobId, string $hostname, array $facts): void
    {
        $codename = $facts['os_codename'] ?? null;
        if ($codename === null) {
            return;
        }

        $supported = Database::scalar(
            'SELECT COUNT(*) FROM zabbix_versions WHERE os_codename = :codename AND is_supported = 1',
            ['codename' => $codename]
        );

        if ((int) $supported === 0) {
            JobQueue::log($jobId, sprintf(
                '%s: no supported Zabbix version is configured for "%s". Add a row to zabbix_versions before deploying.',
                $hostname,
                $codename
            ), 'system');
        }

        if (($facts['ntp_synchronized'] ?? null) === false) {
            JobQueue::log($jobId, sprintf(
                '%s: clock is not synchronised. Zabbix triggers and graphs depend on correct time.',
                $hostname
            ), 'system');
        }
    }

    private function cleanup(string $factsDir): void
    {
        foreach (glob($factsDir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($factsDir);
    }
}
