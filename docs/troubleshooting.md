# Troubleshooting

## "Migration lock is held by another process"

**Cause:** A previous migration run crashed without releasing the lock.

**Fix:**
```bash
php runway migrate:unlock
```

Or from code:
```php
$migrate->forceUnlock();
```

## "No migration directory found for module"

**Cause:** The module's migration directory doesn't match any of the configured `$config['migrations']['paths']` wildcard patterns.

**Fix:** Check that your migrations are in one of the configured paths (default: `vendor/*/*/src/Database/Migrations` or `plugins/*/Database/Migrations`). Or override the paths in `app/config/migrations.php` or `config/migrations.php`.

## Migration ran but nothing changed in the database

**Cause:** You probably called `addColumn()` without calling `create()`, `update()`, or `addColumns()` at the end. The builder methods are fluent and collect definitions but do not execute until you call a terminal method.

**Terminal methods:** `create()`, `update()`, `addColumns()`, `drop()`, `dropColumns()`, `rename()`, `modifyColumn()`, `renameColumn()`.

## Rollback isn't going far enough

**Cause:** A breakpoint is set on one of the migrations. Rollback stops before any migration with `breakpoint = 1`.

**Fix:** Check with `migrate:status` (breakpoints show as `[B]`). Clear it:
```bash
php runway migrate:breakpoint 2026-01-15-143022 acme/blog --clear
```

## Rollback rolled back more than expected

**Cause:** Rollback operates on the entire most recent **batch**. All migrations that ran together in one batch are rolled back together.

**Fix:** Use `--module` to scope rollback to a single package:
```bash
php runway migrate:rollback --module acme/blog
```

## Seeds didn't insert any rows

**Cause (1):** No `Seed.php` exists in the package's `Database/Seeds/` directory.
**Cause (2):** The installed version (from `composer/installed.json`) matches the last seeded version (from the `seeds` table), so there are no new seeds to run.
**Cause (3):** The seed file doesn't return an array with `install` and/or `versions` keys.

**Fix:** Check that `Seed.php` is in `src/Database/Seeds/` (or `plugins/{name}/Database/Seeds/`). Check the `seeds` table to see the last recorded version for your package.

## Commands not showing in `php runway --help`

**Cause:** Runway discovers commands from packages. Make sure `flightphp/runway` is installed (`composer require flightphp/runway`).

## Purge didn't remove seed tracking

`migrate:purge` clears both the migration tracking rows and the seed tracking entry, so the package can be cleanly re-installed. If you rolled back instead of purging, the seed entry is preserved. Use `migrate:purge` for a full clean-slate reset.

## "Unknown column type" error

**Cause:** You used a type name that the SchemaBuilder doesn't support.

**Supported types:** `primary`, `integer`, `biginteger`, `string`, `char`, `text`, `mediumtext`, `longtext`, `boolean`, `datetime`, `timestamp`, `time`, `date`, `float`, `decimal`, `enum`, `set`, `blob`, `binary`, `json`.

## Reversible migration didn't reverse properly

**Cause:** You used an operation that can't be auto-reversed (drop, dropColumns, modifyColumn, statement).

**Fix:** Use `up()` and `down()` instead of `change()` for those operations.

## `_bak_` tables left in the database

**Cause:** The data saver copies tables into `_bak_{tablename}` before destructive operations (`drop()`, `dropColumns()`). These copies are normally cleaned up automatically. If the process is killed mid-migration (power loss, `kill -9`, out of memory), cleanup never runs.

**Fix:** It is safe to drop any `_bak_` table manually:
```sql
DROP TABLE IF EXISTS `_bak_users`;
```

## Cannot drop a table referenced by a foreign key

**Cause:** MySQL blocks `DROP TABLE` when another table has a foreign key pointing to it. The data saver copies the table before attempting the drop, but MySQL rejects the drop itself.

**Fix:** Drop the foreign key constraint first (or drop the child table first), then drop the parent table:
```php
public function up(): void
{
    $this->table('child_table')->dropForeignKey(['parent_id']);
    $this->table('parent_table')->drop();
}
```

## Raw SQL (`statement()`) wasn't reversed after failure

**Cause:** The data saver does not track `statement()` calls. If a migration uses `statement()` and then fails on a later operation, that raw SQL change will not be undone.

**Fix:** By design. If you use `statement()`, you accept the risk. For important raw SQL, use `up()`/`down()` and write the undo logic yourself in `down()`.
