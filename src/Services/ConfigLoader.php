<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Services;

/**
 * Builds the database connection and merged migration configuration.
 *
 * Both CLI commands and web code call this service so there is one
 * cascade, one set of rules, and no duplicated resolution logic.
 *
 * Cascade (first match wins):
 *   1. Flight::get('db') for PDO, Flight::get('migrations') for config overrides
 *   2. app/config/migrations.php — flat DB keys for PDO, 'migrations' key for config
 *   3. RuntimeException
 *
 * The engine resolves the database connection itself; you never pass a PDO into
 * Migrations. The final config is produced by {@see mergeConfig()}, which applies
 * the `path_mode` rules to `paths` and `seeds.paths`.
 *
 * @deprecated 'keys' is the legacy default for `path_mode`. It preserves the old
 *             positional merge behavior and will be replaced by 'replace' as the
 *             default in a future release. Set `path_mode` explicitly to opt into
 *             'replace'/'add' or to silence this notice.
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
                . 'or create app/config/migrations.php with your database credentials.'
            );
        }

        $defaults = require __DIR__ . '/../Config/Config.php';
        $config   = self::mergeConfig($defaults, $source['override']);

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

        return self::mergeConfig($defaults, $source['override'] ?? null);
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * Combine defaults with an override according to `path_mode`.
     *
     * `path_mode` governs how override `paths` and `seeds.paths` combine with the
     * defaults (it applies to both, using one mode):
     *
     *   - replace : the override array replaces the whole paths/seeds array.
     *               If the override does not supply a section, its defaults remain.
     *   - add     : override paths are appended to the defaults, deduped.
     *   - keys    : legacy positional merge via array_replace_recursive (default).
     *
     * All other keys (e.g. `versions`, `module_names`) merge recursively as before.
     *
     * @param  array<string, mixed>      $defaults
     * @param  array<string, mixed>|null $override  The 'migrations' override array.
     * @return array<string, mixed>
     */
    private static function mergeConfig(array $defaults, ?array $override): array
    {
        if ($override === null) {
            return $defaults;
        }

        $merged = array_replace_recursive($defaults, ['migrations' => $override]);
        $mode   = $merged['migrations']['path_mode'] ?? 'keys';

        $merged['migrations']['paths']
            = self::mergeList(
                $defaults['migrations']['paths'] ?? [],
                $override['paths'] ?? null,
                $mode
            );

        $merged['migrations']['seeds']['paths']
            = self::mergeList(
                $defaults['migrations']['seeds']['paths'] ?? [],
                $override['seeds']['paths'] ?? null,
                $mode
            );

        return $merged;
    }

    /**
     * Combine one paths list (migrations or seeds) per the given mode.
     *
     * @param  string[]     $defaultList
     * @param  string[]|null $overrideList
     * @param  string       $mode  replace|keys|add
     * @return string[]
     */
    private static function mergeList(array $defaultList, ?array $overrideList, string $mode): array
    {
        if ($overrideList === null) {
            return $defaultList;
        }

        return match ($mode) {
            'replace' => array_values($overrideList),
            'add'     => array_values(array_unique(array_merge($defaultList, $overrideList))),
            default   => array_replace_recursive($defaultList, $overrideList),
        };
    }

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
                            $override = $m;
                        }
                    } catch (\Throwable) {
                    }

                    return ['pdo' => $candidate, 'override' => $override];
                }
            } catch (\Throwable) {
                // Flight loaded but no db registered — fall through.
            }
        }

        // 2. File-based
        $root   = defined('RUNWAY_PROJECT_ROOT') ? RUNWAY_PROJECT_ROOT : getcwd();
        $path   = $root . '/app/config/migrations.php';

        if (is_file($path)) {
            $data = require $path;
            if (is_array($data)) {
                // Pull out the 'migrations' key as config override.
                $migrations = $data['migrations'] ?? null;
                unset($data['migrations']);
                if (!is_array($migrations)) {
                    $migrations = null;
                }

                // Everything left is flat DB credentials.
                return [
                    'dbCredentials' => $data,
                    'override'      => $migrations,
                    'path'          => $path,
                ];
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
