<?php

declare(strict_types=1);

namespace ZbxDeploy\Core;

/**
 * Append-only record of who did what. Never write secrets into $detail -
 * this table is meant to be readable by anyone investigating an incident.
 */
final class Audit
{
    /** @param array<string, mixed> $detail */
    public static function record(
        string $action,
        ?string $objectType = null,
        ?int $objectId = null,
        array $detail = [],
        ?string $actor = null
    ): void {
        try {
            Database::run(
                'INSERT INTO audit (actor, action, object_type, object_id, detail, ip_address)
                 VALUES (:actor, :action, :object_type, :object_id, :detail, :ip)',
                [
                    'actor'       => $actor ?? (Auth::check() ? Auth::user() : 'system'),
                    'action'      => $action,
                    'object_type' => $objectType,
                    'object_id'   => $objectId,
                    'detail'      => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_SLASHES),
                    'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
                ]
            );
        } catch (\Throwable $e) {
            // Auditing must never break the action it is recording.
            error_log('Audit write failed: ' . $e->getMessage());
        }
    }

    /** @return list<array<string, mixed>> */
    public static function recent(int $limit = 50): array
    {
        return Database::all('SELECT * FROM audit ORDER BY id DESC LIMIT ' . $limit);
    }
}
