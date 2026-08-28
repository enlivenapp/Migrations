# Changelog

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