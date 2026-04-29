<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Services;

use Composer\Semver\Comparator;
use Enlivenapp\Migrations\Exceptions\LockException;
use Enlivenapp\Migrations\Exceptions\MigrationException;
use Enlivenapp\Migrations\Services\ConfigLoader;
use Enlivenapp\Migrations\Services\DbConnection;
use Enlivenapp\Migrations\Services\SchemaBuilder;

/**
 * The main entry point for running migrations and seeds.
 *
 * Finds migration files, runs them in order, tracks what's been applied,
 * and rolls back cleanly if something fails. No framework needed - just
 * give it a PDO connection.
 *
 * Migration files go in your package's Database/Migrations/ directory.
 * Filename format: YYYY-MM-DD-HHmmss_ClassName.php
 *
 * Two ways to use it:
 *   - From a plugin system: call runMigrate() to run migrations and seeds
 *   - From the CLI (via Runway): call runMigrate(), runModule(), rollbackLast(), etc.
 */
class MigrationSetup
{
    private const TRACKING_TABLE = 'migrations';
    private const SEED_TABLE     = 'seeds';

    private SchemaBuilder        $schemaBuilder;
    private DbConnection         $db;
    private DatabaseMigrationLock $lock;

    private string $projectRoot;

    public function __construct(
        private ?\PDO $pdo = null,
        private array $config = [],
        ?string $projectRoot = null,
    ) {
        $this->projectRoot = $projectRoot
            ?? (defined('RUNWAY_PROJECT_ROOT') ? RUNWAY_PROJECT_ROOT
            : (defined('PROJECT_ROOT') ? PROJECT_ROOT
            : getcwd()));

        if ($this->pdo === null) {
            $resolved   = ConfigLoader::load();
            $this->pdo  = $resolved['pdo'];
            $defaults   = $resolved['config'];
        } else {
            $defaults = require __DIR__ . '/../Config/Config.php';
        }

        $this->config        = !empty($config)
            ? array_replace_recursive($defaults, $config)
            : $defaults;
        $this->db            = new DbConnection($this->pdo);
        $this->lock          = new DatabaseMigrationLock($this->db);
        $this->schemaBuilder = new SchemaBuilder($this->pdo);
    }

    // -----------------------------------------------------------------------
    // Hook-driven entry points (public API - stable signatures)
    // -----------------------------------------------------------------------

    /**
     * Run pending migrations and seeds for all packages, or a single package.
     *
     * Discovers packages from configured migration paths, reads the installed
     * version from composer/installed.json (ceiling) and the last seeded
     * version from the seeds table (floor), runs any pending migrations,
     * then runs seed deltas for packages whose version has changed.
     *
     * @param string|null $moduleName  Package name to run for, or null for all packages
     * @return array<string, ModuleResult>  Results keyed by package name (only packages where something ran)
     * @throws LockException If the migration lock is held by another process
     * @throws MigrationException If $moduleName is given but not found
     */
    /**
     * Run all pending migrations and seeds, optionally scoped to one module.
     *
     * @param  string|null $moduleName  Scope to a single module, or null for all
     * @return ModuleResult[]  Keyed by package name
     * @throws LockException If the migration lock is held by another process
     * @throws MigrationException If $moduleName is given but not found
     */
    public function runMigrate(?string $moduleName = null): array
    {
        $this->ensureTrackingTable();

        $dirs = $this->resolveAllMigrationDirs();

        if ($moduleName !== null) {
            if (! isset($dirs[$moduleName])) {
                throw new MigrationException(
                    "No migration directory found for module \"{$moduleName}\". "
                    . 'Does it have a Database/Migrations directory matching the configured paths?'
                );
            }
            $dirs = [$moduleName => $dirs[$moduleName]];
        }

        // Pre-check for pending work before acquiring the lock.
        // Avoids two UPDATE queries (acquire + release) on every request
        // when there is nothing to run.
        $allInstalledVersions = $this->loadAllInstalledVersions();
        $allSeededVersions    = $this->loadAllSeededVersions();
        $executedVersions     = $this->getExecutedVersions();

        $hasPending = false;

        foreach ($dirs as $name => $dir) {
            foreach ($this->discoverMigrationFiles($dir) as $version => $info) {
                if (! in_array($version, $executedVersions, true)) {
                    $hasPending = true;
                    break 2;
                }
            }
        }

        if (! $hasPending) {
            foreach ($dirs as $name => $dir) {
                $installedVersion = $allInstalledVersions[$name] ?? null;
                $seededVersion    = $allSeededVersions[$name] ?? null;
                if ($installedVersion !== null && $installedVersion !== $seededVersion) {
                    $seedDir = $this->resolveModuleSeedDir($dir);
                    if ($seedDir !== null && is_file(rtrim($seedDir, '/') . '/Seed.php')) {
                        $hasPending = true;
                        break;
                    }
                }
            }
        }

        if (! $hasPending) {
            return [];
        }

        if (! $this->lock->acquire()) {
            throw new LockException(
                'Migration lock is held by another process. '
                . 'If the previous run crashed, release with: php runway migrate:unlock'
            );
        }

        // Reload executed versions under lock — another process may have
        // run between our pre-check and lock acquisition.
        $executedVersions = $this->getExecutedVersions();
        $results = [];

        try {
            $batch = $this->nextBatch();

            foreach ($dirs as $name => $dir) {
                try {
                    $moduleResult     = new ModuleResult($name);
                    $installedVersion = $allInstalledVersions[$name] ?? null;
                    $seededVersion    = $allSeededVersions[$name] ?? null;

                    // Run pending migrations
                    $migrationResults = $this->runModuleMigrations($name, $dir, $batch, false, $executedVersions);
                    $moduleResult->setMigrationResults($migrationResults);

                    $ranMigrations = ! empty($migrationResults);

                    if ($moduleResult->hasMigrationFailure()) {
                        $moduleResult->setVersion($installedVersion);
                        $results[$name] = $moduleResult;
                        continue;
                    }

                    // Run seeds if version changed
                    $ranSeeds = false;
                    if ($installedVersion !== null && $installedVersion !== $seededVersion) {
                        $seedDir  = $this->resolveModuleSeedDir($dir);
                        $seedData = $this->resolveSeedData($name, $seedDir);
                        if ($seedData !== null) {
                            $seedResults = $this->runSeeds($name, $seedData, $seededVersion, $installedVersion);
                            $moduleResult->setSeedResults($seedResults);
                            $this->updateSeededVersion($name, $installedVersion);
                            $ranSeeds = true;
                        }
                    }

                    if ($ranMigrations || $ranSeeds) {
                        $moduleResult->setVersion($installedVersion);
                        $results[$name] = $moduleResult;
                    }
                } catch (\Throwable $e) {
                    error_log('migrations: ' . $name . ' — ' . $e->getMessage());
                    $moduleResult = new ModuleResult($name);
                    $moduleResult->fail($e->getMessage());
                    $results[$name] = $moduleResult;
                }
            }
        } finally {
            $this->lock->release();
        }

        return $results;
    }

