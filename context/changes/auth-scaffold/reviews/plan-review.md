<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Auth Scaffold Implementation Plan

- **Plan**: context/changes/auth-scaffold/plan.md
- **Mode**: Deep
- **Date**: 2026-08-25
- **Verdict**: SOUND (after fix)
- **Findings**: 1 critical, 0 warnings, 0 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | FAIL (fixed) |
| Plan Completeness | PASS |

## Grounding
Grounding: 7/7 paths ✓, 3/3 symbols ✓, brief↔plan ✓

## Findings

### F1 — Test-DB connection is broken; migration + kernel test can't run under APP_ENV=test

- **Severity**: ❌ CRITICAL
- **Impact**: 🔬 HIGH — architectural stakes; think carefully before deciding
- **Dimension**: Blind Spots
- **Location**: Phase 1 (success criteria 1.4), Phase 2 (all of it)
- **Detail**: `config/packages/doctrine.yaml`'s `when@test` block set `dbname_suffix: '_test%env(default::TEST_TOKEN)%'` (stock Flex/ParaTest recipe leftover). `.env.test` already redirects `DATABASE_URL` to a fully separate connection (host `database-test`, dbname `app_test`), so the suffix doubled up and made Doctrine resolve to a nonexistent `app_test_test` database under `APP_ENV=test`. Verified directly: `docker compose exec -e APP_ENV=test php bin/console dbal:run-sql "SELECT current_database()"` failed with `FATAL: database "app_test_test" does not exist`; dev env connected fine (`xmed`). Since `phpunit.dist.xml` forces `APP_ENV=test`, this blocked not just the migration step (1.4) but the entire Phase 2 kernel test and quality gate (2.1–2.3). Pre-existing repo bug, not introduced by this plan, but the plan's "Open Risks & Assumptions" didn't cover it and auth-scaffold is the first change to actually exercise Doctrine under `APP_ENV=test`.
- **Fix A ⭐ Recommended (Applied)**: Remove the `dbname_suffix` line / `when@test` block from `config/packages/doctrine.yaml`.
  - Strength: One-line change; verified to restore correct resolution to `app_test`, which docker-compose already provisions.
  - Tradeoff: Diverges from the stock Flex/ParaTest recipe comment — parallel test execution (ParaTest) would need per-process DB isolation reintroduced deliberately if adopted later.
  - Confidence: HIGH — reproduced the failure and the fix live against running containers.
  - Blind spot: None significant.
- **Fix B**: Keep `dbname_suffix`, provision an `app_test_test` database instead.
  - Strength: Preserves the stock Flex/ParaTest convention untouched.
  - Tradeoff: Permanently confusing db name not matching any `.env*` variable; fragile against `docker compose down -v`.
  - Confidence: MEDIUM — untested.
  - Blind spot: Doesn't remove the underlying fragility for future contributors.
- **Decision**: FIXED (Fix A) — `config/packages/doctrine.yaml`'s `when@test` block removed; verified `dbal:run-sql "SELECT current_database()"` now returns `app_test` under `APP_ENV=test`. Plan updated with a new Phase 1 prerequisite item (0. Test database connectivity fix), a Critical Implementation Detail note, a new success criterion, and Progress item 1.0 marked done (uncommitted).
