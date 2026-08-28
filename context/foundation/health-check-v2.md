---
project: dia-guide
checked_at: 2026-08-28T23:22:43Z
health_status: healthy
context_type: brownfield
language_family: php
stack_assessment_available: false
checks_run:
  - lockfile
  - dependency_audit
  - outdated_deps
  - test_runner
  - ci_cd
  - configuration
audit_findings:
  critical: 0
  high: 0
  moderate: 0
  low: 0
test_runner_detected: true
ci_provider: null
recommended_fixes: 4
category_a_applied: 2
category_b_remaining: 2
---

> Refresh of `context/foundation/health-check.md` (written 2026-08-19). That
> report is now stale: it recorded a single kernel-boot test and flagged a
> missing project-specific `AGENTS.md`. Both have since changed — the PHPUnit
> suite is at 153 tests, a Playwright E2E suite exists, and `AGENTS.md` /
> `CLAUDE.md` carry real project guidance. The prior file is left in place; this
> is the current picture.

## Dependency Health

This is a PHP project (Symfony 7.4) with a small JavaScript surface for the
Playwright E2E harness. Both ecosystems were audited.

### Lockfile

```
Status: present (composer.lock)
Package manager: composer

Status: present (package-lock.json)
Package manager: npm
```

Both lockfiles are committed and versions are pinned — builds are reproducible
and the agent can reason about exact dependency state.

### Security Audit

```
Tool: composer audit --format=json   (run inside the php container)
Summary: 0 CRITICAL, 0 HIGH, 0 MODERATE, 0 LOW
Direct vs transitive: not distinguished by this tool
```

No advisories, no abandoned packages.

```
Tool: npm audit --json
Summary: 0 CRITICAL, 0 HIGH, 0 MODERATE, 0 LOW
```

Clean — the only JS dependency is `@playwright/test`.

### Outdated Dependencies

```
Packages with major version gaps: 0
```

`composer outdated --direct` reports only **semver-safe patch updates** on
tooling:

- **doctrine/doctrine-migrations-bundle**: 4.0.0 → 4.0.1
- **friendsofphp/php-cs-fixer**: v3.95.20 → v3.95.23
- **phpstan/phpstan**: 2.2.8 → 2.2.9
- **phpunit/phpunit**: 13.3.1 → 13.3.2

The Symfony framework components (`symfony/console`, `symfony/framework-bundle`,
`symfony/form`, …) report `7.4.x → 8.1.x` as "update-possible". This is **not a
staleness finding** — `AGENTS.md` pins the project to Symfony 7.4 (an LTS
release), so holding the major version is deliberate. `npm outdated` reports
nothing.

## Test Suite

```
Test runner: PHPUnit
Tests found: 153 (single "Project Test Suite")
Test execution: enumerable and green in the php container
```

```
Configuration: phpunit.dist.xml
Framework: PHPUnit 13.3.1 (via symfony/test-pack), PHP 8.5.9 in the Docker container
Run: docker compose exec php vendor/bin/phpunit
```

Coverage is now real business-logic coverage, not a smoke test. Tests span
`tests/Service/{Suggestion,Warning,Editability,Chart,History,Export}`,
`tests/Controller`, `tests/Security`, and `tests/Kernel` — including the PRD's
core rules (base-dose suggestion, insulin/WW ratio suggestion, hypoglycemia
warning) at their boundary conditions.

Static analysis gates are both green:

- **PHPStan** (level 5, `src/` only, `phpstan.neon`): 0 errors.
- **PHP-CS-Fixer** (`@Symfony` ruleset, `.php-cs-fixer.dist.php`): 0 of 72 files
  need changes.

### Browser E2E (Playwright)

```
Test runner: Playwright
Tests found: 16 (5 files)
Test execution: enumerable in the playwright container
```

```
Configuration: playwright.config.ts + package.json (repo root)
Framework: @playwright/test 1.62.1
Run: docker compose exec playwright npx playwright test
```

Specs and rules live under `tests/e2e/`; PHP test-support (seed command +
`POST /__e2e__/reset`) under `tests/Support/E2e/`, wired only via `when@e2e`.
The suite drives the `php-e2e` container, which shares the `database-test`
Postgres — so the PHPUnit and E2E suites must run **sequentially, never
concurrently** (matters once CI stages are defined).

## CI/CD

```
Provider: not detected
Configuration: not found
```

| Stage      | Status | Notes           |
|------------|--------|-----------------|
| Lint       | ✗      | not configured  |
| Test       | ✗      | not configured  |
| Build      | ✗      | not configured  |
| Type check | ✗      | not configured  |
| Security   | ✗      | not configured  |

ℹ No CI/CD configuration detected. You'll set this up in the infrastructure and
deployment lesson. For now, a local test runner is sufficient for agent
collaboration.

That said, the test surface has grown enough — 153 PHPUnit tests, 16 Playwright
tests, plus three static gates (PHPStan, PHP-CS-Fixer, and the PHP 8.2 floor
that nothing currently validates) — that wiring CI is worth doing soon rather
than late. `AGENTS.md` already anticipates it, calling the sequential
PHPUnit-then-E2E constraint out as something that "matters for CI stage
ordering".

## Configuration

All expected configuration files present. No gaps that block agent work.

- `.editorconfig` — present.
- `.gitignore` — present; excludes `.env.local*`, `/var/`, `/vendor/`,
  `/node_modules/`, and the local Postgres data directories.
