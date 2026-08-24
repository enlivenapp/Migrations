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
 * Releases the migration lock.
 *
 * The migration system holds a lock while running to prevent concurrent runs.
 * If a previous migration process crashed without cleaning up, the lock will
 * remain held and subsequent runs will refuse to start. This command clears
 * that lock so migrations can run again.
 *
 * Only use this after confirming the crashed process is no longer running.
 *
 * Use --dry-run to check whether the lock is currently held without releasing
 * it - useful for diagnosing a stuck migration without making any changes.
 *
 * Usage:
 *   php runway migrate:unlock [--dry-run]
 */
class MigrateUnlockCommand extends AbstractBaseCommand
{
    public function __construct(array $config)
    {
        parent::__construct('migrate:unlock', 'Force-release the migration lock after a crash', $config);

        $this
            ->option('--dry-run', 'Show lock state without releasing it', null, false)
            ->usage(
                '<bold>  migrate:unlock</end>           <comment>Clears the migrations_lock row</end><eol/>' .
                '<bold>  migrate:unlock</end> <comment>--dry-run</end>  <comment>Show lock state without releasing</end>'
            );
    }

    public function execute(bool $dryRun = false): void
    {
        $io = $this->app()->io();

        try {
            $migrate = CommandHelper::build();
            $info   = $migrate->forceUnlock($dryRun);
        } catch (\Throwable $e) {
            $io->error('Could not operate on lock: ' . $e->getMessage(), true);
            return;
        }

        if ($dryRun) {
            if ($info['is_locked']) {
                $io->warn(
                    sprintf(
                        'Lock IS held (PID: %s, acquired: %s). --dry-run: not released.',
                        $info['locked_by'] ?? '?',
                        $info['locked_at'] ?? '?'
                    ),
                    true
                );
            } else {
                $io->info('Lock is NOT currently held. Nothing to release.', true);
            }
            return;
        }

        if ($info['is_locked']) {
            $io->ok(
                sprintf(
                    'Lock released (was held by PID %s since %s).',
                    $info['locked_by'] ?? '?',
                    $info['locked_at'] ?? '?'
                ),
                true
            );
        } else {
            $io->info('Lock was not held; nothing to release.', true);
        }
    }
}
