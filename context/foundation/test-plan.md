# Test Plan

> Phased test rollout for this project. Strategy is frozen at the top
> (§1–§5); cookbook patterns at the bottom (§6) fill in as phases ship.
> Read before writing any new test.
>
> Refresh: re-run `/10x-test-plan --refresh` when stale (see §8).
>
> Last updated: 2026-08-28

## 1. Strategy

Tests follow three non-negotiable principles for this project:

1. **Cost × signal.** The cheapest test that gives a real signal for the
   risk wins. Do not promote to e2e because e2e "feels safer." Do not put a
   vision model on top of a deterministic visual diff that already catches
   the regression.
2. **User concerns are first-class evidence.** Risks anchored in "the team
   is worried about X, and the failure would surface somewhere in area Y"
   carry the same weight as PRD lines or hot-spot data.
3. **Risks are scenarios, not code locations.** This plan documents *what
   could fail* and *why we believe it's likely* — drawn from documents,
   interview, and codebase *signal* (churn, structure, test base). It does
   NOT claim to know which line owns the failure. That knowledge is
   produced by `/10x-research` during each rollout phase. If the plan and
   research disagree about where the failure lives, research is the
   ground truth.

Hot-spot scope used for likelihood weighting: `src/` (excludes `tests/`,
`var/`, `vendor/`), last 30 days, 25 commits.

## 2. Risk Map

| # | Risk (failure scenario) | Impact | Likelihood | Source (evidence — not anchor) |
|---|---|---|---|---|
| 1 | A patient can view, edit, delete, or export another patient's diary entries despite ownership checks existing | High | Medium | interview Q1 (top concern), interview Q3 (`DiaryController` named as low-confidence area), hot-spot dir `src/Controller` (23 commits/30d, `DiaryController` alone 9), PRD Access Control + NFR "dostęp wyłącznie dla uwierzytelnionego użytkownika" |
| 2 | Recommendation algorithms (insulin/WW ratio suggestion, base-dose adjustment, hypoglycemia warning) produce an incorrect medical suggestion on boundary/incomplete data, and the patient acts on it | High | High | interview Q1 (second top concern), interview Q3 + Q4 (user names algorithm logic as least-confident and under-tested), PRD Business Logic thresholds (US-01, FR-009, FR-010), hot-spot dir `src/Service` (6 commits/30d, 3 relevant to Suggestion/Warning) |
| 3 | The 24h edit/delete boundary rule (FR-014) blocks a legitimate edit or allows one past the window, corrupting historical trend data | Medium | Medium | PRD FR-014, hot-spot dir `src/Controller` (`DiaryController` 9 commits/30d — same hottest file), interview Q3 |
| 4 | Export (CSV today, PDF planned in S-07b) leaks another patient's data, or a future free-text export field enables formula injection | Medium | Low | PRD FR-012, lessons.md CSV formula-injection lesson, roadmap S-07b Unknowns ("Format PDF nie był jeszcze researchowany") |
| 5 | An unauthenticated request or session-boundary gap reaches a patient-only screen | High | Low | PRD Access Control ("Niezalogowany użytkownik: brak dostępu"), abuse/security lens (app has auth + PII), existing `SecurityControllerTest`/`RegistrationControllerTest` coverage lowers likelihood |

### Risk Response Guidance

