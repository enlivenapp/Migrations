<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Services;

use Enlivenapp\Migrations\Exceptions\MigrationException;
use Enlivenapp\Migrations\Services\TableSnapshot;

/**
 * The table builder you use inside migrations to create, modify, or drop tables.
 *
 * You get an instance by calling $this->table('tablename') inside any migration method.
 * Methods that queue up definitions (addColumn, addIndex, addForeignKey) are chainable -
 * call as many as you need, then finish with create() for a new table, or
 * update() to apply columns, indexes, and FKs to an existing table. Methods that execute immediately
 * (drop, dropColumns, rename, statement, modifyColumn, renameColumn) run right away.
 *
 * Example - create a new table:
 *
 *   $this->table('users')
 *       ->addColumn('id', 'primary')
 *       ->addColumn('email', 'string', ['length' => 255])
 *       ->addColumn('active', 'boolean', ['default' => true])
 *       ->addIndex(['email'], ['unique' => true])
 *       ->addForeignKey(['org_id'], 'orgs', ['id'], ['delete' => 'CASCADE'])
 *       ->create();
 *
 * Supported column types: primary, integer, biginteger, tinyint, string, char, text, mediumtext, longtext, boolean, datetime, timestamp, time, date, float, decimal, enum, set, blob, binary, json.
 *
 * Supported column options: nullable (bool), default (mixed), length (int), precision (int), scale (int), unsigned (bool), comment (string), after (string), first (bool), auto_increment (bool).
 */
class SchemaBuilder
{
    private string $tableName = '';
    /** @var array<int, array<string, mixed>> */
    private array $columns = [];
    /** @var array<int, array<string, mixed>> */
    private array $indexes = [];
    /** @var array<int, array<string, mixed>> */
    private array $foreignKeys = [];

    private bool $recording = false;
    /** @var array<int, array<string, mixed>> */
    private array $recordedOps = [];

    private bool $pretending = false;
    /** @var string[] SQL statements captured in pretend mode */
    private array $pretendedSql = [];

    private bool $safetyNetEnabled = false;
    /** @var array<int, array<string, mixed>> */
    private array $safetyNetOps = [];
    /** @var array<string, TableSnapshot> Backup clones keyed by table name */
    private array $tableClones = [];

    public function __construct(private \PDO $pdo)
    {
    }

    // -----------------------------------------------------------------------
    // Entry point
    // -----------------------------------------------------------------------

    /**
     * Set the table you want to work with.
     *
     * This must be called before any other method. It also resets any column,
     * index, and foreign key definitions that were queued from a previous call,
     * so you can safely reuse the builder for multiple tables in one migration.
     * Returns $this so you can chain addColumn(), addIndex(), etc. right after.
     */
    public function table(string $name): static
    {
        $this->tableName   = $name;
        $this->columns     = [];
        $this->indexes     = [];
        $this->foreignKeys = [];
        return $this;
    }

    /**
     * Start tracking which operations are run so they can be reversed later.
     *
     * Called internally by the migration runner before executing a reversible
     * migration's up() method. You do not need to call this yourself unless
     * you are building custom runner logic.
     */
    public function startRecording(): void
    {
        $this->recording = true;
        $this->recordedOps = [];
    }

    /**
     * Stop tracking operations and return everything that was recorded.
     *
     * Called internally by the migration runner after a reversible migration's
     * up() method finishes. The returned array is what gets passed to
     * reverseOperations() if the migration is rolled back.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stopRecording(): array
    {
        $this->recording = false;
        $ops = $this->recordedOps;
        $this->recordedOps = [];
        return $ops;
    }

    /**
     * Enable or disable pretend mode.
     *
     * When pretending, exec() captures SQL strings instead of executing them.
     * Read-only queries (hasTable, hasColumn) still execute normally.
     */
    public function setPretendMode(bool $enabled): void
    {
        $this->pretending = $enabled;
        $this->pretendedSql = [];
    }

    /**
     * Return SQL statements captured during pretend mode.
     *
     * @return string[]
     */
    public function getPretendedSql(): array
    {
        return $this->pretendedSql;
    }

    // -----------------------------------------------------------------------
    // Safety net — protects against partial migration failure
    // -----------------------------------------------------------------------

    /**
     * Enable the safety net for the current migration.
     *
     * Called by the migration runner before each migration's up() or change().
     * Resets any state from the previous migration.
     */
    public function enableSafetyNet(): void
    {
        $this->safetyNetEnabled = true;
        $this->safetyNetOps = [];
        $this->tableClones = [];
    }

    /**
     * Disable the safety net.
     */
    public function disableSafetyNet(): void
    {
        $this->safetyNetEnabled = false;
    }

    /**
     * Restore all tables touched by the current migration to their pre-migration state.
     *
     * Called by the migration runner when a migration fails partway through.
     * Reverses recorded operations in reverse order, restores any cloned tables,
     * then cleans up backup tables.
     */
    public function restoreSafetyNet(): void
    {
        // Reverse operations in reverse order
        foreach (array_reverse($this->safetyNetOps) as $op) {
            try {
                $this->reverseSafetyNetOp($op);
            } catch (\Throwable $e) {
                // Best effort — continue reversing remaining ops
            }
        }

        // Drop any remaining backup tables that weren't consumed by restore
        foreach ($this->tableClones as $snapshot) {
            try {
                $this->pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', $snapshot->getBackupTableName()));
            } catch (\Throwable $e) {
                // Best effort cleanup
            }
        }

        $this->safetyNetOps = [];
        $this->tableClones = [];
    }

