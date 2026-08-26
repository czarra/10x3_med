# S-03: Sugestia skorygowanego przelicznika insulina/WW — Implementation Plan

## Context

DiaGuide's roadmap (`context/foundation/roadmap.md`) names **S-03** (`insulin-ww-ratio-suggestion`) the **north star**: the smallest complete flow that validates the product's core hypothesis — that the system can learn from a patient's historical glycemia data and suggest corrected dosing parameters, rather than acting as a rigid calculator. It is literally the only fully-specified user story in the PRD (`US-01`), with concrete acceptance criteria (FR-009, FR-011).

S-01 (patient onboarding) and S-02 (diary entry logging) are both already shipped (`done` in the roadmap) and exist specifically to feed this slice: `DiaryEntry` snapshots the profile's ratio/base-dose at write time and stores raw glycemia/WW/insulin/activity data, and `log-diary-entry/plan.md` explicitly says *"this plan only stores the snapshot values [S-03] will read."* Nothing in the codebase computes a suggestion yet — no service layer exists at all.

A `research.md` (`context/changes/insulin-ww-ratio-suggestion/research.md`) already investigated the codebase and PRD in depth and flagged several open design questions (meal-pairing window, whether to build the PRD's second, unscoped "base-dose" algorithm, threshold values, where suggestions surface). This plan resolves every one of those questions through direct discussion with the product owner — including two live corrections mid-design (WW-normalizing the ratio step, and converting `baseDose` to an integer with a fixed-target base-dose formula) — and is now fully decided, with worked numeric examples verified against the chosen formulas.

## Overview

Implement **two** dose-suggestion algorithms (both scoped in, per product owner decision — the PRD's second "base-dose" algorithm has no FR number but was explicitly pulled into scope):

1. **Insulin/WW ratio suggestion** (US-01/FR-009, the roadmap-scoped one) — analyzes the glycemia delta between a meal and its 2h-after reading across the last 3 complete meal pairs, and suggests a WW-normalized ratio adjustment.
2. **Base-dose suggestion** (PRD narrative only, no FR/AC — implemented anyway per product owner) — analyzes 3 consecutive days of fasting glycemia against a fixed target, and suggests a whole-unit base-dose adjustment.

Both surface as recommendation cards with a persistent medical disclaimer (FR-011) on a new `/pulpit` dashboard route, each with a single "Accept" action (no dismiss) that writes to `PatientProfile` and appends an immutable history record.

## Current State Analysis

- **`PatientProfile`** (`src/Entity/PatientProfile.php`): one row per user, `baseDose: float`, `insulinWwRatio: float`, setters bump `updatedAt`. `findOneByUser()` is the only repository method.
- **`DiaryEntry`** (`src/Entity/DiaryEntry.php`): `glycemiaMgDl` (int), `measuredAt`, `ww`/`insulinDose` (nullable floats), `activityIntensity`/`activityDurationMinutes` (nullable), `insulinWwRatioSnapshot`/`baseDoseSnapshot` (non-null floats, captured from the profile at construction time by `DiaryController`). `DiaryEntryRepository` has **zero** custom query methods.
- **No `src/Service/` directory exists** — this slice creates the first service layer in the app.
- **No dashboard/history view exists** — only `/onboarding`, `/profil`, `/dziennik/nowy` (create-only).
- Established conventions (from S-01/S-02, confirmed by direct file reads): `#[IsGranted('ROLE_USER')]` + end-anchored `access_control` regex on every protected route; `findOneByUser()`-style repository lookups (never a bidirectional Doctrine collection on `User`); Polish flash messages (`addFlash('success', …)`, only `'success'` is ever read in any template); named-argument constructors for entities with 2+ adjacent same-typed params (established by the `DiaryEntry` review); `KernelTestCase`/`WebTestCase` + `uniqid()`-suffixed emails + raw-`DELETE` cleanup in tests; `RequireOnboardingSubscriber` gates every authenticated route except `patient_onboarding`/`app_logout` — a profile-less user is redirected to onboarding automatically, before any new controller body runs.

## Desired End State

A logged-in, onboarded patient with enough diary history sees a `/pulpit` page (linked from the nav) showing:
- A ratio-suggestion card (or a neutral "not enough data / well matched" message) with an Accept button and a persistent disclaimer.
- A base-dose-suggestion card (or the same neutral message) with an Accept button and the same disclaimer.

Accepting a card updates the corresponding `PatientProfile` field and is never re-suggested from the same underlying data (only new diary entries logged after acceptance count toward the next suggestion).

### Key Discoveries

- `PatientProfile::setBaseDose()`/`setInsulinWwRatio()` already exist and bump `updatedAt` — direct reuse, no new profile-write path needed (`src/Entity/PatientProfile.php:61-80`).
- `security.yaml`'s `access_control` (`config/packages/security.yaml:29-30`) and `form_login.default_target_path` both currently point at `patient_profile` / `/profil` — **the product owner decided NOT to change the landing page**, so neither `HomeController` nor `default_target_path` change in this plan; `/pulpit` is reached only via a new nav link.
- `RequireOnboardingSubscriber` (`src/EventSubscriber/RequireOnboardingSubscriber.php`) needs no special-casing for the new routes — it already redirects any profile-less authenticated user to onboarding before any controller runs; `DashboardController` still defensively re-fetches the profile and 404s if absent, mirroring `DiaryController`.

## What We're NOT Doing

- No dismiss/reject action on a suggestion card — only Accept (matches US-01's AC, which specifies exactly one button).
- No persisted "pending" suggestion state — suggestions are always computed live from `DiaryEntry` history; only **accepted** adjustments are persisted, as an append-only history log.
- No change to `HomeController`'s `/` redirect or `security.yaml`'s `default_target_path` — the dashboard is reached via nav link only, not made the login landing page.
- No CGM integration, no automatic dose administration, no multi-insulin-type support — all out of scope per PRD Non-Goals, unaffected by this slice.
- No configurable-via-UI constants — thresholds/scaling factors are named PHP constants, not admin-editable settings.

## Implementation Approach

Two independent, cohesive services (`InsulinWwRatioSuggestionService`, `BaseDoseSuggestionService`) under a new `App\Service\Suggestion` namespace, each backed by its own append-only history entity/repository (providing the "only count entries after last acceptance" cutoff) and sharing a single `SuggestionScaling::FACTOR` constant so the two algorithms' proportional-step math can't silently drift apart. Both read from one new `DiaryEntryRepository` query method and do all pairing/grouping logic in-memory (fine at MVP data volumes). A single new `DashboardController` renders both cards and handles both accept actions.

Because this plan also converts `PatientProfile::$baseDose` / `DiaryEntry::$baseDoseSnapshot` from `float` to `int` (a correction discovered mid-design, driven by the base-dose algorithm needing whole-unit suggestions), it touches already-shipped S-01/S-02 code. That conversion is sequenced as its own first phase, independently verifiable via the full existing test suite, before any new feature code is written on top of it.

## Critical Implementation Details

**Float→int deprecation trap (Phase 1)**: PHPUnit runs with `failOnDeprecation="true"` (`phpunit.dist.xml`). Once `baseDose`/`baseDoseSnapshot` constructor params become `int`, any call site still passing a fractional float literal (e.g. `12.5`) triggers `Deprecated: Implicit conversion from float to int loses precision`, which **fails the whole suite**, not just that assertion. Every such literal must become an integer literal, not just have its assertion updated — this affects `tests/Entity/PatientProfileTest.php`, `tests/Entity/DiaryEntryTest.php`, `tests/Controller/DiaryControllerTest.php`, `tests/Controller/ProfileControllerTest.php`, `tests/Controller/OnboardingControllerTest.php`, `tests/Controller/HomeControllerTest.php`.

**Postgres migration cast**: `ALTER COLUMN ... TYPE INT` on an existing `DOUBLE PRECISION` column fails outright in Postgres without an explicit cast. Both altered columns need `USING ROUND(column)::INTEGER`.

**Meal-pair "after reading" tie-break**: if more than one `DiaryEntry` falls inside the ±30min window around `meal.measuredAt + 2h`, take the one closest to the exact target instant, tie-broken by earliest `measuredAt`.

**Base-dose fasting-gap must use unfiltered history, not the cutoff-filtered list**: the ratio algorithm can safely fetch only entries after the re-trigger cutoff, because both halves of a meal pair should be "new" data anyway. The base-dose algorithm is different — "is this the first entry of the day, ≥6h since the last insulin/WW-bearing entry" is a property of the *full* timeline, independent of the re-trigger cutoff. `BaseDoseSuggestionService` must fetch the user's **complete** ordered `DiaryEntry` history (no `$after` param) to correctly classify fasting candidates, and only *then* restrict which calendar days are eligible to count toward the 3-consecutive-day streak to those whose date falls after the cutoff date. Getting this backwards (filtering entries before classifying) would misclassify a post-cutoff entry as "first of day" when a real, disqualifying entry actually preceded it before the cutoff.

**No prior insulin/WW-bearing entry at all**: the ≥6h gap condition is vacuously satisfied (nothing to violate) — document this as an explicit comment/invariant in `BaseDoseSuggestionService`, not a silent default.

**Streak-breaking**: "3 literal consecutive calendar days" breaks not only on an in-band reading but equally on a calendar date with **no entries at all** (no fasting candidate that day). Both must be treated as streak-breaks.

**Defensive range clamp on suggested values**: `PatientProfile`'s own validation constraints (`insulinWwRatio` ∈ [0.1, 10.0], `baseDose` ∈ (0, 35]) are enforced by the Symfony Validator only inside a `Form`-driven flow (`isValid()`). The accept actions in this plan write directly via setter + `flush()`, bypassing that validator entirely. Since a current value near the top of its range plus a computed step could overshoot (e.g. ratio 9.9 + step 0.3 = 10.2), both services must clamp their final suggested value to the entity's own valid range (`[0.1, 10.0]` for ratio, `[1, 35]` for base dose) before returning it — this is a correctness requirement, not just a nicety, since an unclamped write would violate the entity's own documented invariants.

**Accept-time re-derivation, not trust of posted values**: the accept controller actions must re-run the suggestion service server-side and use *that* result, never a value posted from the form — closes a staleness/tamper gap between page render and click. If the re-derived suggestion is no longer available (race), redirect back to `/pulpit` with no DB write and no flash message — introducing a new non-`'success'` flash type for this rare edge case is unnecessary scope.

**Shared scaling constant**: `RATIO_SCALING_FACTOR` and the base-dose algorithm's scaling factor are the *same* `0.02` value by design (documented ISF≈50mg/dL/unit simplification) — hold it once in `SuggestionScaling::FACTOR` and have both services reference it, so a future tuning change can't silently desync the two algorithms.

**PRD's "computation in progress" NFR**: `prd.md`'s Non-Functional Requirements require visible feedback if trend computation takes longer than 500ms. This is satisfied trivially by this design — `/pulpit` is a synchronous, full-page GET render (not AJAX), so the browser's native loading indicator covers it; no custom spinner/progress UI is needed at MVP data volumes.

---

## Phase 1: `baseDose` float → int conversion

### Overview

Converts `PatientProfile::$baseDose` and `DiaryEntry::$baseDoseSnapshot` from `float` to `int`, required because base-dose suggestions must be whole-unit (the product owner corrected the original proportional-float design specifically for this reason). Lands and is fully verified independently before any new feature code exists, since it touches already-shipped S-01/S-02 entities.

### Changes Required:

#### 1. Entities

**File**: `src/Entity/PatientProfile.php`

**Intent**: `$baseDose` becomes `int` throughout (property, constructor param, getter, setter). Validation attributes (`Assert\Positive`, `Assert\LessThanOrEqual(35)`) are unchanged — both work identically on `int`.

**Contract**: `getBaseDose(): int`, `setBaseDose(int $baseDose): static`, constructor signature `__construct(User $user, int $baseDose, float $insulinWwRatio)`.

**File**: `src/Entity/DiaryEntry.php`

**Intent**: `$baseDoseSnapshot` becomes `int`, mirroring the profile field it snapshots. `$insulinWwRatioSnapshot` stays `float` — unaffected.

**Contract**: `getBaseDoseSnapshot(): int`, constructor param `int $baseDoseSnapshot`. `DiaryController::new()` needs no code change — it passes `$profile->getBaseDose()` through directly, which becomes type-consistent automatically once both sides are `int`.

#### 2. Form

**File**: `src/Form/ProfileFormType.php`

**Intent**: `baseDose` field switches from a fractional-accepting number field to a whole-unit field.

**Contract**: `->add('baseDose', IntegerType::class, ['label' => 'Dawka bazowa (j.)'])`, importing `Symfony\Component\Form\Extension\Core\Type\IntegerType` (keep the existing `NumberType` import — `insulinWwRatio` still uses it).

#### 3. Migration

**File**: `migrations/VersionYYYYMMDDHHMMSS.php` (new)

**Intent**: Alter both affected columns' Postgres type.

**Contract**:
```php
public function up(Schema $schema): void
{
    $this->addSql('ALTER TABLE patient_profiles ALTER base_dose TYPE INT USING ROUND(base_dose)::INTEGER');
    $this->addSql('ALTER TABLE diary_entries ALTER base_dose_snapshot TYPE INT USING ROUND(base_dose_snapshot)::INTEGER');
}

public function down(Schema $schema): void
{
    $this->addSql('ALTER TABLE patient_profiles ALTER base_dose TYPE DOUBLE PRECISION');
    $this->addSql('ALTER TABLE diary_entries ALTER base_dose_snapshot TYPE DOUBLE PRECISION');
}
```
Generate via `bin/console doctrine:migrations:diff` after the entity edits land, then hand-verify the `USING` clause is present (the diff tool may not add it automatically). Apply to **both** the dev DB and the `database-test` service (`APP_ENV=test`) before running the suite — never via `./run-dev.sh` (destructive, tears down containers).

#### 4. Existing test updates

**Files**: `tests/Entity/PatientProfileTest.php`, `tests/Entity/DiaryEntryTest.php`, `tests/Controller/DiaryControllerTest.php`, `tests/Controller/ProfileControllerTest.php`, `tests/Controller/OnboardingControllerTest.php`, `tests/Controller/HomeControllerTest.php`, `tests/Controller/SecurityControllerTest.php`

**Intent**: Every fractional `baseDose`/`baseDoseSnapshot` literal (PHP literal or form-submitted string) becomes a whole number, per the deprecation trap noted in Critical Implementation Details. Whole-number floats (e.g. `10.0`) should also be normalized to int literals for consistency even though they don't trigger the deprecation.

**Contract**: Find every call site via `grep -rn "baseDose\|new PatientProfile(\|new DiaryEntry(" tests/` — plain `grep -rn "baseDose" tests/` misses positional-argument constructions that don't name the field (e.g. `new PatientProfile($user, 10.0, 1.0)`). Representative examples: `PatientProfileTest.php`'s `new PatientProfile($user, 12.5, 1.2)` → `new PatientProfile($user, 13, 1.2)` (and its paired `assertSame`); `DiaryEntryTest.php`'s `baseDoseSnapshot: 12.5` → `baseDoseSnapshot: 13`; `DiaryControllerTest.php`'s `createProfile(...)` helper signature changes its `float $baseDose` param to `int`; `OnboardingControllerTest.php`'s form-submitted `'profile_form[baseDose]' => '12.5'` → `'12'`; `SecurityControllerTest.php`'s two `new PatientProfile($user, 10.0, 1.0)` (lines 21, 72) → `new PatientProfile($user, 10, 1.0)`.

### Success Criteria:

#### Automated Verification:

- Migration applies cleanly on both dev and test DBs: `docker compose exec php bin/console doctrine:migrations:migrate --env=dev` and `--env=test`
- Static analysis passes: `docker compose exec php vendor/bin/phpstan analyse`
- Code style passes: `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run`
- Full existing test suite passes with zero deprecation failures: `docker compose exec php vendor/bin/phpunit`

#### Manual Verification:

- `/profil` edit form only accepts whole numbers for "Dawka bazowa" (browser-level number input behavior)

---

## Phase 2: History entities, repositories, migration

### Overview

Append-only audit log for accepted suggestions — provides both the re-trigger cutoff (only entries after the last acceptance count toward the next suggestion) and a durable record for future features (e.g. S-07's export, which references "sugerowane przeliczniki").

### Changes Required:

#### 1. Entities

**File**: `src/Entity/RatioAdjustmentHistory.php` (new)

**Intent**: Immutable record of one accepted ratio change.

**Contract**: `id`, `user` (ManyToOne, not-null), `oldRatio` (float), `newRatio` (float), `acceptedAt` (DateTimeImmutable). Constructor takes all four; every call site uses named arguments (`new RatioAdjustmentHistory(user: ..., oldRatio: ..., newRatio: ..., acceptedAt: ...)`) per the codebase's established convention for entities with 2+ adjacent same-typed constructor params. Getters only, no setters — same "immutable snapshot" shape as `DiaryEntry`'s ratio/base-dose snapshot fields.

**File**: `src/Entity/BaseDoseAdjustmentHistory.php` (new)

**Intent**: Same shape, for base-dose changes.

**Contract**: Identical to above with `oldBaseDose`/`newBaseDose` as `int`.

#### 2. Repositories

**File**: `src/Repository/RatioAdjustmentHistoryRepository.php` (new)

**Intent**: Supplies the re-trigger cutoff for the ratio algorithm.

**Contract**: `findLatestByUser(User $user): ?RatioAdjustmentHistory` — ordered by `acceptedAt DESC`, `setMaxResults(1)`.

**File**: `src/Repository/BaseDoseAdjustmentHistoryRepository.php` (new)

**Intent**: Same, for base-dose.

**Contract**: `findLatestByUser(User $user): ?BaseDoseAdjustmentHistory`, identical shape.

#### 3. Migration

**File**: `migrations/VersionYYYYMMDDHHMMSS.php` (new, after Phase 1's)

**Intent**: Create both history tables.

**Contract**: Two `CREATE TABLE` statements — `ratio_adjustment_histories` and `base_dose_adjustment_histories` (pluralized per the existing `users`/`patient_profiles`/`diary_entries` naming convention), each with `id`, its two value columns, `accepted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL`, `user_id INT NOT NULL` with a **non-unique** index (a user can have many history rows, unlike `patient_profiles`' unique-per-user index) and an FK to `users(id)`, matching `diary_entries`' migration shape. Generate via `bin/console doctrine:migrations:diff` against the mapped entities rather than hand-writing constraint/index names.

### Success Criteria:

#### Automated Verification:

- Migration applies cleanly on dev and test DBs
- `docker compose exec php vendor/bin/phpstan analyse` passes
- `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run` passes

#### Manual Verification:

- N/A (no user-facing surface yet — verified through Phase 5/6)

---

## Phase 3: `DiaryEntryRepository` query addition

### Overview

The single new data-access seam both suggestion services depend on.

### Changes Required:

#### 1. Repository method

**File**: `src/Repository/DiaryEntryRepository.php`

**Intent**: Fetch a user's diary history in chronological order, optionally bounded by a cutoff timestamp. Both services do all pairing/grouping logic in-memory over the returned array.

**Contract**: `findByUserOrderedByMeasuredAt(User $user, ?\DateTimeImmutable $after = null): array` (returns `DiaryEntry[]`), ascending by `measuredAt`; when `$after` is given, only entries with `measuredAt > $after`. Called with `$after = null` by `BaseDoseSuggestionService` (per the Critical Implementation Details note on why base-dose needs unfiltered history) and with the ratio cutoff by `InsulinWwRatioSuggestionService`.

### Success Criteria:

#### Automated Verification:

- `docker compose exec php vendor/bin/phpstan analyse` passes
- New repository method covered by at least one test in Phase 6 (via the services that call it)

#### Manual Verification:

- N/A

---

## Phase 4: Suggestion service layer

### Overview

The two algorithms, fully specified and worked-example-verified with the product owner. First service classes in the app — establishes the `App\Service\Suggestion` namespace as the home for all business-rule computation, matching the "thin controller, fat validation/services" direction the codebase's existing thin-controller style implies but has never needed until now.

### Changes Required:

#### 1. Shared scaling constant

**File**: `src/Service/Suggestion/SuggestionScaling.php` (new)

**Intent**: Single source of truth for the proportional-step scaling factor both algorithms use, so tuning one can't silently desync the other.

**Contract**: `final class SuggestionScaling { public const FACTOR = 0.02; }`

#### 2. Result DTOs

**File**: `src/Service/Suggestion/RatioSuggestionResult.php` (new)

**Intent**: Typed, immutable result — avoids raw arrays/nullable-float soup in the controller/template. Carries a human-readable `context` string so the template can satisfy FR-009's "jasny kontekst" requirement (e.g. *"Ostatnie 3 posiłki poskutkowały zbyt wysoką glikemią po posiłku."*) without re-deriving the trigger direction itself.

**Contract**: Named-constructor pair `RatioSuggestionResult::suggest(float $currentRatio, float $suggestedRatio, string $context): self` and `::none(): self`, exposing `readonly bool $available`, `readonly ?float $currentRatio`, `readonly ?float $suggestedRatio`, `readonly ?string $context` (`null` on `::none()`).

**File**: `src/Service/Suggestion/BaseDoseSuggestionResult.php` (new)

**Intent**: Same shape for base-dose.

**Contract**: `::suggest(int $currentBaseDose, int $suggestedBaseDose, string $context): self` / `::none(): self`, `?int` fields, `readonly ?string $context`.

#### 3. Ratio suggestion algorithm

**File**: `src/Service/Suggestion/InsulinWwRatioSuggestionService.php` (new)

**Intent**: Implements the WW-normalized ratio-correction algorithm, verified against four worked examples with the product owner.

**Contract**: `suggestFor(User $user, PatientProfile $profile): RatioSuggestionResult`. Named constants: `REQUIRED_PAIRS = 3`, `AFTER_MEAL_TARGET_MINUTES = 120`, `AFTER_MEAL_TOLERANCE_MINUTES = 30`, `RATIO_THRESHOLD_MGDL = 45`, `RATIO_MIN_STEP = 0.05`, `RATIO_MAX_STEP = 0.3`, `RATIO_STEP_ROUNDING = 0.05`.

Algorithm, precisely:
1. `$cutoff = ratioAdjustmentHistoryRepository->findLatestByUser($user)?->getAcceptedAt()`.
2. `$entries = diaryEntryRepository->findByUserOrderedByMeasuredAt($user, $cutoff)`.
3. A **meal entry** is any entry with `ww !== null && insulinDose !== null` (its own `glycemiaMgDl`/`measuredAt` is the "before" reading). Its **matched after-reading** is the entry (any type) whose `measuredAt` is nearest to `meal.measuredAt + 120min`, constrained to the `±30min` window; ties broken by earliest `measuredAt`. A meal with no reading in that window is excluded entirely (not counted as a pair).
4. Take the **last 3** complete pairs (chronologically most recent, all necessarily after the cutoff since step 2 already filtered). Fewer than 3 → `RatioSuggestionResult::none()`.
5. For each pair, `delta = after.glycemiaMgDl - before.glycemiaMgDl`. **Majority rule**: at least 2 of the 3 pairs must have `|delta| > 45` in the same direction (both rise or both fall) — otherwise `::none()`.
6. **Magnitude**, computed only over the pairs that satisfied the direction condition (2 or 3 of them): `nadwyżka_i = delta_i - sign(delta_i) × 45`, `nadwyżka_per_ww_i = nadwyżka_i / meal.ww`. Average → `avg`. `krok_raw = avg × SuggestionScaling::FACTOR`. `krok = clamp(krok_raw, 0.05, 0.3)`, rounded to the nearest 0.05.
7. `new_ratio = current_ratio ± krok` (sign matches the trigger direction), then **clamped to `[0.1, 10.0]`** (the entity's own valid range — see Critical Implementation Details) before being returned.
8. `context` string (FR-009): `"Ostatnie 3 posiłki poskutkowały zbyt wysoką glikemią po posiłku."` when the trigger direction is a rise, `"Ostatnie 3 posiłki poskutkowały zbyt niską glikemią po posiłku."` when it's a fall. Passed to `RatioSuggestionResult::suggest()` alongside the ratio values.

**Worked examples this must reproduce exactly** (verified with product owner):
- Meals (before=100,after=180,WW=4), (before=110,after=185,WW=5), (before=95,after=170,WW=3) → deltas +80/+75/+75, all >45 → nadwyżka/WW: 8.75/6.00/10.00 → avg=8.25 → krok_raw=0.165 → round to 0.15. Ratio 1.0 → **1.15**.
- Same deltas (+80 each), all WW=2 → nadwyżka/WW=17.5 each → krok_raw=0.35 → clamped to max 0.3. Ratio 1.0 → **1.3**.
- Deltas +60 (rise, >45), −50 (fall, opposite direction), +30 (below threshold) → no majority in same direction → **none()**.
- Falling: (before=150,after=90,WW=5), (before=140,after=85,WW=6), (before=160,after=100,WW=4) → deltas −60/−55/−60, all exceed −45 → nadwyżka/WW: 3.00/1.67/3.75 → avg=2.81 → krok_raw=0.056 → round to 0.05 (min-step floor). Ratio 1.0 → **0.95**.

#### 4. Base-dose suggestion algorithm

**File**: `src/Service/Suggestion/BaseDoseSuggestionService.php` (new)

**Intent**: Implements the fasting-glycemia base-dose algorithm against a fixed target of 120 mg/dL, verified against four worked examples with the product owner.

**Contract**: `suggestFor(User $user, PatientProfile $profile): BaseDoseSuggestionResult`. Named constants: `FASTING_GAP_HOURS = 6`, `TARGET_GLYCEMIA = 120`, `BAND_LOW = 95`, `BAND_HIGH = 145`, `STEP_CLAMP_MIN = -2.0`, `STEP_CLAMP_MAX = 2.0`, `MIN_MAGNITUDE = 0.5`, `REQUIRED_CONSECUTIVE_DAYS = 3`.

Algorithm, precisely:
1. `$cutoffDate = baseDoseAdjustmentHistoryRepository->findLatestByUser($user)?->getAcceptedAt()`.
2. `$entries = diaryEntryRepository->findByUserOrderedByMeasuredAt($user)` — **unfiltered**, full history (see Critical Implementation Details for why).
3. Group entries by calendar date. For each date, the **fasting reading** is that date's first entry (by `measuredAt`), *if* the gap since the most recent prior entry (any earlier date/time in the full history) with `ww !== null || insulinDose !== null` is `>= 6 hours`. If no such prior entry exists anywhere in history, the condition is vacuously satisfied. The fasting entry's *own* `ww`/`insulinDose` values do not disqualify it.
4. Restrict candidate dates to those strictly after `$cutoffDate` (if set). Scan for the most recent run of **3 literally consecutive calendar dates**, each with a qualifying fasting reading, each reading outside `[95, 145]`, all in the same direction. A date with no fasting candidate at all (missing day) breaks the streak exactly like an in-band reading does. No such run → `BaseDoseSuggestionResult::none()`.
5. `nadwyżka_i = fasting_i - 120` (signed, against the fixed target — not the band edge) for the 3 days in the run. `avg = average(nadwyżka_i)`. `krok_raw = avg × SuggestionScaling::FACTOR`, clamped to `[-2.0, 2.0]`.
6. If `|krok_raw| <= 0.5` → `::none()` (documented defensive invariant — see Critical Implementation Details on why this is mathematically near-guaranteed to pass once step 4's trigger fires, but must still be checked explicitly, not assumed).
7. `krok = (int) round(krok_raw)` (PHP's default half-away-from-zero rounding). `new_base_dose = current_base_dose + krok`, then **clamped to `[1, 35]`** before being returned.
8. `context` string (FR-009): `"Poziom cukru na czczo przez ostatnie 3 dni był zbyt wysoki."` when the 3-day run is above the band, `"Poziom cukru na czczo przez ostatnie 3 dni był zbyt niski."` when below. Passed to `BaseDoseSuggestionResult::suggest()` alongside the base-dose values.

**Worked examples this must reproduce exactly**:
- Fasting days 160, 155, 170 (all >145, 3 consecutive) → avg=161.67 → nadwyżka avg=41.67 → krok_raw=0.833 → round → +1. Base dose 10 → **11**.
- Fasting days 80, 75, 85 (all <95) → avg=80 → nadwyżka avg=−40 → krok_raw=−0.8 → round → −1. Base dose 10 → **9**.
- Fasting days 250, 240, 260 (all >145) → avg=250 → nadwyżka avg=130 → krok_raw=2.6 → clamp to 2.0 → round → +2. Base dose 10 → **12**.
- Fasting days 150 (>145), 110 (inside [95,145] — breaks streak), 160 (>145) → **none()**.

### Success Criteria:

#### Automated Verification:

- `docker compose exec php vendor/bin/phpstan analyse` passes
- All Phase 6 unit tests for both services pass (see Phase 6 — written alongside/immediately after this phase)

#### Manual Verification:

- N/A (no UI yet — verified through Phase 5/6)

---

## Phase 5: Controller, routes, templates, security wiring

### Overview

Surfaces both suggestions on a new `/pulpit` dashboard, wires the Accept actions, and extends access control. Per product-owner decision, this does **not** change the login/home landing page — `/pulpit` is reached only via a new nav link.

### Changes Required:

#### 1. Controller

**File**: `src/Controller/DashboardController.php` (new)

**Intent**: Renders both suggestion cards; handles both accept actions with server-side re-derivation (never trusting posted values) and CSRF protection.

**Contract**:
- `#[Route('/pulpit', name: 'patient_dashboard', methods: ['GET'])]`, `#[IsGranted('ROLE_USER')]` — defensively re-fetches the profile via `PatientProfileRepository::findOneByUser()` (404 if absent, mirroring `DiaryController`), calls both services, renders both results.
- `#[Route('/pulpit/przelicznik/akceptuj', name: 'patient_dashboard_accept_ratio', methods: ['POST'])]`, `#[IsGranted('ROLE_USER')]` — validates CSRF token (`isCsrfTokenValid('accept_ratio_suggestion', ...)`), re-runs `InsulinWwRatioSuggestionService::suggestFor()`; if still `available`, calls `PatientProfile::setInsulinWwRatio()`, persists `new RatioAdjustmentHistory(user: ..., oldRatio: ..., newRatio: ..., acceptedAt: new \DateTimeImmutable())`, flushes, flashes `'success'`, redirects to `patient_dashboard`. If no longer available (race), redirects with no DB write and no flash.
- `#[Route('/pulpit/dawka-bazowa/akceptuj', name: 'patient_dashboard_accept_base_dose', methods: ['POST'])]` — mirrors the above for `BaseDoseSuggestionService`/`PatientProfile::setBaseDose()`/`BaseDoseAdjustmentHistory`.

#### 2. Template

**File**: `templates/dashboard/index.html.twig` (new)

**Intent**: Two cards (or neutral status messages when `!available`), each with a plain POST form (hidden CSRF input, no full Symfony FormType needed for a single-button action — mirrors the existing logout-form pattern) and the FR-011 disclaimer directly beneath.

**Contract**: Extends `base.html.twig`. Each available card shows its `context` string (FR-009's "jasny kontekst") directly above the current→suggested value. Disclaimer copy appears under both cards identically (informational/educational framing, referencing that this is an algorithmic suggestion requiring doctor consultation, per FR-011's exact wording). Accept button labels: ratio card uses US-01's exact AC wording, "Zapisz nowy przelicznik w profilu"; base-dose card (no AC constraint) uses the analogous "Zapisz nową dawkę bazową w profilu".

**File**: `templates/base.html.twig`

**Intent**: Add a nav link to the dashboard.

**Contract**: `<li><a href="{{ path('patient_dashboard') }}">Pulpit</a></li>` alongside the existing profile/diary links.

#### 3. Security

**File**: `config/packages/security.yaml`

**Intent**: Extend access control to cover the new routes.

**Contract**: `access_control` regex becomes `^/(onboarding|profil)$|^/dziennik(/|$)|^/pulpit(/|$)` — `pulpit` needs prefix-matching (`/|$`) since it has POST sub-routes, matching the existing `dziennik` style.

### Success Criteria:

#### Automated Verification:

- `docker compose exec php vendor/bin/phpstan analyse` passes
- `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run` passes
- Phase 6 controller tests pass

#### Manual Verification:

- Log in as a patient with <3 complete meal pairs and no fasting history → `/pulpit` shows both neutral messages, no crash
- Log in as a patient whose diary data satisfies the ratio worked example → `/pulpit` shows the ratio card with the correct suggested value and disclaimer; clicking Accept updates `/profil`'s displayed ratio and the card disappears/shows neutral state on next visit
- Same for the base-dose worked example
- Nav link to `/pulpit` visible and working from any authenticated page

---

## Phase 6: Testing

### Overview

Unit tests for both algorithms (reproducing every worked example above exactly) plus controller/integration tests for the accept flow and access control.

### Changes Required:

#### 1. Service unit tests

**File**: `tests/Service/Suggestion/InsulinWwRatioSuggestionServiceTest.php` (new)

**Intent**: `KernelTestCase` + `EntityManagerInterface`, `uniqid()`-suffixed emails, raw-`DELETE` cleanup — matching established entity-test conventions.

**Contract**: One test per worked example (1.0→1.15, 1.0→1.3 max-clamp, mixed-direction→none, falling 1.0→0.95 min-step-floor), plus: fewer-than-3-pairs→none, unmatched meal entry excluded from pairing, re-trigger cutoff excludes pre-acceptance pairs, final-value clamp to `[0.1, 10.0]` when a step would overshoot, `context` string matches the rise/fall wording for each direction.

**File**: `tests/Service/Suggestion/BaseDoseSuggestionServiceTest.php` (new)

**Intent**: Same conventions.

**Contract**: One test per worked example (10→11, 10→9, 10→12 max-clamp, streak-broken-by-in-band-day→none), plus: streak broken by a missing calendar day (no entries at all), fewer-than-3-day run anywhere→none, re-trigger cutoff respected, fasting-gap vacuous-when-no-prior-entry case, final-value clamp to `[1, 35]`, `context` string matches the above/below-band wording for each direction.

#### 2. Controller tests

**File**: `tests/Controller/DashboardControllerTest.php` (new)

**Intent**: `WebTestCase`, mirroring `DiaryControllerTest`'s `createUser`/`createProfile`/`cleanupUser` helper pattern, extended to also clean up the two new history tables.

**Contract**: `GET /pulpit` with insufficient data shows both neutral messages; `GET /pulpit` with a qualifying scenario shows the card + disclaimer; `POST` to each accept route updates the profile, persists a history row, redirects with a success flash; accept POST when the suggestion is no longer available makes no DB changes; profile-less authenticated user hitting `/pulpit` is redirected to `/onboarding` (mirrors `DiaryControllerTest`'s equivalent case); invalid/missing CSRF token on an accept POST is rejected.

### Success Criteria:

#### Automated Verification:

- Full suite passes: `docker compose exec php vendor/bin/phpunit`
- `docker compose exec php vendor/bin/phpstan analyse` passes
- `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run` passes

#### Manual Verification:

- All Phase 5 manual verification steps re-confirmed after tests land, no regressions in `/onboarding`, `/profil`, `/dziennik/nowy`, `/` flows

---

## Testing Strategy

### Unit Tests

- Every worked example from Phase 4 (8 total across both algorithms) as an exact-value regression test.
- Boundary conditions: majority-rule edge (exactly 2/3), min-step floor, max-step clamp, entity-range clamp, streak-breaking by both an in-band day and a missing day, vacuous fasting-gap case, unmatched meal-pair exclusion.

### Integration Tests

- Full accept flow (GET dashboard → POST accept → profile updated → history row persisted → dashboard reflects the new state) for both algorithms.
- Access-control: profile-less user redirected to onboarding; unauthenticated user redirected to login.

### Manual Testing Steps

1. Seed a test patient with 3 meal entries matching the ratio "rise" worked example via `/dziennik/nowy`; visit `/pulpit`; confirm the card shows the exact predicted new ratio and disclaimer; click Accept; confirm `/profil` reflects the change.
2. Seed 3 consecutive days of fasting readings matching the base-dose worked example; repeat the same check for the base-dose card.
3. Confirm a patient with insufficient data sees neutral messages, not an error.
4. Confirm accepting a suggestion, then immediately re-visiting `/pulpit`, does not re-show the same suggestion (re-trigger cutoff working).

## Migration Notes

Both migrations in this plan (Phase 1's type conversion, Phase 2's new tables) must be applied to **both** the dev database and the `database-test` service (`APP_ENV=test`) separately, per `AGENTS.md` — never via `./run-dev.sh`, which is destructive (tears down running containers before rebuilding).

## References

- Research: `context/changes/insulin-ww-ratio-suggestion/research.md`
- Roadmap entry: `context/foundation/roadmap.md:170-185` (S-03)
- PRD: `context/foundation/prd.md` (FR-009/010/011, US-01, Business Logic section)
- Reused setters: `src/Entity/PatientProfile.php:61-80`
- Snapshot precedent: `src/Entity/DiaryEntry.php` (constructor + named-argument convention)
- Access control precedent: `config/packages/security.yaml:29-30`
- Onboarding gate: `src/EventSubscriber/RequireOnboardingSubscriber.php`
- Prior slice's forward note: `context/archive/2026-08-25-log-diary-entry/plan.md` ("What We're NOT Doing" — explicitly defers S-03's logic to this plan)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands.

### Phase 1: baseDose float → int conversion

#### Automated

- [x] 1.1 Migration applies cleanly on dev and test DBs — 49d22b1
- [x] 1.2 phpstan passes — 49d22b1
- [x] 1.3 php-cs-fixer dry-run passes — 49d22b1
- [x] 1.4 Full existing test suite passes with zero deprecation failures — 49d22b1

#### Manual

- [x] 1.5 `/profil` form only accepts whole numbers for base dose — 49d22b1

### Phase 2: History entities, repositories, migration

#### Automated

- [x] 2.1 Migration applies cleanly on dev and test DBs
- [x] 2.2 phpstan passes
- [x] 2.3 php-cs-fixer dry-run passes

### Phase 3: DiaryEntryRepository query addition

#### Automated

- [ ] 3.1 phpstan passes
- [ ] 3.2 New method covered by Phase 6 service tests

### Phase 4: Suggestion service layer

#### Automated

- [ ] 4.1 phpstan passes
- [ ] 4.2 All Phase 6 unit tests for both services pass

### Phase 5: Controller, routes, templates, security wiring

#### Automated

- [ ] 5.1 phpstan passes
- [ ] 5.2 php-cs-fixer dry-run passes
- [ ] 5.3 Phase 6 controller tests pass

#### Manual

- [ ] 5.4 Insufficient-data patient sees neutral messages on `/pulpit`, no crash
- [ ] 5.5 Ratio worked-example patient sees correct card + disclaimer; Accept updates `/profil`
- [ ] 5.6 Base-dose worked-example patient sees correct card + disclaimer; Accept updates `/profil`
- [ ] 5.7 Nav link to `/pulpit` visible and working

### Phase 6: Testing

#### Automated

- [ ] 6.1 Full suite passes
- [ ] 6.2 phpstan passes
- [ ] 6.3 php-cs-fixer dry-run passes

#### Manual

- [ ] 6.4 Phase 5 manual steps re-confirmed, no regressions in onboarding/profile/diary/home flows
