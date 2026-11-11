<?php
// src/Core/Catalog.php

declare(strict_types=1);

namespace ZbxDeploy\Core;

/**
 * The role catalogue.
 *
 * Every role ZbxDeploy can apply is declared by one manifest file in
 * /catalog. A manifest describes what the role does, what it needs from the
 * host, and which parameters it takes - and the UI builds its form from that
 * description instead of a hand-written page per role.
 *
 * Adding a role is therefore: write the Ansible role, write the manifest.
 * No PHP changes.
 *
 * Manifests are plain PHP arrays rather than YAML on purpose: no parser, no
 * extra dependency, and a typo is a syntax error instead of a silent surprise
 * halfway through a deployment.
 */
final class Catalog
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $roles = null;

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        if (self::$roles !== null) {
            return self::$roles;
        }

        $roles = [];
        foreach (glob(Config::appRoot() . '/catalog/*.php') ?: [] as $file) {
            /** @var array<string, mixed> $manifest */
            $manifest = require $file;

            if (!isset($manifest['id'])) {
                throw new \RuntimeException('Catalog manifest without an id: ' . $file);
            }

            $manifest += [
                'label'          => $manifest['id'],
                'summary'        => '',
                'type'           => 'state',
                'risk'           => 'low',
                'order'          => 500,
                'ansible_role'   => $manifest['id'],
                'requires_facts' => [],
                // Roles that need the version matrix (repository URL, template
                // directory) say so; the apply job then refuses to run them on
                // a host with no matching zabbix_versions row.
                'requires_release' => false,
                'params'         => [],
            ];

            $roles[(string) $manifest['id']] = $manifest;
        }

        uasort($roles, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        self::$roles = $roles;

        return self::$roles;
    }

    /** @return array<string, mixed>|null */
    public static function get(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    public static function label(string $id): string
    {
        return (string) (self::get($id)['label'] ?? $id);
    }

    public static function order(string $id): int
    {
        return (int) (self::get($id)['order'] ?? 500);
    }

    /**
     * Turn raw form input into a clean parameter array, using the manifest as
     * the schema. Unknown keys are dropped; missing ones fall back to the
     * declared default.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: list<string>} [params, errors]
     */
    public static function validate(string $id, array $input): array
    {
        $manifest = self::get($id);
        if ($manifest === null) {
            return [[], ['Unknown role: ' . $id]];
        }

        $params = [];
        $errors = [];

        /** @var array<string, array<string, mixed>> $schema */
        $schema = $manifest['params'];

        foreach ($schema as $name => $spec) {
            $type = (string) ($spec['type'] ?? 'text');
            $given = $input[$name] ?? null;
            $label = (string) ($spec['label'] ?? $name);

            if ($type === 'bool') {
                $params[$name] = $given !== null && $given !== '' && $given !== '0';
                continue;
            }

            if ($given === null || $given === '') {
                if (($spec['required'] ?? false) === true) {
                    $errors[] = sprintf('%s is required.', $label);
                    continue;
                }
                $params[$name] = $spec['default'] ?? null;
                continue;
            }

            switch ($type) {
                case 'number':
                    if (!is_numeric($given)) {
                        $errors[] = sprintf('%s must be a number.', $label);
                        break;
                    }
                    $value = (int) $given;
                    if (isset($spec['min']) && $value < (int) $spec['min']) {
                        $errors[] = sprintf('%s must be at least %d.', $label, (int) $spec['min']);
                        break;
                    }
                    if (isset($spec['max']) && $value > (int) $spec['max']) {
                        $errors[] = sprintf('%s must be at most %d.', $label, (int) $spec['max']);
                        break;
                    }
                    $params[$name] = $value;
                    break;

                case 'select':
                    $options = array_keys((array) ($spec['options'] ?? []));
                    if (!in_array((string) $given, array_map('strval', $options), true)) {
                        $errors[] = sprintf('%s has an invalid value.', $label);
                        break;
                    }
                    $params[$name] = (string) $given;
                    break;

                case 'list':
                    // One entry per line in the textarea.
                    $items = preg_split('/[\r\n,]+/', (string) $given) ?: [];
                    $items = array_values(array_filter(array_map('trim', $items), static fn (string $s): bool => $s !== ''));
                    if ($items === [] && ($spec['required'] ?? false) === true) {
                        $errors[] = sprintf('%s needs at least one entry.', $label);
                        break;
                    }
                    $params[$name] = $items;
                    break;

                default:
                    $value = trim((string) $given);
                    if (isset($spec['pattern']) && !preg_match((string) $spec['pattern'], $value)) {
                        $errors[] = sprintf('%s is not in the expected format.', $label);
                        break;
                    }
                    $params[$name] = $value;
            }
        }

        return [$params, $errors];
    }

    /**
     * Stable fingerprint of what we are about to apply. Stored on the role row
     * so a later run can tell "nothing changed" from "needs applying".
     *
     * @param array<string, mixed> $params
     */
    public static function fingerprint(string $id, array $params, string $zabbixVersion): string
    {
        ksort($params);

        return hash('sha256', $id . '|' . $zabbixVersion . '|' . json_encode($params));
    }
}