    // -----------------------------------------------------------------------
    // CLI-mode entry points
    // -----------------------------------------------------------------------

    /**
     * Run every pending migration across all packages found in the configured paths.
     *
     * Scans all directories listed under migrations.paths in the config, finds
     * packages that have migration files not yet recorded in the tracking table,
     * and runs them all in timestamp order. All migrations that run together are
     * assigned the same batch number.
     *
     * If one package fails, its batch is reversed automatically, but other packages
     * are still attempted. Each package's result is included in the returned array
     * regardless of success or failure.
     *
     * @param bool $dryRun  When true, all migrations execute inside a database transaction
     *                      that is rolled back at the end - nothing is permanently written.
     *                      Useful for checking whether migrations will run cleanly.
     * @return MigrationResult[]  One result entry per migration attempted, across all packages.
     *                            Check each result's success flag individually.
     * @throws LockException  If another migration process is already holding the lock
     */
    /**
     * @deprecated Use runMigrate() directly. This method exists for backward compatibility.
     * @return ModuleResult[]
     */
    public function runAll(bool $dryRun = false): array
    {
        return $this->runMigrate();
    }

    /**
     * Run any pending migrations for one specific package.
     *
     * Looks up the package by name in the configured migration paths, then runs
     * only the migrations for that package that haven't been recorded yet. All
     * migrations that run in this call share the same batch number.
     *
     * If the package has no pending migrations, an empty array is returned - that
     * is not an error.
     *
     * @param string $moduleName  The package name to run migrations for, e.g. "vendor/my-plugin".
     *                            Must match a directory found in the configured migration paths.
     * @param bool   $dryRun      When true, DDL is captured without executing; tracking writes are suppressed
     *                            at the end - nothing is permanently written.
     * @return MigrationResult[]  One result entry per migration attempted for this package
     * @throws LockException       If another migration process is already holding the lock
     * @throws MigrationException  If no migration directory can be found for $moduleName
     */
    public function runModule(string $moduleName, bool $dryRun = false): array
    {
        $this->ensureTrackingTable();
        if (! $this->lock->acquire()) {
            throw new LockException(
                'Migration lock is held by another process. ' .
                'If the previous run crashed, release with: php runway migrate:unlock'
            );
        }

        try {
            $dirs = $this->resolveAllMigrationDirs();

            if (! isset($dirs[$moduleName])) {
                throw new MigrationException(
                    "No migration directory found for module \"{$moduleName}\". " .
                    'Does it have a Database/Migrations directory matching the configured paths?'
                );
            }

            if ($dryRun) {
                $this->schemaBuilder->setPretendMode(true);
            }

            $batch   = $this->nextBatch();
            $results = $this->runModuleMigrations($moduleName, $dirs[$moduleName], $batch, $dryRun);

            return $results;
        } finally {
            $this->schemaBuilder->setPretendMode(false);
            $this->lock->release();
        }
    }

