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
 * Undoes the most recent batch of migrations.
 *
 * A batch is all the migrations that ran together in a single migrate:all or
 * migrate:single call. Rolling back reverses every migration in that batch by
 * calling their down() methods in reverse order.
 *
 * Use --module to limit the rollback to one package's last batch instead of
 * rolling back across all packages. Use --dry-run to preview what would be
 * reversed without committing any changes.
 *
 * Usage:
 *   php runway migrate:rollback [--module vendor/package] [--dry-run]
 */
class MigrateRollbackCommand extends AbstractBaseCommand
{
    public function __construct(array $config)
    {
        parent::__construct('migrate:rollback', 'Roll back the most recent migration batch', $config);

        $this
            ->option('--module', 'Scope rollback to a specific module (e.g. vendor/my-plugin)', null, null)
            ->option('--dry-run', 'Preview changes without applying them', null, false)
            ->usage(
                '<bold>  migrate:rollback</end>                           <comment>Roll back last batch (all modules)</end><eol/>' .
                '<bold>  migrate:rollback</end> <comment>--module vendor/my-plugin</end>   <comment>Roll back only that module\'s last batch</end><eol/>' .
                '<bold>  migrate:rollback</end> <comment>--dry-run</end>                   <comment>Show what would be rolled back</end>'
            );
    }

    public function execute(?string $module = null, bool $dryRun = false): void
    {
        $io = $this->app()->io();

        if ($dryRun) {
            $io->comment('Dry-run mode: changes will be rolled back.', true);
        }

        try {
            $migrate  = CommandHelper::build();
            $results = $migrate->rollbackLast($module ?: null, $dryRun);
        } catch (LockException $e) {
            $io->error($e->getMessage(), true);
            return;
        } catch (MigrationException $e) {
            $io->error($e->getMessage(), true);
            return;
        } catch (\Throwable $e) {
            $io->error('Rollback failed: ' . $e->getMessage(), true);
            return;
        }

        if (empty($results)) {
            $io->info('Nothing to roll back.', true);
            return;
        }

        foreach ($results as $result) {
            if ($result->isSuccess()) {
                $io->ok('  ' . ($dryRun ? '[dry] ' : '') . 'Rolled back: ' . $result->getName(), true);
            } else {
                $io->error('  Failed:      ' . $result->getName(), true);
                $io->error('  Reason:      ' . $result->getMessage(), true);
            }
        }

        $failed = array_filter($results, fn($r) => ! $r->isSuccess());

        if (! empty($failed)) {
            $io->error('Rollback completed with errors (see above).', true);
            return;
        }

        if ($dryRun) {
            $io->comment('Dry-run complete - no changes committed.', true);
        } else {
            $io->ok('Rollback complete.', true);
        }
    }
}
