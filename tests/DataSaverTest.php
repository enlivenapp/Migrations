<?php

/**
 * Data Saver Test Suite
 *
 * Runs against a live MySQL database to verify every data saver path
 * in SchemaBuilder and the MigrationSetup integration.
 *
 * Usage: (2-4 minute runtime depending on computer setup)
 *   From project root:
 *   php vendor/enlivenapp/migrations/tests/SafetyNetTest.php
 *
 * All test tables use the prefix `_test_sn_` and are cleaned up between tests.
 * Report is written to DataSaverResults.md alongside this file.
 */

declare(strict_types=1);

set_time_limit(300);

require_once __DIR__ . '/../../../autoload.php';

use Enlivenapp\Migrations\Services\SchemaBuilder;
use Enlivenapp\Migrations\Services\MigrationSetup;
use Enlivenapp\Migrations\Services\Migration;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
$db = [
    'driver'   => 'mysql',
    'host'     => 'localhost',
    'port'     => 3306,
    'dbname'   => '',
    'user'     => '',
    'password' => '',
    'charset'  => 'utf8mb4',
];

$dsn = sprintf(
    '%s:host=%s;port=%d;dbname=%s;charset=%s',
    $db['driver'], $db['host'], $db['port'], $db['dbname'], $db['charset']
);

$pdo = new PDO($dsn, $db['user'], $db['password'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

// ---------------------------------------------------------------------------
// Test harness — dual verification: SchemaBuilder + raw information_schema
// ---------------------------------------------------------------------------

/** @var array<string, array{name: string, status: string, detail: string, category: string}> */
$results = [];

function pass(string $category, string $name, string $detail = ''): array
{
    return ['category' => $category, 'name' => $name, 'status' => 'PASS', 'detail' => $detail];
}

function fail(string $category, string $name, string $detail): array
{
    return ['category' => $category, 'name' => $name, 'status' => 'FAIL', 'detail' => $detail];
}

// --- Raw information_schema helpers (independent of SchemaBuilder) ----------

function rawTableExists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$name]);
    return (int) $stmt->fetchColumn() > 0;
}

function rawColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function rawColumnType(PDO $pdo, string $table, string $column): ?string
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (string) $val : null;
}

function rawColumnNullable(PDO $pdo, string $table, string $column): ?bool
{
    $stmt = $pdo->prepare(
        'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $val = $stmt->fetchColumn();
    return $val !== false ? ($val === 'YES') : null;
}

function rawColumnDefault(PDO $pdo, string $table, string $column): ?string
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : null;
}

function rawIndexExists(PDO $pdo, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $indexName]);
    return (int) $stmt->fetchColumn() > 0;
}

function rawIndexIsUnique(PDO $pdo, string $table, string $indexName): ?bool
{
    $stmt = $pdo->prepare(
        'SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
    );
    $stmt->execute([$table, $indexName]);
    $val = $stmt->fetchColumn();
    return $val !== false ? ((int) $val === 0) : null;
}

function rawIndexColumns(PDO $pdo, string $table, string $indexName): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX'
    );
    $stmt->execute([$table, $indexName]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function rawFkExists(PDO $pdo, string $table, string $fkName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = \'FOREIGN KEY\''
    );
    $stmt->execute([$table, $fkName]);
    return (int) $stmt->fetchColumn() > 0;
}

function rawFkDetails(PDO $pdo, string $table, string $fkName): ?array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME '
        . 'FROM information_schema.KEY_COLUMN_USAGE '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? '
        . 'ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([$table, $fkName]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return null;
    }

    $stmt2 = $pdo->prepare(
        'SELECT DELETE_RULE, UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS '
        . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
    );
    $stmt2->execute([$table, $fkName]);
    $rules = $stmt2->fetch(PDO::FETCH_ASSOC) ?: ['DELETE_RULE' => 'RESTRICT', 'UPDATE_RULE' => 'RESTRICT'];

    return [
        'columns'    => array_column($rows, 'COLUMN_NAME'),
        'refTable'   => $rows[0]['REFERENCED_TABLE_NAME'],
        'refColumns' => array_column($rows, 'REFERENCED_COLUMN_NAME'),
        'deleteRule'  => $rules['DELETE_RULE'],
        'updateRule'  => $rules['UPDATE_RULE'],
    ];
}

function rawRowCount(PDO $pdo, string $table): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}

function rawAutoIncrement(PDO $pdo, string $table): int
{
    $stmt = $pdo->prepare(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function rawColumnList(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([$table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// --- Dual-verification assertion helpers -----------------------------------

/**
 * Verify a table exists (or not) via both SchemaBuilder and raw SQL.
 * Returns null on success, error string on mismatch or wrong state.
 */
function assertTable(PDO $pdo, SchemaBuilder $verify, string $table, bool $shouldExist, string $context = ''): ?string
{
    $sbResult  = $verify->hasTable($table);
    $rawResult = rawTableExists($pdo, $table);
    $ctx       = $context ? " ({$context})" : '';

    if ($sbResult !== $rawResult) {
        return "DISAGREE on `{$table}`{$ctx}: SchemaBuilder=" . var_export($sbResult, true)
            . ", info_schema=" . var_export($rawResult, true);
    }
    if ($sbResult !== $shouldExist) {
        $word = $shouldExist ? 'exist' : 'not exist';
        return "Expected `{$table}` to {$word}{$ctx}, both agree it " . ($sbResult ? 'exists' : 'does not');
    }
    return null;
}

/**
 * Verify a column exists (or not) via both SchemaBuilder and raw SQL.
 */
function assertColumn(PDO $pdo, SchemaBuilder $verify, string $table, string $column, bool $shouldExist, string $context = ''): ?string
{
    $verify->table($table);
    $sbResult  = $verify->hasColumn($column);
    $rawResult = rawColumnExists($pdo, $table, $column);
    $ctx       = $context ? " ({$context})" : '';

    if ($sbResult !== $rawResult) {
        return "DISAGREE on `{$table}`.`{$column}`{$ctx}: SchemaBuilder=" . var_export($sbResult, true)
            . ", info_schema=" . var_export($rawResult, true);
    }
    if ($sbResult !== $shouldExist) {
        $word = $shouldExist ? 'exist' : 'not exist';
        return "Expected `{$table}`.`{$column}` to {$word}{$ctx}, both agree it " . ($sbResult ? 'exists' : 'does not');
    }
    return null;
}

/**
 * Collect assertion errors into an array, filtering nulls.
 */
function check(array &$errors, ?string $result): void
{
    if ($result !== null) {
        $errors[] = $result;
    }
}

/**
 * Drop all _test_sn_ tables and their _bak_ counterparts.
 */
function cleanTestTables(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $stmt = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES "
        . "WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME LIKE '_test_sn_%' OR TABLE_NAME LIKE '_bak__test_sn_%')"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * Clean tracking table rows for test packages.
 */
function cleanTrackingRows(PDO $pdo): void
{
    try {
        $pdo->exec("DELETE FROM `migrations` WHERE `package` LIKE 'test-vendor/test-pkg%'");
        $pdo->exec("DELETE FROM `seeds` WHERE `package` LIKE 'test-vendor/test-pkg%'");
    } catch (\Throwable $e) {
    }
}

/**
 * Remove a temp directory tree.
 */
function removeTmpDir(string $tmpDir): void
{
    $it = new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            @unlink($file->getRealPath());
        }
    }
    @rmdir($tmpDir);
}

// ---------------------------------------------------------------------------
// Clean slate
// ---------------------------------------------------------------------------

cleanTestTables($pdo);

// ===========================================================================
// A. SchemaBuilder self-checks (hasTable / hasColumn)
// ===========================================================================

fwrite(STDERR, "Running: A. SchemaBuilder Self-Checks\n");
$cat = 'A. SchemaBuilder Self-Checks';

// A1: hasTable true for existing table
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);
    $sb->table('_test_sn_a1')->addColumn('id', 'primary')->create();

    $errors = [];
    check($errors, assertTable($pdo, $verify, '_test_sn_a1', true, 'after create'));

    $results[] = empty($errors)
        ? pass($cat, 'hasTable returns true for existing table', 'Both SchemaBuilder and info_schema agree')
        : fail($cat, 'hasTable returns true for existing table', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'hasTable returns true for existing table', $e->getMessage());
}
cleanTestTables($pdo);

// A2: hasTable false for nonexistent table
try {
    $verify = new SchemaBuilder($pdo);
    $errors = [];
    check($errors, assertTable($pdo, $verify, '_test_sn_nope_9999', false, 'never created'));

    $results[] = empty($errors)
        ? pass($cat, 'hasTable returns false for nonexistent table', 'Both agree')
        : fail($cat, 'hasTable returns false for nonexistent table', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'hasTable returns false for nonexistent table', $e->getMessage());
}

// A3: hasColumn true for existing column
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);
    $sb->table('_test_sn_a3')
        ->addColumn('id', 'primary')
        ->addColumn('email', 'string', ['length' => 255])
        ->create();

    $errors = [];
    check($errors, assertColumn($pdo, $verify, '_test_sn_a3', 'id', true, 'pk column'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_a3', 'email', true, 'string column'));

    $results[] = empty($errors)
        ? pass($cat, 'hasColumn returns true for existing columns', 'Both agree for id and email')
        : fail($cat, 'hasColumn returns true for existing columns', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'hasColumn returns true for existing columns', $e->getMessage());
}
cleanTestTables($pdo);

// A4: hasColumn false for nonexistent column
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);
    $sb->table('_test_sn_a4')->addColumn('id', 'primary')->create();

    $errors = [];
    check($errors, assertColumn($pdo, $verify, '_test_sn_a4', 'nope_col', false, 'never added'));

    $results[] = empty($errors)
        ? pass($cat, 'hasColumn returns false for nonexistent column', 'Both agree')
        : fail($cat, 'hasColumn returns false for nonexistent column', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'hasColumn returns false for nonexistent column', $e->getMessage());
}
cleanTestTables($pdo);

