<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Services;

/**
 * Holds the result of running (or rolling back) a single migration file.
 *
 * Tells you whether it succeeded, which module it belonged to, and any
 * error message if something went wrong.
 */
class MigrationResult
{
    private bool    $success = false;
    private ?string $message = null;

    public function __construct(
        private string $name,
        private string $module,
    ) {
    }

    /** Marks this migration as having completed successfully. */
    public function markSuccess(string $message = 'ok'): void
    {
        $this->success = true;
        $this->message = $message;
    }

    /** Marks this migration as having failed, with a reason. */
    public function markFailed(string $message): void
    {
        $this->success = false;
        $this->message = $message;
    }

    /** Returns the migration class name (or class name + ':rollback' for a rollback). */
    public function getName(): string
    {
        return $this->name;
    }

    /** Returns the package name this migration belongs to. */
    public function getModule(): string
    {
        return $this->module;
    }

    /** Returns true if the migration ran without errors. */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /** Returns the success or error message, or null if none was recorded. */
    public function getMessage(): ?string
    {
        return $this->message;
    }
}
