<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Exceptions;

/**
 * Thrown when the migration lock is held by another process and cannot be acquired.
 */
class LockException extends MigrationException
{
}
