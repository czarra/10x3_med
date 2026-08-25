# Patient Onboarding (S-01) — Plan Brief

> Full plan: `context/changes/patient-onboarding/plan.md`

## What & Why

Build the first user-visible slice of DiaGuide: a patient registers with e-mail + password, logs in, and — before reaching anywhere else — sets the two initial dosing parameters (base insulin dose, insulin/WW ratio) every later feature depends on. This is roadmap slice S-01, unblocked by F-01 (`auth-scaffold`), which shipped the `User` entity and security wiring but no actual auth flow.

## Starting Point

`security.yaml` has a working password hasher and an entity-backed user provider, but the `main` firewall has no authenticator and `access_control` is empty — nobody can currently log in. The logout route loader is pre-wired but unused. `App\Entity\User` has no dosing fields. No form component is installed yet; `symfony/maker-bundle`, `symfony/browser-kit`, and `symfony/css-selector` are available but unused so far.

## Desired End State

A new patient can register, land automatically on a mandatory onboarding screen, and cannot reach any other page of the app until they've entered real dosing values (the form defaults to `0`/`0`, which fail validation on purpose). Afterward they land on `/profil`, which doubles as a stand-in dashboard and lets them edit those same values any time, with no re-authentication. Returning patients log in and land on `/profil` directly.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Form handling | Symfony Form component | Idiomatic, free CSRF via already-installed `security-csrf`, matches Symfony conventions the codebase already follows | Plan (user-confirmed) |
| Dosing data model | Separate `PatientProfile` entity (1:1 with `User`) | Keeps auth concerns separate from clinical-profile concerns | Plan (user-confirmed) |
| Auth feature scope | Register/login/logout only | Matches PRD FR-001/002 exactly; remember-me/reset/throttling not required | Plan (user-confirmed) |
| Password policy | Length 8–4096, digit, special char, `NotCompromisedPassword` | User-specified complexity on top of Symfony's own breach-check recommendation | Plan (user-confirmed) |
| Onboarding defaults | Both fields default to `0` | Forces active entry rather than silently accepting a plausible-looking default | Plan (user-confirmed) |
| Validation ranges | Base dose: `>0`–35; ratio: `0.1`–10.0 | User's clinical judgment (30 units is already risky) | Plan (user-confirmed) |
| Onboarding gate | Hard redirect via subscriber, not a one-time nudge | Structurally guarantees FR-001's "must confirm or change," can't be bypassed by URL | Plan (user-confirmed) |
| Profile edit re-auth | No password re-entry | These are dosing-config values, not credentials; re-auth pattern reserved for actual credential changes | Plan (user-confirmed) |
| Post-login/onboarding landing | `/profil` | No dashboard exists yet (S-02+); profile page is the closest stand-in | Plan |
| Tooling | `symfony/maker-bundle` (`make:registration-form`, `make:authenticator`, `make:form`) | Continues the precedent F-01 set with `make:user`/`make:migration` | Plan |

## Scope

**In scope:** registration, login, logout, mandatory onboarding gate, profile view/edit for base dose + insulin/WW ratio.

**Out of scope:** password reset, remember-me, login throttling, email verification, OAuth, Diabetolog role, any real dashboard/diary UI (S-02), historical ratio snapshotting on diary entries (S-02), landing-page content changes.

## Architecture / Approach

Four Symfony-idiomatic pieces on top of the existing `User`/`security.yaml` foundation: a new `PatientProfile` entity (1:1, owning side), a shared `ProfileFormType` reused by onboarding and profile-edit, a `kernel.request` subscriber that hard-gates any authenticated user without a profile back to `/onboarding`, and a `make:authenticator`-generated login flow. All forms are plain Twig, no CSS framework, matching the existing skeleton.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. PatientProfile data model | Entity, repository, migration | None — foundation only |
| 2. Onboarding, profile edit, access gate | Gated screens, tested via `loginUser()` | Gate subscriber exclusion logic — wrong exclusions cause redirect loops |
| 3. Login and logout | Real authentication, wires the authenticator | Success-redirect target must respect the Phase 2 gate |
| 4. Registration | Account creation, auto-login, redirect to onboarding, full quality gate | Duplicate-e-mail handling; password-policy edge cases |

**Prerequisites:** F-01 (`auth-scaffold`) fully landed — confirmed (`context/changes/auth-scaffold/plan.md` Progress is all checked).
**Estimated effort:** ~4 sessions, one per phase, within the 3-week/after-hours MVP budget.

## Open Risks & Assumptions

- ~~`Security::login()` without a pre-registered authenticator...~~ **Resolved by plan review (2026-08-25)**: `Security::login()` throws `LogicException` when the firewall has zero registered authenticators (confirmed against `vendor/symfony/security-bundle/Security.php:243-246`, and independently by `make:registration-form` itself refusing to generate the auto-login call in that case). Fixed by reordering: Phase 3 is now "Login and logout" (wires `custom_authenticators`), Phase 4 is now "Registration" (its auto-login call now resolves against an already-registered authenticator).
- Base-dose validation floor (`Positive`, i.e. any value `> 0`) versus the ratio's explicit `0.1` floor is a judgment call reconciling two separate user answers — flagged in the plan's Critical Implementation Details, not re-litigated here.

## Success Criteria (Summary)

- A patient can go from registration to a saved initial profile without ever seeing an unprotected page in between.
- A returning patient logs in and lands on their current profile, editable at any time, with the full automated quality gate green.
