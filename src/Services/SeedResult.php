<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Services;

/**
 * Holds the result of inserting one batch of seed rows into one table.
 *
 * Each seed block targets a single table within an install or version context -
 * this records whether that insert succeeded and any error message if it didn't.
 */
class SeedResult
{
    private bool    $success = false;
    private ?string $message = null;

    /**
     * @param string $moduleName  Composer package name
     * @param string $context     'install' or a version string (e.g. '1.2.0')
     * @param string $table       Target table name
     */
    public function __construct(
        private string $moduleName,
        private string $context,
        private string $table,
    ) {
    }

    /** Returns the package name these seeds belong to. */
    public function getModuleName(): string
    {
        return $this->moduleName;
    }

    /** Returns whether these seeds ran during install or a specific version upgrade. */
    public function getContext(): string
    {
        return $this->context;
    }

    /** Returns the name of the table that was seeded. */
    public function getTable(): string
    {
        return $this->table;
    }

    /** Returns true if the seed rows were inserted without errors. */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /** Returns the success or error message, or null if none was recorded. */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /** Marks this seed batch as having completed successfully. */
    public function markSuccess(string $message = 'ok'): void
    {
        $this->success = true;
        $this->message = $message;
    }

    /** Marks this seed batch as having failed, with a reason. */
    public function markFailed(string $message): void
    {
        $this->success = false;
        $this->message = $message;
    }
}
