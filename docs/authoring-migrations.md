# Writing Migrations

## File naming

Migration files go in your package's `src/Database/Migrations/` directory.

Filename format: `YYYY-MM-DD-HHmmss_ClassName.php`

The timestamp sets the run order. The PascalCase part must match the class name inside. Never rename a migration file after it has been applied because the class name links it to the tracking table.

Use `php runway migrate:make ClassName vendor/package` to generate one automatically.

## Class structure

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Database\Migrations;

use Enlivenapp\Migrations\Services\Migration;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        // build schema
    }

    public function down(): void
    {
        // reverse it
    }
}
```

Or use `change()` for reversible migrations. See "Reversible migrations" below.

## Creating a table

```php
$this->table('users')
    ->addColumn('id', 'primary')
    ->addColumn('email', 'string', ['length' => 255])
    ->addColumn('name', 'string')
    ->addColumn('active', 'boolean', ['default' => true])
    ->addIndex(['email'], ['unique' => true])
    ->create();
```

## Column types

| Type | MySQL type | Notes |
|---|---|---|
| `primary` | `INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | One per table, always first |
| `integer` | `INT` | Supports `unsigned`, `auto_increment` |
| `biginteger` | `BIGINT` | Supports `unsigned`, `auto_increment` |
| `string` | `VARCHAR(length)` | Default length: 255 |
| `char` | `CHAR(length)` | Fixed-length, default: 255 |
| `text` | `TEXT` | No default value allowed (MySQL < 8.0.13) |
| `mediumtext` | `MEDIUMTEXT` | No default value |
| `longtext` | `LONGTEXT` | No default value |
| `boolean` | `TINYINT(1)` | Use `true`/`false` for defaults |
| `datetime` | `DATETIME` | |
| `timestamp` | `TIMESTAMP` | |
| `time` | `TIME` | |
| `date` | `DATE` | |
| `float` | `FLOAT` | |
| `decimal` | `DECIMAL(precision, scale)` | Default: 10,2 |
| `enum` | `ENUM('a','b',...)` | Requires `values` option |
| `set` | `SET('a','b',...)` | Requires `values` option |
| `blob` | `BLOB` | No default value |
| `binary` | `VARBINARY(length)` | Default length: 255 |
| `json` | `JSON` | No default value |

## Column options

| Option | Type | Description |
|---|---|---|
| `nullable` | bool | `true` = NULL allowed, `false` (default) = NOT NULL |
| `default` | mixed | Default value. Use `'CURRENT_TIMESTAMP'` for timestamps |
| `length` | int | For string, char, binary types |
| `unsigned` | bool | For integer, biginteger |
| `precision` | int | For decimal (default: 10) |
| `scale` | int | For decimal (default: 2) |
| `values` | array | Required for enum and set: `['draft', 'published', 'archived']` |
| `comment` | string | Column comment |
| `after` | string | Position after this column (ALTER TABLE only) |
| `first` | bool | Position as first column (ALTER TABLE only) |
| `auto_increment` | bool | For integer/biginteger (non-primary key) |

## Indexes

```php
// Regular index
->addIndex(['email'])

// Unique index
->addIndex(['email'], ['unique' => true])

// Named index
->addIndex(['first_name', 'last_name'], ['name' => 'idx_full_name'])
```

## Foreign keys

```php
->addForeignKey(['user_id'], 'users', ['id'], ['delete' => 'CASCADE'])
->addForeignKey(['category_id'], 'categories', ['id'], ['delete' => 'SET NULL', 'update' => 'CASCADE'])
```

Options for `delete` and `update`: `CASCADE`, `SET NULL`, `RESTRICT`, `NO ACTION`. Default: `RESTRICT`.

## Altering existing tables

### Add columns, indexes, and foreign keys

Use `update()` to apply columns, indexes, and foreign keys to an existing table, the same way `create()` works for new tables:

```php
$this->table('users')
    ->addColumn('phone', 'string', ['length' => 20, 'nullable' => true, 'after' => 'email'])
    ->addColumn('role', 'string', ['length' => 30, 'default' => 'user'])
    ->addIndex(['role'])
    ->addForeignKey(['org_id'], 'orgs', ['id'], ['delete' => 'CASCADE'])
    ->update();
```

If you only need to add columns (no indexes or FKs), `addColumns()` still works:

