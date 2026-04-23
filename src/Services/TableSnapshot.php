<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Services;

/**
 * Holds information about a backup clone of a table.
 *
 * Created by the safety net before destructive operations (DROP TABLE, DROP COLUMN)
 * so the table can be fully restored — data included — if the migration fails.
 */
class TableSnapshot
{
    public function __construct(
        private string $tableName,
        private string $backupTableName,
        private int $autoIncrement = 0,
    ) {
    }

    /**
     * The original table name that was cloned.
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * The name of the backup clone table (e.g. _bak_users).
     */
    public function getBackupTableName(): string
    {
        return $this->backupTableName;
    }

    /**
     * The AUTO_INCREMENT value the original table had before cloning.
     *
     * CREATE TABLE ... LIKE preserves the auto_increment column definition but
     * not the current counter value. This must be restored separately after
     * renaming the backup back to the original name.
     */
    public function getAutoIncrement(): int
    {
        return $this->autoIncrement;
    }
}
