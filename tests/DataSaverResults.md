# Data Saver Test Results

**Date:** 2026-04-26 22:46:44  
**Database:** flight@localhost  
**Total:** 33 | **Passed:** 33 | **Failed:** 0

## A. SchemaBuilder Self-Checks

| # | Test | Status | Detail |
|---|------|--------|--------|
| 1 | hasTable returns true for existing table | PASS | Both SchemaBuilder and info_schema agree |
| 2 | hasTable returns false for nonexistent table | PASS | Both agree |
| 3 | hasColumn returns true for existing columns | PASS | Both agree for id and email |
| 4 | hasColumn returns false for nonexistent column | PASS | Both agree |

## B. Lightweight Tier

| # | Test | Status | Detail |
|---|------|--------|--------|
| 5 | create() reversed on restore | PASS | Table created then dropped on restore |
| 6 | addColumns() single reversed on restore | PASS | Column added then removed; original intact |
| 7 | addColumns() multiple reversed on restore | PASS | All 3 columns removed; id intact |
| 8 | update() col+idx+FK reversed on restore | PASS | All 4 artifacts (2 cols, 1 idx, 1 FK) removed |
| 9 | modifyColumn() reversed on restore | PASS | Type: varchar(50) -> int -> varchar(50), default restored |
| 10 | renameColumn() reversed on restore | PASS | old_name -> new_name -> old_name |
| 11 | rename() table reversed on restore | PASS | \_test_sn_b7 -> \_new -> \_test_sn_b7 |
| 12 | dropIndex() by name reversed on restore | PASS | Unique index dropped then recreated with same uniqueness and columns |
| 13 | dropIndex() by column array reversed on restore | PASS | Compound index dropped by column lookup, recreated on restore |
| 14 | dropForeignKey() by name reversed on restore | PASS | FK recreated with same rules (CASCADE/RESTRICT) |
| 15 | dropForeignKey() by column array reversed on restore | PASS | FK dropped by column lookup, recreated on restore |

## C. Heavy Tier

| # | Test | Status | Detail |
|---|------|--------|--------|
| 16 | drop() reversed on restore (data preserved) | PASS | 3 rows restored, backup cleaned up |
| 17 | dropColumns() single reversed on restore | PASS | Column and data restored |
| 18 | dropColumns() multiple reversed on restore | PASS | Both columns and all data restored |
| 19 | AUTO_INCREMENT preserved after drop+restore | PASS | AI=6, next insert ID=6 |
| 20 | Two destructive ops, one clone | PASS | Single backup preserved both columns; restore recovered all data |
| 21 | Drop FK-referenced table: blocked, cleanup OK | PASS | MySQL blocked DROP, data saver cleaned up clone, FK intact |

## D. Cleanup / Lifecycle

| # | Test | Status | Detail |
|---|------|--------|--------|
| 22 | cleanupSafetyNet drops backup on success | PASS | Backup dropped, destructive change kept |
| 23 | enableSafetyNet resets state | PASS | Second enable cleared first session; restore only undid second |
| 24 | Disabled data saver: no ops, no clones | PASS | No backup created; restore was no-op |

## E. Multi-Operation

| # | Test | Status | Detail |
|---|------|--------|--------|
| 25 | Multiple lightweight ops on one table reversed | PASS | 3 ops reversed: add, modify (text->varchar(100)), rename |
| 26 | Mixed lightweight+heavy on one table reversed | PASS | addColumn + dropColumns + renameColumn all reversed; data intact |
| 27 | Ops across multiple tables reversed | PASS | 3 ops on 2 tables reversed; data intact |

## F. Edge Cases

| # | Test | Status | Detail |
|---|------|--------|--------|
| 28 | statement() not tracked by data saver | PASS | Raw SQL column survived restore — by design |
| 29 | Pretend mode captures SQL without executing | PASS | 1 statement(s) captured, no DB change |
| 30 | Recording mode captures ops for reversal | PASS | Recorded 2 ops: create,addColumns; reverseOperations undid all |

## G. Integration

| # | Test | Status | Detail |
|---|------|--------|--------|
| 31 | Migration failure: data saver + batch rollback | PASS | Failure detected, data saver reversed partial, batch rollback cleaned up [KNOWN BUG: \_bak_ table leaked — disableSafetyNet() not called before batch rollback] |
| 32 | Migration success: cleanup | PASS | Both migrations ran, column dropped, no backups, 2 tracking rows |
| 33 | change() migration: record + rollback | PASS | Forward created table, reversal_ops stored, rollback dropped it |

