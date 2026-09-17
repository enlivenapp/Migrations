[![Stable? Not Quite Yet](https://img.shields.io/badge/stable%3F-not%20quite%20yet-blue?style=for-the-badge)](https://packagist.org/packages/enlivenapp/migrations)
[![License](https://img.shields.io/packagist/l/enlivenapp/migrations?style=for-the-badge)](https://packagist.org/packages/enlivenapp/migrations)
[![PHP Version](https://img.shields.io/packagist/php-v/enlivenapp/migrations?style=for-the-badge)](https://packagist.org/packages/enlivenapp/migrations)
[![Monthly Downloads](https://img.shields.io/packagist/dm/enlivenapp/migrations?style=for-the-badge)](https://packagist.org/packages/enlivenapp/migrations)
[![Total Downloads](https://img.shields.io/packagist/dt/enlivenapp/migrations?style=for-the-badge)](https://packagist.org/packages/enlivenapp/migrations)
[![GitHub Issues](https://img.shields.io/github/issues/enlivenapp/Migrations?style=for-the-badge)](https://github.com/enlivenapp/Migrations/issues)
[![Contributors](https://img.shields.io/github/contributors/enlivenapp/Migrations?style=for-the-badge)](https://github.com/enlivenapp/Migrations/graphs/contributors)
[![Latest Release](https://img.shields.io/github/v/release/enlivenapp/Migrations?style=for-the-badge)](https://github.com/enlivenapp/Migrations/releases)
[![Contributions Welcome](https://img.shields.io/badge/contributions-welcome-blue?style=for-the-badge)](https://github.com/enlivenapp/Migrations/pulls)

# Migrations Library

**I noticed folks downloading some of these packages. I'm super grateful, Thank You!  I would like to let folks know until this notice disappears I'm doing a lot of breaking changes without worrying about them.  Once versions are up around 0.5.x things should settle down.**


Migrations is a single-package standalone PHP library for database migrations and seeds with minimal requirements. It tracks changes applied to your database and runs new ones in order. No framework required, just a database connection. MySQL and MariaDB supported currently. Optional CLI support is available through the `flightphp/runway` CLI.  

## Requirements

- PHP >= 8.1 with `ext-pdo` and `ext-pdo_mysql`
- MySQL or MariaDB
- `composer/semver` ^3.0

## Recommended:
- `flightphp/runway`
- `enlivenapp/flight-school`

## Data Saver

The data saver automatically attempts to protect every migration against partial failure. Non-destructive changes are repaired by running the opposite operation. For destructive changes, tables are temporaily copied before the operation. If the migration fails, everything is reverted to the pre-migration state.

#### Quick notes:

- to use command line functionality, you'll need to `require flightphp/runway`, otherwise you'll only have programatic access.
- versions differences are derived from a caller-provided `migrations.versions` map
  (which takes precedence) merged with `composer/installed.json`, compared against the
  seeds table in the database. Non-composer packages (core, local plugins) seed once
  using a `0.0.0` sentinel when no version resolves.

> If you're familiar with Phinx/CakePHP you'll find the fluent chain very familiar. Codeingiter and Sympony users will find these fairly intuitive.  Laravel... o_0  :D

> PRs and contributions are welcome.

#### Supported Frameworks

- FlightPHP

## Install

```bash
composer require enlivenapp/migrations
```

Full functionality:

```bash
composer require flightphp/runway
```

## Default Paths/Settings

By default Migrations look in:
```php
    'vendor/*/*/Database/Migrations',
    'vendor/*/*/src/Database/Migrations',
```
and seeds expects a return array from:

```php
    'vendor/*/*/Database/Seeds',
    'vendor/*/*/src/Database/Seeds',
```

## Configuration

Migrations resolves its own database connection and does not continue looking
after it finds one. You never pass it a PDO. The cascade is:

1. **FlightPHP** (only if loaded): `Flight::get('db')` is checked for a PDO. If
   found, `Flight::get('migrations')` is used as the config override and no file
   is read.
2. **File**: `app/config/migrations.php` — flat database credentials plus an
   optional `migrations` key of overrides. This is the only config file.
3. Nothing found → Migrations throws.

Every key except `paths` and `seeds.paths` merges recursively, so you only need
to set what you want to change. Those two lists are governed by `path_mode`
(below).

`app/config/migrations.php`:

```php
// app/config/migrations.php
return [
    // database connection
    'host'     => 'localhost',
    'dbname'   => 'myapp',
    'user'     => 'root',
    'password' => '',
    // below is optional
    'port'     => 3306,
    'charset'  => 'utf8mb4',
    // the key we look for to override default settings
    'migrations' => [
        // How 'paths' and 'seeds.paths' below combine with the defaults.
        //   'replace' -> replace the entire list, no positional merge
        //   'keys'    -> legacy positional merge (default, deprecated)
        //   'add'     -> append to the defaults (deduped)
        // Applies to both paths and seeds.
        'path_mode' => 'add',
        // set folders to look in (recursive)
        'paths' => [
            'app/Database/Migrations',
        ],
        // if you place a seed file in a different location
        // you can add it to the override. File must be 'Seed.php'
        'seeds' => [
            // set folders to look in (recursive)
            'paths'  => [
                'app/Database/Seeds',
            ],
        ],
    ],
];
```

**`path_mode`** — controls how the override `paths` / `seeds.paths` lists are
combined with the defaults. It applies to both lists using one setting:

| Value | Behavior |
|---|---|
| `replace` | The override list replaces the whole default list. |
| `add` | The override list is appended to the defaults (deduplicated). |
| `keys` | Legacy positional merge (the default). **Deprecated** — a future release will make `replace` the default. Set `path_mode` explicitly. |

If the override does not include `paths` or `seeds.paths`, the defaults are kept.

*Important Notes:*
- If a database connection is not found, Migrations will throw an exception.

### Additional configuration

Two optional keys let you seed and track code that isn't a composer package (for
example, a host app's own migrations under `app/Database/Migrations`). Both live
under the `migrations` key in your config.

```php
return [
    'migrations' => [
        // Existing paths / seeds keys omitted for brevity.

        // Version for each module name, taking precedence over
        // composer/installed.json. Used for seeding deltas.
        'versions' => [
            'pubvana/pubvana' => '3.0.0',
            'app/blog'        => '1.0.0',
        ],

        // Give a migration path pattern a real package identity.
        'module_names' => [
            'app/Database/Migrations' => 'pubvana/pubvana',
        ],
    ],
];
```

**`versions`** — a map of `moduleName => version`. When a package has no entry in
`composer/installed.json` (core and non-composer code aren't composer packages),
this is the version used for seeding. If neither this map nor `installed.json`
resolves a version, the package's `install` seed block runs once using a `0.0.0`
sentinel.

**`module_names`** — maps a migration path **pattern** to a module name. By default
a directory like `app/Database/Migrations` is derived as the basename `Migrations`.
Use this to give it a real identity (e.g. `pubvana/pubvana`) so its seeds/migrations
are tracked under that name.


## Setting Up Migrations

Migrations resolves the database connection itself, so you never pass it a PDO.

```php
// Uses the resolved database connection and the default config
$migrate = new \Enlivenapp\Migrations\Services\MigrationSetup();

// Add extra paths/seeds at runtime. This array is additive: its 'paths' and
// 'seeds.paths' are appended to the resolved config (deduped), regardless of
// 'path_mode'. Other keys merge recursively.
$config = [
    'migrations' => [
        'paths' => [
            'app/Database/Migrations',
        ],
        'seeds' => [
            'paths' => [
                'app/Database/Seeds',
            ],
        ],
    ],
];

$migrate = new \Enlivenapp\Migrations\Services\MigrationSetup($config);

// php8+ named argument form
$migrate = new \Enlivenapp\Migrations\Services\MigrationSetup(config: $config);
```

## Quick Migration file example

```php
// vendor/acme/blog/src/Database/Migrations/2026-01-15-143022_CreatePostsTable.php

<?php
declare(strict_types=1);

namespace Acme\Blog\Database\Migrations;

use Enlivenapp\Migrations\Services\Migration;

class CreatePostsTable extends Migration
{
    public function up(): void
    {
        $this->table('posts')
            ->addColumn('id', 'primary')
            ->addColumn('title', 'string', ['length' => 200])
            ->addColumn('body', 'text', ['nullable' => true])
            ->addColumn('published', 'boolean', ['default' => false])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['title'])
            ->create();
    }

    public function down(): void
    {
        $this->table('posts')->drop();
    }
}
```
**Run with runway**
Run it:
```bash
php runway migrate:single acme/blog
```

Or run everything:
```bash
php runway migrate:all
```

> See below for programatic usage


## Reversible migrations

Instead of writing `up()` and `down()` separately, you can write `change()`. The library records what `change()` did and reverses it automatically on rollback.

```php
class CreatePostsTable extends Migration
{
    public function change(): void
    {
        $this->table('posts')
            ->addColumn('id', 'primary')
            ->addColumn('title', 'string', ['length' => 200])
            ->create();
    }
}
```

Only forward operations are auto-reversible: create table, add columns, rename table, rename column. If your migration drops or modifies things, use `up()` and `down()`.

## CLI commands

| Command | Description |
|---|---|
| `migrate:all [--dry-run]` | Run all pending migrations |
| `migrate:single <package> [--dry-run]` | Run migrations for one package |
| `migrate:make <Name> <package> [--path]` | Create a new migration file |
| `migrate:rollback [--module=NAME] [--dry-run]` | Roll back the last batch |
| `migrate:status` | Show what's been run and what's pending |
| `migrate:breakpoint <version> <package> [--clear]` | Set or clear a rollback breakpoint |
| `migrate:unlock [--dry-run]` | Force-release the lock after a crash |
| `migrate:purge <package> [--dry-run]` | Drop a package's tables (destructive, asks for confirmation) |

`--dry-run` shows what would happen without applying any changes. Operations (create, drop, alter) are captured without executing; seed inserts run inside a transaction that is rolled back.

## Seeds

Seeds are optional. If you have need of seeding the database use the process below:

```php
/*  File Locations default:  vendor/{vendor}/{package}/Database/Seeds/Seed.php
                               vendor/{vendor}/{package}/src/Database/Seeds/Seed.php

// seeds on update of version 1.1.0.  Multiple versions since install: Seeds from last version (installed or updated) 
// seeded through to the current version are ran. in the instance below. if installed at 0.8.5,  there were versions 
// 0.9.0 and 0.9.5 and 1.1.0 that had not been seeded, all of these seeds would be included in the database seed run.  
*/
return [
    'install' => [
        ['table' => 'posts',
            'rows'  => [
                ['title' => 'Welcome', 'body' => 'post-body', 'published' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')],
                ['title' => 'Welcome 2', 'body' => 'post-body-2', 'published' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')],
            ],
        ],
    ],
    'versions' => [
        '0.9.0' => [...],
        '0.9.5' => [...],
        '1.1.0' => [
            ['table' => 'posts',
                'rows'  => [
                    ['title' => 'Welcome', 'body' => 'post-body', 'published' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')],
                    ['title' => 'Welcome 2', 'body' => 'post-body-2', 'published' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')],
                ],
            ],
        ],
    ],
];
```

**Non-composer packages (core, local plugins):** packages without a resolvable
version in `composer/installed.json` (and no `migrations.versions` entry) run their
`install` block once using a `0.0.0` sentinel, so the default data is inserted and the
row is tracked. Seeds use `INSERT IGNORE`, so rows that already exist are silently
skipped rather than erroring — seeds are idempotent and safe against existing data.

## Running Migrations Programatically

```php
// Any file in your application that wants to run Mirgraions

// Install/Update all packages
$migrate->runMigrate();

// Install/Update a single package
$migrate->runMigrate('vendor/package');

```

If your brain hurts after reading this like mine did writing it, please consider [Flight School Plugin Manager](https://github.com/enlivenapp/FlightPHP-Flight-School) it manages all of this automatically for FlightPHP plugins.

## Documentation

- [Writing Migrations](docs/authoring-migrations.md)
- [Writing Seeds](docs/authoring-seeds.md)
- [API Reference](docs/api-reference.md)
- [Troubleshooting](docs/troubleshooting.md)

## License

MIT. See [LICENSE](LICENSE).
