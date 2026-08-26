# Diary History View (S-05) Implementation Plan

## Overview

Let a logged-in, onboarded patient browse their diary history at
`/dziennik/historia`: entries grouped by day (newest day first, 7 days per
page, plain query-param pagination) and a 7-day glucose trend chart with
hipoglikemia/norma/hiperglikemia zone shading. This is roadmap slice S-05
(FR-008's Secondary Success Criterion), unblocked by S-02 (`log-diary-entry`,
`done`), which shipped `DiaryEntry`, `DiaryEntryRepository`, and the
`DiaryController`/form/template conventions this plan extends.

## Current State Analysis

`DiaryEntry` (`src/Entity/DiaryEntry.php`) and `DiaryEntryRepository`
(`src/Repository/DiaryEntryRepository.php`) already exist. The repository has
one finder, `findByUserOrderedByMeasuredAt(User $user, ?\DateTimeImmutable
$after = null)` — ascending order, optional lower-bound — which the chart's
7-day window reuses unchanged. `DiaryController::new()` is the only action so
far (`/dziennik/nowy`); it establishes the create-flow pattern this plan does
*not* need (no form binding here, read-only view). The app has **no charting
library and no JS build pipeline** — `templates/base.html.twig` links a single
vendored `public/css/pico.min.css` via a plain `<link>`, and every screen
(dashboard, diary/new, profile) is pure server-rendered Twig. Service classes
in `src/Service/Suggestion/` and `src/Service/Warning/` establish the pattern
this plan follows: a final class, constructor-injected repository, one public
method taking `User` (+ other args) and returning a readonly result DTO
(`RatioSuggestionResult`, `HypoglycemiaWarningResult`), tested with
`KernelTestCase` against a real (test) database — see
`tests/Service/Suggestion/InsulinWwRatioSuggestionServiceTest.php`.

No general glucose zone thresholds exist anywhere — `HypoglycemiaWarningService`'s
90/110/140 mg/dL constants are a different, unrelated calculation
(post-activity-risk projection), not a display classification scale.

## Desired End State

A logged-in patient with a completed profile can open "Historia wpisów" from
the nav and see their entries grouped under day headers, newest day on top,
7 days per page with "Nowsze"/"Starsze" links (`?page=N`). If they have any
entries at all, a 7-day glucose chart renders above the list as inline SVG:
three shaded horizontal bands (hipoglikemia <70, norma 70–180, hiperglikemia
>180 mg/dL) with the glucose readings from the last 7 days plotted as a line.
A patient with zero entries ever sees a friendly empty message and a link to
`/dziennik/nowy` instead — no chart, no pagination controls. Verify via
automated tests plus a manual walkthrough (see Phase 2 Success Criteria).

### Key Discoveries:

- `DiaryEntryRepository::findByUserOrderedByMeasuredAt()`
  (`src/Repository/DiaryEntryRepository.php:23`) already fits the chart's
  7-day window exactly (`$after = $now->modify('-7 days')`) — no change
  needed for that call site.
- `InsulinWwRatioSuggestionService::suggestFor()`
  (`src/Service/Suggestion/InsulinWwRatioSuggestionService.php:27`) is the
  precedent for a service that injects a repository, takes `User` (+args),
  and returns a readonly result DTO — both new services here follow this
  exact shape, including `KernelTestCase` tests that persist real entries
  (`tests/Service/Suggestion/InsulinWwRatioSuggestionServiceTest.php`).
- `access_control` in `config/packages/security.yaml:30` already matches
  `^/dziennik(/|$)`, so `/dziennik/historia` is covered by the existing
  onboarding/auth gate with no config change.
- `public/css/pico.min.css` is a plain committed static file linked via
  `<link rel="stylesheet">` in `base.html.twig:9` — the precedent for adding
  a second small hand-written stylesheet for the chart's zone colors instead
  of inline `style=""` attributes scattered across SVG elements.
- `tests/Controller/DiaryControllerTest.php` already has
  `createUser()`/`createProfile()`/`cleanupUser()` helpers this plan's new
  controller tests reuse directly (same file, new test methods).

## What We're NOT Doing

- Editing or deleting diary entries (S-06/FR-014) — this view is read-only.
- Exporting history to PDF/CSV (S-07) — that slice packages this same view.
- Any new insulin/WW ratio or base-dose suggestion logic (already covered by
  S-03/S-04) — this plan only displays existing entries.
