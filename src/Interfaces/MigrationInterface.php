<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Interfaces;

/**
 * The contract every migration class must follow.
 *
 * In practice you should extend the Migration base class rather than
 * implementing this interface directly - the base class gives you
 * $this->table() and handles all the setup for you.
 *
 * Migration files must follow the naming convention:
 *   YYYY-MM-DD-HHmmss_ClassName.php
 *
 * Example:
 *   2024-01-15-143022_CreateUsersTable.php
 */
interface MigrationInterface
{
    /**
     * Runs the migration forward - create tables, add columns, transform data, etc.
     */
    public function up(): void;

    /**
     * Undoes what up() did. Called during rollback.
     */
    public function down(): void;
}