- `.env.dist` — present; the environment-variable template (Symfony-idiomatic
  equivalent of `.env.example`, documented in `AGENTS.md`).
- `phpstan.neon` — present; analysis runs clean.
- `.php-cs-fixer.dist.php` — present; dry-run reports nothing to fix.
- `playwright.config.ts` + root `package.json` — present; E2E harness wired.
- `AGENTS.md` + `CLAUDE.md` — present, with **project-specific content**
  (Docker-wrapped command convention, PHP 8.5 runtime note, git-tracked `.env*`
  caveat, E2E locator/wait rules, sequential-suite constraint). The previous
  check flagged these as generic stubs; that gap is now **closed**.

### Low severity

- **`composer.json` PHP constraint vs actual runtime** — `composer.json`
  declares `php: >=8.2`, but the Docker image (and every command run against it)
  is PHP 8.5.9. PHP-CS-Fixer emits a warning about the mismatch on every run.
  Nothing currently exercises the 8.2 floor, so the declared minimum is
  untested. Fix: either raise the constraint to match the runtime
  (`"php": ">=8.5"`), or leave it and let CI run the suite against 8.2 once CI
  exists. Not blocking either way.

## Stack Assessment Cross-Reference

No `stack-assessment.md` found. Run `/10x-stack-assess` for quality-gate
analysis.

For context: `context/foundation/tech-stack.md` still records
`bootstrapper_confidence: best-effort` and `quality_override: true` — Symfony
was chosen outside `/10x-tech-stack-selector`'s starter registry (which only
carries Laravel for PHP), so no formal typed / convention-based / popular /
well-documented scoring exists for this exact starter choice. That is a registry
gap, not a finding about this codebase — and this run shows the codebase itself
is in good shape regardless.

## Recommended Fixes

### Fix before agent work (Category A) — ✅ both applied 2026-08-28

#### 1. Apply the available semver-safe patch updates — ✅ DONE

**Impact**: keeps the toolchain (PHPUnit, PHPStan, PHP-CS-Fixer,
DoctrineMigrationsBundle) on the latest bugfix releases the agent will be
verifying against. All four are patch bumps with no expected behaviour change.
**Severity**: low
**Effort**: quick (< 5 min)
**What was done**:

```
docker compose exec php composer update \
  doctrine/doctrine-migrations-bundle friendsofphp/php-cs-fixer \
  phpstan/phpstan phpunit/phpunit --with-dependencies
```

Installed: `doctrine/doctrine-migrations-bundle` 4.0.0 → 4.0.1,
`friendsofphp/php-cs-fixer` v3.95.20 → v3.95.23, `phpstan/phpstan` 2.2.8 → 2.2.9,
`phpunit/phpunit` 13.3.1 → 13.3.2 (plus Symfony 7.4.16 → 7.4.17 patch upgrades
pulled in transitively, all within the `7.4.*` pin). Composer's default
`bump-after-update` also tightened the four constraint strings in
`composer.json` (e.g. `^3.95` → `^3.95.23`). Verified afterwards: PHPUnit
`OK (153 tests, 412 assertions)`, PHPStan `No errors`, PHP-CS-Fixer `0 of 72`.

#### 2. Align the `composer.json` PHP floor with the real runtime — ✅ DONE

**Impact**: the declared `>=8.2` minimum is never exercised (everything runs on
8.5), so the agent could write 8.5-only syntax that silently violates the
contract. Making the constraint match reality removes the ambiguity — and the
recurring PHP-CS-Fixer warning.
**Severity**: low
**Effort**: quick (< 5 min)
**What was done**: set `"php": ">=8.5"` in `composer.json` `require`; the
`composer update` above refreshed `composer.lock` accordingly. The PHP-CS-Fixer
"running on 8.5.9 but minimum is 8.2" warning is now gone.

### Addressed in upcoming lessons (Category B)

#### No CI/CD pipeline

**Lesson**: [Sprint Zero z Agentem: infrastruktura, walking skeleton i pierwszy deploy (M1L5)](https://platforma.przeprogramowani.pl/external/10xdevs-3/m1-l5)
**What you'll do there**: set up lint / test / build / type-check / security
stages for the deployment target — including PHPUnit and Playwright as
**separate, sequential** stages (shared `database-test` Postgres), PHPStan and
PHP-CS-Fixer as lint gates, and optionally a job on the declared PHP 8.2
minimum.

#### No deployment configuration yet

**Lesson**: [Sprint Zero z Agentem: infrastruktura, walking skeleton i pierwszy deploy (M1L5)](https://platforma.przeprogramowani.pl/external/10xdevs-3/m1-l5)
**What you'll do there**: wire up the deployment target recorded in
`context/foundation/tech-stack.md` / `infrastructure.md`.

## Summary

Health status: **healthy**

Dependencies are clean in both ecosystems (0 advisories from `composer audit`
and `npm audit`, no major-version drift), and the project now has genuine test
infrastructure: 153 PHPUnit tests covering the PRD's core dosing rules at their
boundaries, a 16-test Playwright E2E suite, and three green static gates
(PHPStan, PHP-CS-Fixer, and a documented `AGENTS.md` / `CLAUDE.md`). Both
Category A quick wins have been applied — the four tooling packages are on their
latest patch releases and `composer.json` now declares `php: >=8.5` to match the
runtime. The one remaining gap of substance, no CI automation, is expected at
this stage and is covered in the infrastructure lesson; given how much the test
surface has grown, it's worth prioritising when you get there.