- Interactive chart features (tooltips beyond a native `<title>` hover,
  zoom, date-range picking) — the chart is a static 7-day picture.
- Annotating chart points that had insulin/activity recorded — plain glucose
  line only, per the confirmed scope.
- Any client-side JS (AJAX pagination, expand/collapse) — pagination is
  plain `<a href>` links, list rows show full detail inline.

## Implementation Approach

Two new, DB-independent-logic-but-repository-backed services following the
existing `Suggestion`/`Warning` service pattern: `DiaryHistoryService`
(fetches a user's full history, groups by calendar day, paginates 7
day-groups at a time) and `GlucoseHistoryChartService` (fetches the last 7
days via the existing repository method, maps readings to fixed SVG pixel
coordinates plus static zone-band rectangles). Both return readonly result
DTOs. `DiaryController` gains a second action, `history()`, that calls both
services and renders `templates/diary/history.html.twig`, which loops the
DTOs to emit `<svg>` markup directly — no chart library, no JS. A new small
`public/css/diary-chart.css` holds the zone-band/line colors, linked the same
way `pico.min.css` already is.

The full history is fetched and grouped/paginated in PHP on every request,
with no `LIMIT`/`OFFSET` at the SQL level — matching how
`InsulinWwRatioSuggestionService` already fetches and processes a user's
full filtered entry set in PHP. The PRD's `target_scale` (small users, low
QPS, small data volume) makes this the appropriate level of engineering for
an MVP; see Performance Considerations.

## Critical Implementation Details

- **SVG axis mapping is fixed-range, not data-driven**: the chart's Y axis
  always spans a constant `Y_AXIS_MIN_MGDL`–`Y_AXIS_MAX_MGDL` range (pick
  40–300, comfortably wider than the 70/180 zone thresholds on both sides)
  rather than scaling to the entries' actual min/max — this keeps the zone
  bands' pixel positions constant across every render (computed once from
  constants, independent of entries) and makes cross-page/cross-visit charts
  visually comparable. SVG y-coordinates are inverted (`y = 0` is the top),
  so higher glucose values must map to *smaller* `y`; clamp any reading
  outside the axis range to the nearest edge instead of letting it fall
  outside the `viewBox`. X axis maps `measuredAt` linearly across
  `[$now - 7 days, $now]`, left to right.
- **Day-group ordering must stay consistent in both dimensions**:
  `DiaryHistoryService` groups entries fetched in descending `measuredAt`
  order; because the source array is already newest-first, grouping by
  `measuredAt->format('Y-m-d')` naturally yields both newest-day-first
  *and* newest-entry-first-within-a-day without a second sort pass — do not
  re-sort within groups, and do not switch the repository fetch to ascending
  order for this call site.
- **Page-number clamping, not rejection**: `DiaryHistoryService::buildPage()`
  clamps any `$requestedPage` (including 0, negative, or past the last page)
  into `[1, totalPages]` rather than raising an error — an out-of-range
  `?page=` query value should silently show the nearest valid page, matching
  how a patient might land on a stale bookmarked/shared link.

## Phase 1: History & Chart Data Services

### Overview

Add the day-grouping/pagination service and the chart-coordinate service,
plus their result DTOs and the one new repository method. No route,
controller, or template yet — this phase is verified entirely through
`KernelTestCase` service tests and static analysis.

### Changes Required:

#### 1. Repository addition

**File**: `src/Repository/DiaryEntryRepository.php`

