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
 * Set or clear a breakpoint on a migration to control how far rollback can go.
 *
 * When a breakpoint is set, rollback and purge stop before that migration.
 * Use this to protect known-good states — for example, after a release — so
 * that rollback in development doesn't accidentally undo production migrations.
 *
 * Usage:
 *   php runway migrate:breakpoint <version> <package>
 *   php runway migrate:breakpoint <version> <package> --clear
 */
class MigrateBreakpointCommand extends AbstractBaseCommand
{
    public function __construct(array $config)
    {
        parent::__construct('migrate:breakpoint', 'Set or clear a rollback breakpoint on a migration', $config);

        $this
            ->argument('[migration]', 'Migration version timestamp (e.g. 2026-01-15-143022)')
            ->argument('[package]', 'Composer package name (e.g. vendor/my-plugin)')
            ->option('--clear', 'Remove the breakpoint instead of setting it', null, false)
            ->usage(
                '<bold>  migrate:breakpoint</end> <comment>2026-01-15-143022 vendor/my-plugin</end>          <comment>Set breakpoint</end><eol/>' .
                '<bold>  migrate:breakpoint</end> <comment>2026-01-15-143022 vendor/my-plugin --clear</end>  <comment>Clear breakpoint</end>'
            );
    }

    public function execute(
        ?string $migration = null,
        ?string $package   = null,
        bool    $clear     = false,
    ): void {
        $io = $this->app()->io();

        if ($migration === null || $migration === '' || $package === null || $package === '') {
            $this->showHelp();
            return;
        }

        try {
            $migrate = CommandHelper::build();
            $migrate->setBreakpoint($migration, $package, !$clear);
        } catch (\Throwable $e) {
            $io->error($e->getMessage(), true);
            return;
        }

        if ($clear) {
            $io->ok("Breakpoint cleared on {$package}:{$migration}.", true);
        } else {
            $io->ok("Breakpoint set on {$package}:{$migration}. Rollback will stop before this migration.", true);
        }
    }
}
