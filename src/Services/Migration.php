<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Services;

use Enlivenapp\Migrations\Interfaces\MigrationInterface;

/**
 * Base class that every migration file extends.
 *
 * Inside your migration you can call $this->table('name') to get the schema
 * builder for that table - that's how you create, modify, or drop tables.
 *
 * You have two ways to write a migration:
 *
 *   1. Override up() and down() separately - up() makes the change, down()
 *      undoes it. Use this when the rollback logic isn't obvious from the
 *      forward change.
 *
 *   2. Override change() instead - for common operations like creating a table
 *      or adding a column where the system can figure out the rollback for you.
 *      No need to write down() at all.
 *
 * Example using up()/down():
 *
 *   class CreateUsersTable extends Migration
 *   {
 *       public function up(): void
 *       {
 *           $this->table('users')
 *               ->addColumn('id', 'primary')
 *               ->addColumn('email', 'string', ['length' => 255])
 *               ->create();
 *       }
 *
 *       public function down(): void
 *       {
 *           $this->table('users')->drop();
 *       }
 *   }
 */
abstract class Migration implements MigrationInterface
{
    private SchemaBuilder $schemaBuilder;

    /**
     * Gives this migration access to the schema builder before it runs.
     * The runner calls this for you - you never need to call it yourself.
     */
    public function setSchemaBuilder(SchemaBuilder $sb): void
    {
        $this->schemaBuilder = $sb;
    }

    /**
     * Returns the schema builder for the named table.
     * This is how you create, modify, or drop tables inside your migration.
     */
    protected function table(string $name): SchemaBuilder
    {
        return $this->schemaBuilder->table($name);
    }

    /**
     * Override this to define what your migration does when running forward.
     * If you're using change() instead, you don't need this.
     */
    public function up(): void
    {
        // Default no-op. Override this OR change().
    }

    /**
     * Override this to undo what up() did. Called during rollback.
     * If you're using change() instead, you don't need this.
     */
    public function down(): void
    {
        // Default no-op. Override this OR change().
    }

    /**
     * Override this instead of up()/down() when your migration can be automatically reversed.
     * Works well for creating tables, adding columns, and renaming things - operations
     * where the system knows how to undo them without you spelling it out.
     */
    public function change(): void
    {
        // Override in subclass
    }

    /**
     * Returns true if this migration uses change() rather than up().
     * Used internally by the runner to decide how to run and roll back the migration.
     */
    public function usesChange(): bool
    {
        // True if the subclass overrides change() but not up()
        $changeClass = (new \ReflectionMethod($this, 'change'))->getDeclaringClass()->getName();
        $upClass     = (new \ReflectionMethod($this, 'up'))->getDeclaringClass()->getName();

        return $changeClass !== self::class && $upClass === self::class;
    }
}