**Intent**: Support fetching a user's full history newest-first for
day-grouping/pagination, alongside the existing ascending finder (kept
unchanged, still used by the chart's 7-day window).

**Contract**: New method `findByUserOrderedByMeasuredAtDesc(User $user): array` (`@return DiaryEntry[]`), same shape as the existing
`findByUserOrderedByMeasuredAt()` but `orderBy('e.measuredAt', 'DESC')`, no
`$after` parameter — the history view needs the full set to compute
day-group counts and page boundaries.

#### 2. Day-group and history-page DTOs

**File**: `src/Service/History/DiaryDayGroup.php`

**Intent**: Represent one calendar day's entries for the history list.

**Contract**: Readonly class with `public readonly \DateTimeImmutable $date` and `/** @var DiaryEntry[] */ public readonly array $entries`, constructor-set.

**File**: `src/Service/History/DiaryHistoryPage.php`

**Intent**: Result DTO `DiaryHistoryService` returns, consumed directly by
the controller/template.

**Contract**: Readonly class with `/** @var DiaryDayGroup[] */ public readonly array $dayGroups`, `public readonly int $currentPage`, `public readonly int $totalPages`, `public readonly bool $hasEntries` (true iff the patient has any entry at all across their *entire* history, not just the current page — this is what the template checks to decide whether to render the chart/pagination at all vs. the empty state).

#### 3. DiaryHistoryService

**File**: `src/Service/History/DiaryHistoryService.php`

**Intent**: Turn a user's full entry history into a day-grouped, paginated
result, 7 day-groups per page, following the `InsulinWwRatioSuggestionService`
constructor-injection/DTO-return pattern.

**Contract**: `final class DiaryHistoryService` with `public const DAYS_PER_PAGE = 7;`, constructor-injects `DiaryEntryRepository`, one public method `buildPage(User $user, int $requestedPage): DiaryHistoryPage`. Fetches via `findByUserOrderedByMeasuredAtDesc()`, groups into `DiaryDayGroup[]` keyed by `measuredAt->format('Y-m-d')` (see Critical Implementation Details for ordering), computes `totalPages = max(1, (int) ceil($totalDayGroups / self::DAYS_PER_PAGE))`, clamps `$requestedPage` into `[1, totalPages]`, slices that page's day-groups.

#### 4. Chart point/band DTOs

**File**: `src/Service/Chart/ChartPoint.php`

**Intent**: One plotted glucose reading, pre-computed to SVG pixel coordinates so the template does no math.

**Contract**: Readonly class with `public readonly float $x`, `public readonly float $y`, `public readonly int $glycemiaMgDl`, `public readonly \DateTimeImmutable $measuredAt`.

**File**: `src/Service/Chart/ChartZoneBand.php`

**Intent**: One shaded horizontal band (hipoglikemia/norma/hiperglikemia) for the chart background.

**Contract**: Readonly class with `public readonly float $y`, `public readonly float $height`, `public readonly string $cssClass` (one of `'zone-hipo'`, `'zone-norma'`, `'zone-hiper'`), `public readonly string $label`.

**File**: `src/Service/Chart/GlucoseHistoryChart.php`

**Intent**: Result DTO the template renders directly into an `<svg>` — no coordinate math in Twig.

**Contract**: Readonly class with `public readonly int $viewBoxWidth`, `public readonly int $viewBoxHeight`, `/** @var ChartZoneBand[] */ public readonly array $zoneBands`, `/** @var ChartPoint[] */ public readonly array $points`, `public readonly string $polylinePoints` (pre-joined `"x,y x,y ..."`, ready for `<polyline points="...">`), `public readonly bool $hasPoints`, `/** @var array<array{x: float, label: string}> */ public readonly array $xAxisLabels` (one per day in the window, e.g. `"26.08"`, for a minimal date axis under the chart).

#### 5. GlucoseHistoryChartService

**File**: `src/Service/Chart/GlucoseHistoryChartService.php`

**Intent**: Map the last 7 days of a user's glucose entries onto fixed SVG pixel coordinates with static zone-band shading.

**Contract**: `final class GlucoseHistoryChartService` with constants `HYPO_MAX_MGDL = 69`, `HYPER_MIN_MGDL = 181` (so hipo ≤69, norma 70–180, hiper ≥181 — the confirmed zone bands), plus fixed layout constants (`VIEWBOX_WIDTH`, `VIEWBOX_HEIGHT`, `Y_AXIS_MIN_MGDL = 40`, `Y_AXIS_MAX_MGDL = 300`). Constructor-injects `DiaryEntryRepository`. Method `buildFor(User $user, \DateTimeImmutable $now): GlucoseHistoryChart`: fetches `findByUserOrderedByMeasuredAt($user, $now->modify('-7 days'))`, maps each entry's `measuredAt` linearly across `[$now - 7 days, $now]` for `x` and `glycemiaMgDl` across `[Y_AXIS_MIN_MGDL, Y_AXIS_MAX_MGDL]` (inverted, clamped) for `y`, builds the 3 zone bands once from the same fixed Y scale (independent of entries), and builds 7 evenly-spaced `xAxisLabels`.

### Success Criteria:

#### Automated Verification:

- Unit/kernel tests pass: `docker compose exec php vendor/bin/phpunit --filter "DiaryHistoryServiceTest|GlucoseHistoryChartServiceTest"`
- Static analysis passes: `docker compose exec php vendor/bin/phpstan analyse`
- Code style passes: `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff`

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. (No user-facing surface exists yet in this phase, so there is no manual verification step — automated tests are the full bar here.)

---

## Phase 2: Route, Controller, Template, Navigation

### Overview

Wire the two services into a new `DiaryController::history()` action, render
the day list + inline SVG chart + pagination, add the nav link and the small
chart stylesheet, and cover happy path/empty state/pagination/gate behavior
with `WebTestCase` tests.

### Changes Required:

#### 1. Controller action

**File**: `src/Controller/DiaryController.php`

**Intent**: Read the requested page, call both services, render the history template. Read-only — no form.

**Contract**: `#[Route('/dziennik/historia', name: 'diary_entry_history', methods: ['GET'])]`, `#[IsGranted('ROLE_USER')]`. `$page = max(1, $request->query->getInt('page', 1));` fetch `$historyPage = $diaryHistoryService->buildPage($user, $page)` and `$chart = $glucoseHistoryChartService->buildFor($user, new \DateTimeImmutable())`; render `diary/history.html.twig` with both plus nothing else — no `PatientProfile` lookup needed (this view doesn't use snapshot values, unlike `new()`).

#### 2. Template

**File**: `templates/diary/history.html.twig`

**Intent**: Render the empty state, or the chart + day-grouped list + pagination, following the app's plain-Twig, no-JS style (`templates/dashboard/index.html.twig` is the closest sibling: `<article>` sections, a flash loop, `{% include '_disclaimer.html.twig' %}` only where a recommendation is shown — not needed here since this view shows raw history, not algorithmic advice).

**Contract**: Extends `base.html.twig`. If `not historyPage.hasEntries`: render "Brak wpisów w dzienniczku." plus a link to `path('diary_entry_new')`, nothing else. Otherwise: an `<svg viewBox="0 0 {{ chart.viewBoxWidth }} {{ chart.viewBoxHeight }}">` looping `chart.zoneBands` into `<rect>`s plus one `<text>` per band using `band.label` (positioned inside/beside its band, e.g. `x="4" y="{{ band.y + band.height / 2 }}"`) so each color is legible without relying on hue alone, `chart.xAxisLabels` into `<text>`s, and — only `if chart.hasPoints` — a `<polyline points="{{ chart.polylinePoints }}">` plus one `<circle>` per `chart.points` (each with a `<title>` showing date/time + mg/dL for hover detail); then day headers looping `historyPage.dayGroups`, each listing every entry's time, glycemia, WW, insulin dose, and activity (intensity + duration, or a dash when absent); then "Nowsze"/"Starsze" links to `path('diary_entry_history', {page: ...})`, shown only when a previous/next page exists.

#### 3. Chart stylesheet

**File**: `public/css/diary-chart.css`

**Intent**: Zone-band fill colors and line/point styling for the chart, kept out of inline `style=""` attributes.

**Contract**: Classes `.zone-hipo`, `.zone-norma`, `.zone-hiper` (background fills — e.g. a red/amber/red-ish pair flanking a green "norma" band), `.glycemia-line`, `.glycemia-point`. Linked from `history.html.twig`'s `stylesheets` block the same way `base.html.twig:9` links `pico.min.css`.

#### 4. Navigation link

**File**: `templates/base.html.twig`

**Intent**: Let a logged-in patient reach the history view from anywhere.

**Contract**: Inside the existing nav dropdown (`templates/base.html.twig:34-37`), add `<li><a href="{{ path('diary_entry_history') }}">Historia wpisów</a></li>` alongside the existing "Dodaj wpis"/"Przejdź do profilu" links.

### Success Criteria:

#### Automated Verification:

- Full test suite passes: `docker compose exec php vendor/bin/phpunit`
- Static analysis passes: `docker compose exec php vendor/bin/phpstan analyse`
- Code style passes: `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff`

#### Manual Verification:

- Log in as a patient with no entries: confirm the empty message and link to `/dziennik/nowy`, no chart, no pagination.
- Add entries spanning more than 7 distinct days (some days with multiple entries): confirm day headers, newest-day-first ordering, newest-entry-first within a day, and correct field display (glycemia/WW/insulin/activity, dashes where absent).
- Confirm pagination: with >7 day-groups, "Starsze" appears and moves to older days; "Nowsze" appears on later pages and returns correctly; visiting `?page=999` or `?page=0` directly shows a valid clamped page instead of an error.
- Confirm the chart: entries within the last 7 days plot as a line inside the correct zone band (a <70 reading visibly inside the hipo band, a >180 reading inside the hiper band); a patient whose only entries are older than 7 days sees the bands with no line/points and no error.
- Confirm nav link reaches `/dziennik/historia` from any authenticated page.
- Confirm an unauthenticated visit redirects to login, and an authenticated-but-not-onboarded visit redirects to `/onboarding` (existing gate, unchanged).

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Testing Strategy

### Unit Tests:

- `DiaryHistoryService`: single day groups correctly; multiple same-day
  entries cluster into one group with newest-entry-first ordering; day-group
  ordering is newest-day-first; >7 day-groups paginate correctly (page 1 =
  newest 7 days, page 2 = the rest); `totalPages` computed correctly;
  `$requestedPage` clamps below 1 and above `totalPages`; zero entries yields
  `hasEntries = false` and `totalPages = 1` with an empty `dayGroups`.
- `GlucoseHistoryChartService`: a reading at each zone boundary (69/70,
  180/181) maps to the expected side of the corresponding band; a reading
  older than 7 days is excluded from `points`/`polylinePoints` but bands and
  `xAxisLabels` are still populated (`hasPoints = false` only when the window
  has zero entries); an out-of-axis-range reading clamps to the nearest edge
  instead of an out-of-viewBox coordinate.

### Integration Tests:

- Full page-render flow via `WebTestCase` + `loginUser()`: empty state,
  multi-day history with pagination, zone/point rendering presence; one entry
  with no WW/insulin dose/activity renders the fallback dash for those fields.
- Onboarding gate still redirects a profile-less authenticated user away
  from `/dziennik/historia` (regression smoke test, no gate code changes).

### Manual Testing Steps:

See Phase 2 Manual Verification above.

## Performance Considerations

`DiaryHistoryService`/`GlucoseHistoryChartService` fetch and process a
user's full (or 7-day) entry set in PHP with no SQL-level pagination,
matching the existing `InsulinWwRatioSuggestionService`/
`BaseDoseSuggestionService` pattern. The PRD's `target_scale` (small users,
low QPS, small data volume) makes this the right-sized approach for the MVP;
revisit with DB-level pagination only if usage patterns outgrow that
assumption.

## Migration Notes

No schema changes — reuses the existing `diary_entries` table.

## References

- Roadmap slice: `context/foundation/roadmap.md` (S-05)
- Prior implementation to follow: `context/archive/2026-08-25-log-diary-entry/plan.md`
- Pattern source: `src/Service/Suggestion/InsulinWwRatioSuggestionService.php`, `src/Controller/DiaryController.php`, `src/Controller/DashboardController.php`
- Test pattern source: `tests/Service/Suggestion/InsulinWwRatioSuggestionServiceTest.php`, `tests/Controller/DiaryControllerTest.php`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: History & Chart Data Services

#### Automated

- [x] 1.1 Unit/kernel tests pass — 2504716
- [x] 1.2 Static analysis passes — 2504716
- [x] 1.3 Code style passes — 2504716

### Phase 2: Route, Controller, Template, Navigation

#### Automated

- [x] 2.1 Full test suite passes — 1ddbafc
- [x] 2.2 Static analysis passes — 1ddbafc
- [x] 2.3 Code style passes — 1ddbafc

#### Manual

- [x] 2.4 Empty state verified (no entries, no chart/pagination) — 1ddbafc
- [x] 2.5 Day-grouping and field display verified across multiple days — 1ddbafc
- [x] 2.6 Pagination verified, including out-of-range `?page=` clamping — 1ddbafc
- [x] 2.7 Chart zone shading and point placement verified — 1ddbafc
- [x] 2.8 Nav link verified — 1ddbafc
- [x] 2.9 Auth/onboarding gate behavior on the new route verified — 1ddbafc