    /**
     * Undo the most recent batch of migrations.
     *
     * A "batch" is the group of migrations that were all run together in a single
     * call to runMigrate() or runModule(). Every migration in that group shares the same
     * batch number, and rolling back a batch reverses all of them in newest-first order.
     *
     * If $module is given, only that package's most recent batch is rolled back. If
     * $module is null, the globally most recent batch across all packages is rolled back.
     *
     * Rollback stops early if it reaches a migration that has a breakpoint set. Migrations
     * after the breakpoint (i.e., newer than it) will be rolled back; the breakpointed
     * migration and anything older will not be touched.
     *
     * @param string|null $module   Package name to scope the rollback to, e.g. "vendor/my-plugin".
     *                              Pass null to roll back the most recent batch globally.
     * @param bool        $dryRun   When true, DDL is captured without executing; tracking writes are suppressed
     *                              at the end - nothing is permanently written.
     * @return MigrationResult[]  One result entry per migration that was rolled back (or attempted)
     * @throws LockException  If another migration process is already holding the lock
     */
    public function rollbackLast(?string $module = null, bool $dryRun = false): array
    {
        $this->ensureTrackingTable();
        if (! $this->lock->acquire()) {
            throw new LockException(
                'Migration lock is held by another process. ' .
                'If the previous run crashed, release with: php runway migrate:unlock'
            );
        }

        try {
            if ($dryRun) {
                $this->schemaBuilder->setPretendMode(true);
            }

            $results = $this->doRollbackBatch($module, $dryRun);

            return $results;
        } finally {
            $this->schemaBuilder->setPretendMode(false);
            $this->lock->release();
        }
    }

    /**
     * Drop all database tables for a package by running every migration's down() in reverse.
     *
     * This is destructive and permanent. It goes through every migration that has been
     * recorded for the package, newest first, calls down() on each one, and removes the
     * tracking row. The end result is as if the package was never installed.
     *
     * Rollback stops early at a breakpoint, just like rollbackLast(). If a breakpoint is
     * set, tables created before it will not be dropped.
     *
     * This method is only called by the migrate:purge CLI command, which requires the
     * developer to confirm the operation interactively before it runs.
     *
     * @param string $moduleName  Package name whose migrations should be purged, e.g. "vendor/my-plugin"
     * @param bool   $dryRun      When true, DDL is captured without executing; tracking writes are suppressed
     *                            at the end - nothing is permanently written. Use this to verify
     *                            the down() methods work before committing.
     * @return MigrationResult[]  One result entry per migration that was purged (or attempted)
     * @throws LockException  If another migration process is already holding the lock
     */
    public function purgeModule(string $moduleName, bool $dryRun = false): array
    {
        $this->ensureTrackingTable();
        if (! $this->lock->acquire()) {
            throw new LockException(
                'Migration lock is held by another process. ' .
                'If the previous run crashed, release with: php runway migrate:unlock'
            );
        }

        try {
            if ($dryRun) {
                $this->schemaBuilder->setPretendMode(true);
            }

            $results = $this->doPurgeModule($moduleName, $dryRun);

            return $results;
        } finally {
            $this->schemaBuilder->setPretendMode(false);
            $this->lock->release();
        }
    }

    /**
     * Release the migration lock so that migration commands can run again.
     *
     * The lock is normally acquired at the start of any migration operation and released
     * when it finishes. If the process crashes mid-run (power loss, fatal error, killed
     * process), the lock can be left in a held state and nothing else will be able to run
     * until it is cleared. Use this method - via the migrate:unlock CLI command - to
     * force-release it.
     *
     * Returns information about the lock before releasing it, so you can see who held it
     * and when it was acquired.
     *
     * @param bool $dryRun  When true, returns the current lock state but does not release it.
     *                      Useful for checking whether the lock is held without touching it.
     * @return array{is_locked: bool, locked_by: string|null, locked_at: string|null}
     *         is_locked - true if the lock was held at the time of the call
     *         locked_by - identifier of the process that held the lock, or null
     *         locked_at - ISO timestamp of when the lock was acquired, or null
     */
    public function forceUnlock(bool $dryRun = false): array
    {
        $this->ensureTrackingTable();

        $info = $this->lock->getLockInfo();

        if (! $dryRun) {
            $this->lock->release();
        }

        return [
            'is_locked' => $info !== null,
            'locked_by' => $info['locked_by'] ?? null,
            'locked_at' => $info['locked_at'] ?? null,
        ];
    }

    /**
     * Mark a migration as a breakpoint so that rollback will not go past it.
     *
     * When rolling back, the runner stops as soon as it reaches a migration with a
     * breakpoint set. This lets you protect a known-good state - for example, after
     * a release - so that running rollbackLast() in development doesn't accidentally
     * undo migrations from a previous deploy.
     *
     * To clear a breakpoint, call this method again with $enabled = false.
     *
     * @param string $version  The version timestamp of the migration to mark, in the format
     *                         YYYY-MM-DD-HHmmss (matches the filename prefix)
     * @param string $package  The package the migration belongs to, e.g. "vendor/my-plugin"
     * @param bool   $enabled  true to set the breakpoint (default); false to remove it
     */
    public function setBreakpoint(string $version, string $package, bool $enabled = true): void
    {
        $this->ensureTrackingTable();
        $this->db->execute(
            'UPDATE `' . self::TRACKING_TABLE . '` SET `breakpoint` = ? WHERE `version` = ? AND `package` = ?',
            [$enabled ? 1 : 0, $version, $package]
        );
    }