// ===========================================================================
// B. Lightweight tier — non-destructive operations reversed on restore
// ===========================================================================

fwrite(STDERR, "Running: B. Lightweight Tier\n");
$cat = 'B. Lightweight Tier';

// B1: create() → restore drops the created table
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->enableSafetyNet();
    $sb->table('_test_sn_b1')
        ->addColumn('id', 'primary')
        ->addColumn('name', 'string', ['length' => 100])
        ->create();

    $errors = [];
    check($errors, assertTable($pdo, $verify, '_test_sn_b1', true, 'after create'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_b1', 'name', true, 'after create'));

    $sb->restoreSafetyNet();

    check($errors, assertTable($pdo, $verify, '_test_sn_b1', false, 'after restore'));

    $results[] = empty($errors)
        ? pass($cat, 'create() reversed on restore', 'Table created then dropped on restore')
        : fail($cat, 'create() reversed on restore', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'create() reversed on restore', $e->getMessage());
}
cleanTestTables($pdo);

// B2: addColumns() single column → restore removes it
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->table('_test_sn_b2')->addColumn('id', 'primary')->addColumn('name', 'string', ['length' => 100])->create();

    $sb->enableSafetyNet();
    $sb->table('_test_sn_b2')->addColumn('email', 'string', ['length' => 255])->addColumns();

    $errors = [];
    check($errors, assertColumn($pdo, $verify, '_test_sn_b2', 'email', true, 'after addColumns'));

    $sb->restoreSafetyNet();

    check($errors, assertColumn($pdo, $verify, '_test_sn_b2', 'email', false, 'after restore'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_b2', 'name', true, 'original intact'));

    $results[] = empty($errors)
        ? pass($cat, 'addColumns() single reversed on restore', 'Column added then removed; original intact')
        : fail($cat, 'addColumns() single reversed on restore', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'addColumns() single reversed on restore', $e->getMessage());
}
cleanTestTables($pdo);

// B3: addColumns() multiple columns → restore removes all
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->table('_test_sn_b3')->addColumn('id', 'primary')->create();

    $sb->enableSafetyNet();
    $sb->table('_test_sn_b3')
        ->addColumn('col_a', 'string', ['length' => 50])
        ->addColumn('col_b', 'integer')
        ->addColumn('col_c', 'boolean', ['default' => true])
        ->addColumns();

    $errors = [];
    check($errors, assertColumn($pdo, $verify, '_test_sn_b3', 'col_a', true, 'after add'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_b3', 'col_b', true, 'after add'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_b3', 'col_c', true, 'after add'));

    $sb->restoreSafetyNet();

    check($errors, assertColumn($pdo, $verify, '_test_sn_b3', 'col_a', false, 'after restore'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_b3', 'col_b', false, 'after restore'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_b3', 'col_c', false, 'after restore'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_b3', 'id', true, 'original intact'));

    $results[] = empty($errors)
        ? pass($cat, 'addColumns() multiple reversed on restore', 'All 3 columns removed; id intact')
        : fail($cat, 'addColumns() multiple reversed on restore', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'addColumns() multiple reversed on restore', $e->getMessage());
}
cleanTestTables($pdo);

// B4: update() with columns + index + FK → restore removes all
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    // Parent table for FK
    $sb->table('_test_sn_b4_parent')->addColumn('id', 'primary')->create();

    // Child table
    $sb->table('_test_sn_b4_child')
        ->addColumn('id', 'primary')
        ->create();

    $sb->enableSafetyNet();
    $sb->table('_test_sn_b4_child')
        ->addColumn('ref_id', 'integer', ['unsigned' => true])
        ->addColumn('code', 'string', ['length' => 20])
        ->addIndex(['code'], ['name' => 'idx_b4_code', 'unique' => true])
        ->addForeignKey(['ref_id'], '_test_sn_b4_parent', ['id'], ['delete' => 'CASCADE', 'name' => 'fk_b4_ref'])
        ->update();

    $errors = [];
    check($errors, assertColumn($pdo, $verify, '_test_sn_b4_child', 'ref_id', true, 'after update'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_b4_child', 'code', true, 'after update'));
    if (!rawIndexExists($pdo, '_test_sn_b4_child', 'idx_b4_code')) {
        $errors[] = 'Index idx_b4_code not found after update';
    }
    if (!rawFkExists($pdo, '_test_sn_b4_child', 'fk_b4_ref')) {
        $errors[] = 'FK fk_b4_ref not found after update';
    }

    $sb->restoreSafetyNet();

    check($errors, assertColumn($pdo, $verify, '_test_sn_b4_child', 'ref_id', false, 'after restore'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_b4_child', 'code', false, 'after restore'));
    if (rawIndexExists($pdo, '_test_sn_b4_child', 'idx_b4_code')) {
        $errors[] = 'Index idx_b4_code still exists after restore';
    }
    if (rawFkExists($pdo, '_test_sn_b4_child', 'fk_b4_ref')) {
        $errors[] = 'FK fk_b4_ref still exists after restore';
    }

    $results[] = empty($errors)
        ? pass($cat, 'update() col+idx+FK reversed on restore', 'All 4 artifacts (2 cols, 1 idx, 1 FK) removed')
        : fail($cat, 'update() col+idx+FK reversed on restore', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'update() col+idx+FK reversed on restore', $e->getMessage());
}
cleanTestTables($pdo);

// B5: modifyColumn() → restore reverts type
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->table('_test_sn_b5')
        ->addColumn('id', 'primary')
        ->addColumn('status', 'string', ['length' => 50, 'default' => 'active'])
        ->create();

    $beforeType = rawColumnType($pdo, '_test_sn_b5', 'status');
    $beforeDefault = rawColumnDefault($pdo, '_test_sn_b5', 'status');

    $sb->enableSafetyNet();
    $sb->table('_test_sn_b5')->modifyColumn('status', 'integer', ['default' => 0]);

    $errors = [];
    $midType = rawColumnType($pdo, '_test_sn_b5', 'status');
    if ($midType === $beforeType) {
        $errors[] = "Column type did not change after modifyColumn: still {$midType}";
    }

    $sb->restoreSafetyNet();

    $afterType = rawColumnType($pdo, '_test_sn_b5', 'status');
    $afterDefault = rawColumnDefault($pdo, '_test_sn_b5', 'status');
    if ($afterType !== $beforeType) {
        $errors[] = "Type not restored: before={$beforeType}, after={$afterType}";
    }
    if ($afterDefault !== $beforeDefault) {
        $errors[] = "Default not restored: before={$beforeDefault}, after={$afterDefault}";
    }
    check($errors, assertColumn($pdo, $verify, '_test_sn_b5', 'status', true, 'column still exists'));

    $results[] = empty($errors)
        ? pass($cat, 'modifyColumn() reversed on restore', "Type: {$beforeType} -> {$midType} -> {$afterType}, default restored")
        : fail($cat, 'modifyColumn() reversed on restore', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'modifyColumn() reversed on restore', $e->getMessage());
}
cleanTestTables($pdo);

// B6: renameColumn() → restore reverts name
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->table('_test_sn_b6')
        ->addColumn('id', 'primary')
        ->addColumn('old_name', 'string', ['length' => 100])
        ->create();

    $sb->enableSafetyNet();
    $sb->table('_test_sn_b6')->renameColumn('old_name', 'new_name');

    $errors = [];
    check($errors, assertColumn($pdo, $verify, '_test_sn_b6', 'new_name', true, 'after rename'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_b6', 'old_name', false, 'after rename'));

    $sb->restoreSafetyNet();

    check($errors, assertColumn($pdo, $verify, '_test_sn_b6', 'old_name', true, 'after restore'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_b6', 'new_name', false, 'after restore'));

    $results[] = empty($errors)
        ? pass($cat, 'renameColumn() reversed on restore', 'old_name -> new_name -> old_name')
        : fail($cat, 'renameColumn() reversed on restore', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'renameColumn() reversed on restore', $e->getMessage());
}
cleanTestTables($pdo);

// B7: rename() table → restore reverts name
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->table('_test_sn_b7')
        ->addColumn('id', 'primary')
        ->addColumn('val', 'string', ['length' => 50])
        ->create();

    $sb->enableSafetyNet();
    $sb->table('_test_sn_b7')->rename('_test_sn_b7_new');

    $errors = [];
    check($errors, assertTable($pdo, $verify, '_test_sn_b7_new', true, 'after rename'));
    check($errors, assertTable($pdo, $verify, '_test_sn_b7', false, 'after rename'));

    $sb->restoreSafetyNet();

    check($errors, assertTable($pdo, $verify, '_test_sn_b7', true, 'after restore'));
    check($errors, assertTable($pdo, $verify, '_test_sn_b7_new', false, 'after restore'));

    $results[] = empty($errors)
        ? pass($cat, 'rename() table reversed on restore', '_test_sn_b7 -> _new -> _test_sn_b7')
        : fail($cat, 'rename() table reversed on restore', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'rename() table reversed on restore', $e->getMessage());
}
cleanTestTables($pdo);

// B8: dropIndex() by name → restore recreates with same uniqueness and columns
try {
    $sb = new SchemaBuilder($pdo);

    $sb->table('_test_sn_b8')
        ->addColumn('id', 'primary')
        ->addColumn('code', 'string', ['length' => 20])
        ->addIndex(['code'], ['unique' => true, 'name' => 'idx_b8_code'])
        ->create();

    $beforeUnique = rawIndexIsUnique($pdo, '_test_sn_b8', 'idx_b8_code');
    $beforeCols = rawIndexColumns($pdo, '_test_sn_b8', 'idx_b8_code');

    $errors = [];
    if (!rawIndexExists($pdo, '_test_sn_b8', 'idx_b8_code')) {
        $errors[] = 'Index not found before test';
    }

    $sb->enableSafetyNet();
    $sb->table('_test_sn_b8')->dropIndex('idx_b8_code');

    if (rawIndexExists($pdo, '_test_sn_b8', 'idx_b8_code')) {
        $errors[] = 'Index still exists after drop';
    }

    $sb->restoreSafetyNet();

    if (!rawIndexExists($pdo, '_test_sn_b8', 'idx_b8_code')) {
        $errors[] = 'Index not recreated after restore';
    }
    $afterUnique = rawIndexIsUnique($pdo, '_test_sn_b8', 'idx_b8_code');
    $afterCols = rawIndexColumns($pdo, '_test_sn_b8', 'idx_b8_code');
    if ($afterUnique !== $beforeUnique) {
        $errors[] = "Uniqueness changed: before={$beforeUnique}, after={$afterUnique}";
    }
    if ($afterCols !== $beforeCols) {
        $errors[] = "Columns changed: before=" . implode(',', $beforeCols) . ", after=" . implode(',', $afterCols);
    }

    $results[] = empty($errors)
        ? pass($cat, 'dropIndex() by name reversed on restore', 'Unique index dropped then recreated with same uniqueness and columns')
        : fail($cat, 'dropIndex() by name reversed on restore', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'dropIndex() by name reversed on restore', $e->getMessage());
}
cleanTestTables($pdo);

// B9: dropIndex() by column array → restore recreates
try {
    $sb = new SchemaBuilder($pdo);

    $sb->table('_test_sn_b9')
        ->addColumn('id', 'primary')
        ->addColumn('first', 'string', ['length' => 50])
        ->addColumn('last', 'string', ['length' => 50])
        ->addIndex(['first', 'last'], ['name' => 'idx_b9_name'])
        ->create();

    $errors = [];

    $sb->enableSafetyNet();
    $sb->table('_test_sn_b9')->dropIndex(['first', 'last']);

    if (rawIndexExists($pdo, '_test_sn_b9', 'idx_b9_name')) {
        $errors[] = 'Index still exists after dropIndex by columns';
    }

    $sb->restoreSafetyNet();

    if (!rawIndexExists($pdo, '_test_sn_b9', 'idx_b9_name')) {
        $errors[] = 'Index not recreated after restore';
    }
    $afterCols = rawIndexColumns($pdo, '_test_sn_b9', 'idx_b9_name');
    if ($afterCols !== ['first', 'last']) {
        $errors[] = 'Columns wrong after restore: ' . implode(',', $afterCols);
    }

    $results[] = empty($errors)
        ? pass($cat, 'dropIndex() by column array reversed on restore', 'Compound index dropped by column lookup, recreated on restore')
        : fail($cat, 'dropIndex() by column array reversed on restore', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'dropIndex() by column array reversed on restore', $e->getMessage());
}
cleanTestTables($pdo);

// B10: dropForeignKey() by name → restore recreates with same rules
try {
    $sb = new SchemaBuilder($pdo);

    $sb->table('_test_sn_b10_p')->addColumn('id', 'primary')->create();

    $sb->table('_test_sn_b10_c')
        ->addColumn('id', 'primary')
        ->addColumn('ref_id', 'integer', ['unsigned' => true])
        ->addForeignKey(['ref_id'], '_test_sn_b10_p', ['id'], ['delete' => 'CASCADE', 'update' => 'RESTRICT', 'name' => 'fk_b10'])
        ->create();

    $beforeDef = rawFkDetails($pdo, '_test_sn_b10_c', 'fk_b10');

    $errors = [];

    $sb->enableSafetyNet();
    $sb->table('_test_sn_b10_c')->dropForeignKey('fk_b10');

    if (rawFkExists($pdo, '_test_sn_b10_c', 'fk_b10')) {
        $errors[] = 'FK still exists after drop';
    }

    $sb->restoreSafetyNet();

    if (!rawFkExists($pdo, '_test_sn_b10_c', 'fk_b10')) {
        $errors[] = 'FK not recreated after restore';
    }
    $afterDef = rawFkDetails($pdo, '_test_sn_b10_c', 'fk_b10');
    if ($afterDef !== $beforeDef) {
        $errors[] = 'FK definition changed: before=' . json_encode($beforeDef) . ', after=' . json_encode($afterDef);
    }

    $results[] = empty($errors)
        ? pass($cat, 'dropForeignKey() by name reversed on restore', "FK recreated with same rules (CASCADE/{$beforeDef['updateRule']})")
        : fail($cat, 'dropForeignKey() by name reversed on restore', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'dropForeignKey() by name reversed on restore', $e->getMessage());
}
cleanTestTables($pdo);

// B11: dropForeignKey() by column array → restore recreates
try {
    $sb = new SchemaBuilder($pdo);

    $sb->table('_test_sn_b11_p')->addColumn('id', 'primary')->create();

    $sb->table('_test_sn_b11_c')
        ->addColumn('id', 'primary')
        ->addColumn('parent_id', 'integer', ['unsigned' => true])
        ->addForeignKey(['parent_id'], '_test_sn_b11_p', ['id'], ['delete' => 'RESTRICT', 'name' => 'fk_b11'])
        ->create();

    $errors = [];

    $sb->enableSafetyNet();
    $sb->table('_test_sn_b11_c')->dropForeignKey(['parent_id']);

    if (rawFkExists($pdo, '_test_sn_b11_c', 'fk_b11')) {
        $errors[] = 'FK still exists after drop by column array';
    }

    $sb->restoreSafetyNet();

    if (!rawFkExists($pdo, '_test_sn_b11_c', 'fk_b11')) {
        $errors[] = 'FK not recreated after restore';
    }

    $results[] = empty($errors)
        ? pass($cat, 'dropForeignKey() by column array reversed on restore', 'FK dropped by column lookup, recreated on restore')
        : fail($cat, 'dropForeignKey() by column array reversed on restore', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'dropForeignKey() by column array reversed on restore', $e->getMessage());
}
cleanTestTables($pdo);

// ===========================================================================
// C. Heavy tier — destructive operations reversed with data preservation
// ===========================================================================

fwrite(STDERR, "Running: C. Heavy Tier\n");
$cat = 'C. Heavy Tier';

// C1: drop() → restore brings back table + all data
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->table('_test_sn_c1')
        ->addColumn('id', 'primary')
        ->addColumn('val', 'string', ['length' => 100])
        ->create();

    $pdo->exec("INSERT INTO `_test_sn_c1` (`val`) VALUES ('alpha'), ('beta'), ('gamma')");
    $beforeCount = rawRowCount($pdo, '_test_sn_c1');
    $beforeCols = rawColumnList($pdo, '_test_sn_c1');

    $sb->enableSafetyNet();
    $sb->table('_test_sn_c1')->drop();

    $errors = [];
    check($errors, assertTable($pdo, $verify, '_test_sn_c1', false, 'after drop'));
    check($errors, assertTable($pdo, $verify, '_bak__test_sn_c1', true, 'backup exists'));

    $sb->restoreSafetyNet();

    check($errors, assertTable($pdo, $verify, '_test_sn_c1', true, 'after restore'));
    check($errors, assertTable($pdo, $verify, '_bak__test_sn_c1', false, 'backup cleaned up'));

    $afterCount = rawRowCount($pdo, '_test_sn_c1');
    $afterCols = rawColumnList($pdo, '_test_sn_c1');
    if ($afterCount !== $beforeCount) {
        $errors[] = "Row count: before={$beforeCount}, after={$afterCount}";
    }
    if ($afterCols !== $beforeCols) {
        $errors[] = "Columns changed: before=" . implode(',', $beforeCols) . ", after=" . implode(',', $afterCols);
    }

    // Verify actual data values
    $vals = $pdo->query("SELECT `val` FROM `_test_sn_c1` ORDER BY `id`")->fetchAll(PDO::FETCH_COLUMN);
    if ($vals !== ['alpha', 'beta', 'gamma']) {
        $errors[] = 'Data mismatch: ' . json_encode($vals);
    }

    $results[] = empty($errors)
        ? pass($cat, 'drop() reversed on restore (data preserved)', "{$afterCount} rows restored, backup cleaned up")
        : fail($cat, 'drop() reversed on restore (data preserved)', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'drop() reversed on restore (data preserved)', $e->getMessage());
}
cleanTestTables($pdo);

// C2: dropColumns() single → restore brings back column + data
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->table('_test_sn_c2')
        ->addColumn('id', 'primary')
        ->addColumn('keep_me', 'string', ['length' => 50])
        ->addColumn('drop_me', 'string', ['length' => 50])
        ->create();

    $pdo->exec("INSERT INTO `_test_sn_c2` (`keep_me`, `drop_me`) VALUES ('a', 'x'), ('b', 'y')");

    $sb->enableSafetyNet();
    $sb->table('_test_sn_c2')->dropColumns(['drop_me']);

    $errors = [];
    check($errors, assertColumn($pdo, $verify, '_test_sn_c2', 'drop_me', false, 'after dropColumns'));
    check($errors, assertTable($pdo, $verify, '_bak__test_sn_c2', true, 'backup exists'));

    $sb->restoreSafetyNet();

    check($errors, assertColumn($pdo, $verify, '_test_sn_c2', 'drop_me', true, 'after restore'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_c2', 'keep_me', true, 'original intact'));
    check($errors, assertTable($pdo, $verify, '_bak__test_sn_c2', false, 'backup cleaned up'));

    $vals = $pdo->query("SELECT `drop_me` FROM `_test_sn_c2` ORDER BY `id`")->fetchAll(PDO::FETCH_COLUMN);
    if ($vals !== ['x', 'y']) {
        $errors[] = 'Data mismatch in dropped column: ' . json_encode($vals);
    }

    $results[] = empty($errors)
        ? pass($cat, 'dropColumns() single reversed on restore', 'Column and data restored')
        : fail($cat, 'dropColumns() single reversed on restore', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'dropColumns() single reversed on restore', $e->getMessage());
}
cleanTestTables($pdo);

// C3: dropColumns() multiple → restore brings back all columns + data
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->table('_test_sn_c3')
        ->addColumn('id', 'primary')
        ->addColumn('keep', 'string', ['length' => 50])
        ->addColumn('drop_a', 'string', ['length' => 50])
        ->addColumn('drop_b', 'integer', ['default' => 0])
        ->create();

    $pdo->exec("INSERT INTO `_test_sn_c3` (`keep`, `drop_a`, `drop_b`) VALUES ('row1', 'aa', 11), ('row2', 'bb', 22)");

    $sb->enableSafetyNet();
    $sb->table('_test_sn_c3')->dropColumns(['drop_a', 'drop_b']);

    $errors = [];
    check($errors, assertColumn($pdo, $verify, '_test_sn_c3', 'drop_a', false, 'after drop'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_c3', 'drop_b', false, 'after drop'));

    $sb->restoreSafetyNet();

    check($errors, assertColumn($pdo, $verify, '_test_sn_c3', 'drop_a', true, 'after restore'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_c3', 'drop_b', true, 'after restore'));

    $rows = $pdo->query("SELECT `drop_a`, `drop_b` FROM `_test_sn_c3` ORDER BY `id`")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows[0]['drop_a'] !== 'aa' || (int) $rows[0]['drop_b'] !== 11
        || $rows[1]['drop_a'] !== 'bb' || (int) $rows[1]['drop_b'] !== 22) {
        $errors[] = 'Data mismatch: ' . json_encode($rows);
    }

    $results[] = empty($errors)
        ? pass($cat, 'dropColumns() multiple reversed on restore', 'Both columns and all data restored')
        : fail($cat, 'dropColumns() multiple reversed on restore', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'dropColumns() multiple reversed on restore', $e->getMessage());
}
cleanTestTables($pdo);

// C4: AUTO_INCREMENT preserved after drop+restore
try {
    $sb = new SchemaBuilder($pdo);

    $sb->table('_test_sn_c4')
        ->addColumn('id', 'primary')
        ->addColumn('val', 'string', ['length' => 50])
        ->create();

    $pdo->exec("INSERT INTO `_test_sn_c4` (`val`) VALUES ('a'), ('b'), ('c'), ('d'), ('e')");
    $beforeAI = rawAutoIncrement($pdo, '_test_sn_c4');

    $errors = [];
    if ($beforeAI <= 1) {
        $errors[] = "AUTO_INCREMENT not advanced after inserts: {$beforeAI}";
    }

    $sb->enableSafetyNet();
    $sb->table('_test_sn_c4')->drop();
    $sb->restoreSafetyNet();

    $afterAI = rawAutoIncrement($pdo, '_test_sn_c4');
    if ($afterAI !== $beforeAI) {
        $errors[] = "AUTO_INCREMENT not preserved: before={$beforeAI}, after={$afterAI}";
    }

    // Verify inserting after restore continues from correct ID
    $pdo->exec("INSERT INTO `_test_sn_c4` (`val`) VALUES ('f')");
    $lastId = (int) $pdo->lastInsertId();
    if ($lastId !== $beforeAI) {
        $errors[] = "Next inserted ID unexpected: expected={$beforeAI}, got={$lastId}";
    }

    $results[] = empty($errors)
        ? pass($cat, 'AUTO_INCREMENT preserved after drop+restore', "AI={$beforeAI}, next insert ID={$lastId}")
        : fail($cat, 'AUTO_INCREMENT preserved after drop+restore', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'AUTO_INCREMENT preserved after drop+restore', $e->getMessage());
}
cleanTestTables($pdo);

// C5: Same table, two destructive ops → only one clone
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->table('_test_sn_c5')
        ->addColumn('id', 'primary')
        ->addColumn('col_a', 'string', ['length' => 50])
        ->addColumn('col_b', 'string', ['length' => 50])
        ->create();

    $pdo->exec("INSERT INTO `_test_sn_c5` (`col_a`, `col_b`) VALUES ('a1', 'b1'), ('a2', 'b2')");

    $sb->enableSafetyNet();

    // First destructive op — triggers clone
    $sb->table('_test_sn_c5')->dropColumns(['col_a']);

    // Second destructive op on same table — should NOT re-clone (would lose col_a from backup)
    $sb->table('_test_sn_c5')->dropColumns(['col_b']);

    $errors = [];
    check($errors, assertColumn($pdo, $verify, '_test_sn_c5', 'col_a', false, 'both dropped'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_c5', 'col_b', false, 'both dropped'));

    // Backup should have BOTH columns (cloned before first drop)
    if (!rawColumnExists($pdo, '_bak__test_sn_c5', 'col_a')) {
        $errors[] = 'Backup missing col_a — was it re-cloned after first drop?';
    }
    if (!rawColumnExists($pdo, '_bak__test_sn_c5', 'col_b')) {
        $errors[] = 'Backup missing col_b — was it re-cloned after first drop?';
    }

    $sb->restoreSafetyNet();

    check($errors, assertColumn($pdo, $verify, '_test_sn_c5', 'col_a', true, 'after restore'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_c5', 'col_b', true, 'after restore'));

    $rows = $pdo->query("SELECT `col_a`, `col_b` FROM `_test_sn_c5` ORDER BY `id`")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows[0]['col_a'] !== 'a1' || $rows[0]['col_b'] !== 'b1') {
        $errors[] = 'Data mismatch after restore: ' . json_encode($rows);
    }

    $results[] = empty($errors)
        ? pass($cat, 'Two destructive ops, one clone', 'Single backup preserved both columns; restore recovered all data')
        : fail($cat, 'Two destructive ops, one clone', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'Two destructive ops, one clone', $e->getMessage());
}
cleanTestTables($pdo);

// C6: drop table referenced by FK — MySQL blocks this, data saver cleans up clone
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    // Parent
    $sb->table('_test_sn_c6_p')
        ->addColumn('id', 'primary')
        ->addColumn('name', 'string', ['length' => 50])
        ->create();
    $pdo->exec("INSERT INTO `_test_sn_c6_p` (`name`) VALUES ('parent1')");

    // Child with FK
    $sb->table('_test_sn_c6_c')
        ->addColumn('id', 'primary')
        ->addColumn('pid', 'integer', ['unsigned' => true])
        ->addForeignKey(['pid'], '_test_sn_c6_p', ['id'], ['delete' => 'CASCADE', 'name' => 'fk_c6'])
        ->create();
    $pdo->exec("INSERT INTO `_test_sn_c6_c` (`pid`) VALUES (1)");

    $sb->enableSafetyNet();

    $errors = [];

    // drop() creates a clone BEFORE attempting the DROP.
    // MySQL blocks the DROP because of the FK reference.
    // The data saver should handle this gracefully.
    $dropFailed = false;
    try {
        $sb->table('_test_sn_c6_p')->drop();
    } catch (\Throwable $e) {
        $dropFailed = true;
    }

    if (!$dropFailed) {
        $errors[] = 'DROP should have failed due to FK constraint';
    }

    // Parent table should still exist (DROP was blocked)
    check($errors, assertTable($pdo, $verify, '_test_sn_c6_p', true, 'parent still exists after blocked DROP'));

    // restoreSafetyNet should clean up the backup clone without error
    $sb->restoreSafetyNet();

    check($errors, assertTable($pdo, $verify, '_bak__test_sn_c6_p', false, 'backup cleaned up'));

    // FK should still be intact and enforced
    if (!rawFkExists($pdo, '_test_sn_c6_c', 'fk_c6')) {
        $errors[] = 'FK fk_c6 lost';
    }
    try {
        $pdo->exec("INSERT INTO `_test_sn_c6_c` (`pid`) VALUES (9999)");
        $errors[] = 'FK not enforced — invalid child row accepted';
    } catch (\PDOException $e) {
        // Expected
    }

    $results[] = empty($errors)
        ? pass($cat, 'Drop FK-referenced table: blocked, cleanup OK', 'MySQL blocked DROP, data saver cleaned up clone, FK intact')
        : fail($cat, 'Drop FK-referenced table: blocked, cleanup OK', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'Drop FK-referenced table: blocked, cleanup OK', $e->getMessage());
}
cleanTestTables($pdo);

// ===========================================================================
// D. Cleanup and lifecycle
// ===========================================================================

fwrite(STDERR, "Running: D. Cleanup / Lifecycle\n");
$cat = 'D. Cleanup / Lifecycle';

// D1: cleanupSafetyNet drops _bak_ tables on success
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->table('_test_sn_d1')
        ->addColumn('id', 'primary')
        ->addColumn('val', 'string', ['length' => 50])
        ->create();
    $pdo->exec("INSERT INTO `_test_sn_d1` (`val`) VALUES ('keep')");

    $sb->enableSafetyNet();
    $sb->table('_test_sn_d1')->dropColumns(['val']);

    $errors = [];
    check($errors, assertTable($pdo, $verify, '_bak__test_sn_d1', true, 'backup created'));

    $sb->cleanupSafetyNet();

    check($errors, assertTable($pdo, $verify, '_bak__test_sn_d1', false, 'backup dropped'));
    check($errors, assertTable($pdo, $verify, '_test_sn_d1', true, 'table still exists'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_d1', 'val', false, 'destructive change kept'));

    $results[] = empty($errors)
        ? pass($cat, 'cleanupSafetyNet drops backup on success', 'Backup dropped, destructive change kept')
        : fail($cat, 'cleanupSafetyNet drops backup on success', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'cleanupSafetyNet drops backup on success', $e->getMessage());
}
cleanTestTables($pdo);

// D2: enableSafetyNet resets state from previous run
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    // First migration: create a table with data saver
    $sb->table('_test_sn_d2a')->addColumn('id', 'primary')->create();

    $sb->enableSafetyNet();
    $sb->table('_test_sn_d2a')
        ->addColumn('added', 'string', ['length' => 50])
        ->addColumns();

    // Second migration: enableSafetyNet again (should reset)
    $sb->enableSafetyNet();

    $sb->table('_test_sn_d2a')
        ->addColumn('added2', 'string', ['length' => 50])
        ->addColumns();

    // Restore should only undo ops from AFTER the second enableSafetyNet
    $sb->restoreSafetyNet();

    $errors = [];
    check($errors, assertColumn($pdo, $verify, '_test_sn_d2a', 'added', true, 'first migration column preserved'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_d2a', 'added2', false, 'second migration column restored'));

    $results[] = empty($errors)
        ? pass($cat, 'enableSafetyNet resets state', 'Second enable cleared first session; restore only undid second')
        : fail($cat, 'enableSafetyNet resets state', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'enableSafetyNet resets state', $e->getMessage());
}
cleanTestTables($pdo);

// D3: Safety net disabled → no ops recorded, no clones made
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->table('_test_sn_d3')
        ->addColumn('id', 'primary')
        ->addColumn('drop_this', 'string', ['length' => 50])
        ->create();
    $pdo->exec("INSERT INTO `_test_sn_d3` (`drop_this`) VALUES ('gone')");

    // Do NOT enable data saver
    $sb->table('_test_sn_d3')->dropColumns(['drop_this']);

    $errors = [];
    // No backup should exist
    check($errors, assertTable($pdo, $verify, '_bak__test_sn_d3', false, 'no backup without data saver'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_d3', 'drop_this', false, 'column dropped'));

    // restoreSafetyNet should be a no-op (nothing recorded)
    $sb->restoreSafetyNet();

    // Column should still be gone — restore had nothing to do
    check($errors, assertColumn($pdo, $verify, '_test_sn_d3', 'drop_this', false, 'still gone after no-op restore'));

    $results[] = empty($errors)
        ? pass($cat, 'Disabled data saver: no ops, no clones', 'No backup created; restore was no-op')
        : fail($cat, 'Disabled data saver: no ops, no clones', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'Disabled data saver: no ops, no clones', $e->getMessage());
}
cleanTestTables($pdo);

// ===========================================================================
// E. Multi-operation sequences
// ===========================================================================

fwrite(STDERR, "Running: E. Multi-Operation\n");
$cat = 'E. Multi-Operation';

// E1: Multiple lightweight ops on one table → all reversed
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->table('_test_sn_e1')
        ->addColumn('id', 'primary')
        ->addColumn('original', 'string', ['length' => 100])
        ->create();

    $sb->enableSafetyNet();

    // Op 1: add column
    $sb->table('_test_sn_e1')->addColumn('added', 'string', ['length' => 50])->addColumns();
    // Op 2: modify original column
    $sb->table('_test_sn_e1')->modifyColumn('original', 'text');
    // Op 3: rename column
    $sb->table('_test_sn_e1')->renameColumn('added', 'renamed');

    $errors = [];
    check($errors, assertColumn($pdo, $verify, '_test_sn_e1', 'renamed', true, 'mid-state'));
    $midType = rawColumnType($pdo, '_test_sn_e1', 'original');

    $sb->restoreSafetyNet();

    check($errors, assertColumn($pdo, $verify, '_test_sn_e1', 'added', false, 'added col gone'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_e1', 'renamed', false, 'renamed col gone'));
    $afterType = rawColumnType($pdo, '_test_sn_e1', 'original');
    if ($afterType !== 'varchar(100)') {
        $errors[] = "original type not reverted: {$afterType}";
    }

    $results[] = empty($errors)
        ? pass($cat, 'Multiple lightweight ops on one table reversed', "3 ops reversed: add, modify ({$midType}->varchar(100)), rename")
        : fail($cat, 'Multiple lightweight ops on one table reversed', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'Multiple lightweight ops on one table reversed', $e->getMessage());
}
cleanTestTables($pdo);

// E2: Mixed lightweight + heavy ops on one table → all reversed
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->table('_test_sn_e2')
        ->addColumn('id', 'primary')
        ->addColumn('keep', 'string', ['length' => 50])
        ->addColumn('destroy', 'string', ['length' => 50])
        ->create();
    $pdo->exec("INSERT INTO `_test_sn_e2` (`keep`, `destroy`) VALUES ('k1', 'd1'), ('k2', 'd2')");

    $sb->enableSafetyNet();

    // Lightweight: add column
    $sb->table('_test_sn_e2')->addColumn('new_col', 'integer', ['default' => 0])->addColumns();
    // Heavy: drop column (clones table)
    $sb->table('_test_sn_e2')->dropColumns(['destroy']);
    // Lightweight: rename another column
    $sb->table('_test_sn_e2')->renameColumn('keep', 'kept');

    $errors = [];
    check($errors, assertColumn($pdo, $verify, '_test_sn_e2', 'new_col', true, 'mid-state'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_e2', 'destroy', false, 'mid-state'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_e2', 'kept', true, 'mid-state'));

    $sb->restoreSafetyNet();

    check($errors, assertColumn($pdo, $verify, '_test_sn_e2', 'new_col', false, 'after restore'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_e2', 'destroy', true, 'after restore'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_e2', 'keep', true, 'after restore'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_e2', 'kept', false, 'after restore'));

    $vals = $pdo->query("SELECT `destroy` FROM `_test_sn_e2` ORDER BY `id`")->fetchAll(PDO::FETCH_COLUMN);
    if ($vals !== ['d1', 'd2']) {
        $errors[] = 'Data in destroyed column not restored: ' . json_encode($vals);
    }

    $results[] = empty($errors)
        ? pass($cat, 'Mixed lightweight+heavy on one table reversed', 'addColumn + dropColumns + renameColumn all reversed; data intact')
        : fail($cat, 'Mixed lightweight+heavy on one table reversed', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'Mixed lightweight+heavy on one table reversed', $e->getMessage());
}
cleanTestTables($pdo);

// E3: Operations across multiple tables → all reversed
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->table('_test_sn_e3a')
        ->addColumn('id', 'primary')
        ->addColumn('name', 'string', ['length' => 100])
        ->addColumn('remove_me', 'string', ['length' => 50])
        ->create();
    $pdo->exec("INSERT INTO `_test_sn_e3a` (`name`, `remove_me`) VALUES ('alice', 'x'), ('bob', 'y')");

    $sb->table('_test_sn_e3b')
        ->addColumn('id', 'primary')
        ->addColumn('code', 'string', ['length' => 20])
        ->create();

    $sb->enableSafetyNet();

    // Table B: add column (lightweight)
    $sb->table('_test_sn_e3b')->addColumn('extra', 'string', ['length' => 100])->addColumns();
    // Table A: drop column (heavy)
    $sb->table('_test_sn_e3a')->dropColumns(['remove_me']);
    // Table B: rename column (lightweight)
    $sb->table('_test_sn_e3b')->renameColumn('code', 'slug');

    $errors = [];
    check($errors, assertColumn($pdo, $verify, '_test_sn_e3a', 'remove_me', false, 'mid'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_e3b', 'extra', true, 'mid'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_e3b', 'slug', true, 'mid'));

    $sb->restoreSafetyNet();

    check($errors, assertColumn($pdo, $verify, '_test_sn_e3a', 'remove_me', true, 'after restore'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_e3b', 'extra', false, 'after restore'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_e3b', 'code', true, 'after restore'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_e3b', 'slug', false, 'after restore'));

    $aData = $pdo->query("SELECT `remove_me` FROM `_test_sn_e3a` ORDER BY `id`")->fetchAll(PDO::FETCH_COLUMN);
    if ($aData !== ['x', 'y']) {
        $errors[] = 'Table A data not restored: ' . json_encode($aData);
    }

    $results[] = empty($errors)
        ? pass($cat, 'Ops across multiple tables reversed', '3 ops on 2 tables reversed; data intact')
        : fail($cat, 'Ops across multiple tables reversed', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'Ops across multiple tables reversed', $e->getMessage());
}
cleanTestTables($pdo);

// ===========================================================================
// F. Edge cases
// ===========================================================================

fwrite(STDERR, "Running: F. Edge Cases\n");
$cat = 'F. Edge Cases';

// F1: statement() does NOT record data saver ops
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->table('_test_sn_f1')
        ->addColumn('id', 'primary')
        ->addColumn('val', 'string', ['length' => 50])
        ->create();

    $sb->enableSafetyNet();

    // Raw SQL via statement() — data saver should NOT track this
    $sb->statement("ALTER TABLE `_test_sn_f1` ADD COLUMN `raw_col` VARCHAR(50) NOT NULL DEFAULT ''");

    $errors = [];
    check($errors, assertColumn($pdo, $verify, '_test_sn_f1', 'raw_col', true, 'after statement'));

    $sb->restoreSafetyNet();

    // raw_col should still be there — statement() is not tracked
    check($errors, assertColumn($pdo, $verify, '_test_sn_f1', 'raw_col', true, 'after restore — not tracked'));

    $results[] = empty($errors)
        ? pass($cat, 'statement() not tracked by data saver', 'Raw SQL column survived restore — by design')
        : fail($cat, 'statement() not tracked by data saver', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'statement() not tracked by data saver', $e->getMessage());
}
cleanTestTables($pdo);

// F2: Pretend mode — no actual DB changes
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->table('_test_sn_f2')
        ->addColumn('id', 'primary')
        ->addColumn('val', 'string', ['length' => 50])
        ->create();

    $sb->setPretendMode(true);

    $sb->table('_test_sn_f2')
        ->addColumn('pretend_col', 'string', ['length' => 100])
        ->addColumns();

    $errors = [];
    // Column should NOT exist — pretend mode captures SQL but doesn't execute
    check($errors, assertColumn($pdo, $verify, '_test_sn_f2', 'pretend_col', false, 'pretend mode — no real change'));

    $sql = $sb->getPretendedSql();
    if (empty($sql)) {
        $errors[] = 'No SQL captured in pretend mode';
    }

    $sb->setPretendMode(false);

    $results[] = empty($errors)
        ? pass($cat, 'Pretend mode captures SQL without executing', count($sql) . ' statement(s) captured, no DB change')
        : fail($cat, 'Pretend mode captures SQL without executing', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'Pretend mode captures SQL without executing', $e->getMessage());
}
cleanTestTables($pdo);

// F3: Recording mode captures ops for change() reversibility
try {
    $sb = new SchemaBuilder($pdo);
    $verify = new SchemaBuilder($pdo);

    $sb->startRecording();

    $sb->table('_test_sn_f3')
        ->addColumn('id', 'primary')
        ->addColumn('name', 'string', ['length' => 100])
        ->create();

    $sb->table('_test_sn_f3')
        ->addColumn('extra', 'string', ['length' => 50])
        ->addColumns();

    $ops = $sb->stopRecording();

    $errors = [];
    if (empty($ops)) {
        $errors[] = 'No ops recorded';
    }

    // Should have recorded: create + addColumns
    $opTypes = array_column($ops, 'op');
    if (!in_array('create', $opTypes)) {
        $errors[] = 'create not recorded';
    }
    if (!in_array('addColumns', $opTypes)) {
        $errors[] = 'addColumns not recorded';
    }

    // Now reverse the recorded ops
    $sb->reverseOperations($ops);

    check($errors, assertTable($pdo, $verify, '_test_sn_f3', false, 'after reverseOperations'));

    $results[] = empty($errors)
        ? pass($cat, 'Recording mode captures ops for reversal', 'Recorded ' . count($ops) . ' ops: ' . implode(',', $opTypes) . '; reverseOperations undid all')
        : fail($cat, 'Recording mode captures ops for reversal', implode('; ', $errors));
} catch (\Throwable $e) {
    $results[] = fail($cat, 'Recording mode captures ops for reversal', $e->getMessage());
}
cleanTestTables($pdo);

// ===========================================================================
// G. Integration through MigrationSetup
// ===========================================================================

fwrite(STDERR, "Running: G. Integration\n");
$cat = 'G. Integration';

// G1: Migration failure → data saver + batch rollback
try {
    $tmpDir = sys_get_temp_dir() . '/sn_test_' . uniqid();
    $migDir = $tmpDir . '/vendor/test-vendor/test-pkg-g1/src/Database/Migrations';
    mkdir($migDir, 0777, true);

    $mig1 = <<<'PHP'
<?php
class CreateTestG1Table extends \Enlivenapp\Migrations\Services\Migration
{
    public function up(): void
    {
        $this->table('_test_sn_g1')
            ->addColumn('id', 'primary')
            ->addColumn('name', 'string', ['length' => 100])
            ->create();
    }

    public function down(): void
    {
        $this->table('_test_sn_g1')->drop();
    }
}
PHP;
    file_put_contents($migDir . '/2026-01-01-000001_CreateTestG1Table.php', $mig1);

    $mig2 = <<<'PHP'
<?php
class FailingG1Migration extends \Enlivenapp\Migrations\Services\Migration
{
    public function up(): void
    {
        // Succeeds: adds email column
        $this->table('_test_sn_g1')
            ->addColumn('email', 'string', ['length' => 255])
            ->addColumns();

        // Fails: index on nonexistent column
        $this->table('_test_sn_g1')
            ->addIndex(['nonexistent_xyz'])
            ->update();
    }

    public function down(): void
    {
        $this->table('_test_sn_g1')->dropColumns(['email']);
    }
}
PHP;
    file_put_contents($migDir . '/2026-01-01-000002_FailingG1Migration.php', $mig2);

    $composerDir = $tmpDir . '/vendor/composer';
    mkdir($composerDir, 0777, true);
    file_put_contents($composerDir . '/installed.json', json_encode([
        'packages' => [['name' => 'test-vendor/test-pkg-g1', 'version' => '1.0.0']],
    ]));

    $setup = new MigrationSetup($pdo, [
        'migrations' => ['paths' => ['vendor/*/*/src/Database/Migrations']],
    ], $tmpDir);

    $moduleResults = $setup->runMigrate();

    $hadFailure = false;
    $hadRollback = false;
    $resultDetails = [];
    foreach ($moduleResults as $moduleResult) {
        foreach ($moduleResult->getMigrationResults() as $r) {
            $resultDetails[] = $r->getName() . '=' . ($r->isSuccess() ? 'ok' : 'FAIL');
            if (!$r->isSuccess() && !str_ends_with($r->getName(), ':rollback')) {
                $hadFailure = true;
            }
            if (str_ends_with($r->getName(), ':rollback') && $r->isSuccess()) {
                $hadRollback = true;
            }
        }
    }

    $verify = new SchemaBuilder($pdo);
    $errors = [];

    if (!$hadFailure) {
        $errors[] = 'No failure triggered: ' . implode(', ', $resultDetails);
    }
    if (!$hadRollback) {
        $errors[] = 'No rollback triggered';
    }

    // Table should be gone — batch rollback ran migration 1's down()
    check($errors, assertTable($pdo, $verify, '_test_sn_g1', false, 'after batch rollback'));

    // Known bug check: _bak_ leaked because data saver stays enabled during rollback
    $bakLeaked = rawTableExists($pdo, '_bak__test_sn_g1');

    $msg = 'Failure detected, data saver reversed partial, batch rollback cleaned up';
    if ($bakLeaked) {
        $msg .= ' [KNOWN BUG: _bak_ table leaked — disableSafetyNet() not called before batch rollback]';
    }

    $results[] = empty($errors)
        ? pass($cat, 'Migration failure: data saver + batch rollback', $msg)
        : fail($cat, 'Migration failure: data saver + batch rollback', implode('; ', $errors));

    removeTmpDir($tmpDir);
} catch (\Throwable $e) {
    $results[] = fail($cat, 'Migration failure: data saver + batch rollback', $e->getMessage());
}
cleanTestTables($pdo);
cleanTrackingRows($pdo);

// G2: Migration success → cleanup
try {
    $tmpDir = sys_get_temp_dir() . '/sn_test_' . uniqid();
    $migDir = $tmpDir . '/vendor/test-vendor/test-pkg-g2/src/Database/Migrations';
    mkdir($migDir, 0777, true);

    $mig1 = <<<'PHP'
<?php
class CreateTestG2Table extends \Enlivenapp\Migrations\Services\Migration
{
    public function up(): void
    {
        $this->table('_test_sn_g2')
            ->addColumn('id', 'primary')
            ->addColumn('name', 'string', ['length' => 100])
            ->addColumn('drop_this', 'string', ['length' => 50])
            ->create();
    }

    public function down(): void
    {
        $this->table('_test_sn_g2')->drop();
    }
}
PHP;
    file_put_contents($migDir . '/2026-01-01-000001_CreateTestG2Table.php', $mig1);

    $mig2 = <<<'PHP'
<?php
class SuccessG2Migration extends \Enlivenapp\Migrations\Services\Migration
{
    public function up(): void
    {
        $this->table('_test_sn_g2')->dropColumns(['drop_this']);
    }

    public function down(): void
    {
        $this->table('_test_sn_g2')
            ->addColumn('drop_this', 'string', ['length' => 50])
            ->addColumns();
    }
}
PHP;
    file_put_contents($migDir . '/2026-01-01-000002_SuccessG2Migration.php', $mig2);

    $composerDir = $tmpDir . '/vendor/composer';
    mkdir($composerDir, 0777, true);
    file_put_contents($composerDir . '/installed.json', json_encode([
        'packages' => [['name' => 'test-vendor/test-pkg-g2', 'version' => '1.0.0']],
    ]));

    $setup = new MigrationSetup($pdo, [
        'migrations' => ['paths' => ['vendor/*/*/src/Database/Migrations']],
    ], $tmpDir);

    $moduleResults = $setup->runMigrate();

    $allSuccess = true;
    foreach ($moduleResults as $moduleResult) {
        if (!$moduleResult->isSuccess()) {
            $allSuccess = false;
        }
    }

    $verify = new SchemaBuilder($pdo);
    $errors = [];

    if (!$allSuccess) {
        $errors[] = 'Not all migrations succeeded';
    }
    check($errors, assertTable($pdo, $verify, '_test_sn_g2', true, 'table exists'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_g2', 'drop_this', false, 'column dropped'));
    check($errors, assertTable($pdo, $verify, '_bak__test_sn_g2', false, 'no backup left'));

    // Verify tracking rows exist
    $tracking = $pdo->query(
        "SELECT COUNT(*) FROM `migrations` WHERE `package` = 'test-vendor/test-pkg-g2'"
    )->fetchColumn();
    if ((int) $tracking !== 2) {
        $errors[] = "Expected 2 tracking rows, got {$tracking}";
    }

    $results[] = empty($errors)
        ? pass($cat, 'Migration success: cleanup', 'Both migrations ran, column dropped, no backups, 2 tracking rows')
        : fail($cat, 'Migration success: cleanup', implode('; ', $errors));

    removeTmpDir($tmpDir);
} catch (\Throwable $e) {
    $results[] = fail($cat, 'Migration success: cleanup', $e->getMessage());
}
cleanTestTables($pdo);
cleanTrackingRows($pdo);

// G3: change() migration → recording + reversal via rollback
try {
    $tmpDir = sys_get_temp_dir() . '/sn_test_' . uniqid();
    $migDir = $tmpDir . '/vendor/test-vendor/test-pkg-g3/src/Database/Migrations';
    mkdir($migDir, 0777, true);

    $mig1 = <<<'PHP'
<?php
class ChangeG3Migration extends \Enlivenapp\Migrations\Services\Migration
{
    public function change(): void
    {
        $this->table('_test_sn_g3')
            ->addColumn('id', 'primary')
            ->addColumn('title', 'string', ['length' => 200])
            ->create();
    }
}
PHP;
    file_put_contents($migDir . '/2026-01-01-000001_ChangeG3Migration.php', $mig1);

    $composerDir = $tmpDir . '/vendor/composer';
    mkdir($composerDir, 0777, true);
    file_put_contents($composerDir . '/installed.json', json_encode([
        'packages' => [['name' => 'test-vendor/test-pkg-g3', 'version' => '1.0.0']],
    ]));

    $setup = new MigrationSetup($pdo, [
        'migrations' => ['paths' => ['vendor/*/*/src/Database/Migrations']],
    ], $tmpDir);

    // Run forward
    $moduleResults = $setup->runMigrate();

    $verify = new SchemaBuilder($pdo);
    $errors = [];

    $allSuccess = true;
    foreach ($moduleResults as $moduleResult) {
        if (!$moduleResult->isSuccess()) {
            $allSuccess = false;
        }
    }
    if (!$allSuccess) {
        $errors[] = 'Forward migration failed';
    }
    check($errors, assertTable($pdo, $verify, '_test_sn_g3', true, 'after forward'));
    check($errors, assertColumn($pdo, $verify, '_test_sn_g3', 'title', true, 'after forward'));

    // Verify reversal_ops were stored
    $opsRow = $pdo->query(
        "SELECT `reversal_ops` FROM `migrations` WHERE `package` = 'test-vendor/test-pkg-g3' LIMIT 1"
    )->fetchColumn();
    if (empty($opsRow)) {
        $errors[] = 'No reversal_ops stored for change() migration';
    }

    // Rollback
    $rollbackResults = $setup->rollbackLast('test-vendor/test-pkg-g3');

    $rollbackSuccess = true;
    foreach ($rollbackResults as $r) {
        if (!$r->isSuccess()) {
            $rollbackSuccess = false;
        }
    }
    if (!$rollbackSuccess) {
        $errors[] = 'Rollback failed';
    }

    check($errors, assertTable($pdo, $verify, '_test_sn_g3', false, 'after rollback'));

    $results[] = empty($errors)
        ? pass($cat, 'change() migration: record + rollback', 'Forward created table, reversal_ops stored, rollback dropped it')
        : fail($cat, 'change() migration: record + rollback', implode('; ', $errors));

    removeTmpDir($tmpDir);
} catch (\Throwable $e) {
    $results[] = fail($cat, 'change() migration: record + rollback', $e->getMessage());
}
cleanTestTables($pdo);
cleanTrackingRows($pdo);

// ===========================================================================
// OUTPUT: Build report, write to file, print to stdout
// ===========================================================================

$totalTests = count($results);
$passed     = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$failed     = $totalTests - $passed;

// Group results by category
$categories = [];
foreach ($results as $r) {
    $categories[$r['category']][] = $r;
}

$out = "# Data Saver Test Results\n\n";
$out .= "**Date:** " . date('Y-m-d H:i:s') . "  \n";
$out .= "**Database:** {$db['dbname']}@{$db['host']}  \n";
$out .= "**Total:** {$totalTests} | **Passed:** {$passed} | **Failed:** {$failed}\n\n";

$num = 0;
foreach ($categories as $catName => $catResults) {
    $out .= "## {$catName}\n\n";
    $out .= "| # | Test | Status | Detail |\n";
    $out .= "|---|------|--------|--------|\n";

    foreach ($catResults as $r) {
        $num++;
        $status = $r['status'] === 'PASS' ? 'PASS' : '**FAIL**';
        $detail = str_replace(['|', "\n"], ['\|', ' '], $r['detail']);
        $detail = preg_replace('/(?<=\s|^)_/', '\\_', $detail);
        $out .= "| {$num} | {$r['name']} | {$status} | {$detail} |\n";
    }

    $out .= "\n";
}

if ($failed > 0) {
    $out .= "## Failures\n\n";
    $num = 0;
    foreach ($results as $r) {
        $num++;
        if ($r['status'] === 'FAIL') {
            $out .= "### {$num}. {$r['name']} ({$r['category']})\n\n";
            $out .= "```\n{$r['detail']}\n```\n\n";
        }
    }
}

$reportPath = __DIR__ . '/DataSaverResults.md';
file_put_contents($reportPath, $out);

echo $out;
echo "\n---\nReport written to: {$reportPath}\n";

exit($failed > 0 ? 1 : 0);
