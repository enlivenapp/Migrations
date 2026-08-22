<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Commands;

use flight\commands\AbstractBaseCommand;

/**
 * Help command for the migrations CLI.
 *
 * Running `php runway migrate` lists all available migration sub-commands
 * with a short description of each. Use this when you want to see what
 * migration commands are available without looking them up elsewhere.
 *
 * Usage:
 *   php runway migrate
 */
class MigrateCommand extends AbstractBaseCommand
{
    public function __construct(array $config)
    {
        parent::__construct('migrate', 'Flight Migrations - database migration CLI', $config);

        $this->usage(
            '<bold>Available commands:</end><eol/>' .
            '<eol/>' .
            '<bold>  migrate:all</end>        <comment>Run all pending migrations across every module</end><eol/>' .
            '<bold>  migrate:single</end>     <comment>Run pending migrations for one module</end><eol/>' .
            '<bold>  migrate:make</end>       <comment>Create a new migration file</end><eol/>' .
            '<bold>  migrate:rollback</end>   <comment>Roll back the most recent batch</end><eol/>' .
            '<bold>  migrate:status</end>     <comment>Show executed and pending migrations</end><eol/>' .
            '<bold>  migrate:breakpoint</end> <comment>Set or clear a rollback breakpoint on a migration</end><eol/>' .
            '<bold>  migrate:unlock</end>     <comment>Force-release the migration lock after a crash</end><eol/>' .
            '<bold>  migrate:purge</end>      <comment>Destroy all data for a module (explicit, irreversible)</end><eol/>' .
            '<eol/>' .
            '<comment>Run any command without arguments to see its usage.</end>'
        );
    }

    public function execute(): void
    {
        $this->showHelp();
    }
}
