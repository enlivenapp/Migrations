<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Helpers;

use Enlivenapp\Migrations\Services\MigrationSetup;

/**
 * Builds a MigrationSetup for Runway CLI commands.
 */
class CommandHelper
{
    /**
     * Creates a ready-to-use MigrationSetup.
     */
    public static function build(): MigrationSetup
    {
        return new MigrationSetup();
    }
}
