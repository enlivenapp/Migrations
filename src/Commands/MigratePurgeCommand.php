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
 * Drops all tables created by a package's migrations, then removes the
 * tracking records for those migrations.
 *
 * Runs down() on every applied migration for the package in reverse order,
 * then deletes the corresponding rows from the migrations tracking table.
 * After a purge the package is in a clean-slate state as if it had never
 * been migrated.
 *
 * This is destructive and permanent - all data in the affected tables is
 * lost. The command prompts for y/N confirmation before proceeding.
 *
 * Use --dry-run to preview what would be purged without committing any
 * changes - DDL is captured without executing (pretend mode).
 *
 * Usage:
 *   php runway migrate:purge <package> [--dry-run]
 */
class MigratePurgeCommand extends AbstractBaseCommand
{
    public function __construct(array $config)
    {
        parent::__construct('migrate:purge', 'Destroy all data for a module (explicit, irreversible)', $config);

        $this
            ->argument('[module]', 'Composer package name of the module (e.g. vendor/my-plugin)')
            ->option('--dry-run', 'Preview what would be purged without applying changes', null, false)
            ->usage(
                '<bold>  migrate:purge</end> <comment>vendor/my-plugin</end>           <comment>Destroy all module data (prompts for confirmation)</end><eol/>' .
                '<bold>  migrate:purge</end> <comment>vendor/my-plugin --dry-run</end>  <comment>Show what would be purged without committing</end>'
            );
    }

    public function execute(?string $module = null, bool $dryRun = false): void
    {
        $io = $this->app()->io();

        if ($module === null || $module === '') {
            $this->showHelp();
            return;
        }

        if ($dryRun) {
            $io->comment('Dry-run mode: changes will be rolled back.', true);
        } else {
            // Interactive confirmation required for destructive operation
            $io->warn(
                "WARNING: This will destroy all data for module \"{$module}\" by running down() on every executed migration, then removing its tracking rows.",
                true
            );
            $io->write('Are you sure? (y/N): ', false);

            $answer = trim((string) fgets(STDIN));

            if (strtolower($answer) !== 'y') {
                $io->info('Aborted.', true);
                return;
            }
        }

        try {
            $migrate  = CommandHelper::build();
            $results = $migrate->purgeModule($module, $dryRun);
        } catch (LockException $e) {
            $io->error($e->getMessage(), true);
            return;
        } catch (\Throwable $e) {
            $io->error('Purge failed: ' . $e->getMessage(), true);
            return;
        }

        if (empty($results)) {
            $io->info("No executed migrations found for \"{$module}\". Nothing purged.", true);
            return;
        }

        foreach ($results as $result) {
            if ($result->isSuccess()) {
                $io->ok('  ' . ($dryRun ? '[dry] ' : '') . 'Purged: ' . $result->getName(), true);
            } else {
                $io->error('  Failed: ' . $result->getName(), true);
                $io->error('  Reason: ' . $result->getMessage(), true);
            }
        }

        $failed = array_filter($results, fn($r) => ! $r->isSuccess());

        if (! empty($failed)) {
            $io->error('Purge completed with errors (see above).', true);
            return;
        }

        if ($dryRun) {
            $io->comment('Dry-run complete - no changes committed.', true);
        } else {
            $io->ok("Module \"{$module}\" purged successfully.", true);
        }
    }
}
