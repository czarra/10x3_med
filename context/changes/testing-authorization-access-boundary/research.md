---
date: 2026-08-28T13:33:50+02:00
researcher: Radoslaw Michałkiewicz
git_commit: 406848839a325ee893a0a13fc328513bbc6953e7
branch: main
repository: czarra/10x3_med
topic: "Authorization & access-boundary hardening — risk #1 (cross-account diary access) and risk #5 (unauthenticated/session-boundary gap)"
tags: [research, codebase, authorization, diary-controller, voter, security-yaml, access-control]
status: complete
last_updated: 2026-08-28
last_updated_by: Radoslaw Michałkiewicz
---

# Research: Authorization & access-boundary hardening (test-plan Phase 1)

**Date**: 2026-08-28T13:33:50+02:00
**Researcher**: Radoslaw Michałkiewicz
**Git Commit**: 406848839a325ee893a0a13fc328513bbc6953e7
**Branch**: main
**Repository**: czarra/10x3_med

## Research Question

Ground `context/foundation/test-plan.md` Phase 1 (risks #1 and #5) with concrete facts before planning integration tests:

- **#1**: Which `DiaryController` actions call the ownership check, and by which mechanism (voter attribute, manual compare, query-scoping)? Challenge whether a passing voter unit test actually proves every HTTP entry point is protected.
- **#5**: Which routes are firewall-protected today, and which negative/boundary cases do existing security tests already assert? Challenge whether "happy-path security tests already cover this" is true.

## Summary

Both "must challenge" assumptions in the test-plan turn out to be **false as stated**, and the two risks are more precisely scoped than the risk map implies:

**Risk #1** — Ownership enforcement is not one mechanism, it's two, split cleanly by whether the action is id-addressed:
- `edit`/`delete` (`/dziennik/{id}/edytuj`, `/dziennik/{id}/usun`) fetch the entry unscoped (`DiaryEntryRepository::find($id)`) and then rely on a manual `isGranted(DiaryEntryVoter::EDIT|DELETE, $entry)` call, denying with **404** (not 403) to avoid leaking that another patient's entry exists.
- `new`/`history`/`export` never take an entry id; ownership is enforced by building the query around `$this->getUser()` (`WHERE e.user = :user`). **The Voter is never invoked for these three actions.**
- The existing Voter unit test (`tests/Security/DiaryEntryVoterTest.php`) is a plain `TestCase`, not `WebTestCase` — it proves the Voter's own logic is correct but proves nothing about `history`/`export`, since they don't call it. This is exactly the failure mode the test-plan's "must challenge" language anticipated.
- At the HTTP level, cross-account functional tests **already exist** for `edit`, `delete`, and `export` (`tests/Controller/DiaryControllerTest.php`). The one concrete, previously-undocumented gap: **`history` (`GET /dziennik/historia`) has zero cross-account test** — no test logs in as a second user and asserts user A's entries are absent from user B's `/dziennik/historia` response.

**Risk #5** — Happy-path security tests do **not** already cover unauthenticated/negative boundary cases for most routes:
- 9 distinct controller actions sit behind the single `access_control` rule (`^/(onboarding|profil)$|^/dziennik(/|$)|^/pulpit(/|$)`, `ROLE_USER`) plus per-action `#[IsGranted('ROLE_USER')]`.
- Only **1 of 9** (`diary_entry_export`) has a genuine fresh-anonymous-client test (`testExportRequiresAuthentication`). `patient_profile` has only a *post-logout* variant (`SecurityControllerTest::testLogoutThenProfileRedirectsToLogin`), not a from-scratch anonymous-client test. The other 7 actions (`onboarding`, `pulpit`, `pulpit/przelicznik/akceptuj`, `pulpit/dawka-bazowa/akceptuj`, `dziennik/nowy`, `dziennik/{id}/edytuj`, `dziennik/{id}/usun`, `dziennik/historia`) have **no** unauthenticated-access regression test at all.
- Login negative case IS tested and doesn't leak account existence (`testWrongPasswordShowsErrorAndDoesNotLogIn` asserts the generic "Invalid credentials" message for both wrong-password and unknown-email cases).
- Registration's duplicate-email negative case IS tested for status code/no-second-row, but the underlying `#[UniqueEntity]` validation message ("Istnieje już konto z tym adresem e-mail") **does leak account existence** (email enumeration) — a related, previously-undocumented finding worth flagging to the plan even though it's a disclosure issue rather than an auth-bypass.
- The anonymous `/` → `/profil` → `/login` two-hop redirect chain (`HomeController` redirects unconditionally to `patient_profile`, which then bounces to login) is untested for the anonymous case — `HomeControllerTest` only covers the authenticated redirect.

## Detailed Findings

### DiaryController route inventory and ownership mechanism (risk #1)

| Route | Method | Route name | Controller:line | Ownership mechanism |
|---|---|---|---|---|
| `/dziennik/nowy` | GET/POST | `diary_entry_new` | `DiaryController::new` (25-63) | N/A — entry always built for `$this->getUser()`; form doesn't expose a `user` field |
| `/dziennik/{id}/edytuj` | GET/POST | `diary_entry_edit` | `DiaryController::edit` (65-91) | Manual `isGranted(DiaryEntryVoter::EDIT, $entry)` after unscoped `find($id)`, denies with 404 |
| `/dziennik/{id}/usun` | POST | `diary_entry_delete` | `DiaryController::delete` (93-115) | Same pattern as edit, `DiaryEntryVoter::DELETE`, 404 |
| `/dziennik/historia` | GET | `diary_entry_history` | `DiaryController::history` (117-132) | Query-scoping only — `DiaryHistoryService::buildPage($user)` → `WHERE e.user = :user`; Voter never called |
| `/dziennik/eksport` | GET | `diary_entry_export` | `DiaryController::export` (134-160) | Same query-scoping as `history`; `DiaryExportService` itself does no filtering, trusts the `DiaryHistoryPage` it's handed |

All five actions carry `#[IsGranted('ROLE_USER')]` — that proves authentication only, not ownership.

`src/Security/DiaryEntryVoter.php` (44 lines) is the app's **only** Voter. It supports exactly two attributes, `DIARY_ENTRY_EDIT` and `DIARY_ENTRY_DELETE` (lines 16-17, 24-27), subject type `DiaryEntry`. `voteOnAttribute` (29-42) conflates two concerns in one boolean: ownership (`$subject->getUser()->getId() !== $user->getId()`) and the 24h editability rule (`$this->editabilityService->isEditable(...)`) — a `false` vote (→ 404) doesn't distinguish "not yours" from "past the edit window." That conflation matters for risk #3, not #1, but is worth the future plan knowing about.

There is no `DIARY_ENTRY_VIEW`/`DIARY_ENTRY_EXPORT` attribute anywhere — `history`/`export` were designed from the start to rely on query-scoping rather than the Voter (confirmed independently by `context/archive/2026-08-27-export-diary-history/research.md:61-62`, see Historical Context below).

### Existing test coverage for risk #1

`tests/Controller/DiaryControllerTest.php` (789 lines, `WebTestCase`) has three genuine cross-account functional tests, all using a shared two-user fixture pattern (private `createUser`/`createProfile`/`createEntry` helpers, `$client->loginUser($otherUser)`, cleanup of both users in a `finally` block):

- `testEditReturns404ForAnotherUsersEntry` (line 338): user B `GET`s user A's edit URL → asserts 404.
- `testDeleteReturns404ForAnotherUsersEntry` (line 435): user B `POST`s delete on user A's entry → asserts 404, then re-queries and asserts the entry `assertNotNull(...)` — confirms it was **not** deleted.
- `testExportOnlyIncludesRequestingUsersEntries` (line 597): user A and user B each have one entry; logged in as user A, asserts A's value is in the CSV and B's is not.

`tests/Security/DiaryEntryVoterTest.php` (131 lines) extends plain `TestCase`, not `WebTestCase`/`KernelTestCase`. `testOwnershipMismatchDenies` (line 17) is the only ownership-mismatch coverage and never touches routing, the controller, or the manual `isGranted()` call sites — it cannot prove `edit`/`delete` actually invoke the Voter, and says nothing about `history`/`export` at all.

**Gap**: `diary_entry_history` has no cross-account test anywhere in the suite — every `testHistory*` test in `DiaryControllerTest.php` uses a single logged-in user.

`tests/Service/Export/DiaryExportServiceTest.php` (221 lines) is single-user only; the only cross-account export coverage lives at the HTTP layer in `DiaryControllerTest`.

### Security/firewall configuration and route inventory (risk #5)

`config/packages/security.yaml` (29 lines, full):

```yaml
security:
    password_hashers:
        App\Entity\User:
            algorithm: auto
    providers:
        app_user_provider:
            entity:
                class: App\Entity\User
                property: email
    firewalls:
        dev:
            pattern: ^/(_(profiler|wdt)|css|images|js)/
            security: false
        main:
            lazy: true
            provider: app_user_provider
            form_login:
                login_path: app_login
                check_path: app_login
                enable_csrf: true
                default_target_path: patient_profile
            logout:
                path: app_logout
                enable_csrf: true
                target: app_login
    access_control:
        - { path: ^/(onboarding|profil)$|^/dziennik(/|$)|^/pulpit(/|$), roles: ROLE_USER }
```

One real firewall (`main`), session-based `form_login`, no remember-me/API tokens/stateless firewall, no `switch_user`, no `access_denied_url` override — an unauthorized hit falls through to the default entry point, a 302 to `/login`.

Public (not matched by `access_control`): `/login`, `/logout`, `/register`, `/` (home).

Protected actions vs. unauthenticated-access test coverage:

| Route name | Path | Controller:line | Unauthenticated-access test? |
|---|---|---|---|
| `patient_onboarding` | `GET/POST /onboarding` | `OnboardingController.php:19` | **NO** |
| `patient_profile` | `GET/POST /profil` | `ProfileController.php:17` | Partial — only post-logout via `SecurityControllerTest::testLogoutThenProfileRedirectsToLogin`, not a fresh anonymous client |
| `patient_dashboard` | `GET /pulpit` | `DashboardController.php:21` | **NO** |
| `patient_dashboard_accept_ratio` | `POST /pulpit/przelicznik/akceptuj` | `DashboardController.php:42` | **NO** |
| `patient_dashboard_accept_base_dose` | `POST /pulpit/dawka-bazowa/akceptuj` | `DashboardController.php:85` | **NO** |
| `diary_entry_new` | `GET/POST /dziennik/nowy` | `DiaryController.php:25` | **NO** |
| `diary_entry_edit` | `GET/POST /dziennik/{id}/edytuj` | `DiaryController.php:65` | **NO** |
| `diary_entry_delete` | `POST /dziennik/{id}/usun` | `DiaryController.php:93` | **NO** |
| `diary_entry_history` | `GET /dziennik/historia` | `DiaryController.php:117` | **NO** |
| `diary_entry_export` | `GET /dziennik/eksport` | `DiaryController.php:134` | **YES** — `DiaryControllerTest.php:639-645` |

`HomeController::index` at `/` is not itself in `access_control` — it always redirects to `patient_profile`, so an anonymous hit to `/` would chain 302→`/profil`→302→`/login`. `HomeControllerTest` only covers the *authenticated* redirect; the anonymous two-hop chain is untested.

### Login/registration negative-path behavior (risk #5)

`src/Controller/SecurityController.php` (32 lines): `login()` just renders `AuthenticationUtils`' last error/username; Symfony's `form_login` handles bad credentials itself. The template renders the generic `error.messageKey` ("Invalid credentials") for **both** wrong-password and unknown-email — no email enumeration on login, confirmed by `SecurityControllerTest::testWrongPasswordShowsErrorAndDoesNotLogIn` (line 50-65), which also asserts `security.token_storage` holds a null token.

`src/Controller/RegistrationController.php` (18-50): catches `UniqueConstraintViolationException` as a race-condition fallback (redirects to `app_login` instead of 500, line 36). The primary duplicate-email path is `#[UniqueEntity(fields: ['email'], message: 'Istnieje już konto z tym adresem e-mail.')]` on `src/Entity/User.php:15` — this message **is** rendered on the form and does disclose that the email is already registered (email enumeration). `RegistrationControllerTest::testDuplicateEmailIsRejectedWithFormErrorAndNoSecondRow` (line 35-56) asserts 422 + exactly one DB row, but does not assert on the leaked message content. On successful registration, `$security->login($user, 'form_login', 'main')` logs the user in immediately (line 42) and redirects to `patient_onboarding`.

`RegistrationControllerTest.php` also covers three password-policy negative cases (missing digit, missing special character, under 8 characters), all asserting 422 + no user created.

## Code References

- [`src/Controller/DiaryController.php:25-63`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/src/Controller/DiaryController.php#L25-L63) — `new()`, no per-entry ownership check needed
- [`src/Controller/DiaryController.php:65-91`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/src/Controller/DiaryController.php#L65-L91) — `edit()`, unscoped `find($id)` + manual `isGranted()` → 404
- [`src/Controller/DiaryController.php:93-115`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/src/Controller/DiaryController.php#L93-L115) — `delete()`, same pattern + CSRF check
- [`src/Controller/DiaryController.php:117-132`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/src/Controller/DiaryController.php#L117-L132) — `history()`, query-scoping only, Voter never called
- [`src/Controller/DiaryController.php:134-160`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/src/Controller/DiaryController.php#L134-L160) — `export()`, same query-scoping as `history`
- [`src/Security/DiaryEntryVoter.php:16-42`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/src/Security/DiaryEntryVoter.php#L16-L42) — the app's only Voter; `EDIT`/`DELETE` only, conflates ownership + 24h editability
- [`src/Repository/DiaryEntryRepository.php:41-49`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/src/Repository/DiaryEntryRepository.php#L41-L49) — `findByUserOrderedByMeasuredAtDesc`, the `WHERE e.user = :user` scoping used by `history`/`export`
- [`src/Service/Export/DiaryExportService.php:13-31`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/src/Service/Export/DiaryExportService.php#L13-L31) — pure CSV formatter, does no scoping of its own
- [`tests/Controller/DiaryControllerTest.php:338-358`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/tests/Controller/DiaryControllerTest.php#L338-L358) — `testEditReturns404ForAnotherUsersEntry`
- [`tests/Controller/DiaryControllerTest.php:435-461`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/tests/Controller/DiaryControllerTest.php#L435-L461) — `testDeleteReturns404ForAnotherUsersEntry`
- [`tests/Controller/DiaryControllerTest.php:597-621`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/tests/Controller/DiaryControllerTest.php#L597-L621) — `testExportOnlyIncludesRequestingUsersEntries`
- [`tests/Controller/DiaryControllerTest.php:639-645`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/tests/Controller/DiaryControllerTest.php#L639-L645) — `testExportRequiresAuthentication`, the only anonymous-client test in the suite
- [`tests/Controller/DiaryControllerTest.php:759-787`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/tests/Controller/DiaryControllerTest.php#L759-L787) — `createUser`/`createProfile`/`cleanupUser` fixture helpers to follow for new tests
- [`tests/Security/DiaryEntryVoterTest.php:17`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/tests/Security/DiaryEntryVoterTest.php#L17) — `testOwnershipMismatchDenies`, unit-level only
- [`config/packages/security.yaml:1-29`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/config/packages/security.yaml#L1-L29) — full firewall/access_control config
- [`src/Controller/SecurityController.php:12-32`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/src/Controller/SecurityController.php#L12-L32) — `login()`/`logout()`
- [`src/Controller/RegistrationController.php:18-50`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/src/Controller/RegistrationController.php#L18-L50) — registration flow, race-condition fallback, immediate login on success
- [`src/Entity/User.php:15`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/src/Entity/User.php#L15) — `#[UniqueEntity]` message that discloses account existence
- [`tests/Controller/SecurityControllerTest.php:50-91`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/tests/Controller/SecurityControllerTest.php#L50-L91) — wrong-password and post-logout tests
- [`tests/Controller/RegistrationControllerTest.php:35-104`](https://github.com/czarra/10x3_med/blob/406848839a325ee893a0a13fc328513bbc6953e7/tests/Controller/RegistrationControllerTest.php#L35-L104) — duplicate-email and password-policy negative tests

## Architecture Insights

- **Two independent ownership mechanisms by design, not oversight.** Id-addressed mutations (`edit`/`delete`) use a Voter; user-scoped reads (`new`/`history`/`export`) use query-scoping. Both are internally consistent today, but they are separate code paths with no shared test coverage — a future id-addressed read route (e.g. a single-entry "show" view) would silently need its own protection, since neither existing mechanism would automatically cover it.
- **404-over-403 is a deliberate anti-enumeration pattern**, established when the Voter was introduced (`context/archive/2026-08-27-edit-delete-diary-entry/`) and consistently applied since. Any new ownership-denial test should assert 404, not 403, to match this convention.
- **Dual-layer route protection convention**: every patient-only action carries both a URL-pattern `access_control` rule in `security.yaml` and a per-action `#[IsGranted('ROLE_USER')]` attribute — established starting with `context/archive/2026-08-25-patient-onboarding/`. Both layers would need to fail simultaneously for an unauthenticated request to leak through, but neither layer has broad regression coverage today.
- **Regex-anchoring has already bitten this project twice** in `access_control` (patient-onboarding plan-review F1, log-diary-entry plan-review F1) — both caught in plan review before shipping, not by a test. There's still no runtime test that would catch a similar regex regression if review missed it, since 8 of 9 protected actions have no anonymous-access test.
- **No shared test fixture trait** — `createUser`/`createProfile`/`createEntry`/`cleanupUser` are duplicated privately in `DiaryControllerTest` and `DiaryExportServiceTest`. New tests for this phase should follow the existing duplicated-helper convention rather than introduce a new abstraction, unless the plan explicitly wants to extract a trait.

## Historical Context (from prior changes)

- `context/archive/2026-08-24-auth-scaffold/plan.md` — scaffolded `security.yaml` with `access_control: []` (explicitly empty, nothing to protect yet) and the `User` entity; no authenticator.
- `context/archive/2026-08-25-patient-onboarding/plan.md`, `reviews/plan-review.md`, `reviews/impl-review-post-plan.md` — added the authenticator, login/registration, and the first `access_control` rule. Plan-review F1 caught an unanchored regex (`^/(onboarding|profil)` would prefix-match `/onboarding-status`) before implementation. Post-plan impl-review F3 flagged the `/` → `/profil` redirect shipping without a direct test; fixed by adding `HomeControllerTest::testHomeRedirectsAuthenticatedUserToProfile` — which, per this research, still only covers the *authenticated* case, leaving the anonymous chain untested to this day.
- `context/archive/2026-08-25-log-diary-entry/plan.md`, `reviews/plan-review.md` — extended `access_control` for `/dziennik/*`; plan-review F1 caught a second regex-anchoring regression during that extension, fixed to `^/(onboarding|profil)$|^/dziennik(/|$)`. Established the dual-layer `access_control` + `#[IsGranted]` convention.
- `context/archive/2026-08-27-edit-delete-diary-entry/research.md`, `plan.md`, `reviews/impl-review.md` — introduced `DiaryEntryVoter`, the app's first (and only) id-addressed route/Voter. Design intentionally chose 404 over 403 for both "not yours" and "locked" to avoid leaking entry existence. Impl-review found 0 critical issues.
- `context/archive/2026-08-27-export-diary-history/research.md:61-62` — explicitly documents the split this research confirms: *"Read/list actions don't use the Voter at all — they're scoped entirely by the repository's `WHERE user = :user` clause... [the Voter is] used solely for `edit`/`delete`."* Its own test plan already flagged cross-user isolation as "the security-critical case" for export, which is why `export` (unlike `history`) already has a cross-account test.
- `context/foundation/prd.md:106` (NFR) — *"Dostęp do nich ma wyłącznie uwierzytelniony użytkownik."* `prd.md:125` (Pacjent access) and `prd.md:131` (Niezalogowany użytkownik: *"Brak dostępu do jakichkolwiek funkcji aplikacji poza ekranem powitalnym, rejestracji i logowania."*) are the exact PRD anchors for risks #1 and #5 respectively.
- `context/foundation/roadmap.md:50` — *"Dane glikemii i historii leczenia... są szyfrowane i niedostępne dla podmiotów trzecich bez wyraźnej zgody pacjenta."*
- `context/foundation/lessons.md` — only 2 entries (Polish/English communication convention; CSV formula-injection). Neither touches auth/ownership; the two-mechanism ownership split and the 404-vs-403 decision are documented only in the edit-delete-diary-entry change folder, not yet distilled into a standing lesson.

## Related Research

- `context/archive/2026-08-27-edit-delete-diary-entry/research.md` — original design rationale for `DiaryEntryVoter` and the 404-over-403 decision.
- `context/archive/2026-08-27-export-diary-history/research.md` — prior confirmation of the Voter/query-scoping split, and export's own cross-account test rationale.
- `context/archive/2026-08-25-patient-onboarding/reviews/plan-review.md` and `context/archive/2026-08-25-log-diary-entry/reviews/plan-review.md` — the two `access_control` regex-anchoring near-misses.

## Open Questions

- Should the plan add an explicit `history`/`export` Voter attribute (e.g. `DIARY_ENTRY_VIEW`) so all `DiaryController` actions share one enforcement mechanism, or is query-scoping an acceptable permanent design for read actions? (Design question, out of scope for a test-only phase, but worth a note in the plan if the two-mechanism split is flagged as risk.)
- The registration email-enumeration finding (leaked via `#[UniqueEntity]`'s message) doesn't map cleanly onto risk #5's stated scenario ("unauthenticated/session-boundary gap") — it's a disclosure issue in an authenticated-adjacent flow. Should Phase 1's plan include a test asserting this behavior (documenting it as accepted/known), or should it be logged as a new candidate risk for a future test-plan refresh instead?
- Should the anonymous `/` → `/profil` → `/login` two-hop chain get its own explicit `HomeControllerTest` case, or is directly testing `/profil` (and the other 7 uncovered actions) with a fresh anonymous client sufficient to prove the boundary holds?
