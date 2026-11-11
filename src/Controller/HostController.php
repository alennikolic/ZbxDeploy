<?php
// src/Controller/HostController.php

declare(strict_types=1);

namespace ZbxDeploy\Controller;

use ZbxDeploy\Core\Audit;
use ZbxDeploy\Core\Auth;
use ZbxDeploy\Core\Csrf;
use ZbxDeploy\Core\Database;
use ZbxDeploy\Core\Flash;
use ZbxDeploy\Core\JobQueue;
use ZbxDeploy\Core\View;

final class HostController
{
    public function index(): void
    {
        $hosts = Database::all(
            'SELECT h.*, e.name AS environment_name, c.name AS credential_name
             FROM hosts h
             JOIN environments e ON e.id = h.environment_id
             LEFT JOIN credentials c ON c.id = h.credential_id
             ORDER BY e.name, h.hostname'
        );

        View::send('hosts/index.twig', [
            'hosts'        => $hosts,
            'environments' => Database::all('SELECT id, name FROM environments ORDER BY name'),
            'credentials'  => Database::all('SELECT id, name, ssh_user FROM credentials ORDER BY name'),
        ]);
    }

    public function store(): void
    {
        Csrf::requireValid();

        $environmentId = (int) ($_POST['environment_id'] ?? 0);
        $hostname = trim((string) ($_POST['hostname'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $sshPort = (int) ($_POST['ssh_port'] ?? 22);
        $credentialId = (int) ($_POST['credential_id'] ?? 0) ?: null;
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($hostname === '' || $address === '' || $environmentId === 0) {
            Flash::error('Environment, hostname and address are required.');
            View::redirect('/hosts');
        }

        if ($sshPort < 1 || $sshPort > 65535) {
            Flash::error('SSH port must be between 1 and 65535.');
            View::redirect('/hosts');
        }

        try {
            Database::run(
                'INSERT INTO hosts (environment_id, hostname, address, ssh_port, credential_id, notes)
                 VALUES (:env, :hostname, :address, :port, :cred, :notes)',
                [
                    'env'      => $environmentId,
                    'hostname' => $hostname,
                    'address'  => $address,
                    'port'     => $sshPort,
                    'cred'     => $credentialId,
                    'notes'    => $notes !== '' ? $notes : null,
                ]
            );
        } catch (\PDOException $e) {
            Flash::error(str_contains($e->getMessage(), '1062')
                ? sprintf('Host "%s" already exists in that environment.', $hostname)
                : 'Could not add host: ' . $e->getMessage());
            View::redirect('/hosts');
        }

        $hostId = Database::lastInsertId();
        Audit::record('host.created', 'host', $hostId, ['hostname' => $hostname, 'address' => $address]);
        Flash::success(sprintf('Host %s added. Run a probe to collect its facts.', $hostname));

        View::redirect('/hosts');
    }

    public function show(string $id): void
    {
        $host = Database::one(
            'SELECT h.*, e.name AS environment_name, c.name AS credential_name
             FROM hosts h
             JOIN environments e ON e.id = h.environment_id
             LEFT JOIN credentials c ON c.id = h.credential_id
             WHERE h.id = :id',
            ['id' => (int) $id]
        );

        if ($host === null) {
            http_response_code(404);
            View::send('error.twig', ['title' => 'Not found', 'message' => 'No such host.']);

            return;
        }

        $facts = $host['facts'] !== null ? json_decode((string) $host['facts'], true) : null;

        View::send('hosts/show.twig', [
            'host'    => $host,
            'facts'   => is_array($facts) ? $facts : [],
            'catalog' => \ZbxDeploy\Core\Catalog::all(),
            'roles'   => Database::all('SELECT * FROM host_roles WHERE host_id = :id ORDER BY role', ['id' => $host['id']]),
            'jobs'  => Database::all(
                "SELECT * FROM jobs WHERE JSON_CONTAINS(params->'$.host_ids', :id, '$') ORDER BY id DESC LIMIT 10",
                ['id' => (string) (int) $host['id']]
            ),
        ]);
    }

    /**
     * Queue a probe for one host or, with no id, for every host that has a
     * credential attached.
     */
    public function probe(?string $id = null): void
    {
        Csrf::requireValid();

        if ($id !== null) {
            $host = Database::one('SELECT * FROM hosts WHERE id = :id', ['id' => (int) $id]);
            if ($host === null) {
                Flash::error('No such host.');
                View::redirect('/hosts');
            }
            $hostIds = [(int) $host['id']];
            $environmentId = (int) $host['environment_id'];
            $title = sprintf('Probe %s', $host['hostname']);
        } else {
            $rows = Database::all('SELECT id, environment_id FROM hosts WHERE credential_id IS NOT NULL');
            if ($rows === []) {
                Flash::error('No hosts with credentials to probe.');
                View::redirect('/hosts');
            }
            $hostIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            $environmentId = (int) $rows[0]['environment_id'];
            $title = sprintf('Probe %d hosts', count($hostIds));
        }

        $jobId = JobQueue::enqueue(
            'probe',
            $title,
            ['host_ids' => $hostIds],
            $environmentId,
            false,
            Auth::user()
        );

        Audit::record('job.enqueued', 'job', $jobId, ['type' => 'probe', 'host_ids' => $hostIds]);
        Flash::success('Probe queued.');

        View::redirect('/jobs/' . $jobId);
    }

    public function delete(string $id): void
    {
        Csrf::requireValid();

        $host = Database::one('SELECT * FROM hosts WHERE id = :id', ['id' => (int) $id]);
        if ($host === null) {
            Flash::error('No such host.');
            View::redirect('/hosts');
        }

        Database::run('DELETE FROM hosts WHERE id = :id', ['id' => (int) $id]);
        Audit::record('host.deleted', 'host', (int) $id, ['hostname' => $host['hostname']]);
        Flash::success(sprintf('Host %s removed from the inventory. Nothing was changed on the server itself.', $host['hostname']));

        View::redirect('/hosts');
    }
}
