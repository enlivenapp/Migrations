<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Services;

use Enlivenapp\Migrations\Exceptions\MigrationException;

/**
 * Thin wrapper around PDO that throws MigrationException instead of PDOException.
 *
 * Used internally by the migration runner and lock - you don't need to interact
 * with this directly. MySQL-only; no dialect abstractions.
 */
class DbConnection
{
    public function __construct(private \PDO $pdo)
    {
        // Ensure exceptions mode so all errors surface as PDOException
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    /**
     * Returns the raw PDO connection.
     */
    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Runs a SQL statement (INSERT, UPDATE, DELETE, CREATE, etc.) with optional parameter bindings.
     * Use this when you don't need to read back any rows.
     *
     * @param string  $sql
     * @param mixed[] $bindings  Positional bindings
     * @throws MigrationException
     */
    public function execute(string $sql, array $bindings = []): void
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
        } catch (\PDOException $e) {
            throw new MigrationException('DB execute error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Runs a SELECT and returns all matching rows as associative arrays.
     *
     * @param  string  $sql
     * @param  mixed[] $bindings
     * @return array<int, array<string, mixed>>
     * @throws MigrationException
     */
    public function query(string $sql, array $bindings = []): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new MigrationException('DB query error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Runs an UPDATE or DELETE and returns how many rows were affected.
     *
     * @param  string  $sql
     * @param  mixed[] $bindings
     * @return int
     * @throws MigrationException
     */
    public function update(string $sql, array $bindings = []): int
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            throw new MigrationException('DB update error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Starts a database transaction.
     */
    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    /**
     * Commits the current transaction, saving all changes.
     */
    public function commit(): void
    {
        $this->pdo->commit();
    }

    /**
     * Rolls back the current transaction, discarding all changes since beginTransaction().
     */
    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
