<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Services;

/**
 * Holds the results of running migrations and seeds for one package.
 *
 * Returned by runMigrate(). Contains the individual result of
 * each migration and each seed that ran, plus an overall pass/fail for the
 * whole operation.
 */
class ModuleResult
{
    private bool    $success = true;
    private ?string $message = null;
    private ?string $version = null;

    /** @var MigrationResult[] */
    private array $migrationResults = [];

    /** @var SeedResult[] */
    private array $seedResults = [];

    public function __construct(private string $moduleName)
    {
    }

    /** Returns the name of the package this result belongs to. */
    public function getModuleName(): string
    {
        return $this->moduleName;
    }

    /** Returns true if the overall operation completed without a migration failure. */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /** Returns a human-readable summary of the outcome, or null if none was set. */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /** Returns the installed version of the package, if set. */
    public function getVersion(): ?string
    {
        return $this->version;
    }

    /** Sets the installed version of the package. */
    public function setVersion(?string $version): void
    {
        $this->version = $version;
    }

    /**
     * Returns the result of each migration that ran.
     *
     * @return MigrationResult[]
     */
    public function getMigrationResults(): array
    {
        return $this->migrationResults;
    }

    /**
     * Returns the result of each seed batch that ran.
     *
     * @return SeedResult[]
     */
    public function getSeedResults(): array
    {
        return $this->seedResults;
    }

    /** @param MigrationResult[] $results */
    public function setMigrationResults(array $results): void
    {
        $this->migrationResults = $results;

        // If any migration failed (non-rollback entry), mark overall failure
        foreach ($results as $r) {
            if (! $r->isSuccess() && ! str_ends_with($r->getName(), ':rollback')) {
                $this->success = false;
                if ($this->message === null) {
                    $this->message = 'Migration failed: ' . $r->getMessage();
                }
                break;
            }
        }
    }

    /** @param SeedResult[] $results */
    public function setSeedResults(array $results): void
    {
        $this->seedResults = $results;
        // Seed failures do not flip the overall success flag - seeds are best-effort
    }

    public function hasMigrationFailure(): bool
    {
        foreach ($this->migrationResults as $r) {
            if (! $r->isSuccess() && ! str_ends_with($r->getName(), ':rollback')) {
                return true;
            }
        }
        return false;
    }

    public function markSuccess(string $message = 'ok'): void
    {
        $this->success = true;
        $this->message = $message;
    }

    public function fail(string $message): void
    {
        $this->success = false;
        $this->message = $message;
    }
}
