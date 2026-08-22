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
            $io->comment('Dry-run mode is not supported with migrate:all. Use migrate:single --dry-run instead.', true);
            return;
        }

        try {
            $migrate = CommandHelper::build();
            $moduleResults = $migrate->runMigrate();
        } catch (LockException $e) {
            $io->error($e->getMessage(), true);
            return;
        } catch (\Throwable $e) {
            $io->error('Migration failed: ' . $e->getMessage(), true);
            return;
        }

        if (empty($moduleResults)) {
            $io->info('Nothing to migrate.', true);
            return;
        }

        $hasFailure = false;

        foreach ($moduleResults as $moduleResult) {
            foreach ($moduleResult->getMigrationResults() as $r) {
                if ($r->isSuccess()) {
                    $io->ok('  Migrated: ' . $r->getName(), true);
                } else {
                    $io->error('  Failed:   ' . $r->getName(), true);
                    $io->error('  Reason:   ' . $r->getMessage(), true);
                    $hasFailure = true;
                }
            }

            foreach ($moduleResult->getSeedResults() as $r) {
                if ($r->isSuccess()) {
                    $io->ok('  Seeded:   ' . $r->getModuleName() . ':' . $r->getTable(), true);
                } else {
                    $io->error('  Seed failed: ' . $r->getTable() . ' — ' . $r->getMessage(), true);
                }
            }
        }

        if ($hasFailure) {
            $io->error('Migrations halted due to failure.', true);
        } else {
            $io->ok('All migrations complete.', true);
        }
    }
}
