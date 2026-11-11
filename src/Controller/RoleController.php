<?php
// src/Controller/RoleController.php

declare(strict_types=1);

namespace ZbxDeploy\Controller;

use ZbxDeploy\Core\Audit;
use ZbxDeploy\Core\Auth;
use ZbxDeploy\Core\Catalog;
use ZbxDeploy\Core\Csrf;
use ZbxDeploy\Core\Database;
use ZbxDeploy\Core\Flash;
use ZbxDeploy\Core\JobQueue;
use ZbxDeploy\Core\View;

/**
 * Assigning roles to hosts, and queueing the job that makes reality match.
 *
 * The parameter form is rendered from the catalogue manifest, so this
 * controller never grows a branch per role.
 */
final class RoleController
{
    /**
     * Step one: pick a role. Step two (same page, with ?role=): fill in its
     * parameters. Two plain requests instead of a pile of JavaScript.
     */
    public function newForm(string $hostId): void
    {
        $host = $this->host((int) $hostId);
        $roleId = (string) ($_GET['role'] ?? '');
        $manifest = $roleId !== '' ? Catalog::get($roleId) : null;

        $assigned = Database::all(
            'SELECT role FROM host_roles WHERE host_id = :id',
            ['id' => $host['id']]
        );

        View::send('roles/new.twig', [
            'host'      => $host,
            'catalog'   => Catalog::all(),
            'assigned'  => array_column($assigned, 'role'),
            'manifest'  => $manifest,
        ]);
    }

    public function store(string $hostId): void
    {
        Csrf::requireValid();

        $host = $this->host((int) $hostId);
        $roleId = (string) ($_POST['role'] ?? '');

        if (Catalog::get($roleId) === null) {
            Flash::error('Unknown role.');
            View::redirect('/hosts/' . $host['id']);
        }

        [$params, $errors] = Catalog::validate($roleId, $_POST['params'] ?? []);

        if ($errors !== []) {
            Flash::error(implode(' ', $errors));
            View::redirect(sprintf('/hosts/%d/roles/new?role=%s', $host['id'], urlencode($roleId)));
        }

        Database::run(
            "INSERT INTO host_roles (host_id, role, params, desired_state, last_status)
             VALUES (:host, :role, :params, 'present', 'pending')
             ON DUPLICATE KEY UPDATE
                params = VALUES(params),
                desired_state = 'present',
                last_status = 'pending'",
            [
                'host'   => $host['id'],
                'role'   => $roleId,
                'params' => json_encode($params, JSON_UNESCAPED_SLASHES),
            ]
        );

        Audit::record('role.assigned', 'host', (int) $host['id'], ['role' => $roleId]);
        Flash::success(sprintf('%s assigned. Apply the host to put it into effect.', Catalog::label($roleId)));

        View::redirect('/hosts/' . $host['id']);
    }

    public function delete(string $hostId, string $roleRowId): void
    {
        Csrf::requireValid();

        $host = $this->host((int) $hostId);

        $row = Database::one(
            'SELECT * FROM host_roles WHERE id = :id AND host_id = :host',
            ['id' => (int) $roleRowId, 'host' => $host['id']]
        );

        if ($row === null) {
            Flash::error('No such role assignment.');
            View::redirect('/hosts/' . $host['id']);
        }

        Database::run('DELETE FROM host_roles WHERE id = :id', ['id' => $row['id']]);
        Audit::record('role.unassigned', 'host', (int) $host['id'], ['role' => $row['role']]);

        // Being explicit: removing the assignment does not undo the work.
        Flash::info(sprintf(
            '%s removed from the plan for %s. Nothing was uninstalled on the server.',
            Catalog::label((string) $row['role']),
            $host['hostname']
        ));

        View::redirect('/hosts/' . $host['id']);
    }

    /**
     * Queue an apply for one host. The check_mode checkbox maps straight onto
     * ansible-playbook --check, so a dry run is one click, not a second code path.
     */
    public function apply(string $hostId): void
    {
        Csrf::requireValid();

        $host = $this->host((int) $hostId);
        $checkMode = !empty($_POST['check_mode']);

        $roleCount = (int) Database::scalar(
            "SELECT COUNT(*) FROM host_roles WHERE host_id = :id AND desired_state = 'present'",
            ['id' => $host['id']]
        );

        if ($roleCount === 0) {
            Flash::error('Assign at least one role before applying.');
            View::redirect('/hosts/' . $host['id']);
        }

        if ($host['credential_id'] === null) {
            Flash::error('This host has no SSH credential attached.');
            View::redirect('/hosts/' . $host['id']);
        }

        $jobId = JobQueue::enqueue(
            'apply',
            sprintf('%s %s', $checkMode ? 'Check' : 'Apply', $host['hostname']),
            ['host_ids' => [(int) $host['id']]],
            (int) $host['environment_id'],
            $checkMode,
            Auth::user()
        );

        Audit::record('job.enqueued', 'job', $jobId, [
            'type'       => 'apply',
            'host'       => $host['hostname'],
            'check_mode' => $checkMode,
        ]);

        View::redirect('/jobs/' . $jobId);
    }

    /** @return array<string, mixed> */
    private function host(int $id): array
    {
        $host = Database::one('SELECT * FROM hosts WHERE id = :id', ['id' => $id]);

        if ($host === null) {
            http_response_code(404);
            View::send('error.twig', ['title' => 'Not found', 'message' => 'No such host.']);
            exit;
        }

        return $host;
    }
}