| Risk | What would prove protection | Must challenge | Context `/10x-research` must ground | Likely cheapest layer | Anti-pattern to avoid |
|---|---|---|---|---|---|
| #1 | A second patient account can never view, edit, delete, or export another patient's diary entry — every `DiaryController` action returns 403/404 for a non-owned resource | A passing voter unit test for one action does not mean every controller action calls it — each HTTP entry point must be checked independently | Which `DiaryController` actions call the ownership check today, and by which mechanism (voter attribute, manual compare, query-scoping) | integration (functional test, two fixture users) | Testing only the Voter class in isolation instead of the actual HTTP action |
| #2 | A suggestion/warning never fires when input doesn't meet the PRD's stated minimum (e.g. fewer than 3 paired entries), and the adjustment direction matches the PRD's stated rule (glycemia rose → raise ratio; fell → lower it) | Today's threshold constants are correct just because a happy-path test asserts today's output — the oracle must come from the PRD rule, not the service's own math | Exact threshold values and how partial entries (missing WW or insulin) are excluded from the calculation | unit | Oracle problem — copying expected output from the implementation instead of the PRD's rule |
| #3 | An entry created exactly at the 24h boundary, and one a moment after it, are both treated correctly by edit/delete | Server time and stored timestamp share timezone/precision — a test only checking "well inside" vs. "well outside" will miss an off-by-one | Where the 24h check is computed (request time vs. stored `createdAt`) and the app's timezone/precision | integration | Testing only comfortably-inside/outside windows, skipping the exact boundary second |
| #4 | Export output never contains another patient's rows, and any free-text field added later escapes leading `=`, `+`, `-`, `@` per lessons.md | The export query is scoped by the logged-in patient's ID at every call site, not just the controller entry point | How the export query is scoped to the current patient, and whether S-07b introduces a free-text field | integration (cross-account denial) + unit (injection escaping, once applicable) | Only testing well-formed output, never a second patient's data or a hostile value |
| #5 | Every patient-only route redirects an unauthenticated request to login; login/registration negative cases (duplicate email, wrong password) fail safely without leaking whether an email is registered | Existing security tests cover the happy path — do they also cover the negative/boundary cases | Which routes are firewall-protected today and which negative cases existing tests already assert | integration (extend existing pattern) | Duplicating happy-path coverage that already exists instead of adding the negative case |

## 3. Phased Rollout

| # | Phase name | Goal (one line) | Risks covered | Test types | Status | Change folder |
|---|---|---|---|---|---|---|
| 1 | Authorization & access-boundary hardening | Prove no patient can reach another patient's diary data through any CRUD/export action or an unauthenticated route | #1, #5 | integration | done | context/archive/2026-08-28-testing-authorization-access-boundary/ |
| 2 | Recommendation-algorithm edge-case coverage | Prove ratio/base-dose/hypoglycemia suggestions only fire on qualifying data and follow the PRD's direction rule | #2 | unit | change opened | context/changes/testing-recommendation-algorithm-edge-cases/ |
| 3 | Diary-entry time-boundary regression coverage | Prove the 24h edit/delete rule holds exactly at the boundary, not just well inside/outside it | #3 | integration | not started | — |
| 4 | Export data-integrity & injection safety | Prove export never crosses patients and stays injection-safe as S-07b adds new fields | #4 | integration + unit | not started | — |

## 4. Stack

| Layer | Tool | Version | Notes |
|---|---|---|---|
| unit + integration | PHPUnit | ^13.3 | `KERNEL_CLASS=App\Kernel`, runs against `database-test` Postgres, `failOnDeprecation/Notice/Warning` |
| functional/HTTP | Symfony BrowserKit + CssSelector | 7.4.* | Already in `require-dev`; WebTestClient pattern already used in `tests/Controller/*Test.php` |
| static analysis | PHPStan | ^2.2 | level 5, `src/` only (`phpstan.neon`) |
| e2e (browser) | none yet | n/a | Playwright not scaffolded (see CLAUDE.md); not needed here — every risk in this rollout is server-side/HTTP-testable via the existing functional-test pattern |

**Stack grounding tools (current session):**
- Docs: none available in current session — no Context7/framework-docs MCP exposed; checked: 2026-08-28
- Search: none available in current session — no Exa/web-search MCP exposed; checked: 2026-08-28
- Runtime/browser: none — no Playwright MCP exposed; not used; checked: 2026-08-28
- Provider/platform: none — no GitHub/Cloudflare/etc. MCP exposed in this session; not used; checked: 2026-08-28

## 5. Quality Gates