    /**
     * Clean up after a successful migration.
     *
     * Drops any backup clone tables and resets safety net state.
     */
    public function cleanupSafetyNet(): void
    {
        foreach ($this->tableClones as $snapshot) {
            try {
                $this->pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', $snapshot->getBackupTableName()));
            } catch (\Throwable $e) {
                // Best effort cleanup
            }
        }

        $this->safetyNetOps = [];
        $this->tableClones = [];
    }

    // -----------------------------------------------------------------------
    // Column definitions
    // -----------------------------------------------------------------------

    /**
     * Queue a column to be added to the table.
     *
     * Nothing is written to the database until you call create() (for a new table)
     * or addColumns() (to alter an existing table). You can chain this method as
     * many times as you need before calling either of those.
     *
     * $type is one of the supported column types - see the class docblock for the full list.
     * $options fine-tunes the column - see the class docblock for the full list of options.
     * For enum and set types, pass a 'values' key in $options with an array of allowed values.
     *
     * @param string               $name    The column name.
     * @param string               $type    The column type. See the class docblock for all supported types.
     * @param array<string, mixed> $options Optional modifiers. See the class docblock for all supported options.
     */
    public function addColumn(string $name, string $type, array $options = []): static
    {
        $this->columns[] = compact('name', 'type', 'options');
        return $this;
    }

    // -----------------------------------------------------------------------
    // Index definitions
    // -----------------------------------------------------------------------

    /**
     * Queue an index to be added when create() runs.
     *
     * Pass an array of one or more column names that make up the index.
     * By default the index name is auto-generated from the table and column names;
     * use the 'name' option to override it.
     *
     * @param string[]             $columns  One or more column names to include in the index.
     * @param array<string, mixed> $options  unique (bool) - make it a UNIQUE index; name (string) - override the auto-generated index name.
     */
    public function addIndex(array $columns, array $options = []): static
    {
        $this->indexes[] = compact('columns', 'options');
        return $this;
    }

    // -----------------------------------------------------------------------
    // Foreign key definitions
    // -----------------------------------------------------------------------

    /**
     * Queue a foreign key constraint to be added when create() runs.
     *
     * $columns is the list of local columns that hold the foreign key.
     * $refTable is the table being referenced, and $refColumns is the list of
     * columns in that table (usually the primary key). Both arrays must have the
     * same number of entries. By default the constraint name is auto-generated;
     * use the 'name' option to override it.
     *
     * @param string[]             $columns     Local column name(s) that form the foreign key.
     * @param string               $refTable    The table being referenced.
     * @param string[]             $refColumns  Column name(s) in $refTable being referenced.
     * @param array<string, mixed> $options     delete (CASCADE|SET NULL|RESTRICT|NO ACTION) - what to do when the referenced row is deleted;
     *                                          update (same values) - what to do when the referenced key changes;
     *                                          name (string) - override the auto-generated constraint name.
     *                                          Defaults for delete and update are RESTRICT.
     */
    public function addForeignKey(
        array  $columns,
        string $refTable,
        array  $refColumns,
        array  $options = [],
    ): static {
        $this->foreignKeys[] = compact('columns', 'refTable', 'refColumns', 'options');
        return $this;
    }

    // -----------------------------------------------------------------------
    // DDL execution
    // -----------------------------------------------------------------------

    /**
     * Run CREATE TABLE using everything you have queued with addColumn(), addIndex(), and addForeignKey().
     *
     * The table is created with InnoDB engine and utf8mb4_unicode_ci charset.
     * Call this once at the end of your chain when creating a brand-new table.
     * To add columns to an existing table use addColumns() instead.
     *
     * @throws MigrationException on PDO error
     */
    public function create(): void
    {
        $this->assertTableName();

        $parts = [];

        foreach ($this->columns as $col) {
            $parts[] = $this->buildColumnDef($col['name'], $col['type'], $col['options']);
        }

        foreach ($this->indexes as $idx) {
            $unique    = (bool) ($idx['options']['unique'] ?? false);
            $indexName = $idx['options']['name'] ?? $this->buildIndexName($this->tableName, $idx['columns']);
            $colList   = implode(', ', array_map(fn($c) => '`' . $c . '`', $idx['columns']));
            $keyword   = $unique ? 'UNIQUE INDEX' : 'INDEX';
            $parts[]   = sprintf('%s `%s` (%s)', $keyword, $indexName, $colList);
        }

        foreach ($this->foreignKeys as $fk) {
            $parts[] = $this->buildForeignKeyDef($fk);
        }

        $sql = sprintf(
            "CREATE TABLE `%s` (\n    %s\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            $this->tableName,
            implode(",\n    ", $parts)
        );

        $this->exec($sql);

        if ($this->recording) {
            $this->recordedOps[] = ['op' => 'create', 'table' => $this->tableName];
        }

        if ($this->safetyNetEnabled) {
            $this->safetyNetOps[] = ['op' => 'create', 'table' => $this->tableName];
        }
    }

