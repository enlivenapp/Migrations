<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Commands;

use Enlivenapp\Migrations\Exceptions\LockException;
use Enlivenapp\Migrations\Exceptions\MigrationException;
use Enlivenapp\Migrations\Helpers\CommandHelper;
use flight\commands\AbstractBaseCommand;

/**
 * Runs pending migrations for one specific package.
 *
 * Applies only the migrations that belong to the given Composer package and
 * have not yet been run. Use this when you want to migrate a single plugin
 * or module without touching anything else.
 *
 * Use --dry-run to preview which migrations would run without applying any
 * changes - DDL is captured without executing (pretend mode).
 *
 * Usage:
 *   php runway migrate:single <package> [--dry-run]
 */
class MigrateSingleCommand extends AbstractBaseCommand
{
    public function __construct(array $config)
    {
        parent::__construct('migrate:single', 'Run pending migrations for one module', $config);

        $this
            ->argument('[module]', 'Composer package name of the module (e.g. vendor/my-plugin)')
            ->option('--dry-run', 'Preview changes without applying them', null, false)
            ->usage(
                '<bold>  migrate:single</end> <comment>vendor/my-plugin</end>' .
                '<eol/>' .
                '<bold>  migrate:single</end> <comment>vendor/my-plugin --dry-run</end>'
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
        }

        try {
            $migrate  = CommandHelper::build();
            $results = $migrate->runModule($module, $dryRun);
        } catch (LockException $e) {
            $io->error($e->getMessage(), true);
            return;
        } catch (MigrationException $e) {
            $io->error($e->getMessage(), true);
            return;
        } catch (\Throwable $e) {
            $io->error('Migration failed: ' . $e->getMessage(), true);
            return;
        }

        if (empty($results)) {
            $io->info("Nothing to migrate for \"{$module}\".", true);
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
            $io->ok("Module \"{$module}\" migrations complete.", true);
        }
    }
}
