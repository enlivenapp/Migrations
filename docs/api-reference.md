# API Reference

## MigrationSetup

### Constructor

```php
new MigrationSetup(?\PDO $pdo = null, array $config = [], ?string $projectRoot = null)
```

No framework dependency. If no PDO is provided, `ConfigLoader` resolves the connection automatically: Flight first, then `app/config/migrations.php`, then `config/migrations.php`. See [Configuration](../README.md#configuration) for details. Pass `$config` only to override specific defaults. Pass `$projectRoot` to set the base directory for resolving relative paths (defaults to `RUNWAY_PROJECT_ROOT`, `PROJECT_ROOT`, or `getcwd()`).

### Programmatic entry point

| Method | Description | Return |
|---|---|---|
| `runMigrate(?string $moduleName = null)` | Run pending migrations and seeds for all packages (or one if `$moduleName` given). Versions resolved internally from `composer/installed.json` and the `seeds` table. | `array<string, ModuleResult>` |

**`runMigrate` behavior:**

- On first run, creates infrastructure tables (`migrations`, `seeds`, `migrations_lock`) and writes a `.migrations_installed` marker file in the project root. Subsequent requests skip table creation entirely (file check only, no DB).
- Preloads all installed versions (from `composer/installed.json`) and seeded versions (single query) in bulk.
- Compares pending migration files and seed versions against what's been run. If nothing is pending, returns early — no lock acquired, no batch created.
- When work is pending: acquires the lock, runs migrations and seeds in one batch, releases the lock.
- Infrastructure errors (no DB, lock held) throw. Per-package errors are caught and recorded in the result.
- Returns only packages where something ran, keyed by package name.

Seed logic: fresh install (no prior seed record) runs the `install` block plus all `versions` <= installed version. Update runs only `versions` between the last seeded version and the installed version.

### CLI-mode methods

| Method | Description | Return |
|---|---|---|
| `runAll(bool $dryRun = false)` | Run all pending migrations across all discovered modules | `MigrationResult[]` |
| `runModule(string $moduleName, bool $dryRun = false)` | Run pending migrations for one module | `MigrationResult[]` |
| `rollbackLast(?string $module = null, bool $dryRun = false)` | Roll back most recent batch; scope to a module or global | `MigrationResult[]` |
| `purgeModule(string $moduleName, bool $dryRun = false)` | Run `down()` on all executed migrations for a module, remove tracking rows | `MigrationResult[]` |
| `forceUnlock(bool $dryRun = false)` | Release the migration lock; `$dryRun = true` reports state without changing it | `array{is_locked: bool, locked_by: string\|null, locked_at: string\|null}` |
| `setBreakpoint(string $version, string $package, bool $enabled = true)` | Set or clear the breakpoint flag on a migration; rollback stops before a set breakpoint | `void` |
| `getPendingMigrations()` | Return all pending migrations across all discovered modules | `array<int, array{name: string, module: string, version: string, class: string}>` |
| `getExecutedMigrations()` | Return all executed migrations from the tracking table | `array<int, array{name: string, module: string, version: string, class: string, run_at: string, batch: int, breakpoint: bool}>` |

---

## ModuleResult

Returned by `runMigrate()`.

| Method | Return | Description |
|---|---|---|
| `getModuleName()` | `string` | Composer package name passed to the lifecycle method |
| `isSuccess()` | `bool` | `false` if any non-rollback migration failed or an exception was caught |
| `getMessage()` | `?string` | Overall message; first failure message, or `null` if none set |
| `getVersion()` | `?string` | Installed version of the package, set by `runMigrate()` |
| `getMigrationResults()` | `MigrationResult[]` | Per-migration outcomes |
| `getSeedResults()` | `SeedResult[]` | Per-seed-entry outcomes (seed failures do not flip `isSuccess`) |
| `hasMigrationFailure()` | `bool` | `true` if any non-rollback migration result is a failure |

---

## MigrationResult

Value object for a single migration execution attempt.

| Method | Return | Description |
|---|---|---|
| `getName()` | `string` | `module:version` (or `module:version:rollback` for rollback entries) |
| `getModule()` | `string` | Composer package name |
| `isSuccess()` | `bool` | Whether the migration ran successfully |
| `getMessage()` | `?string` | `'ok'` on success, error string on failure |

---

## SeedResult

Value object for a single seed entry (one table + rows pair).

| Method | Return | Description |
|---|---|---|
| `getModuleName()` | `string` | Composer package name |
| `getContext()` | `string` | `'install'` or a version string (e.g. `'1.2.0'`) |
| `getTable()` | `string` | Target table name |
| `isSuccess()` | `bool` | Whether the seed entry was applied successfully |
| `getMessage()` | `?string` | Row count on success, error string on failure |

---

## SchemaBuilder

MySQL table builder. Get an instance via `$this->table()` inside a migration class.

**Supported column types:** `primary`, `integer`, `biginteger`, `string`, `char`, `text`, `mediumtext`, `longtext`, `boolean`, `datetime`, `timestamp`, `time`, `date`, `float`, `decimal`, `enum`, `set`, `blob`, `binary`, `json`

**Column options:** `nullable` (bool), `default` (mixed), `length` (int), `unsigned` (bool), `precision` (int), `scale` (int), `comment` (string), `after` (string), `first` (bool), `auto_increment` (bool)

### Table operations

| Method | Description |
|---|---|
| `table(string $name): static` | Set the active table and reset column/index/FK state; required before any other call |
| `create(): void` | Execute `CREATE TABLE` with all defined columns, indexes, and FKs |
| `update(): void` | Apply all queued columns, indexes, and FKs to an existing table |
| `drop(): void` | Execute `DROP TABLE IF EXISTS` |
| `addColumns(): void` | Execute `ALTER TABLE ... ADD COLUMN` for columns only (use `update()` if you also need indexes or FKs) |
| `dropColumns(array $columns): void` | Execute `ALTER TABLE ... DROP COLUMN` for each named column |
| `rename(string $newName): void` | Execute `RENAME TABLE` |
| `modifyColumn(string $name, string $type, array $options = []): void` | Execute `ALTER TABLE ... MODIFY COLUMN` |
| `renameColumn(string $oldName, string $newName): void` | Execute `ALTER TABLE ... RENAME COLUMN` (MySQL 8.0+) |
| `hasTable(string $name): bool` | Return `true` if the table exists in the current database |
| `hasColumn(string $column): bool` | Return `true` if the column exists in the current table (requires prior `table()` call) |
| `dropIndex(string\|array $indexOrColumns): void` | Drop an index by name, or pass a column array to look up the name from the database |
| `dropForeignKey(string\|array $fkOrColumns): void` | Drop a FK by name, or pass a column array to look up the constraint name from the database |
| `statement(string $sql): void` | Run a raw SQL statement for anything the builder doesn't cover |

### Column / index / FK definition methods

| Method | Description |
|---|---|
| `addColumn(string $name, string $type, array $options = []): static` | Queue a column definition; returns `$this` for chaining |
| `addIndex(array $columns, array $options = []): static` | Queue an index; options: `unique` (bool), `name` (string) |
| `addForeignKey(array $columns, string $refTable, array $refColumns, array $options = []): static` | Queue a FK constraint; options: `delete`, `update` (`CASCADE`\|`SET NULL`\|`RESTRICT`\|`NO ACTION`), `name` |

### Data saver (automatic rollback protection)

If a migration fails partway through, the data saver puts every table it touched back the way it was, including data. Enabled automatically before each migration runs.

Two levels:

- **Lightweight** for non-destructive operations (`addColumns`, `addIndex`, `addForeignKey`, `modifyColumn`, `renameColumn`, `rename`, `dropIndex`, `dropForeignKey`). Reads the table's current state from the database before the change. On failure, runs the opposite operation to undo it.
- **Heavy** for destructive operations (`drop`, `dropColumns`). Copies the table (structure + data) into a `_bak_` table before the operation. On failure, replaces the original from the copy. On success, drops the copy.

`statement()` (raw SQL) is **not** tracked. If you use raw SQL, you accept the risk.

| Method | Description |
|---|---|
| `enableSafetyNet(): void` | Enable and reset data saver state. Called by the runner before each migration |
| `disableSafetyNet(): void` | Disable the data saver |
| `restoreSafetyNet(): void` | Undo all recorded operations and restore any copied tables. Called by the runner on failure |
| `cleanupSafetyNet(): void` | Drop `_bak_` tables after a successful migration |

### Recording (for reversible migrations)

| Method | Description |
|---|---|
| `startRecording(): void` | Start capturing operations for later reversal |
| `stopRecording(): array` | Stop capturing and return the recorded operations array |
| `reverseOperations(array $operations): void` | Undo each recorded operation, last to first |

---

## Migration (base class)

Extend this class for all migration files. Filename must be `YYYY-MM-DD-HHmmss_ClassName.php`.

| Method | Description |
|---|---|
| `table(string $name): SchemaBuilder` | Get the schema builder for the named table |
| `up(): void` | Override for forward migration (paired with `down()`) |
| `down(): void` | Override for rollback (paired with `up()`) |
| `change(): void` | Override instead of `up()`/`down()` for automatically reversible migrations |
| `usesChange(): bool` | Returns `true` if the subclass overrides `change()` but not `up()`; used internally to choose execution path |

---

## DatabaseMigrationLock

Concurrency lock backed by the `migrations_lock` table. Uses a compare-and-swap `UPDATE`, safe on MySQL row-level locking.

| Method | Return | Description |
|---|---|---|
| `acquire(): bool` | `bool` | Attempt to acquire the lock; returns `false` if already held. Lock table must already exist (created by `ensureTrackingTable()` on first install). |
| `release(): void` | `void` | Release the lock unconditionally |
| `isLocked(): bool` | `bool` | Return `true` if the lock is currently held by any process |
| `getLockInfo(): ?array` | `array{locked_by: string\|null, locked_at: string\|null}\|null` | Return lock metadata if held, `null` if free |
| `ensureStore(): void` | `void` | Create the lock table if it doesn't exist. Safe to call every time |

---

## Tracking table schema

Table: `migrations`

| Column | Type | Description |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | Primary key |
| `version` | `VARCHAR(32)` | Timestamp from filename (`YYYY-MM-DD-HHmmss`) |
| `class` | `VARCHAR(255)` | Migration class name |
| `group` | `VARCHAR(64)` | Always `'default'` (reserved for multi-connection) |
| `package` | `VARCHAR(255)` | Composer package name |
| `batch` | `INT UNSIGNED` | Groups migrations executed together for rollback |
| `breakpoint` | `TINYINT(1)` | Rollback stops before this migration when set |
| `reversal_ops` | `TEXT NULL` | JSON-encoded operations for reversible migrations |
| `run_at` | `DATETIME` | When the migration was applied |

## Lock table schema

Table: `migrations_lock`

| Column | Type | Description |
|---|---|---|
| `id` | `INT` (always 1) | Single-row sentinel |
| `is_locked` | `TINYINT(1)` | `0` = free, `1` = held |
| `locked_by` | `VARCHAR(64)` | PID of the holding process |
| `locked_at` | `DATETIME` | When the lock was acquired |

## TableSnapshot

Holds info about a backup copy of a table, created by the data saver before destructive operations.

| Method | Return | Description |
|---|---|---|
| `getTableName()` | `string` | The original table name that was copied |
| `getBackupTableName()` | `string` | The backup table name (e.g. `_bak_users`) |
| `getAutoIncrement()` | `int` | The `AUTO_INCREMENT` value captured before copying. Restored when the backup replaces the original |

---

## Seeds table schema

Table: `seeds`

| Column | Type | Description |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | Primary key |
| `package` | `VARCHAR(255) UNIQUE` | Composer package name (one row per package) |
| `version` | `VARCHAR(32)` | Last seeded version |
| `run_at` | `DATETIME` | When the seed was last applied |
