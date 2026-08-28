# Authorization & Access-Boundary Hardening — Plan Brief

> Full plan: `context/changes/testing-authorization-access-boundary/plan.md`
> Research: `context/changes/testing-authorization-access-boundary/research.md`

## What & Why

Close the two coverage gaps `research.md` found while grounding test-plan.md Phase 1: `DiaryController::history` has no cross-account test (risk #1), and 8 of 9 patient-only routes have no unauthenticated-access test (risk #5). Also fix a related finding surfaced during research — registration's duplicate-email error currently discloses account existence — since the user explicitly asked for it to be fixed now rather than deferred.

## Starting Point

`DiaryController`'s `edit`/`delete`/`export` already have cross-account tests; only `history` doesn't. Of 9 protected route actions behind `security.yaml`'s single `access_control` rule, only `export` (and a partial post-logout variant of `patient_profile`) has an anonymous-access test — the other 7, plus the anonymous `/`→`/profil`→`/login` chain, have none. Six existing test files independently duplicate near-identical `createUser`/`cleanupUser`-style fixture helpers with drifted names and signatures.

## Desired End State

Every patient-only route rejects an anonymous request with a redirect to `/login`, proven by a passing regression test per route. `history` cannot leak another patient's entries, proven the same rigorous way as the existing `export` test. Registration no longer confirms whether an email is already registered. A shared fixture trait exists as the documented, reusable two-user pattern for Diary tests going forward.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| Fixture organization | Extract `DiaryFixturesTrait`, adopt in `DiaryControllerTest` + `DiaryExportServiceTest` only | Satisfies test-plan.md §6.3's open TBD without refactoring files this phase doesn't otherwise touch | Plan |
| Email-enumeration finding | Fix now (generic message) + regression test, not deferred | User explicitly chose to close the gap immediately rather than log it for later | Plan |
| Fix approach | Generic error string on the existing `#[UniqueEntity]` attribute, no mailer flow | No mailer is configured in this repo; a full email-based flow would be real scope creep | Plan |
| Home redirect chain | In scope — add one `HomeControllerTest` case | Cheap, closes a gap research already surfaced, and `/` gates `patient_profile` | Plan |
| Route-gap priority | All 8 remaining routes are must-have, none deferred | User: unauthenticated access must be limited to only login/registration, everything else protected | Plan |
| `history` test rigor | Mixed two-user dataset (both have entries), not an empty-state check | Matches `export`'s proven pattern; an empty-state check would trivially pass even a fully broken query | Plan |

## Scope

**In scope:**
- Cross-account test for `DiaryController::history`
- Unauthenticated-access tests for all 7 previously-uncovered routes + `patient_profile`'s fresh-anonymous case + the `/` redirect chain
- Shared `DiaryFixturesTrait` for `DiaryControllerTest` and `DiaryExportServiceTest`
- Registration duplicate-email message fix + regression test

**Out of scope:**
- Unifying `DiaryController`'s two ownership mechanisms (Voter vs. query-scoping) — architecture decision, not a coverage gap
- A mailer-driven anti-enumeration registration flow
- Risks #2, #3, #4 from test-plan.md — later rollout phases
- Migrating `Onboarding`/`Dashboard`/`Profile`/`Home` test files onto the shared trait — their new tests need no fixtures

## Architecture / Approach

Four independently-shippable phases: extract the fixture trait first (foundation), then close risk #1, then risk #5 (no fixtures needed — pure anonymous-client assertions), then the registration fix. All changes are additive test methods except one single-line message-string change in `src/Entity/User.php`.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Shared fixture trait | `DiaryFixturesTrait`, adopted by 2 existing test files | Refactor could silently weaken an existing assertion |
| 2. `history` cross-account test | Closes risk #1's last gap | A too-weak assertion could false-pass |
| 3. Unauthenticated-access tests | Closes risk #5 across 5 files, 10 new tests | Low — mechanical, well-precedented pattern |
| 4. Registration fix | Closes email-enumeration finding | Message wording is a judgment call, not a hard requirement |

**Prerequisites:** none — all target files and conventions already exist.
**Estimated effort:** ~1 session, 4 phases, all test-only except one 1-line production string change.

## Open Risks & Assumptions

- The chosen generic registration message (`'Rejestracja nie powiodła się. Sprawdź wprowadzone dane i spróbuj ponownie.'`) reduces but doesn't fully eliminate enumeration risk — the field-level error still fires only for this specific failure mode. A full fix would need a mailer-based flow, explicitly out of scope.
- `DiaryController`'s two-mechanism ownership split (Voter for `edit`/`delete`, query-scoping for `new`/`history`/`export`) remains architecturally as-is; a future id-addressed read route would need its own protection, unprompted by either existing mechanism.

## Success Criteria (Summary)

- Every one of the 9 patient-only routes, plus `/`, redirects an anonymous request to `/login` — proven by an automated test per route.
- `DiaryController::history` cannot leak another patient's entries — proven by a mixed-dataset cross-account test.
- Registering with an already-used email no longer confirms that the account exists.
