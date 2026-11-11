<?php

declare(strict_types=1);

namespace ZbxDeploy\Core;

use PDO;
use PDOException;
use PDOStatement;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Config::get('DB_HOST', 'db'),
            Config::int('DB_PORT', 3306),
            Config::get('DB_NAME', 'zbxdeploy')
        );

        self::$pdo = new PDO(
            $dsn,
            Config::require('DB_USER'),
            Config::require('DB_PASSWORD'),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]
        );

        return self::$pdo;
    }

    /**
     * Wait for the database to accept connections. The worker starts alongside
     * MySQL and must not die on the first connection refused.
     */
    public static function waitUntilReady(int $timeoutSeconds = 120): void
    {
        $deadline = time() + $timeoutSeconds;

        while (true) {
            try {
                self::pdo()->query('SELECT 1');

                return;
            } catch (PDOException $e) {
                self::$pdo = null;
                if (time() >= $deadline) {
                    throw $e;
                }
                sleep(2);
            }
        }
    }

    /** @param array<string|int, mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<string|int, mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }
}
