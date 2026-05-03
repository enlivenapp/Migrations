<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Services;


/**
 * Prevents two processes from running migrations at the same time.
 *
 * Uses a single row in a `migrations_lock` table. When a process wants to
 * run migrations it calls acquire() to claim the lock. If another process
 * already holds it, acquire() returns false and the caller should stop.
 * When the migration run finishes, call release() to free the lock.
 *
 * If a process crashes while holding the lock the table row stays marked as
 * locked. You can clear it by calling forceUnlock() or running
 * `php runway migrate:unlock` from the command line.
 *
 * The lock stores the process ID and timestamp so you can see who grabbed it
 * and when - useful for diagnosing stale locks.
 */
class DatabaseMigrationLock
{
    private const LOCK_TABLE = 'migrations_lock';

    public function __construct(private DbConnection $db)
    {
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Tries to grab the lock. Returns true if you got it, false if another process already holds it.
     */
    public function acquire(): bool
    {
        $lockedBy = (string) getmypid();
        $lockedAt = date('Y-m-d H:i:s');

        $affected = $this->db->update(
            'UPDATE `' . self::LOCK_TABLE . '` SET `is_locked` = 1, `locked_by` = ?, `locked_at` = ? WHERE `id` = 1 AND `is_locked` = 0',
            [$lockedBy, $lockedAt]
        );

        return $affected > 0;
    }

    /**
     * Lets go of the lock so other processes can run migrations.
     */
    public function release(): void
    {
        $this->db->execute(
            'UPDATE `' . self::LOCK_TABLE . '` SET `is_locked` = 0, `locked_by` = NULL, `locked_at` = NULL WHERE `id` = 1'
        );
    }

    /**
     * Returns true if any process currently holds the lock.
     */
    public function isLocked(): bool
    {
        $rows = $this->db->query(
            'SELECT `is_locked`, `locked_by`, `locked_at` FROM `' . self::LOCK_TABLE . '` WHERE `id` = 1'
        );

        if (empty($rows)) {
            return false;
        }

        return (bool) ($rows[0]['is_locked'] ?? false);
    }

    /**
     * Returns who holds the lock (process ID) and when they grabbed it, or null if the lock is free.
     *
     * @return array{locked_by: string|null, locked_at: string|null}|null
     */
    public function getLockInfo(): ?array
    {
        $rows = $this->db->query(
            'SELECT `is_locked`, `locked_by`, `locked_at` FROM `' . self::LOCK_TABLE . '` WHERE `id` = 1'
        );

        if (empty($rows) || ! (bool) ($rows[0]['is_locked'] ?? false)) {
            return null;
        }

        return [
            'locked_by' => $rows[0]['locked_by'] ?? null,
            'locked_at' => $rows[0]['locked_at'] ?? null,
        ];
    }

    /**
     * Creates the lock table if it doesn't exist yet. Safe to call every time - won't touch anything if the table is already there.
     */
    public function ensureStore(): void
    {
        $this->db->execute(
            "CREATE TABLE IF NOT EXISTS `" . self::LOCK_TABLE . "` (
                `id`        INT NOT NULL DEFAULT 1,
                `is_locked` TINYINT(1) NOT NULL DEFAULT 0,
                `locked_by` VARCHAR(64) NULL,
                `locked_at` DATETIME NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Insert sentinel row if absent
        $this->db->execute(
            'INSERT IGNORE INTO `' . self::LOCK_TABLE . '` (`id`, `is_locked`, `locked_by`, `locked_at`) VALUES (1, 0, NULL, NULL)'
        );
    }
}
