<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Commands;

use Enlivenapp\Migrations\Helpers\CommandHelper;
use flight\commands\AbstractBaseCommand;

/**
 * Shows which migrations have been applied and which are still pending.
 *
 * Lists all discovered migrations grouped by status. Applied migrations
 * include their batch number and timestamp. Migrations that have a breakpoint
 * set are marked with [B]. Use this to audit the current state of the database
 * before or after running migrations.
 *
 * Read-only - does not acquire the lock or modify anything.
 *
 * Usage:
 *   php runway migrate:status
 */
class MigrateStatusCommand extends AbstractBaseCommand
{
    public function __construct(array $config)
    {
        parent::__construct('migrate:status', 'Show executed and pending migrations', $config);

        $this->usage(
            '<bold>  migrate:status</end> <comment>Lists all migrations with their run status</end>'
        );
    }

    public function execute(): void
    {
        $io = $this->app()->io();

        try {
            $migrate   = CommandHelper::build();
            $pending  = $migrate->getPendingMigrations();
            $executed = $migrate->getExecutedMigrations();
        } catch (\Throwable $e) {
            $io->error('Could not determine migration status: ' . $e->getMessage(), true);
            return;
        }

        if (empty($pending) && empty($executed)) {
            $io->info('No migrations found.', true);
            return;
        }

        if (! empty($executed)) {
            $io->bold('Executed migrations:', true);
            foreach ($executed as $entry) {
                $breakpoint = ! empty($entry['breakpoint']) ? ' [B]' : '';
                $io->ok(
                    sprintf('  [  ran  ]  %s  (batch %d, %s)%s', $entry['name'], $entry['batch'], $entry['run_at'], $breakpoint),
                    true
                );
            }
        }

        if (! empty($pending)) {
            $io->bold('Pending migrations:', true);
            foreach ($pending as $entry) {
                $io->warn('  [pending]  ' . $entry['name'], true);
            }
        } else {
            $io->ok('No pending migrations.', true);
        }
    }
}
