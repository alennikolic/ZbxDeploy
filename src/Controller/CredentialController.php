<?php
// src/Controller/CredentialController.php

declare(strict_types=1);

namespace ZbxDeploy\Controller;

use ZbxDeploy\Core\Audit;
use ZbxDeploy\Core\Crypto;
use ZbxDeploy\Core\Csrf;
use ZbxDeploy\Core\Database;
use ZbxDeploy\Core\Flash;
use ZbxDeploy\Core\View;

/**
 * Credentials are write-only from the UI: you can create and replace them,
 * never read them back.
 */
final class CredentialController
{
    public function index(): void
    {
        View::send('credentials/index.twig', [
            'credentials' => Database::all(
                'SELECT c.id, c.name, c.ssh_user, c.auth_type, c.become_method, c.created_at,
                        e.name AS environment_name,
                        (SELECT COUNT(*) FROM hosts h WHERE h.credential_id = c.id) AS host_count
                 FROM credentials c
                 LEFT JOIN environments e ON e.id = c.environment_id
                 ORDER BY c.name'
            ),
            'environments' => Database::all('SELECT id, name FROM environments ORDER BY name'),
        ]);
    }

    public function store(): void
    {
        Csrf::requireValid();

        $name = trim((string) ($_POST['name'] ?? ''));
        $sshUser = trim((string) ($_POST['ssh_user'] ?? ''));
        $authType = ($_POST['auth_type'] ?? 'key') === 'password' ? 'password' : 'key';
        $secret = (string) ($_POST['secret'] ?? '');
        $becomeMethod = ($_POST['become_method'] ?? 'sudo') === 'none' ? 'none' : 'sudo';
        $becomePassword = (string) ($_POST['become_password'] ?? '');
        $environmentId = (int) ($_POST['environment_id'] ?? 0) ?: null;

        if ($name === '' || $sshUser === '' || trim($secret) === '') {
            Flash::error('Name, SSH user and the key or password are all required.');
            View::redirect('/credentials');
        }

        if ($authType === 'key' && !str_contains($secret, 'PRIVATE KEY')) {
            Flash::error('That does not look like an OpenSSH private key. Paste the whole key file, including the BEGIN and END lines.');
            View::redirect('/credentials');
        }

        try {
            Database::run(
                'INSERT INTO credentials (environment_id, name, ssh_user, auth_type, secret, become_method, become_password)
                 VALUES (:env, :name, :ssh_user, :auth_type, :secret, :become_method, :become_password)',
                [
                    'env'             => $environmentId,
                    'name'            => $name,
                    'ssh_user'        => $sshUser,
                    'auth_type'       => $authType,
                    'secret'          => Crypto::encrypt($secret),
                    'become_method'   => $becomeMethod,
                    'become_password' => $becomePassword !== '' ? Crypto::encrypt($becomePassword) : null,
                ]
            );
        } catch (\PDOException $e) {
            Flash::error(str_contains($e->getMessage(), '1062')
                ? 'A credential with that name already exists.'
                : 'Could not store credential.');
            View::redirect('/credentials');
        }

        // Audit records that a credential was created - never any part of it.
        Audit::record('credential.created', 'credential', Database::lastInsertId(), [
            'name'      => $name,
            'ssh_user'  => $sshUser,
            'auth_type' => $authType,
        ]);

        Flash::success(sprintf('Credential "%s" stored.', $name));
        View::redirect('/credentials');
    }

    public function delete(string $id): void
    {
        Csrf::requireValid();

        $credential = Database::one('SELECT name FROM credentials WHERE id = :id', ['id' => (int) $id]);
        if ($credential === null) {
            Flash::error('No such credential.');
            View::redirect('/credentials');
        }

        Database::run('DELETE FROM credentials WHERE id = :id', ['id' => (int) $id]);
        Audit::record('credential.deleted', 'credential', (int) $id, ['name' => $credential['name']]);
        Flash::success('Credential deleted. Hosts that used it now have none.');

        View::redirect('/credentials');
    }
}
