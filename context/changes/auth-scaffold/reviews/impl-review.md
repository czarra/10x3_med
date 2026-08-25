<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Auth Scaffold Implementation Plan

- **Plan**: context/changes/auth-scaffold/plan.md
- **Scope**: Phase 1 of 2, Phase 2 of 2 (full plan)
- **Date**: 2026-08-25
- **Verdict**: APPROVED
- **Findings**: 0 critical, 1 warning, 0 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Notes

- **Plan Adherence**: All 11 planned changes across both phases (test-DB connectivity fix, security-bundle/maker-bundle deps, security.yaml, User entity, UserRepository, migration, UserTest.php) are full MATCHes against the plan's stated contracts — verified by an independent drift-detection pass reading each actual file against the plan text.
- **Scope Discipline**: Two files beyond the plan's explicit list were touched — `phpstan.neon` (ignoreErrors rule) and `config/services.yaml` (`when@test` public alias for `UserPasswordHasherInterface`). Both were surfaced live during implementation as blockers (vanilla PHPStan false-flagging Doctrine-managed properties; the container compiler pruning an unconsumed service even under `test: true`), presented to the user with alternatives, and approved before applying. Both are narrowly scoped (dev-only tooling / test-only DI override) and don't leak into prod. Composer/Flex byproducts (`composer.lock`, `symfony.lock`, `config/bundles.php`, `config/routes/security.yaml`, `config/reference.php`) are normal recipe/cache-clear artifacts, confirmed via `symfony.lock`'s recipe file lists. The "What We're NOT Doing" boundaries (no login/registration controllers, no email verification, no Diabetolog role, no OAuth, no profile fields, no `access_control` rules) are all confirmed respected.
- **Success Criteria**: All automated checks re-run directly during this review and pass: `dbal:run-sql` under `APP_ENV=test` → `app_test`; `composer show` resolves both packages; `doctrine:schema:validate` → mapping + DB both OK; `phpstan analyse` → 0 errors; `php-cs-fixer --dry-run` → 0 files; `phpunit` → 2 tests, 4 assertions, OK.

## Findings

### F1 — PHPStan `doctrine.columnType` ignore rule isn't path-scoped

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: phpstan.neon:6-9
- **Detail**: The ignore rule added to unblock `phpstan/phpstan-doctrine` (nullable `?string $email`/`?string $password` vs. non-null DB columns — the standard maker-bundle nullable-until-set idiom) is scoped by `identifier` + a message regex, but not by file path. The regex is specific enough that it can only ever match the exact "string|null vs string" mismatch pattern, so it can't mask unrelated Doctrine mapping bugs — but it will also silently apply to any *future* entity with the same nullable-string-vs-NOT-NULL-column shape, including a genuine case where a property was never set before persist. `reportUnmatched: true` gives a safety net if the rule ever stops matching entirely, but doesn't narrow which files it covers.
- **Fix**: Add `paths: [src/Entity/User.php]` to the ignore rule now; extend the list (or reconsider scope) as new entities are added in S-01 and later slices.
- **Decision**: FIXED — added `paths: [src/Entity/User.php]` to the `doctrine.columnType` ignore rule in `phpstan.neon`. `phpstan analyse` re-verified green (18 files, no errors), confirming the rule still matches with the narrower scope.
