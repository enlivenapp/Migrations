# Writing Seeds

Seeds insert default data (roles, permissions, settings) when a package is installed or updated. They are plain PHP arrays. No SQL, no classes, no logic.

## Seed array

```php
return [
    'install' => [
        [
            'table' => 'roles',
            'rows'  => [
                ['alias' => 'admin', 'title' => 'Admin'],
                ['alias' => 'user',  'title' => 'User'],
            ],
        ],
    ],
    'versions' => [
        '0.2.0' => [
            [
                'table' => 'roles',
                'rows'  => [['alias' => 'editor', 'title' => 'Editor']],
            ],
        ],
    ],
];
```

## When rows run

Two scenarios:

**Fresh install**: the `install` block runs, plus every `versions` entry where the version is older than the new version, in order.

**Update**: only `versions` entries from the last version seeded to the current version. The `install` block is skipped.

## How seeds are discovered

The seed file lives at `src/Database/Seeds/Seed.php` in your package (or `plugins/{name}/Database/Seeds/Seed.php` for local plugins). The migration runner discovers it automatically by looking for a sibling `Seeds` directory next to your `Migrations` directory.

Version tracking is handled internally: the `seeds` table records the last seeded version per package, and `composer/installed.json` provides the current installed version. No version arguments need to be passed. The runner figures out what needs to run on its own.

## Seed file

Create `Seed.php` in your package's `src/Database/Seeds/` directory. The file must return an array:

```php
// src/Database/Seeds/Seed.php
<?php
return [
    'install' => [
        [
            'table' => 'roles',
            'rows'  => [
                ['alias' => 'admin', 'title' => 'Admin'],
                ['alias' => 'user',  'title' => 'User'],
            ],
        ],
    ],
];
```

## Error handling

Seeds are best-effort. A failed insert is logged and skipped, and the rest of the rows still run. There is no rollback for seeds. If you need transactional inserts, put them in a migration instead.

## When to use seeds vs. migrations

- **Seeds**: default data that ships with a package (roles, permissions, settings, categories).
- **Migrations**: everything else, including schema changes, data transformations, and complex inserts with conditions.