```php
$this->table('users')
    ->addColumn('phone', 'string', ['length' => 20, 'nullable' => true, 'after' => 'email'])
    ->addColumns();
```

### Drop columns

```php
$this->table('users')->dropColumns(['phone', 'fax']);
```

### Modify a column

Changes the type or options of an existing column:

```php
$this->table('users')->modifyColumn('email', 'string', ['length' => 500, 'nullable' => true]);
```

### Rename a column

Requires MySQL 8.0+:

```php
$this->table('users')->renameColumn('email', 'email_address');
```

### Rename a table

```php
$this->table('old_name')->rename('new_name');
```

### Drop an index

```php
// By column names (uses the auto-generated name)
$this->table('users')->dropIndex(['email']);

// By explicit name
$this->table('users')->dropIndex('idx_users_email');
```

### Drop a foreign key

```php
// By column names
$this->table('posts')->dropForeignKey(['user_id']);

// By explicit name
$this->table('posts')->dropForeignKey('fk_posts_user_id');
```

## Checking if things exist

```php
if (!$this->table('users')->hasTable('users')) {
    // create it
}

if (!$this->table('users')->hasColumn('phone')) {
    $this->table('users')
        ->addColumn('phone', 'string', ['nullable' => true])
        ->addColumns();
}
```

## Raw SQL

When the builder doesn't cover your case:

```php
$this->table('users')->statement('ALTER TABLE `users` ADD FULLTEXT INDEX `ft_bio` (`bio`)');
```

## Reversible migrations

Write a `change()` method instead of `up()` and `down()`:

```php
class AddPhoneToUsers extends Migration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('phone', 'string', ['length' => 20, 'nullable' => true])
            ->addColumns();
    }
}
```

On rollback, the library automatically drops the `phone` column.

**What can be reversed automatically:**

| Operation | Reversal |
|---|---|
| `create()` | `drop()` |
| `addColumns()` | `dropColumns()` |
| `rename()` | `rename()` (swapped) |
| `renameColumn()` | `renameColumn()` (swapped) |

Everything else (`drop`, `dropColumns`, `modifyColumn`, `statement`) cannot be reversed automatically. Use `up()` and `down()` for those.

## How it runs

- Migrations run in timestamp order (oldest first).
- All migrations in a single run are grouped into a **batch**. If any migration fails, every migration in that batch is rolled back automatically.
- A lock prevents two processes from running migrations at the same time.
- `--dry-run` shows what would happen without applying changes. SQL is captured without executing.

## Data saver

If a migration fails halfway through, the data saver puts every table it touched back the way it was, including data. This is automatic. You don't need to enable it.

**How it works:**

- **Non-destructive operations** (add column, add index, modify column, rename, etc.). The table's current state is read from the database before the change. On failure, it runs the opposite operation to undo it.
- **Destructive operations** (`drop()`, `dropColumns()`). The table is copied into a `_bak_` table before the operation runs. On failure, the copy replaces the original. On success, the copy is dropped.
- **`AUTO_INCREMENT`** values are preserved when restoring from a copy.
- **Multiple operations** in one migration are undone last-to-first.
- If the same table has multiple destructive operations, it is only copied once (before the first one), so the backup always has the original state.

**What is NOT protected:**

- `statement()` (raw SQL) is not tracked. If you use `statement()` and the migration fails after it, that raw SQL will not be undone.
- Dropping a table that another table has a foreign key pointing to will fail before the data saver can act. Drop the foreign key first, or drop the child table first.

**After failure:**

1. The data saver undoes the failed migration's partial changes.
2. Batch rollback runs `down()` on every migration that already succeeded in the batch, newest first.
3. The database ends up back where it was before the batch started.

## Breakpoints

Set a breakpoint on a migration to prevent rollback from going past it:

```bash
php runway migrate:breakpoint 2026-01-15-143022 acme/blog
```

Clear it:

```bash
php runway migrate:breakpoint 2026-01-15-143022 acme/blog --clear
```

Or from code:

```php
$migrate->setBreakpoint('2026-01-15-143022', 'acme/blog', true);   // set
$migrate->setBreakpoint('2026-01-15-143022', 'acme/blog', false);  // clear
```

Rollback stops before any migration with a breakpoint. Use `migrate:status` to see which migrations have breakpoints (marked with `[B]`).
