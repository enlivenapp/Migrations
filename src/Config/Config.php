<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

/**
 * Default configuration for migrations.
 *
 * Overrides are resolved by ConfigLoader in this order (first match wins):
 *   1. Flight::get('migrations')  — if Flight is loaded and has a PDO registered
 *   2. app/config/migrations.php  — FlightPHP skeleton layout
 *   3. config/migrations.php      — project root
 *
 * Only the keys you set are overridden; everything else keeps these defaults.
 *
 * @see \Enlivenapp\Migrations\Services\ConfigLoader
 */

return [

    'migrations' => [
        // Paths where migration files live, relative to your project root.
        // Use * as a wildcard to match any folder name.
        'paths' => [
            'vendor/*/*/src/Database/Migrations',
            'plugins/*/Database/Migrations',
        ],

        'seeds' => [
            // Paths where seed file live
            // Use * as a wildcard to match any folder name.
            'paths'  => [
                'vendor/*/*/src/Database/Seeds',
                'plugins/*/Database/Seeds',
            ],
        ],
    ],
];
