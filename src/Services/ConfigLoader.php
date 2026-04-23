<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Services;

/**
 * Resolves the database connection and migration configuration.
 *
 * Both CLI commands and web code call this service so there is one
 * cascade, one set of rules, and no duplicated resolution logic.
 *
 * Cascade (first match wins):
 *   1. Flight::get('db') for PDO, Flight::get('migrations') for config overrides
 *   2. app/config/migrations.php — flat DB keys for PDO, 'migrations' key for config
 *   3. config/migrations.php    — same as above
 *   4. RuntimeException
 */
class ConfigLoader
{
    /**
     * Resolve both the PDO connection and merged migration config.
     *
     * @return array{pdo: \PDO, config: array<string, mixed>}
     * @throws \RuntimeException if no database connection can be resolved
     */
    public static function load(): array
    {
        $source = self::findSource();

        if ($source === null) {
            throw new \RuntimeException(
                'migrations: No database connection found. '
                . 'Register a PDO with Flight::set(\'db\', $pdo), '
                . 'or create a migrations.php file with your database credentials '
                . 'in app/config/ or config/.'
            );
        }

        $defaults = require __DIR__ . '/../Config/Config.php';
        $config   = $source['override'] !== null
            ? array_replace_recursive($defaults, $source['override'])
            : $defaults;

        // Flight path already has a PDO instance.
        if (isset($source['pdo'])) {
            return ['pdo' => $source['pdo'], 'config' => $config];
        }

        // File path — build PDO from the flat DB credentials.
        if (!empty($source['dbCredentials'])) {
            return ['pdo' => self::buildPdo($source['dbCredentials']), 'config' => $config];
        }

        throw new \RuntimeException(
            'migrations: Found config at ' . ($source['path'] ?? 'unknown')
            . ' but no database credentials. Add host/dbname/user/password '
            . 'or use Flight::set(\'db\', $pdo).'
        );
    }

    /**
     * Resolve just the merged migration config (no DB connection).
     *
     * Useful for commands that only need paths/settings (e.g. migrate:make).
     *
     * @return array<string, mixed>
     */
    public static function loadConfig(): array
    {
        $source   = self::findSource();
        $defaults = require __DIR__ . '/../Config/Config.php';

        if ($source === null || $source['override'] === null) {
            return $defaults;
        }

        return array_replace_recursive($defaults, $source['override']);
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * Walk the cascade and return the first source found.
     *
     * @return array{pdo?: \PDO, dbCredentials?: array, override: ?array, path?: string}|null
     */
    private static function findSource(): ?array
    {
        // 1. Flight
        if (class_exists(\Flight::class, false)) {
            try {
                $candidate = \Flight::app()->get('db');
                if ($candidate instanceof \PDO) {
                    $override = null;
                    try {
                        $m = \Flight::app()->get('migrations');
                        if (is_array($m)) {
                            $override = ['migrations' => $m];
                        }
                    } catch (\Throwable) {
                    }

                    return ['pdo' => $candidate, 'override' => $override];
                }
            } catch (\Throwable) {
                // Flight loaded but no db registered — fall through.
            }
        }

        // 2-3. File-based
        $root       = defined('RUNWAY_PROJECT_ROOT') ? RUNWAY_PROJECT_ROOT : getcwd();
        $candidates = [
            $root . '/app/config/migrations.php',
            $root . '/config/migrations.php',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                $data = require $path;
                if (is_array($data)) {
                    // Pull out the 'migrations' key as config override.
                    $migrations = $data['migrations'] ?? null;
                    unset($data['migrations']);

                    $override = $migrations !== null
                        ? ['migrations' => $migrations]
                        : null;

                    // Everything left is flat DB credentials.
                    return [
                        'dbCredentials' => $data,
                        'override'      => $override,
                        'path'          => $path,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Build a PDO instance from a credentials array.
     *
     * @param array<string, mixed> $db
     */
    private static function buildPdo(array $db): \PDO
    {
        $driver  = $db['driver']   ?? 'mysql';
        $host    = $db['host']     ?? 'localhost';
        $port    = $db['port']     ?? 3306;
        $dbname  = $db['dbname']   ?? '';
        $user    = $db['user']     ?? '';
        $pass    = $db['password'] ?? '';
        $charset = $db['charset']  ?? 'utf8mb4';

        $dsn = "{$driver}:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        return new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}