    /**
     * Drop the table if it exists.
     *
     * Uses DROP TABLE IF EXISTS, so it is safe to call even if the table does not exist.
     * Requires table() to have been called first.
     *
     * @throws MigrationException on PDO error
     */
    public function drop(): void
    {
        $this->assertTableName();

        // Heavy tier: clone the table before dropping so data can be restored
        if ($this->safetyNetEnabled && $this->hasTable($this->tableName)) {
            if (!isset($this->tableClones[$this->tableName])) {
                $this->cloneTable($this->tableName);
            }
            // Record before exec — if the drop succeeds, this enables restoration
            $this->safetyNetOps[] = ['op' => 'drop', 'table' => $this->tableName];
        }

        $this->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->tableName));
    }

    /**
     * Apply all queued columns, indexes, and foreign keys to an existing table.
     *
     * This is the existing-table counterpart to create(). Queue up addColumn(),
     * addIndex(), and addForeignKey() calls, then call update() to apply them all.
     * Each operation gets its own ALTER TABLE / CREATE INDEX statement.
     *
     * @throws MigrationException on PDO error
     */
    public function update(): void
    {
        $this->assertTableName();

        foreach ($this->columns as $col) {
            $def = $this->buildColumnDef($col['name'], $col['type'], $col['options']);
            $sql = sprintf('ALTER TABLE `%s` ADD COLUMN %s', $this->tableName, $def);
            $this->exec($sql);

            if ($this->safetyNetEnabled) {
                $this->safetyNetOps[] = ['op' => 'addColumns', 'table' => $this->tableName, 'columns' => [$col['name']]];
            }
        }

        foreach ($this->indexes as $idx) {
            $unique    = (bool) ($idx['options']['unique'] ?? false);
            $indexName = $idx['options']['name'] ?? $this->buildIndexName($this->tableName, $idx['columns']);
            $colList   = implode(', ', array_map(fn($c) => '`' . $c . '`', $idx['columns']));
            $keyword   = $unique ? 'UNIQUE INDEX' : 'INDEX';
            $sql = sprintf('CREATE %s `%s` ON `%s` (%s)', $keyword, $indexName, $this->tableName, $colList);
            $this->exec($sql);

            if ($this->safetyNetEnabled) {
                $this->safetyNetOps[] = ['op' => 'addIndex', 'table' => $this->tableName, 'indexName' => $indexName];
            }
        }

        foreach ($this->foreignKeys as $fk) {
            $localCols = implode(', ', array_map(fn($c) => '`' . $c . '`', $fk['columns']));
            $refCols   = implode(', ', array_map(fn($c) => '`' . $c . '`', $fk['refColumns']));
            $onDelete  = strtoupper($fk['options']['delete'] ?? 'RESTRICT');
            $onUpdate  = strtoupper($fk['options']['update'] ?? 'RESTRICT');
            $fkName    = $fk['options']['name']
                ?? 'fk_' . $this->tableName . '_' . implode('_', $fk['columns']);
            $sql = sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (%s) REFERENCES `%s` (%s) ON DELETE %s ON UPDATE %s',
                $this->tableName, $fkName, $localCols, $fk['refTable'], $refCols, $onDelete, $onUpdate
            );
            $this->exec($sql);

            if ($this->safetyNetEnabled) {
                $this->safetyNetOps[] = ['op' => 'addForeignKey', 'table' => $this->tableName, 'fkName' => $fkName];
            }
        }

        if ($this->recording) {
            $colNames = array_map(fn($c) => $c['name'], $this->columns);
            if (!empty($colNames)) {
                $this->recordedOps[] = ['op' => 'addColumns', 'table' => $this->tableName, 'columns' => $colNames];
            }
        }
    }

    /**
     * Run ALTER TABLE ADD COLUMN for each column you have queued with addColumn().
     *
     * Use this when you only need to add columns to an existing table. If you also
     * need to add indexes or foreign keys, use update() instead.
     *
     * @throws MigrationException on PDO error
     */
    public function addColumns(): void
    {
        $this->assertTableName();

        foreach ($this->columns as $col) {
            $def = $this->buildColumnDef($col['name'], $col['type'], $col['options']);
            $sql = sprintf('ALTER TABLE `%s` ADD COLUMN %s', $this->tableName, $def);
            $this->exec($sql);

            if ($this->safetyNetEnabled) {
                $this->safetyNetOps[] = ['op' => 'addColumns', 'table' => $this->tableName, 'columns' => [$col['name']]];
            }
        }

        if ($this->recording) {
            $colNames = array_map(fn($c) => $c['name'], $this->columns);
            $this->recordedOps[] = ['op' => 'addColumns', 'table' => $this->tableName, 'columns' => $colNames];
        }
    }

    /**
     * Drop one or more columns from the current table.
     *
     * Pass an array of column name strings. Each column gets its own
     * ALTER TABLE DROP COLUMN statement. Requires table() to have been called first.
     *
     * @param string[] $columns  Column names to drop.
     * @throws MigrationException on PDO error
     */
    public function dropColumns(array $columns): void
    {
        $this->assertTableName();

        // Heavy tier: clone the table before dropping columns so data can be restored
        if ($this->safetyNetEnabled && !isset($this->tableClones[$this->tableName])) {
            $this->cloneTable($this->tableName);
        }

        if ($this->safetyNetEnabled) {
            $this->safetyNetOps[] = ['op' => 'dropColumns', 'table' => $this->tableName, 'columns' => $columns];
        }

        foreach ($columns as $col) {
            $sql = sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $this->tableName, $col);
            $this->exec($sql);
        }
    }

    /**
     * Rename the current table to a new name.
     *
     * Runs RENAME TABLE immediately. Requires table() to have been called first
     * with the current (old) table name.
     *
     * @param string $newName  The new table name.
     * @throws MigrationException on PDO error
     */
    public function rename(string $newName): void
    {
        $this->assertTableName();
        $sql = sprintf('RENAME TABLE `%s` TO `%s`', $this->tableName, $newName);
        $this->exec($sql);

        if ($this->recording) {
            $this->recordedOps[] = ['op' => 'rename', 'table' => $this->tableName, 'newName' => $newName];
        }

        if ($this->safetyNetEnabled) {
            $this->safetyNetOps[] = ['op' => 'rename', 'table' => $this->tableName, 'newName' => $newName];
        }
    }

    /**
     * Run any SQL statement you pass in directly against the database connection.
     *
     * Use this when the builder does not cover what you need - for example,
     * adding a CHECK constraint, running a TRUNCATE, or any other DDL or DML that
     * has no dedicated method. Does not require table() to be called first.
     *
     * @param string $sql  The raw SQL to execute.
     * @throws MigrationException on PDO error
     */
    public function statement(string $sql): void
    {
        $this->exec($sql);
    }

    /**
     * Change the type or options of an existing column.
     *
     * Runs ALTER TABLE MODIFY COLUMN immediately. Use this to change a column's
     * data type, nullability, default value, length, or any other attribute.
     * The $type and $options parameters work the same way as in addColumn() -
     * see the class docblock for the full list of supported types and options.
     * Requires table() to have been called first.
     *
     * @param string               $name     The column to modify.
     * @param string               $type     The new column type. See the class docblock for all supported types.
     * @param array<string, mixed> $options  Optional modifiers for the new definition. See the class docblock.
     * @throws MigrationException on PDO error
     */
    public function modifyColumn(string $name, string $type, array $options = []): void
    {
        $this->assertTableName();

        // Capture current definition before modifying so it can be restored
        $beforeDef = null;
        if ($this->safetyNetEnabled) {
            $beforeDef = $this->captureColumnDef($this->tableName, $name);
        }

        $def = $this->buildColumnDef($name, $type, $options);
        $sql = sprintf('ALTER TABLE `%s` MODIFY COLUMN %s', $this->tableName, $def);
        $this->exec($sql);

        if ($this->safetyNetEnabled && $beforeDef !== null) {
            $this->safetyNetOps[] = [
                'op' => 'modifyColumn', 'table' => $this->tableName,
                'column' => $name, 'before' => $beforeDef,
            ];
        }
    }

    /**
     * Rename a column in the current table.
     *
     * Runs ALTER TABLE RENAME COLUMN immediately. Requires MySQL 8.0 or newer -
     * it will fail on older versions. Requires table() to have been called first.
     *
     * @param string $oldName  The current column name.
     * @param string $newName  The new column name.
     * @throws MigrationException on PDO error
     */
    public function renameColumn(string $oldName, string $newName): void
    {
        $this->assertTableName();
        $sql = sprintf('ALTER TABLE `%s` RENAME COLUMN `%s` TO `%s`', $this->tableName, $oldName, $newName);
        $this->exec($sql);

        if ($this->recording) {
            $this->recordedOps[] = ['op' => 'renameColumn', 'table' => $this->tableName, 'oldName' => $oldName, 'newName' => $newName];
        }

        if ($this->safetyNetEnabled) {
            $this->safetyNetOps[] = [
                'op' => 'renameColumn', 'table' => $this->tableName,
                'oldName' => $oldName, 'newName' => $newName,
            ];
        }
    }

    /**
     * Undo a set of recorded operations in reverse order.
     *
     * Used internally by the migration runner to roll back a reversible migration.
     * You pass in the array that was returned by stopRecording(), and this method
     * works through it from last to first, running the opposite of each operation
     * (create becomes drop, addColumns becomes dropColumns, rename is reversed, etc.).
     * You do not need to call this yourself unless you are building custom runner logic.
     *
     * @param array<int, array<string, mixed>> $operations  The recorded operations returned by stopRecording().
     * @throws MigrationException if an unknown or unrecognised operation is encountered.
     */
    public function reverseOperations(array $operations): void
    {
        foreach (array_reverse($operations) as $op) {
            switch ($op['op']) {
                case 'create':
                    $this->table($op['table'])->drop();
                    break;

                case 'addColumns':
                    $this->table($op['table'])->dropColumns($op['columns']);
                    break;

                case 'rename':
                    $oldTable = $op['table'];
                    $newTable = $op['newName'];
                    $this->table($newTable)->rename($oldTable);
                    break;

                case 'renameColumn':
                    $this->table($op['table'])->renameColumn($op['newName'], $op['oldName']);
                    break;

                default:
                    throw new MigrationException(
                        "Cannot reverse unknown operation \"{$op['op']}\"."
                    );
            }
        }
    }

    /**
     * Check whether a table exists in the current database.
     *
     * Returns true if the table exists, false if it does not.
     * Does NOT require table() to be called first - you pass the table name directly.
     * Useful for guarding migrations that should only run if a table is (or is not) present.
     *
     * @param string $name  The table name to check.
     */
    public function hasTable(string $name): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$name]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Check whether a column exists in the current table.
     *
     * Returns true if the column exists, false if it does not.
     * Requires table() to have been called first to set which table to inspect.
     *
     * @param string $column  The column name to check.
     */
    public function hasColumn(string $column): bool
    {
        $this->assertTableName();
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$this->tableName, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Drop an index from the current table.
     *
     * You can identify the index two ways: pass its name as a string, or pass an
     * array of the column names it covers and the builder will look up the actual
     * index name from the database. This works even if the table has been renamed.
     * Requires table() to have been called first.
     *
     * @param string|string[] $indexOrColumns  The index name as a string, or an array of column names to look up.
     * @throws MigrationException if no matching index is found (array form) or on PDO error
     */
    public function dropIndex(string|array $indexOrColumns): void
    {
        $this->assertTableName();

        if (is_array($indexOrColumns)) {
            $indexName = $this->lookupIndexName($this->tableName, $indexOrColumns);
            if ($indexName === null) {
                throw new MigrationException(
                    'No index found on `' . $this->tableName . '` for column(s): ' . implode(', ', $indexOrColumns)
                );
            }
        } else {
            $indexName = $indexOrColumns;
        }

        // Capture definition before dropping so it can be recreated on restore
        $beforeDef = null;
        if ($this->safetyNetEnabled) {
            $beforeDef = $this->captureIndexDef($this->tableName, $indexName);
        }

        $sql = sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $this->tableName, $indexName);
        $this->exec($sql);

        if ($this->safetyNetEnabled && $beforeDef !== null) {
            $this->safetyNetOps[] = ['op' => 'dropIndex', 'table' => $this->tableName, 'indexDef' => $beforeDef];
        }
    }

    /**
     * Drop a foreign key constraint from the current table.
     *
     * Pass the constraint name as a string, or pass an array of the local column
     * names and the builder will look up the actual constraint name from the database.
     * Requires table() to have been called first.
     *
     * @param string|string[] $fkOrColumns  The constraint name as a string, or an array of local column names to look up.
     * @throws MigrationException on PDO error
     */
    public function dropForeignKey(string|array $fkOrColumns): void
    {
        $this->assertTableName();

        if (is_array($fkOrColumns)) {
            $fkName = $this->lookupForeignKeyName($this->tableName, $fkOrColumns);
            if ($fkName === null) {
                throw new MigrationException(
                    'No foreign key found on `' . $this->tableName . '` for column(s): ' . implode(', ', $fkOrColumns)
                );
            }
        } else {
            $fkName = $fkOrColumns;
        }

        // Capture definition before dropping so it can be recreated on restore
        $beforeDef = null;
        if ($this->safetyNetEnabled) {
            $beforeDef = $this->captureForeignKeyDef($this->tableName, $fkName);
        }

        $sql = sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $this->tableName, $fkName);
        $this->exec($sql);

        if ($this->safetyNetEnabled && $beforeDef !== null) {
            $this->safetyNetOps[] = ['op' => 'dropForeignKey', 'table' => $this->tableName, 'fkDef' => $beforeDef];
        }
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Reverse a single safety net operation.
     */
    private function reverseSafetyNetOp(array $op): void
    {
        switch ($op['op']) {
            case 'create':
                $this->pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', $op['table']));
                break;

            case 'drop':
                $snapshot = $this->tableClones[$op['table']] ?? null;
                if ($snapshot !== null) {
                    $this->restoreClone($snapshot);
                }
                break;

            case 'addColumns':
                foreach ($op['columns'] as $col) {
                    $this->pdo->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $op['table'], $col));
                }
                break;

            case 'dropColumns':
                $snapshot = $this->tableClones[$op['table']] ?? null;
                if ($snapshot !== null) {
                    $this->restoreClone($snapshot);
                }
                break;

            case 'modifyColumn':
                $colSql = $this->rebuildColumnSql($op['column'], $op['before']);
                $this->pdo->exec(sprintf('ALTER TABLE `%s` MODIFY COLUMN %s', $op['table'], $colSql));
                break;

            case 'renameColumn':
                $this->pdo->exec(sprintf(
                    'ALTER TABLE `%s` RENAME COLUMN `%s` TO `%s`',
                    $op['table'], $op['newName'], $op['oldName']
                ));
                break;

            case 'rename':
                $this->pdo->exec(sprintf('RENAME TABLE `%s` TO `%s`', $op['newName'], $op['table']));
                break;

            case 'addIndex':
                $this->pdo->exec(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $op['table'], $op['indexName']));
                break;

            case 'addForeignKey':
                $this->pdo->exec(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $op['table'], $op['fkName']));
                break;

            case 'dropIndex':
                $def = $op['indexDef'];
                $unique = ((int) $def['NON_UNIQUE']) === 0;
                $keyword = $unique ? 'UNIQUE INDEX' : 'INDEX';
                $cols = implode(', ', array_map(fn($c) => '`' . $c . '`', explode(',', $def['cols'])));
                $this->pdo->exec(sprintf(
                    'CREATE %s `%s` ON `%s` (%s)',
                    $keyword, $def['INDEX_NAME'], $op['table'], $cols
                ));
                break;

            case 'dropForeignKey':
                $def = $op['fkDef'];
                $localCols = implode(', ', array_map(fn($c) => '`' . $c . '`', $def['columns']));
                $refCols = implode(', ', array_map(fn($c) => '`' . $c . '`', $def['refColumns']));
                $this->pdo->exec(sprintf(
                    'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (%s) REFERENCES `%s` (%s) ON DELETE %s ON UPDATE %s',
                    $op['table'], $def['name'], $localCols, $def['refTable'], $refCols,
                    $def['deleteRule'], $def['updateRule']
                ));
                break;

            default:
                // Unknown op — skip silently during restore
                break;
        }
    }

    /**
     * Clone a table (structure + data) for the heavy safety tier.
     *
     * Used before destructive operations (DROP TABLE, DROP COLUMN) so the
     * table can be fully restored if the migration fails.
     */
    private function cloneTable(string $table): TableSnapshot
    {
        $backupName = '_bak_' . $table;

        // Capture current auto_increment value
        $stmt = $this->pdo->prepare(
            'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        $autoInc = (int) ($stmt->fetchColumn() ?: 0);

        // Clone structure and data
        $this->pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', $backupName));
        $this->pdo->exec(sprintf('CREATE TABLE `%s` LIKE `%s`', $backupName, $table));
        $this->pdo->exec(sprintf('INSERT INTO `%s` SELECT * FROM `%s`', $backupName, $table));

        $snapshot = new TableSnapshot($table, $backupName, $autoInc);
        $this->tableClones[$table] = $snapshot;

        return $snapshot;
    }

    /**
     * Restore a table from its backup clone.
     *
     * Drops the (potentially damaged) original, renames the backup back,
     * and restores the auto_increment counter. Foreign key checks are
     * disabled during the swap so FK references by name are preserved.
     */
    private function restoreClone(TableSnapshot $snapshot): void
    {
        $table = $snapshot->getTableName();
        $backup = $snapshot->getBackupTableName();

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $this->pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
            $this->pdo->exec(sprintf('RENAME TABLE `%s` TO `%s`', $backup, $table));

            if ($snapshot->getAutoIncrement() > 0) {
                $this->pdo->exec(sprintf(
                    'ALTER TABLE `%s` AUTO_INCREMENT = %d',
                    $table, $snapshot->getAutoIncrement()
                ));
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        unset($this->tableClones[$table]);
    }

    /**
     * Capture a column's current definition from information_schema.
     *
     * Used before modifyColumn() so the original definition can be restored.
     *
     * @return array{COLUMN_NAME: string, COLUMN_TYPE: string, IS_NULLABLE: string, COLUMN_DEFAULT: string|null, EXTRA: string, COLUMN_COMMENT: string}
     */
    private function captureColumnDef(string $table, string $column): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT '
            . 'FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new MigrationException(
                "Cannot capture column definition: column `{$column}` not found on table `{$table}`."
            );
        }

        return $row;
    }

    /**
     * Capture an index definition from information_schema.
     *
     * Used before dropIndex() so the index can be recreated on restore.
     *
     * @return array{INDEX_NAME: string, NON_UNIQUE: string, cols: string}
     */
    private function captureIndexDef(string $table, string $indexName): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols '
            . 'FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? '
            . 'GROUP BY INDEX_NAME, NON_UNIQUE'
        );
        $stmt->execute([$table, $indexName]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new MigrationException(
                "Cannot capture index definition: index `{$indexName}` not found on table `{$table}`."
            );
        }

        return $row;
    }

    /**
     * Capture a foreign key definition from information_schema.
     *
     * Used before dropForeignKey() so the FK can be recreated on restore.
     *
     * @return array{name: string, columns: string[], refTable: string, refColumns: string[], deleteRule: string, updateRule: string}
     */
    private function captureForeignKeyDef(string $table, string $fkName): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME '
            . 'FROM information_schema.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? '
            . 'ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$table, $fkName]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            throw new MigrationException(
                "Cannot capture foreign key definition: constraint `{$fkName}` not found on table `{$table}`."
            );
        }

        $stmt2 = $this->pdo->prepare(
            'SELECT DELETE_RULE, UPDATE_RULE '
            . 'FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
        );
        $stmt2->execute([$table, $fkName]);
        $rules = $stmt2->fetch(\PDO::FETCH_ASSOC) ?: ['DELETE_RULE' => 'RESTRICT', 'UPDATE_RULE' => 'RESTRICT'];

        return [
            'name'       => $fkName,
            'columns'    => array_column($rows, 'COLUMN_NAME'),
            'refTable'   => $rows[0]['REFERENCED_TABLE_NAME'],
            'refColumns' => array_column($rows, 'REFERENCED_COLUMN_NAME'),
            'deleteRule'  => $rules['DELETE_RULE'],
            'updateRule'  => $rules['UPDATE_RULE'],
        ];
    }

    /**
     * Rebuild a column definition SQL fragment from captured information_schema data.
     *
     * Uses COLUMN_TYPE directly (e.g. "int unsigned", "varchar(255)") so the
     * original definition is reproduced exactly without needing to map back
     * through SchemaBuilder's type system.
     */
    private function rebuildColumnSql(string $column, array $def): string
    {
        $sql = sprintf('`%s` %s', $column, $def['COLUMN_TYPE']);
        $sql .= $def['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL';

        if ($def['COLUMN_DEFAULT'] !== null) {
            if ($def['COLUMN_DEFAULT'] === 'CURRENT_TIMESTAMP') {
                $sql .= ' DEFAULT CURRENT_TIMESTAMP';
            } else {
                $sql .= " DEFAULT '" . addslashes($def['COLUMN_DEFAULT']) . "'";
            }
        } elseif ($def['IS_NULLABLE'] === 'YES') {
            $sql .= ' DEFAULT NULL';
        }

        if (!empty($def['EXTRA'])) {
            $sql .= ' ' . $def['EXTRA'];
        }

        if (!empty($def['COLUMN_COMMENT'])) {
            $sql .= " COMMENT '" . addslashes($def['COLUMN_COMMENT']) . "'";
        }

        return $sql;
    }

    /**
     * Look up the actual index name from information_schema by matching columns.
     *
     * @param string   $table   Table name
     * @param string[] $columns Column names the index covers
     * @return string|null The index name, or null if not found
     */
    private function lookupIndexName(string $table, array $columns): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols '
            . 'FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME != \'PRIMARY\' '
            . 'GROUP BY INDEX_NAME'
        );
        $stmt->execute([$table]);
        $target = implode(',', $columns);

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['cols'] === $target) {
                return $row['INDEX_NAME'];
            }
        }

        return null;
    }

    /**
     * Look up the actual foreign key constraint name from information_schema by matching columns.
     *
     * @param string   $table   Table name
     * @param string[] $columns Local column names the FK covers
     * @return string|null The constraint name, or null if not found
     */
    private function lookupForeignKeyName(string $table, array $columns): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT CONSTRAINT_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) AS cols '
            . 'FROM information_schema.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL '
            . 'GROUP BY CONSTRAINT_NAME'
        );
        $stmt->execute([$table]);
        $target = implode(',', $columns);

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['cols'] === $target) {
                return $row['CONSTRAINT_NAME'];
            }
        }

        return null;
    }

    /**
     * @param  string              $name
     * @param  string              $type
     * @param  array<string, mixed> $options
     * @return string  SQL fragment for one column definition
     */
    private function buildColumnDef(string $name, string $type, array $options): string
    {
        $nullable      = (bool) ($options['nullable'] ?? false);
        $default       = $options['default'] ?? null;
        $length        = (int) ($options['length'] ?? 0);
        $unsigned      = (bool) ($options['unsigned'] ?? false);
        $precision     = (int) ($options['precision'] ?? 10);
        $scale         = (int) ($options['scale'] ?? 2);
        $comment       = $options['comment'] ?? null;
        $after         = $options['after'] ?? null;
        $first         = (bool) ($options['first'] ?? false);
        $autoIncrement = (bool) ($options['auto_increment'] ?? false);

        $hasDefault = array_key_exists('default', $options);

        switch ($type) {
            case 'primary':
                $def = sprintf('`%s` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', $name);
                break;

            case 'integer':
                $uStr = $unsigned ? ' UNSIGNED' : '';
                $def  = sprintf('`%s` INT%s', $name, $uStr);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                if ($hasDefault) {
                    $def .= ' DEFAULT ' . $this->quoteDefault($default);
                }
                if ($autoIncrement) {
                    $def .= ' AUTO_INCREMENT';
                }
                break;

            case 'biginteger':
                $uStr = $unsigned ? ' UNSIGNED' : '';
                $def  = sprintf('`%s` BIGINT%s', $name, $uStr);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                if ($hasDefault) {
                    $def .= ' DEFAULT ' . $this->quoteDefault($default);
                }
                if ($autoIncrement) {
                    $def .= ' AUTO_INCREMENT';
                }
                break;

            case 'string':
                $len = $length > 0 ? $length : 255;
                $def = sprintf('`%s` VARCHAR(%d)', $name, $len);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                if ($hasDefault) {
                    $def .= ' DEFAULT ' . $this->quoteDefault($default);
                }
                break;

            case 'char':
                $len = $length > 0 ? $length : 255;
                $def = sprintf('`%s` CHAR(%d)', $name, $len);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                if ($hasDefault) {
                    $def .= ' DEFAULT ' . $this->quoteDefault($default);
                }
                break;

            case 'text':
                $def = sprintf('`%s` TEXT', $name);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                // TEXT columns cannot have a default in MySQL < 8.0.13; skip it
                break;

            case 'mediumtext':
                $def = sprintf('`%s` MEDIUMTEXT', $name);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                break;

            case 'longtext':
                $def = sprintf('`%s` LONGTEXT', $name);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                break;

            case 'boolean':
                $def = sprintf('`%s` TINYINT(1)', $name);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                if ($hasDefault) {
                    $boolVal = $default ? '1' : '0';
                    $def    .= ' DEFAULT ' . $boolVal;
                }
                break;

            case 'tinyint':
                $length = $options['length'] ?? 4;
                $def = sprintf('`%s` TINYINT(%d)', $name, $length);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                if ($hasDefault) {
                    $def .= ' DEFAULT ' . (int) $default;
                }
                break;

            case 'datetime':
                $def = sprintf('`%s` DATETIME', $name);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                if ($hasDefault) {
                    $def .= ' DEFAULT ' . $this->quoteDefault($default);
                }
                break;

            case 'timestamp':
                $def = sprintf('`%s` TIMESTAMP', $name);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                if ($hasDefault) {
                    $def .= ' DEFAULT ' . $this->quoteDefault($default);
                }
                break;

            case 'time':
                $def = sprintf('`%s` TIME', $name);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                if ($hasDefault) {
                    $def .= ' DEFAULT ' . $this->quoteDefault($default);
                }
                break;

            case 'date':
                $def = sprintf('`%s` DATE', $name);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                if ($hasDefault) {
                    $def .= ' DEFAULT ' . $this->quoteDefault($default);
                }
                break;

            case 'float':
                $def = sprintf('`%s` FLOAT', $name);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                if ($hasDefault) {
                    $def .= ' DEFAULT ' . $this->quoteDefault($default);
                }
                break;

            case 'decimal':
                $def = sprintf('`%s` DECIMAL(%d, %d)', $name, $precision, $scale);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                if ($hasDefault) {
                    $def .= ' DEFAULT ' . $this->quoteDefault($default);
                }
                break;

            case 'enum':
                $values = $options['values'] ?? [];
                if (empty($values)) {
                    throw new MigrationException("Enum column \"{$name}\" requires a non-empty \"values\" option.");
                }
                $valueList = implode(',', array_map(fn($v) => "'" . addslashes((string) $v) . "'", $values));
                $def = sprintf('`%s` ENUM(%s)', $name, $valueList);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                if ($hasDefault) {
                    $def .= ' DEFAULT ' . $this->quoteDefault($default);
                }
                break;

            case 'set':
                $values = $options['values'] ?? [];
                if (empty($values)) {
                    throw new MigrationException("Set column \"{$name}\" requires a non-empty \"values\" option.");
                }
                $valueList = implode(',', array_map(fn($v) => "'" . addslashes((string) $v) . "'", $values));
                $def = sprintf('`%s` SET(%s)', $name, $valueList);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                if ($hasDefault) {
                    $def .= ' DEFAULT ' . $this->quoteDefault($default);
                }
                break;

            case 'blob':
                $def = sprintf('`%s` BLOB', $name);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                break;

            case 'binary':
                $len = $length > 0 ? $length : 255;
                $def = sprintf('`%s` VARBINARY(%d)', $name, $len);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                if ($hasDefault) {
                    $def .= ' DEFAULT ' . $this->quoteDefault($default);
                }
                break;

            case 'json':
                $def = sprintf('`%s` JSON', $name);
                $def .= $nullable ? ' NULL' : ' NOT NULL';
                break;

            default:
                throw new MigrationException(
                    "Unknown column type \"{$type}\" for column \"{$name}\". " .
                    'Supported: primary, integer, biginteger, tinyint, string, char, text, mediumtext, longtext, boolean, datetime, timestamp, time, date, float, decimal, enum, set, blob, binary, json.'
                );
        }

        if ($comment !== null) {
            $def .= " COMMENT '" . addslashes((string) $comment) . "'";
        }

        if ($first) {
            $def .= ' FIRST';
        } elseif ($after !== null) {
            $def .= ' AFTER `' . $after . '`';
        }

        return $def;
    }

    /**
     * @param  array<string, mixed> $fk
     */
    private function buildForeignKeyDef(array $fk): string
    {
        $localCols = implode(', ', array_map(fn($c) => '`' . $c . '`', $fk['columns']));
        $refCols   = implode(', ', array_map(fn($c) => '`' . $c . '`', $fk['refColumns']));
        $onDelete  = strtoupper($fk['options']['delete'] ?? 'RESTRICT');
        $onUpdate  = strtoupper($fk['options']['update'] ?? 'RESTRICT');
        $fkName    = $fk['options']['name']
            ?? 'fk_' . $this->tableName . '_' . implode('_', $fk['columns']);

        return sprintf(
            'CONSTRAINT `%s` FOREIGN KEY (%s) REFERENCES `%s` (%s) ON DELETE %s ON UPDATE %s',
            $fkName,
            $localCols,
            $fk['refTable'],
            $refCols,
            $onDelete,
            $onUpdate
        );
    }

    private function buildIndexName(string $table, array $columns): string
    {
        return 'idx_' . $table . '_' . implode('_', $columns);
    }

    /**
     * Quote a DEFAULT value appropriately for MySQL.
     *
     * @param mixed $value
     */
    private function quoteDefault(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        // String: use CURRENT_TIMESTAMP literally, wrap others in quotes
        if ($value === 'CURRENT_TIMESTAMP') {
            return 'CURRENT_TIMESTAMP';
        }
        return "'" . addslashes((string) $value) . "'";
    }

    private function assertTableName(): void
    {
        if ($this->tableName === '') {
            throw new MigrationException('Call table() before calling create(), drop(), or other DDL methods.');
        }
    }

    /**
     * Execute SQL via PDO, wrapping PDOException in MigrationException.
     *
     * @throws MigrationException
     */
    private function exec(string $sql): void
    {
        if ($this->pretending) {
            $this->pretendedSql[] = $sql;
            return;
        }

        try {
            $result = $this->pdo->exec($sql);
            if ($result === false) {
                $error = $this->pdo->errorInfo();
                throw new MigrationException(
                    'PDO exec failed: [' . ($error[0] ?? '') . '] ' . ($error[2] ?? 'unknown error')
                );
            }
        } catch (\PDOException $e) {
            throw new MigrationException('Schema error: ' . $e->getMessage(), 0, $e);
        }
    }
}
