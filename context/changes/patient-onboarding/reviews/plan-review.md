<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Patient Onboarding (S-01) Implementation Plan

- **Plan**: `context/changes/patient-onboarding/plan.md`
- **Mode**: Deep
- **Date**: 2026-08-25
- **Verdict**: SOUND (fixed during triage — see Decisions below)
- **Findings**: 0 critical, 1 warning, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | WARNING |
| Plan Completeness | PASS |

## Grounding

Paths/symbols verified directly (no sub-agent needed — small codebase, direct reads gave file:line evidence faster): `config/packages/security.yaml`, `config/routes/security.yaml`, `src/Entity/User.php`, `config/packages/validator.yaml`, `composer.json`, `src/Repository/UserRepository.php`, `tests/Entity/UserTest.php` all match the plan's Current State Analysis. `vendor/symfony/security-bundle/Security.php` (`getAuthenticator()`), `vendor/symfony/maker-bundle/src/Maker/MakeRegistrationForm.php` + `templates/registration/RegistrationController.tpl.php`, `vendor/symfony/maker-bundle/templates/authenticator/LoginFormAuthenticator.tpl.php`, and `vendor/symfony/http-foundation/RequestMatcher/PathRequestMatcher.php` + `security-http/AccessMap.php` all confirmed the plan's specific framework-behavior claims. Progress↔Phase mechanical contract: single `## Progress` heading, all 4 phases match 1:1, no stray checkbox syntax in phase bodies — clean. Contradiction/promise-gap scan: nothing in "What We're NOT Doing" reappears in phases; every Desired-End-State capability has a backing phase. `context/foundation/lessons.md` and `docs/reference/contract-surfaces.md` don't exist in this repo — skipped, as in the prior review.

This is a second review of this plan; the first (same date) found and fixed one CRITICAL sequencing bug (registration's auto-login vs. when the authenticator gets registered — fixed by swapping Phase 3/Phase 4). That fix is confirmed intact in the current plan.

## Findings

### F1 — `access_control` regex isn't end-anchored

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Blind Spots
- **Location**: Phase 2 — Access control contract

**Detail**: `{ path: ^/(onboarding|profil), roles: ROLE_USER }` is not end-anchored. Symfony's `PathRequestMatcher::matches()` does `preg_match('{'.$regexp.'}', $path)` with no implicit `$` — so this rule would also match any future path merely *prefixed* with `/onboarding` or `/profil` (e.g. a hypothetical `/onboarding-status`). Not exploitable today (no such route exists), but a one-character fix now avoids a subtle future access-control gap.

- **Fix**: `{ path: ^/(onboarding|profil)$, roles: ROLE_USER }`
- **Decision**: FIXED — applied to `context/changes/patient-onboarding/plan.md` Phase 2 §6 (Access control contract), with a one-line rationale added inline.

### F2 — Registration's `Security::login($user)` zero-arg call diverges from maker's own safer default

- **Severity**: ℹ️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phase 4 — Registration controller contract

**Detail**: The plan instructed calling `Security::login($user)` with no explicit authenticator name. Correct as far as it goes (`Security.php`'s `getAuthenticator()` resolves cleanly with exactly one registered authenticator), but `make:registration-form` itself, detecting that single authenticator, generates the more robust explicit form: `$security->login($user, LoginFormAuthenticator::class, 'main')` (`RegistrationController.tpl.php:46`). The zero-arg form throws `LogicException('Too many authenticators...')` the instant a second authenticator is ever registered (e.g. remember-me later); the explicit form doesn't have that failure mode, and the maker generates it for free.

- **Fix**: Keep the maker-generated explicit form (`Security::login($user, LoginFormAuthenticator::class, 'main')`) instead of reducing it to the zero-arg overload.
- **Decision**: FIXED — applied to `context/changes/patient-onboarding/plan.md` Phase 4 §2 (Registration controller contract).
