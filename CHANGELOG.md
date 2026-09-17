# Changelog

---

## v0.4.0 - 2026-09-17

### Breaking

- **`MigrationSetup` no longer accepts a PDO.** Migrations resolves its own
  connection through `ConfigLoader`: `Flight::get('db')` when Flight is loaded,
  otherwise the credentials in `app/config/migrations.php`. Passing a PDO now
  throws a `TypeError`. The legacy `new MigrationSetup(null, $config)` positional
  form still works.
- **Constructor signature changed** to
  `__construct(?array $config = null, array|string|null $projectRoot = null)`.
- **Removed the project-root `config/migrations.php` fallback.** The only config
  file is `app/config/migrations.php`.
- **Removed the default `plugins/*` migration/seed paths.** Add plugin paths
  explicitly via config or the runtime `$config` array.
- **Config resolution lives in one place.** `MigrationSetup` no longer reads
  `src/Config/Config.php` or merges defaults itself; it delegates to
  `ConfigLoader`.

### Added

- **`path_mode` config key** (`replace` | `keys` | `add`) controlling how override
  `paths` and `seeds.paths` combine with the defaults. It applies to both lists:
  - `replace` — the override list replaces the default list.
  - `add` — the override list is appended to the defaults (deduplicated).
  - `keys` — legacy positional merge (`array_replace_recursive`). This is the
    default for backward compatibility.
- **Additive runtime config.** The `$config` array passed to `MigrationSetup`
  has its `paths` / `seeds.paths` appended to the resolved config (deduped);
  other keys merge recursively.

### Changed

- **`migrate:all` output.** When nothing runs, it now reports how many migrations
  are applied across how many modules, or lists the pending migrations that did
  not run this pass, instead of the bare "Nothing to migrate."

### Deprecated

- **`path_mode => 'keys'`.** It preserves the old positional merge and is
  deprecated; a future release will make `replace` the default. Set `path_mode`
  explicitly to opt in.

### Docs

- Updated the README and docs for the new config cascade, `path_mode`, additive
  runtime config, and removal of the PDO argument and `plugins/*` defaults.

---

## v0.3.0 - 2026-08-28

### Added

- **Caller-provided version map** (`migrations.versions`). Core modules and local
  plugins (which are not composer packages) can supply their own versions, which
  take precedence over `vendor/composer/installed.json`. This lets non-composer
  code be tracked and seeded like a normal package.
- **`migrations.module_names` override**. Allows a host app to give a migration
  path pattern a real package identity (e.g. `app/Database/Migrations` →
  `pubvana/pubvana`) instead of a directory basename artifact.

### Changed

- **Seeds now run for non-composer packages.** Previously seeds only ran when a
  package had a resolvable installed version (`composer/installed.json`), so core
  and local-plugin seeds silently never ran. The gate is now: *seed file exists
  AND (no seed record OR version changed)*. Packages without a resolvable version
  seed once using a `0.0.0` sentinel so the `install` block runs and the row is
  tracked.
- **Seeds use `INSERT IGNORE`.** Rows that collide with pre-existing unique
  values are silently skipped instead of erroring, so seeds are idempotent and
  safe to run against existing data.

---

## v0.1.1 - 2026-04-26

### Changes
                                                                 
                                                                              
**Modified:**       

- MigrationSetup.php : 17 -> 2 database calls on each page load (387ms avg to 37ms avg page load). Smarter querying instead of shotgun effect. Consolidated ensureStore 
- DatabaseMigrationLock.php : Removed ensureStore from acquire             
- MigrateAllCommand.php : Switched from calling runAll() to runMigrate(), added seed result output, and disabled dry-run mode for this command.


---

## v0.1.0 

- Initial build and testing.