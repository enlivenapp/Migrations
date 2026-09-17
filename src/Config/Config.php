<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

/**
 * Default configuration for migrations.
 *
 * This is the baseline. Overrides and database credentials come from only one
 * place: `app/config/migrations.php` (or `Flight::get('migrations')` when Flight
 * is loaded and has a PDO registered). There is no other override source.
 *
 *   1. Flight::get('db') + Flight::get('migrations')  — if Flight is loaded
 *   2. app/config/migrations.php                      — DB creds + override
 *
 * Every key except `paths` and `seeds.paths` merges recursively, so you only
 * need to set what you want to change. Those two lists are governed by
 * `path_mode` below.
 *
 * `path_mode` controls how override `paths` / `seeds.paths` combine with the
 * defaults below:
 *
 *   - replace : the override array replaces the entire paths/seeds array.
 *   - add     : the override paths are appended to the defaults (deduped).
 *   - keys    : legacy positional merge (array_replace_recursive), retained as
 *               the default for backward compatibility.
 *
 * @deprecated 'keys' preserves legacy positional merge behavior. An upcoming
 *             release will make 'replace' the default. Set `path_mode`
 *             explicitly to opt into 'replace'/'add' or to silence this notice.
 *
 * @see \Enlivenapp\Migrations\Services\ConfigLoader
 */

return [

    'migrations' => [
        // How override paths/seeds combine with the defaults above.
        'path_mode' => 'keys',

        // Paths where migration files live, relative to your project root.
        // Use * as a wildcard to match any folder name.
        'paths' => [
            'vendor/*/*/Database/Migrations',
            'vendor/*/*/src/Database/Migrations',
        ],

        'seeds' => [
            // Paths where seed file live
            // Use * as a wildcard to match any folder name.
            'paths'  => [
                'vendor/*/*/Database/Seeds',
                'vendor/*/*/src/Database/Seeds',
            ],
        ],
    ],
];