| Gate | Where | Required? | Catches |
|---|---|---|---|
| lint + typecheck (phpstan + php-cs-fixer) | local | required | type/style drift |
| unit + integration (phpunit) | local | required after §3 Phase 1 | logic/authorization regressions |
| e2e on critical flows | — | not planned | no risk in this rollout needs a browser; revisit only if a client-side-only risk surfaces |

CI wiring itself (running phpunit/phpstan on every PR) is tracked separately
under roadmap item F-02 (`deploy-pipeline-live`, already "ready") — not
duplicated here.

## 6. Cookbook Patterns

### 6.1 Adding a unit test for a service
- **Location**: `tests/Service/<Area>/`.
- **Naming**: `<Service>Test.php`.
- **Reference test**: `tests/Service/Suggestion/InsulinWwRatioSuggestionServiceTest.php`.
- **Run locally**: `docker compose exec php vendor/bin/phpunit tests/Service/<Area>`.

### 6.2 Adding a functional/HTTP test for a controller
- **Location**: `tests/Controller/`.
- **Naming**: `<Controller>Test.php`.
- **Reference test**: `tests/Controller/DiaryControllerTest.php`.
- **Run locally**: `docker compose exec php vendor/bin/phpunit tests/Controller`.

### 6.3 Adding an ownership/cross-account test for a new endpoint
- **Fixture pattern**: `App\Tests\Support\DiaryFixturesTrait` (`tests/Support/DiaryFixturesTrait.php`) — provides `createUser($entityManager, $emailPrefix = 'diary')`, `createProfile(...)`, `createEntry(...)`, `cleanupUser(...)`.
- **Test shape**: create two users (A, B), each with their own resource, using a mixed dataset (both have data) rather than an empty-state check — a fully-broken query can't trivially pass a mixed-dataset assertion the way it could an empty one. Log in as one user; assert the other's data is denied (404, for id-addressed mutations like edit/delete) or absent from the response (for list/export-style query-scoped reads).
- **Reference tests**: `tests/Controller/DiaryControllerTest.php::testEditReturns404ForAnotherUsersEntry`, `::testHistoryDoesNotExposeAnotherUsersEntries`.
- **Run locally**: `docker compose exec php vendor/bin/phpunit tests/Controller/DiaryControllerTest.php`.

### 6.4 Adding a boundary-time test
- TBD — see §3 Phase 3 for the exact-24h pattern.

### 6.5 Adding an e2e test
- Not applicable to this rollout — see CLAUDE.md's `/10x-e2e` section once Playwright is scaffolded.

### 6.6 Per-rollout-phase notes

- **Phase 1 (Authorization & access-boundary hardening), shipped 2026-08-28**:
  confirmed `DiaryController` enforces ownership via two mechanisms — a
  Voter for id-addressed `edit`/`delete` (404-over-403 anti-enumeration),
  query-scoping for `new`/`history`/`export` — both now have cross-account
  coverage (see §6.3). All 10 patient-only route actions now have an
  unauthenticated-access regression test. Also fixed and tested an
  email-enumeration finding in registration's duplicate-email error
  (adjacent to risk #5, not one of the original two risks). Full record:
  `context/archive/2026-08-28-testing-authorization-access-boundary/`.

## 7. What We Deliberately Don't Test

- **7-day chart visual zones (hipo/norma/hiper CSS/layout/colors)** — low
  business stake relative to the cost of maintaining visual tests. Re-evaluate
  if the chart becomes a value doctors rely on precisely, rather than an
  at-a-glance visualization. (Source: Phase 2 interview Q5.)

## 8. Freshness Ledger

- Strategy (§1–§5) last reviewed: 2026-08-28
- Stack versions last verified: 2026-08-28
- AI-native tool references last verified: 2026-08-28

Refresh (`/10x-test-plan --refresh`) when:
- a new top-3 risk surfaces from the roadmap or archive,
- a recommended tool's `checked:` date is older than three months,
- the project's tech stack changes (new framework, new test runner),
- §7 negative-space no longer matches what the team believes.