    /**
     * Return all migrations that exist on disk but have not been run yet.
     *
     * Scans every package found in the configured migration paths, compares the
     * files on disk against the tracking table, and returns anything that hasn't
     * been applied. Useful for showing what would run before actually running it.
     *
     * @return array<int, array{name: string, module: string, version: string, class: string}>
     *         Each entry describes one pending migration:
     *         name    - a combined string in the form "package:version_ClassName"
     *         module  - the package name, e.g. "vendor/my-plugin"
     *         version - the timestamp from the filename, e.g. "2026-01-15-143000"
     *         class   - the migration class name, e.g. "CreateUsersTable"
     */
    public function getPendingMigrations(): array
    {
        $this->ensureTrackingTable();
        $executed = $this->getExecutedVersions();
        $pending  = [];

        foreach ($this->resolveAllMigrationDirs() as $moduleName => $dir) {
            $files = $this->discoverMigrationFiles($dir);
            foreach ($files as $version => $info) {
                if (! in_array($version, $executed, true)) {
                    $pending[] = [
                        'name'    => $moduleName . ':' . $version . '_' . $info['class'],
                        'module'  => $moduleName,
                        'version' => $version,
                        'class'   => $info['class'],
                    ];
                }
            }
        }

        return $pending;
    }

    /**
     * Return every migration that has been run, pulled directly from the tracking table.
     *
     * Results are sorted oldest-first by version timestamp. Each entry includes the
     * batch number (so you can see which migrations ran together), the timestamp of
     * when it ran, and whether it has a breakpoint set.
     *
     * @return array<int, array{name: string, module: string, version: string, class: string, run_at: string, batch: int, breakpoint: bool}>
     *         Each entry describes one executed migration:
     *         name        - a combined string in the form "package:version_ClassName"
     *         module      - the package name, e.g. "vendor/my-plugin"
     *         version     - the timestamp from the filename, e.g. "2026-01-15-143000"
     *         class       - the migration class name, e.g. "CreateUsersTable"
     *         run_at      - datetime string of when this migration was applied
     *         batch       - integer batch number; migrations with the same number ran together
     *         breakpoint  - true if this migration has a rollback breakpoint set on it
     */
    public function getExecutedMigrations(): array
    {
        $this->ensureTrackingTable();

        $rows = $this->db->query(
            'SELECT `version`, `class`, `package`, `batch`, `run_at`, `breakpoint` FROM `' . self::TRACKING_TABLE . '` ORDER BY `version` ASC'
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'name'        => $row['package'] . ':' . $row['version'] . '_' . $row['class'],
                'module'      => $row['package'],
                'version'     => $row['version'],
                'class'       => $row['class'],
                'run_at'      => $row['run_at'],
                'batch'       => (int) $row['batch'],
                'breakpoint'  => (bool) ($row['breakpoint'] ?? false),
            ];
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Internal - tracking table
    // -----------------------------------------------------------------------

    private function ensureTrackingTable(): void
    {
        $markerFile = $this->projectRoot . DIRECTORY_SEPARATOR . '.migrations_installed';

        if (file_exists($markerFile)) {
            return;
        }

        $this->db->execute(
            "CREATE TABLE IF NOT EXISTS `" . self::TRACKING_TABLE . "` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `version`     VARCHAR(32) NOT NULL,
                `class`       VARCHAR(255) NOT NULL,
                `group`       VARCHAR(64) NOT NULL DEFAULT 'default',
                `package`     VARCHAR(255) NOT NULL,
                `batch`       INT UNSIGNED NOT NULL,
                `breakpoint`  TINYINT(1) NOT NULL DEFAULT 0,
                `reversal_ops` TEXT NULL,
                `run_at`      DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_migrations_version` (`version`),
                INDEX `idx_migrations_package` (`package`),
                INDEX `idx_migrations_batch` (`batch`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->execute(
            "CREATE TABLE IF NOT EXISTS `" . self::SEED_TABLE . "` (
                `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `package`  VARCHAR(255) NOT NULL,
                `version`  VARCHAR(32) NOT NULL,
                `run_at`   DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_seeds_package` (`package`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->lock->ensureStore();

        file_put_contents($markerFile, date('Y-m-d H:i:s'));
    }

    private function nextBatch(): int
    {
        $rows = $this->db->query('SELECT MAX(`batch`) AS `max_batch` FROM `' . self::TRACKING_TABLE . '`');
        return ((int) ($rows[0]['max_batch'] ?? 0)) + 1;
    }

    private function recordMigration(
        string $version,
        string $class,
        string $package,
        int    $batch,
        string $group = 'default',
        ?array $reversalOps = null,
    ): void {
        $this->db->execute(
            'INSERT INTO `' . self::TRACKING_TABLE . '` (`version`, `class`, `group`, `package`, `batch`, `reversal_ops`, `run_at`) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$version, $class, $group, $package, $batch, $reversalOps ? json_encode($reversalOps, JSON_THROW_ON_ERROR) : null, date('Y-m-d H:i:s')]
        );
    }

    private function removeMigrationRecord(string $version, string $package): void
    {
        $this->db->execute(
            'DELETE FROM `' . self::TRACKING_TABLE . '` WHERE `version` = ? AND `package` = ?',
            [$version, $package]
        );
    }

    private function loadReversalOps(string $version, string $package): ?array
    {
        $rows = $this->db->query(
            'SELECT `reversal_ops` FROM `' . self::TRACKING_TABLE . '` WHERE `version` = ? AND `package` = ?',
            [$version, $package]
        );

        if (empty($rows) || $rows[0]['reversal_ops'] === null) {
            return null;
        }

        return json_decode($rows[0]['reversal_ops'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Execute the rollback (down) for a single migration.
     *
     * For change()-based migrations, uses stored reversal_ops to undo.
     * Throws if a change() migration has no reversal_ops stored - the
     * rollback cannot proceed safely without them.
     *
     * @throws MigrationException When a change() migration cannot be reversed.
     */
    private function executeRollback(Migration $migrationObj, string $version, string $package, string $class): void
    {
        if ($migrationObj->usesChange()) {
            $ops = $this->loadReversalOps($version, $package);
            if ($ops !== null) {
                $this->schemaBuilder->reverseOperations($ops);
            } else {
                throw new MigrationException(
                    "Cannot roll back change() migration \"{$class}\" (version {$version}): "
                    . "no reversal_ops stored. Add a down() method to the migration class, "
                    . "or re-run the migration to store reversal ops."
                );
            }
        } else {
            $migrationObj->down();
        }
    }

    /**
     * Return all executed version strings (for pending check).
     *
     * @return string[]
     */
    private function getExecutedVersions(): array
    {
        $rows = $this->db->query('SELECT `version` FROM `' . self::TRACKING_TABLE . '`');
        return array_column($rows, 'version');
    }

    /**
     * Load all installed Composer versions at once, indexed by package name.
     *
     * @return array<string, string>  package name => version
     */
    private function loadAllInstalledVersions(): array
    {
        $installedFile = $this->projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR
            . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';

        if (! is_file($installedFile)) {
            return [];
        }

        $installed = json_decode(file_get_contents($installedFile), true);
        $packages  = $installed['packages'] ?? $installed ?? [];

        $versions = [];
        foreach ($packages as $pkg) {
            $name    = $pkg['name'] ?? null;
            $version = $pkg['version'] ?? null;
            if ($name !== null && $version !== null) {
                $versions[$name] = ltrim($version, 'v');
            }
        }

        return $versions;
    }

    /**
     * Load all seeded versions at once, indexed by package name.
     *
     * @return array<string, string>  package name => version
     */
    private function loadAllSeededVersions(): array
    {
        $rows = $this->db->query(
            'SELECT `package`, `version` FROM `' . self::SEED_TABLE . '`'
        );

        $versions = [];
        foreach ($rows as $row) {
            $versions[$row['package']] = $row['version'];
        }

        return $versions;
    }

    /**
     * Read the installed Composer version of a package from vendor/composer/installed.json.
     */
    private function readInstalledVersion(string $packageName): ?string
    {
        $installedFile = $this->projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR
            . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';

        if (! is_file($installedFile)) {
            return null;
        }

        $installed = json_decode(file_get_contents($installedFile), true);
        $packages  = $installed['packages'] ?? $installed ?? [];

        foreach ($packages as $pkg) {
            if (($pkg['name'] ?? null) === $packageName) {
                $version = $pkg['version'] ?? null;
                return $version !== null ? ltrim($version, 'v') : null;
            }
        }

        return null;
    }

    /**
     * Read the last seeded version for a package from the seeds table.
     */
    private function getSeededVersion(string $packageName): ?string
    {
        $rows = $this->db->query(
            'SELECT `version` FROM `' . self::SEED_TABLE . '` WHERE `package` = ?',
            [$packageName]
        );

        return ! empty($rows) ? $rows[0]['version'] : null;
    }

    /**
     * Record or update the seeded version for a package.
     */
    private function updateSeededVersion(string $packageName, string $version): void
    {
        $this->db->execute(
            'INSERT INTO `' . self::SEED_TABLE . '` (`package`, `version`, `run_at`) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE `version` = VALUES(`version`), `run_at` = VALUES(`run_at`)',
            [$packageName, $version, date('Y-m-d H:i:s')]
        );
    }

    // -----------------------------------------------------------------------
    // Internal - migration execution
    // -----------------------------------------------------------------------

    /**
     * Run all pending migrations for one module directory.
     *
     * All-or-nothing within the batch: if any migration fails, run down()
     * on all that succeeded in this batch in reverse order.
     *
     * @return MigrationResult[]
     */
    private function runModuleMigrations(string $moduleName, string $migrationDir, int $batch, bool $dryRun = false, ?array $executedVersions = null): array
    {
        $files    = $this->discoverMigrationFiles($migrationDir);
        $executed = $executedVersions ?? $this->getExecutedVersions();

        $results   = [];
        $succeeded = []; // [version => class] of migrations that ran in this batch

        foreach ($files as $version => $info) {
            if (in_array($version, $executed, true)) {
                continue; // already ran
            }

            $migrationObj = $this->loadMigrationClass($info['file'], $info['class']);

            try {
                // Enable safety net to protect against partial failure
                if (! $dryRun) {
                    $this->schemaBuilder->enableSafetyNet();
                }

                $ops = null;
                if ($migrationObj->usesChange()) {
                    $this->schemaBuilder->startRecording();
                    $migrationObj->change();
                    $ops = $this->schemaBuilder->stopRecording();
                } else {
                    $migrationObj->up();
                }

                // Migration succeeded — drop backup tables
                if (! $dryRun) {
                    $this->schemaBuilder->cleanupSafetyNet();
                }

                if (! $dryRun) {
                    $this->recordMigration($version, $info['class'], $moduleName, $batch, 'default', $ops);
                }

                $r = new MigrationResult($moduleName . ':' . $version, $moduleName);
                $r->markSuccess();
                $results[]              = $r;
                $succeeded[$version]    = $info;
            } catch (\Throwable $e) {
                // Restore this migration's partial changes
                if (! $dryRun) {
                    try {
                        $this->schemaBuilder->restoreSafetyNet();
                    } catch (\Throwable $restoreError) {
                        error_log('migrations: safety net restore failed for ' . $moduleName . ':' . $version . ' — ' . $restoreError->getMessage());
                    }
                }

                error_log('migrations: ' . $moduleName . ':' . $version . ' failed — ' . $e->getMessage());
                $r = new MigrationResult($moduleName . ':' . $version, $moduleName);
                $r->markFailed($e->getMessage());
                $results[] = $r;

                if (! $dryRun) {
                    // Reverse the batch - run down() on succeeded in reverse order
                    $this->reverseBatch($moduleName, $succeeded, $results);
                }
                break;
            }
        }

        return $results;
    }

    /**
     * Roll back migrations that succeeded in the current batch (all-or-nothing).
     *
     * @param string            $moduleName
     * @param array             $succeeded   [version => file_info] in execution order
     * @param MigrationResult[] &$results
     */
    private function reverseBatch(string $moduleName, array $succeeded, array &$results): void
    {
        if (empty($succeeded)) {
            return;
        }

        // Reverse order
        foreach (array_reverse($succeeded, true) as $version => $info) {
            try {
                $migrationObj = $this->loadMigrationClass($info['file'], $info['class']);
                $this->executeRollback($migrationObj, $version, $moduleName, $info['class']);
                $this->removeMigrationRecord($version, $moduleName);

                $r = new MigrationResult($moduleName . ':' . $version . ':rollback', $moduleName);
                $r->markSuccess('batch rollback');
                $results[] = $r;
            } catch (\Throwable $re) {
                error_log('migrations: rollback failed for ' . $moduleName . ':' . $version . ' — ' . $re->getMessage());
                $r = new MigrationResult($moduleName . ':' . $version . ':rollback', $moduleName);
                $r->markFailed('ROLLBACK FAILED: ' . $re->getMessage());
                $results[] = $r;
                // Continue reversing the rest regardless
            }
        }
    }

    /**
     * Internal: roll back the most recent batch, optionally scoped to a module.
     *
     * @return MigrationResult[]
     */
    private function doRollbackBatch(?string $module, bool $dryRun = false): array
    {
        // Find the batch number to roll back
        if ($module !== null) {
            $rows = $this->db->query(
                'SELECT MAX(`batch`) AS `b` FROM `' . self::TRACKING_TABLE . '` WHERE `package` = ?',
                [$module]
            );
        } else {
            $rows = $this->db->query(
                'SELECT MAX(`batch`) AS `b` FROM `' . self::TRACKING_TABLE . '`'
            );
        }

        $batch = (int) ($rows[0]['b'] ?? 0);

        if ($batch === 0) {
            return [];
        }

        // Fetch all migrations in that batch, newest version first
        if ($module !== null) {
            $rows = $this->db->query(
                'SELECT `version`, `class`, `package`, `breakpoint` FROM `' . self::TRACKING_TABLE . '` WHERE `batch` = ? AND `package` = ? ORDER BY `version` DESC',
                [$batch, $module]
            );
        } else {
            $rows = $this->db->query(
                'SELECT `version`, `class`, `package`, `breakpoint` FROM `' . self::TRACKING_TABLE . '` WHERE `batch` = ? ORDER BY `version` DESC',
                [$batch]
            );
        }

        if (empty($rows)) {
            return [];
        }

        $results = [];
        $dirs    = $this->resolveAllMigrationDirs();

        foreach ($rows as $row) {
            if ((int) ($row['breakpoint'] ?? 0) === 1) {
                break;
            }
            $package = $row['package'];
            $version   = $row['version'];
            $class     = $row['class'];

            // Locate the migration file
            $file = $this->findMigrationFile($dirs[$package] ?? null, $version, $class);

            if ($file === null) {
                error_log('migrations: file not found for ' . $package . ':' . $version . ' class ' . $class);
                $r = new MigrationResult($package . ':' . $version, $package);
                $r->markFailed("Migration file not found for version {$version}, class {$class}");
                $results[] = $r;
                continue;
            }

            try {
                $migrationObj = $this->loadMigrationClass($file, $class);
                $this->executeRollback($migrationObj, $version, $package, $class);
                if (! $dryRun) {
                    $this->removeMigrationRecord($version, $package);
                }

                $r = new MigrationResult($package . ':' . $version, $package);
                $r->markSuccess('rolled back');
                $results[] = $r;
            } catch (\Throwable $e) {
                error_log('migrations: rollback failed for ' . $package . ':' . $version . ' — ' . $e->getMessage());
                $r = new MigrationResult($package . ':' . $version, $package);
                $r->markFailed($e->getMessage());
                $results[] = $r;
                // Continue rolling back the rest of the batch
            }
        }

        return $results;
    }

    /**
     * Internal: purge all executed migrations for a module (down() + remove tracking rows).
     *
     * @return MigrationResult[]
     */
    private function doPurgeModule(string $moduleName, bool $dryRun = false): array
    {
        $rows = $this->db->query(
            'SELECT `version`, `class`, `breakpoint` FROM `' . self::TRACKING_TABLE . '` WHERE `package` = ? ORDER BY `version` DESC',
            [$moduleName]
        );

        if (empty($rows)) {
            return [];
        }

        $dirs    = $this->resolveAllMigrationDirs();
        $results = [];

        foreach ($rows as $row) {
            if ((int) ($row['breakpoint'] ?? 0) === 1) {
                break;
            }
            $version = $row['version'];
            $class   = $row['class'];

            $file = $this->findMigrationFile($dirs[$moduleName] ?? null, $version, $class);

            if ($file === null) {
                error_log('migrations: file not found for ' . $moduleName . ':' . $version . ' class ' . $class);
                $r = new MigrationResult($moduleName . ':' . $version, $moduleName);
                $r->markFailed("Migration file not found for version {$version}, class {$class}");
                $results[] = $r;
                continue;
            }

            try {
                $migrationObj = $this->loadMigrationClass($file, $class);
                $this->executeRollback($migrationObj, $version, $moduleName, $class);
                if (! $dryRun) {
                    $this->removeMigrationRecord($version, $moduleName);
                }

                $r = new MigrationResult($moduleName . ':' . $version, $moduleName);
                $r->markSuccess('purged');
                $results[] = $r;
            } catch (\Throwable $e) {
                error_log('migrations: purge failed for ' . $moduleName . ':' . $version . ' — ' . $e->getMessage());
                $r = new MigrationResult($moduleName . ':' . $version, $moduleName);
                $r->markFailed($e->getMessage());
                $results[] = $r;
            }
        }

        // Clear seed tracking so a fresh install re-seeds
        if (! $dryRun) {
            $this->db->execute(
                'DELETE FROM `' . self::SEED_TABLE . '` WHERE `package` = ?',
                [$moduleName]
            );
        }

        return $results;
    }

    // -----------------------------------------------------------------------
    // Internal - seed execution
    // -----------------------------------------------------------------------

    /**
     * Resolve seed data from Seed.php in the given seed directory.
     *
     * @return array|null
     */
    private function resolveSeedData(string $moduleName, ?string $seedDir): ?array
    {
        if ($seedDir === null || !is_dir($seedDir)) {
            return null;
        }

        $seedFile = rtrim($seedDir, '/') . '/Seed.php';

        if (! is_file($seedFile) || ! is_readable($seedFile)) {
            return null;
        }

        $data = require $seedFile;

        return is_array($data) ? $data : null;
    }

    /**
     * Execute seeds based on version comparison.
     *
     * Fresh install ($oldVersion === null):
     *   - Run 'install' block
     *   - Run all 'versions' where version <= $newVersion, ascending
     *
     * Update ($oldVersion !== null):
     *   - Do NOT run 'install' block
     *   - Run all 'versions' where $oldVersion < version <= $newVersion, ascending
     *
     * Seed failure: log, skip failing block, continue. No rollback.
     *
     * @return SeedResult[]
     */
    private function runSeeds(
        string  $moduleName,
        array   $seedData,
        ?string $oldVersion,
        string  $newVersion,
    ): array {
        $results = [];

        if ($oldVersion === null && ! empty($seedData['install'])) {
            foreach ($seedData['install'] as $entry) {
                $results[] = $this->executeSeedEntry($moduleName, $entry, 'install');
            }
        }

        $versions = array_keys($seedData['versions'] ?? []);
        usort($versions, fn($a, $b) => Comparator::lessThan($a, $b) ? -1 : (Comparator::greaterThan($a, $b) ? 1 : 0));

        foreach ($versions as $version) {
            $applicable = $oldVersion === null
                ? Comparator::lessThanOrEqualTo($version, $newVersion)
                : (Comparator::greaterThan($version, $oldVersion) && Comparator::lessThanOrEqualTo($version, $newVersion));

            if (! $applicable) {
                continue;
            }

            foreach ($seedData['versions'][$version] as $entry) {
                $results[] = $this->executeSeedEntry($moduleName, $entry, $version);
            }
        }

        return $results;
    }

    /**
     * Execute a single seed entry (one table + rows pair) using flight-AR.
     *
     * On failure: log the error and return a failed SeedResult. Does NOT throw.
     *
     * @param  array  $entry  ['table' => '...', 'rows' => [...]]
     * @param  string $context  'install' or a version string
     */
    private function executeSeedEntry(string $moduleName, array $entry, string $context): SeedResult
    {
        $table = $entry['table'] ?? null;
        $rows  = $entry['rows']  ?? [];

        $result = new SeedResult($moduleName, $context, $table ?? '(unknown)');

        if (empty($table) || empty($rows)) {
            error_log('migrations: seed entry missing table or rows for ' . $moduleName);
            $result->markFailed('Seed entry missing table or rows');
            return $result;
        }

        try {
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                $colList = implode(', ', array_map(fn($c) => '`' . $c . '`', $cols));
                $stmt = $this->pdo->prepare("INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders})");
                $stmt->execute(array_values($row));
            }

            $result->markSuccess(count($rows) . ' row(s) inserted into ' . $table);
        } catch (\Throwable $e) {
            error_log('migrations: seed failed for ' . $moduleName . ':' . $table . ' — ' . $e->getMessage());
            $result->markFailed($e->getMessage());
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Internal - file discovery and loading
    // -----------------------------------------------------------------------

    /**
     * Discover migration files in a directory, sorted by version (timestamp) ascending.
     *
     * Filename format: YYYY-MM-DD-HHmmss_ClassName.php
     *
     * @param  string $dir  Absolute path to migrations directory
     * @return array<string, array{file: string, class: string}>  version => {file, class}
     */
    private function discoverMigrationFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files  = glob(rtrim($dir, '/') . '/*.php') ?: [];
        $result = [];

        foreach ($files as $file) {
            $basename = basename($file, '.php');
            // Expected: YYYY-MM-DD-HHmmss_ClassName
            if (! preg_match('/^(\d{4}-\d{2}-\d{2}-\d{6})_([A-Za-z][A-Za-z0-9_]*)$/', $basename, $m)) {
                continue; // skip files that don't match the convention
            }
            $version         = $m[1];
            $class           = $m[2];
            $result[$version] = ['file' => $file, 'class' => $class];
        }

        // Sort by version (timestamp) ascending
        ksort($result);

        return $result;
    }

    /**
     * Find a specific migration file by version and class name.
     *
     * @param  string|null $dir
     * @param  string      $version
     * @param  string      $class
     * @return string|null  Absolute path, or null if not found
     */
    private function findMigrationFile(?string $dir, string $version, string $class): ?string
    {
        if ($dir === null || ! is_dir($dir)) {
            return null;
        }

        $expected = rtrim($dir, '/') . '/' . $version . '_' . $class . '.php';
        return is_file($expected) ? $expected : null;
    }

    /**
     * Load a migration class from a file, inject the SchemaBuilder, and return the instance.
     *
     * @throws MigrationException
     */
    private function loadMigrationClass(string $file, string $class): Migration
    {
        require_once $file;

        // The class may or may not be namespaced. Try it as-is first.
        if (! class_exists($class, false)) {
            // Scan declared classes for a match on the short name
            $found = null;
            foreach (get_declared_classes() as $declared) {
                if (self::classBasename($declared) === $class) {
                    $found = $declared;
                    break;
                }
            }

            if ($found === null) {
                throw new MigrationException(
                    "Migration class \"{$class}\" not found after requiring \"{$file}\". " .
                    'Ensure the class name matches the filename.'
                );
            }

            $class = $found;
        }

        $instance = new $class();

        if (! $instance instanceof Migration) {
            throw new MigrationException(
                "Migration class \"{$class}\" must extend Migration."
            );
        }

        $instance->setSchemaBuilder($this->schemaBuilder);

        return $instance;
    }

    /**
     * Return the short class name from a fully-qualified class name.
     */
    private static function classBasename(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }

    // -----------------------------------------------------------------------
    // Internal - path resolution
    // -----------------------------------------------------------------------

    /**
     * Derive the seed directory from a migration directory.
     *
     * Given a migration dir like vendor/x/y/src/Database/Migrations,
     * returns the sibling vendor/x/y/src/Database/Seeds if it exists.
     *
     * @return string|null  Absolute path, or null if not found
     */
    private function resolveModuleSeedDir(string $migrationDir): ?string
    {
        $seedDir = preg_replace('/Migrations$/', 'Seeds', $migrationDir);

        if ($seedDir !== null && $seedDir !== $migrationDir && is_dir($seedDir)) {
            return $seedDir;
        }

        return null;
    }

    /**
     * Resolve all migration directories from config wildcard paths.
     *
     * @return array<string, string>  module-name => absolute directory path
     */
    private function resolveAllMigrationDirs(): array
    {
        $patterns = $this->config['migrations']['paths'] ?? [];
        $dirs     = [];

        foreach ($patterns as $pattern) {
            $absPattern = $this->absolutePath($pattern);
            foreach (glob($absPattern, GLOB_ONLYDIR) ?: [] as $dir) {
                $moduleName = $this->deriveModuleName($dir, $pattern);
                if ($moduleName !== null) {
                    $dirs[$moduleName] = $dir;
                }
            }
        }

        return $dirs;
    }

    /**
     * Resolve a path relative to the project root if it is not already absolute.
     */
    private function absolutePath(string $path): string
    {
        if ($path !== '' && $path[0] === DIRECTORY_SEPARATOR) {
            return $path;
        }

        return rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * Derive a Composer-style module name from a resolved path and the
     * original wildcard pattern.
     *
     * vendor/author/package/... → "author/package"
     * plugins/name/...          → "plugins/name"
     */
    private function deriveModuleName(string $dir, string $pattern): ?string
    {
        if (str_starts_with($pattern, 'vendor/')) {
            $parts     = explode('/', $dir);
            $vendorIdx = array_search('vendor', $parts);
            if ($vendorIdx !== false && isset($parts[$vendorIdx + 1], $parts[$vendorIdx + 2])) {
                return $parts[$vendorIdx + 1] . '/' . $parts[$vendorIdx + 2];
            }
        }

        if (str_starts_with($pattern, 'plugins/')) {
            $parts     = explode('/', $dir);
            $pluginIdx = array_search('plugins', $parts);
            if ($pluginIdx !== false && isset($parts[$pluginIdx + 1])) {
                return 'plugins/' . $parts[$pluginIdx + 1];
            }
        }

        return basename($dir) ?: null;
    }
}
