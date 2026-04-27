# Changelog



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