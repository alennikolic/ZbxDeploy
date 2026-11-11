<?php

declare(strict_types=1);

namespace ZbxDeploy\Worker;

use ZbxDeploy\Core\Config;
use ZbxDeploy\Core\Crypto;
use ZbxDeploy\Core\JobQueue;

/**
 * Builds a throwaway inventory for a set of hosts and runs a playbook against
 * it, streaming every output line into job_logs as it appears.
 *
 * Nothing here is persisted: the inventory and any decrypted key material live
 * in a 0700 directory under DATA_DIR/tmp and are removed in finally.
 */
final class AnsibleRunner
{
    /**
     * @param list<array<string, mixed>> $hosts  host rows joined with their credential
     * @param array<string, mixed>       $extraVars
     */
    public function run(
        int $jobId,
        array $hosts,
        string $playbook,
        array $extraVars = [],
        bool $checkMode = false
    ): int {
        if ($hosts === []) {
            JobQueue::log($jobId, 'No hosts selected for this job.', 'system');

            return 1;
        }

        $workDir = $this->makeWorkDir($jobId);

        try {
            $inventoryPath = $this->writeInventory($workDir, $hosts);
            $varsPath = $workDir . '/extra_vars.json';
            file_put_contents($varsPath, json_encode($extraVars, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            chmod($varsPath, 0600);

            $playbookPath = Config::appRoot() . '/ansible/playbooks/' . $playbook;
            if (!is_file($playbookPath)) {
                JobQueue::log($jobId, 'Playbook not found: ' . $playbookPath, 'system');

                return 2;
            }

            $command = [
                'ansible-playbook',
                '-i', $inventoryPath,
                $playbookPath,
                '-e', '@' . $varsPath,
            ];

            if ($checkMode) {
                $command[] = '--check';
                $command[] = '--diff';
            }
            if (Config::bool('ANSIBLE_VERBOSE', false)) {
                $command[] = '-v';
            }

            JobQueue::log($jobId, sprintf(
                'Running %s against %d host(s)%s',
                $playbook,
                count($hosts),
                $checkMode ? ' in check mode' : ''
            ), 'system');

            return $this->execute($jobId, $command, $workDir);
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    private function makeWorkDir(int $jobId): string
    {
        $dir = sprintf('%s/tmp/job-%d-%s', Config::dataDir(), $jobId, bin2hex(random_bytes(4)));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create work directory: ' . $dir);
        }

        return $dir;
    }

    /** @param list<array<string, mixed>> $hosts */
    private function writeInventory(string $workDir, array $hosts): string
    {
        $lines = ['[targets]'];

        foreach ($hosts as $host) {
            $parts = [
                $this->inventoryName((string) $host['hostname']),
                'ansible_host=' . $host['address'],
                'ansible_port=' . (int) $host['ssh_port'],
            ];

            if (!empty($host['ssh_user'])) {
                $parts[] = 'ansible_user=' . $host['ssh_user'];
            }

            if (($host['auth_type'] ?? null) === 'key' && !empty($host['secret'])) {
                $keyPath = sprintf('%s/key_%d', $workDir, (int) $host['id']);
                file_put_contents($keyPath, $this->normalizeKey(Crypto::decrypt((string) $host['secret'])));
                chmod($keyPath, 0600);
                $parts[] = 'ansible_ssh_private_key_file=' . $keyPath;
            } elseif (($host['auth_type'] ?? null) === 'password' && !empty($host['secret'])) {
                $parts[] = 'ansible_password=' . $this->quote(Crypto::decrypt((string) $host['secret']));
            }

            if (($host['become_method'] ?? 'sudo') === 'sudo') {
                $parts[] = 'ansible_become=true';
                $parts[] = 'ansible_become_method=sudo';
                if (!empty($host['become_password'])) {
                    $parts[] = 'ansible_become_password=' . $this->quote(Crypto::decrypt((string) $host['become_password']));
                }
            }

            $lines[] = implode(' ', $parts);
        }

        $lines[] = '';
        $lines[] = '[targets:vars]';
        $lines[] = 'ansible_python_interpreter=/usr/bin/python3';

        $path = $workDir . '/inventory.ini';
        file_put_contents($path, implode("\n", $lines) . "\n");
        chmod($path, 0600);

        return $path;
    }

    /**
     * Ansible inventory names must not contain spaces or '='.
     */
    private function inventoryName(string $hostname): string
    {
        return preg_replace('/[^A-Za-z0-9_.\-]/', '_', $hostname) ?? 'host';
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * OpenSSH refuses keys without a trailing newline.
     */
    private function normalizeKey(string $key): string
    {
        return rtrim(str_replace("\r\n", "\n", $key), "\n") . "\n";
    }

    /** @param list<string> $command */
    private function execute(int $jobId, array $command, string $workDir): int
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = [
            'PATH'                    => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME'                    => Config::dataDir(),
            'ANSIBLE_CONFIG'          => Config::appRoot() . '/ansible/ansible.cfg',
            'ANSIBLE_FORCE_COLOR'     => '0',
            'ANSIBLE_NOCOLOR'         => '1',
            'PYTHONUNBUFFERED'        => '1',
            'ANSIBLE_STDOUT_CALLBACK' => Config::get('ANSIBLE_STDOUT_CALLBACK', 'default') ?? 'default',
        ];

        $process = proc_open($command, $descriptors, $pipes, $workDir, $env);
        if (!is_resource($process)) {
            JobQueue::log($jobId, 'Could not start ansible-playbook.', 'system');

            return 127;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $buffers = [1 => '', 2 => ''];
        $streamNames = [1 => 'stdout', 2 => 'stderr'];

        while (true) {
            $read = [];
            foreach ([1, 2] as $fd) {
                if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
                    $read[$fd] = $pipes[$fd];
                }
            }

            if ($read === []) {
                break;
            }

            $write = $except = null;
            $readCopy = array_values($read);
            if (stream_select($readCopy, $write, $except, 1) === false) {
                break;
            }

            foreach ($read as $fd => $pipe) {
                $chunk = fread($pipe, 8192);
                if ($chunk !== false && $chunk !== '') {
                    $buffers[$fd] .= $chunk;
                    $buffers[$fd] = $this->flushLines($jobId, $buffers[$fd], $streamNames[$fd]);
                }
                if (feof($pipe)) {
                    fclose($pipe);
                    unset($pipes[$fd]);
                }
            }
        }

        foreach ([1, 2] as $fd) {
            if ($buffers[$fd] !== '') {
                JobQueue::log($jobId, $buffers[$fd], $streamNames[$fd]);
            }
        }

        $exitCode = proc_close($process);
        JobQueue::log($jobId, 'ansible-playbook exited with code ' . $exitCode, 'system');

        return $exitCode;
    }

    private function flushLines(int $jobId, string $buffer, string $stream): string
    {
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            JobQueue::log($jobId, $line, $stream);
        }

        return $buffer;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                // Key material: overwrite before unlinking.
                $size = $item->getSize();
                if ($size !== false && $size > 0) {
                    @file_put_contents($item->getPathname(), str_repeat("\0", (int) $size));
                }
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
