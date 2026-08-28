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
    'vendor/*/*/src/Database/Migrations',
    'plugins/*/Database/Migrations',
```
and seeds expects a return array from:

```php
    'vendor/*/*/src/Database/Seeds',
    'plugins/*/Database/Seeds',
```

## Configuration

Migrations uses the first database connection and associated migration array found first. It does not continue looking after it finds a database connection.

The order in which Migrations searches:

**FlightPHP** installed (checked first):
`Flight::set('db')`, `Flight::set('migrations')`(see example below)

Migrations checks: `Flight::get('db')` is checked for a PDO database connection, if found `Flight::get('migrations')` is checked and stops look for Migrations config options. 


**File Location Configuration**

Create `migrations.php` file in `config/` or `app/config/` that returns an array with only the keys you want to change. Defaults are in `src/Config/Config.php`.

- `app/config/migrations.php` (checked 2nd)(skeleton/general app layout)
- `config/migrations.php` (checked 3rd)(project root)

```php
// database.php
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
        // set folders to look in (recursive)
        'paths' => [
            'vendor/*/*/src/Database/Migrations',
            'plugins/*/Database/Migrations',
        ],
        // if you place a seed file in a different location
        // you can add it to the override. File must be 'Seed.php'
        'seeds' => [
            // set folders to look in (recursive)
            'paths'  => [
                'vendor/*/*/src/Database/Seeds',
                'plugins/*/Database/Seeds',
            //  'your/path/to/Seeds'
            ],
        ],
    ],
];
```

*Important Notes:* 
- If a database connection is not found, Migrations will throw an exception.

### Additional configuration

Two optional keys let you seed and track code that isn't a composer package (for
example, a host app's own migrations under `app/Database/Migrations`, or local
plugins in `plugins/`). Both live under the `migrations` key in your config.

```php
return [
    'migrations' => [
        // Existing paths / seeds keys omitted for brevity.

        // Version for each module name, taking precedence over
        // composer/installed.json. Used for seeding deltas.
        'versions' => [
            'pubvana/pubvana' => '3.0.0',
            'plugins/Blog'    => '1.0.0',
        ],

        // Give a migration path pattern a real package identity.
        'module_names' => [
            'app/Database/Migrations' => 'pubvana/pubvana',
        ],
    ],
];
```

**`versions`** — a map of `moduleName => version`. When a package has no entry in
`composer/installed.json` (core and local plugins aren't composer packages), this is
the version used for seeding. If neither this map nor `installed.json` resolves a
version, the package's `install` seed block runs once using a `0.0.0` sentinel.

**`module_names`** — maps a migration path **pattern** to a module name. By default
a directory like `app/Database/Migrations` is derived as the basename `Migrations`.
Use this to give it a real identity (e.g. `pubvana/pubvana`) so its seeds/migrations
are tracked under that name.


### Manual Use

You can set the database connection and config settings on migrations at runtime.

## Setting Up Migrations

```php
// Using default configuration
$migrate = new \Enlivenapp\Migrations\Services\MigrationSetup();

// With configuration overrides (only include the keys you want to change)
$config = [
    'migrations' => [
        // set folders to look in (recursive)
        'paths' => [
            'vendor/*/*/src/Database/Migrations',
            'plugins/*/Database/Migrations',
        ],
        // seed overrides
        'seeds' => [
            'paths'  => [
                'vendor/*/*/src/Database/Seeds',
                'plugins/*/Database/Seeds',
            ],
        ],
    ],
];

// attempts to use database and default config
$migrate = new \Enlivenapp\Migrations\Services\MigrationSetup();

// pre php 8+ (null because the db connection is handled elsewhere)
$migrate = new \Enlivenapp\Migrations\Services\MigrationSetup(null, $config);

// php8+ introduced named arguments (allows skipping null in the first arg)
$migrate = new \Enlivenapp\Migrations\Services\MigrationSetup(config: $config);

// Or pass your own connection and/or config directly
$pdo    = new PDO('mysql:host=localhost;dbname=myapp;charset=utf8mb4', 'user', 'pass');
$migrate = new \Enlivenapp\Migrations\Services\MigrationSetup($pdo);
$migrate = new \Enlivenapp\Migrations\Services\MigrationSetup($pdo, $config);
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
/*  File Locations default:  src/Database/Seeds/Seed.php, plugins/{pluginName}/Database/Seeds/Seed.php

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
