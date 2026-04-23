<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Exceptions;

/**
 * Thrown when something goes wrong running a migration or building schema.
 */
class MigrationException extends \RuntimeException
{
}
