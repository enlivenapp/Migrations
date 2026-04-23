<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Commands;

use Enlivenapp\Migrations\Exceptions\LockException;
use Enlivenapp\Migrations\Helpers\CommandHelper;
use flight\commands\AbstractBaseCommand;

/**
 * Runs every pending migration across all packages.
 *
 * Discovers all registered modules and applies any migrations that have not
 * yet been run. Use this during deployments or initial setup when you want
 * to bring all packages up to date in one step.
 *
 * Use --dry-run to preview which migrations would run without applying any
 * changes - DDL is captured without executing (pretend mode).
 *
 * Usage:
 *   php runway migrate:all [--dry-run]
 */
class MigrateAllCommand extends AbstractBaseCommand
{
    public function __construct(array $config)
    {
        parent::__construct('migrate:all', 'Run all pending migrations across every module', $config);

        $this
            ->option('--dry-run', 'Preview changes without applying them', null, false)
            ->usage(
                '<bold>  migrate:all</end>            <comment>Run all pending migrations</end><eol/>' .
                '<bold>  migrate:all</end> <comment>--dry-run</end>  <comment>Show what would run without committing</end>'
            );
    }

    public function execute(bool $dryRun = false): void
    {
        $io = $this->app()->io();

        if ($dryRun) {
            $io->comment('Dry-run mode: changes will be rolled back.', true);
        }

        try {
            $migrate  = CommandHelper::build();
            $results = $migrate->runAll($dryRun);
        } catch (LockException $e) {
            $io->error($e->getMessage(), true);
            return;
        } catch (\Throwable $e) {
            $io->error('Migration failed: ' . $e->getMessage(), true);
            return;
        }

        if (empty($results)) {
            $io->info('Nothing to migrate.', true);
            return;
        }

        foreach ($results as $result) {
            if ($result->isSuccess()) {
                $io->ok('  ' . ($dryRun ? '[dry] ' : '') . 'Migrated: ' . $result->getName(), true);
            } else {
                $io->error('  Failed:   ' . $result->getName(), true);
                $io->error('  Reason:   ' . $result->getMessage(), true);
            }
        }

        $failed = array_filter($results, fn($r) => ! $r->isSuccess());

        if (! empty($failed)) {
            $io->error('Migrations halted due to failure.', true);
            return;
        }

        if ($dryRun) {
            $io->comment('Dry-run complete - no changes committed.', true);
        } else {
            $io->ok('All migrations complete.', true);
        }
    }
}
