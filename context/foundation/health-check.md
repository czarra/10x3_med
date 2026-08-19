---
project: dia-guide
checked_at: 2026-08-19T22:16:42Z
health_status: healthy
context_type: brownfield
language_family: php
stack_assessment_available: false
checks_run:
  - lockfile
  - dependency_audit
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
recommended_fixes: 1
---

## Dependency Health

### Lockfile

```
Status: present (composer.lock)
Package manager: composer
```

### Security Audit

```
Tool: composer audit --format json
Summary: 0 CRITICAL, 0 HIGH, 0 MODERATE, 0 LOW
Direct vs transitive: not distinguished by this tool
```

No advisories and no abandoned packages reported.

### Outdated Dependencies

```
Packages with major version gaps: not checked — no outdated-dependency command is in scope for PHP in this skill's dispatch table.
```

## Test Suite

```
Test runner: PHPUnit
Tests found: 1 test (App\Tests\Kernel\KernelBootTest::testKernelBoots)
Test execution: passing
```

```
Configuration: phpunit.dist.xml
Framework: PHPUnit 13.3.1 (via symfony/test-pack), running on PHP 8.5.9 inside the Docker container
```

All of `symfony/test-pack`, `phpunit/phpunit`, `symfony/browser-kit`, and `symfony/css-selector` are installed under `require-dev`. The suite runs clean via `docker compose exec php php bin/phpunit`.

Coverage is currently a single kernel-boot smoke test — no functional coverage yet for the app's actual business logic (e.g. the insulin/WW ratio suggestion or the hypoglycemia-risk warning from the PRD). Not a blocker, but worth growing as those features land.

## CI/CD

```
Provider: not detected
Configuration: not found
```

ℹ No CI/CD configuration detected. You'll set this up in the infrastructure and deployment lesson. For now, a local test runner is sufficient for agent collaboration.

## Configuration

All expected configuration files present. No gaps detected.

- `.editorconfig` — present.
- `.gitignore` — present, correctly excludes `/var/`, `/vendor/`, `.env.local*`.
- `.env.dist` — present, serves as the environment-variable template.
- `phpstan.neon` — present; `vendor/bin/phpstan analyse src` runs clean (0 errors).
- `.php-cs-fixer.dist.php` — present; `vendor/bin/php-cs-fixer fix --dry-run` reports 0 files needing changes.

One tooling nuance, not a gap: PHP-CS-Fixer itself warns that it's running on PHP 8.5.9 (the Docker image's version) while `composer.json` declares a minimum of `>=8.2` — worth keeping in mind once CI is set up, so lint/test runs also validate against the declared minimum, not only the dev container's newer runtime.

## Stack Assessment Cross-Reference

No stack-assessment.md found. Run `/10x-stack-assess` for quality-gate analysis.

For context: `context/foundation/tech-stack.md` (written by `/10x-tech-stack-selector`) still records `bootstrapper_confidence: best-effort` and `quality_override: true` — Symfony was picked outside that skill's starter registry (which only carries Laravel for PHP), so no formal typed/convention-based/popular/well-documented scoring exists for this exact starter choice. That is a registry gap, not a finding about this codebase, and this run shows the codebase itself is in good shape regardless.

## Recommended Fixes

### Fix before agent work (Category A)

### 1. Test coverage is minimal

**Impact**: only a kernel-boot smoke test exists; none of the PRD's actual business rules (insulin/WW ratio suggestion, hypoglycemia-risk warning) have test coverage yet. An agent changing that logic later will have nothing to verify against beyond "the app still boots."
**Severity**: low
**Effort**: significant (> 1 hour, ongoing as features are built — not a one-shot fix)
**Fix**: as each functional requirement from the PRD is implemented, add a corresponding PHPUnit test (`docker compose exec php php bin/phpunit`) alongside it rather than after the fact.

### Addressed in upcoming lessons (Category B)

### No CI/CD pipeline

**Lesson**: [Sprint Zero z Agentem: infrastruktura, walking skeleton i pierwszy deploy (M1L5)](https://platforma.przeprogramowani.pl/external/10xdevs-3/m1-l5)
**What you'll do there**: set up a CI/CD pipeline (lint, test, build, deploy stages) for the chosen deployment target — including running PHPUnit/PHPStan/PHP-CS-Fixer against the project's declared PHP 8.2 minimum, not just the container's 8.5.

### No project-specific AGENTS.md / CLAUDE.md content

**Lesson**: [Agent Onboarding: Agents.md, AI Rules i feedback loops (M1L4)](https://platforma.przeprogramowani.pl/external/10xdevs-3/m1-l4)
**What you'll do there**: build the real onboarding document for this codebase (stack conventions, the Docker-wrapped command convention, testing setup). The `CLAUDE.md` currently in this directory is this toolkit's own generic instructions, not project-specific guidance yet — generating a stub now would be premature.

### No deployment configuration yet

**Lesson**: [Sprint Zero z Agentem: infrastruktura, walking skeleton i pierwszy deploy (M1L5)](https://platforma.przeprogramowani.pl/external/10xdevs-3/m1-l5)
**What you'll do there**: wire up the Railway deployment target already recorded in `context/foundation/tech-stack.md`.

## Summary

Health status: healthy

Every Category A gap from the previous check has been closed: PHPUnit is installed and its one test passes, PHPStan reports zero errors, and PHP-CS-Fixer reports zero files needing fixes. Dependencies remain clean with no security advisories. The only remaining note is that test coverage is currently a single smoke test — fine for now, but it should grow alongside the actual business logic as it's built.

Next step: proceed to agent onboarding. CI/CD and project-specific agent instructions remain expected gaps at this stage and are covered in upcoming lessons.
