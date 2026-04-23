[![Version](http://poser.pugx.org/enlivenapp/migrations/version)](https://packagist.org/packages/enlivenapp/migrations)
[![License](http://poser.pugx.org/enlivenapp/migrations/license)](https://packagist.org/packages/enlivenapp/migrations)
[![Suggesters](http://poser.pugx.org/enlivenapp/migrations/suggesters)](https://packagist.org/packages/enlivenapp/migrations)
[![PHP Version Require](http://poser.pugx.org/enlivenapp/migrations/require/php)](https://packagist.org/packages/enlivenapp/migrations)
[![Monthly Downloads](https://poser.pugx.org/enlivenapp/migrations/d/monthly)](https://packagist.org/packages/enlivenapp/migrations)

# Migrations Library

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
- versions differences are derived from composer/installed,json and the seeds table in the database.

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
