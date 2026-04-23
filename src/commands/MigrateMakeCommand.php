<?php

/**
 * @package   Enlivenapp\Migrations
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\Migrations\Commands;

use Enlivenapp\Migrations\Services\ConfigLoader;
use flight\commands\AbstractBaseCommand;

/**
 * Creates a new migration file for a package.
 *
 * Generates a timestamped migration file with the correct filename format,
 * the right namespace derived from the Composer package name, and an empty
 * up()/down() boilerplate ready to fill in. The file is placed in the
 * package's configured migrations directory, or a conventional default if
 * none is configured.
 *
 * Use --path to write the file to a specific directory instead.
 *
 * Usage:
 *   php runway migrate:make <ClassName> <package> [--path /override/path]
 */
class MigrateMakeCommand extends AbstractBaseCommand
{
    public function __construct(array $config)
    {
        parent::__construct('migrate:make', 'Create a new migration file', $config);

        $this
            ->argument('[name]', 'PascalCase class name (e.g. CreateUsersTable)')
            ->argument('[module]', 'Composer package name (e.g. vendor/my-plugin)')
            ->option('--path', 'Override the migration directory path (absolute)', null, null)
            ->usage(
                '<bold>  migrate:make</end> <comment>CreateUsersTable vendor/my-plugin</end><eol/>' .
                '<bold>  migrate:make</end> <comment>CreateUsersTable vendor/my-plugin --path /absolute/path</end>'
            );
    }

    public function execute(?string $name = null, ?string $module = null, ?string $path = null): void
    {
        $io = $this->app()->io();

        if ($name === null || $name === '' || $module === null || $module === '') {
            $this->showHelp();
            return;
        }

        $timestamp = date('Y-m-d-His');
        $filename  = $timestamp . '_' . $name . '.php';

        // Determine output directory
        if ($path !== null && $path !== '') {
            $dir = rtrim($path, '/');
        } else {
            $dir = $this->resolveDirectory($module);
        }

        if ($dir === null) {
            $io->error('Could not determine migration directory for module "' . $module . '".', true);
            return;
        }

        // Create directory if it does not exist
        if (! is_dir($dir)) {
            if (! mkdir($dir, 0755, true)) {
                $io->error('Could not create directory: ' . $dir, true);
                return;
            }
        }

        $namespace = $this->resolveNamespace($module);
        $filePath  = $dir . '/' . $filename;
        $contents  = $this->buildTemplate($namespace, $name);

        file_put_contents($filePath, $contents);

        $io->ok($filePath, true);
    }

    /**
     * Resolve the migration directory for the given module from config paths,
     * falling back to a conventional default.
     */
    private function resolveDirectory(string $module): ?string
    {
        $config = ConfigLoader::loadConfig();
        $paths  = $config['migrations']['paths'] ?? [];

        foreach ($paths as $pattern) {
            $expanded = glob($pattern, GLOB_ONLYDIR);
            if ($expanded === false) {
                continue;
            }
            foreach ($expanded as $candidate) {
                // Match if the module name appears in the path
                $normalized = str_replace('\\', '/', $candidate);
                if (str_contains($normalized, $module)) {
                    return rtrim($candidate, '/');
                }
            }
        }

        // Conventional default: vendor/vendor/package/src/Database/Migrations
        $parts     = explode('/', $module, 2);
        $vendorDir = isset($parts[1]) ? 'vendor/' . $module : 'vendor/' . $module;

        return getcwd() . '/' . $vendorDir . '/src/Database/Migrations';
    }

    /**
     * Convert a Composer package name to a PHP namespace.
     * e.g. vendor/my-package => Vendor\MyPackage\Database\Migrations
     */
    private function resolveNamespace(string $module): string
    {
        $segments = explode('/', $module);
        $parts    = [];

        foreach ($segments as $segment) {
            // Convert kebab-case to PascalCase
            $part = implode('', array_map('ucfirst', explode('-', $segment)));
            $parts[] = ucfirst($part);
        }

        return implode('\\', $parts) . '\\Database\\Migrations';
    }

    /**
     * Build the migration file template.
     */
    private function buildTemplate(string $namespace, string $className): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Enlivenapp\Migrations\Services\Migration;

class {$className} extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
}
PHP;
    }
}
