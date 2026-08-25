# Log Diary Entry (S-02) Implementation Plan

## Overview

Let a logged-in, onboarded patient add a diary entry: a required blood glucose
reading with timestamp, plus optional meal data (WW, insulin dose) and optional
physical activity data (intensity, duration). This is roadmap slice S-02
(FR-004–FR-007), unblocked by S-01 (`patient-onboarding`), which shipped the
`User`/`PatientProfile` entities, the onboarding gate, and the Symfony Form +
controller conventions this plan reuses.

## Current State Analysis

`patient-onboarding` (status `impl_reviewed`) is fully implemented: `User` and
`PatientProfile` (1:1, `baseDose`, `insulinWwRatio`) exist and are validated
(`src/Entity/PatientProfile.php`), `RequireOnboardingSubscriber`
(`src/EventSubscriber/RequireOnboardingSubscriber.php:16`) redirects any
authenticated user without a profile to `/onboarding`, and `ProfileController`
+ `ProfileFormType` establish the pattern this plan follows: plain Symfony
Form bound to `data_class`, no CSS framework beyond vendored Pico, `IsGranted`
attribute for auth, flash message + redirect on success.

Nothing diary-related exists yet: no `DiaryEntry` entity, no diary controller,
route, form, or template, and `templates/base.html.twig` nav has only the
logout button.

## Desired End State

A logged-in patient with a completed profile can open "Dodaj wpis"
(`/dziennik/nowy`), submit a glucose reading (required) with optional WW,
insulin dose, and activity intensity/duration, and see it persisted with a
snapshot of their `baseDose`/`insulinWwRatio` at that moment. The form clears
and shows a success flash, ready for the next entry — no listing UI exists yet
(that's S-05). Invalid submissions (glucose ≤ 20, future timestamp, out-of-range
optional fields, activity intensity without a paired duration or vice versa)
re-render the form with inline errors, same as the existing `ProfileFormType`
pattern. Verify via automated tests plus a manual walkthrough (see Phase 2
Success Criteria).

### Key Discoveries:

- `PatientProfile` is fetched via `PatientProfileRepository::findOneByUser()`
  (`src/Repository/PatientProfileRepository.php:20`) — same lookup the new
  controller uses to source the snapshot values.
- `RequireOnboardingSubscriber::EXCLUDED_ROUTES`
  (`src/EventSubscriber/RequireOnboardingSubscriber.php:16`) already excludes
  only `patient_onboarding`/`app_logout` — the new diary route needs no
  exclusion; the gate should apply to it exactly like every other route.
- `User` has a bare `__construct()` with no required args
  (`src/Entity/User.php:45`), and `RegistrationController` builds `new User()`
  then binds a form to it (`src/Controller/RegistrationController.php:21-22`)
  — this is the precedent for constructing a fresh, form-bound entity in a
  create (not edit) flow, which `DiaryEntry` follows.
- Test conventions: controller tests extend `WebTestCase`, use
  `$client->loginUser()`, build/submit via `$crawler->filter('main > form')`,
  and clean up raw rows with `DELETE FROM ... WHERE user_id = ?`
  (`tests/Controller/ProfileControllerTest.php`); entity tests extend
  `KernelTestCase`, boot the kernel, and validate via `ValidatorInterface`
  directly (`tests/Entity/PatientProfileTest.php`).

## What We're NOT Doing

- Listing, browsing, or paginating past entries (S-05).
- Editing or deleting entries, including the 24h edit window (S-06/FR-014).
- Any insulin/WW ratio suggestion or hypoglycemia-risk logic (S-03/S-04) —
  this plan only stores the snapshot values those slices will read.
- A dashboard or home-screen summary of entries.
- CGM/device integration (Non-Goal in PRD).

## Implementation Approach

Two Symfony-idiomatic pieces on top of the existing `PatientProfile`
foundation: a new `DiaryEntry` entity (`ManyToOne` to `User`, all
meal/activity fields nullable, plus non-nullable `insulinWwRatioSnapshot` /
`baseDoseSnapshot` columns copied from the patient's current profile at
creation time) and a `DiaryController::new()` create-only flow mirroring
`RegistrationController`'s "build a fresh entity, bind a form to it" pattern.
A new `ActivityIntensity` backed PHP enum (`light`/`medium`/`strong`) maps to
Symfony's built-in `EnumType` form field. The onboarding gate subscriber needs
no changes — it already protects any route not explicitly excluded.

## Critical Implementation Details

- **Snapshot timing**: `insulinWwRatioSnapshot`/`baseDoseSnapshot` must be read
  from `PatientProfile` in the controller, immediately before constructing the
  `DiaryEntry`, not defaulted inside the entity itself — the entity has no way
  to reach the current user's profile on its own.
- **Activity fields must be paired**: `activityIntensity` and
  `activityDurationMinutes` are each individually nullable at the column
  level, but FR-007 describes them as one unit ("intensity ... and its
  duration"). Enforce pairing with an `Assert\Callback` on `DiaryEntry` that
  adds a violation when exactly one of the two is set — leaving both nullable
  independently would let inconsistent activity data (duration with no
  intensity) reach S-04's hypoglycemia-warning logic later. The violation
  must attach to a specific field, not the form root — call
  `$context->buildViolation(...)->atPath('activityDurationMinutes')->addViolation()`
  (or the symmetric field for the missing side) so it renders inline like
  every other field error, per the Desired End State's "inline errors"
  behavior.
- **Future-timestamp check is time-sensitive**: `measuredAt` uses
  `Assert\LessThanOrEqual(value: 'now')`. Tests asserting the boundary should
  use a timestamp built from `new \DateTimeImmutable()` at assertion time (or
  a value safely in the past), not a hardcoded literal — the constraint
  re-evaluates "now" on every validation call.

## Phase 1: DiaryEntry Data Model

### Overview

Add the `DiaryEntry` entity, its `ActivityIntensity` enum, repository, and
migration. No UI yet — this phase is verified through entity-level tests and
static analysis only.

### Changes Required:

#### 1. Activity intensity enum

**File**: `src/Entity/ActivityIntensity.php`

**Intent**: Represent the three PRD-mandated activity levels (Lekki/Średni/Mocny) as a type-safe, Doctrine-mappable value instead of a free string.

**Contract**: A backed `enum ActivityIntensity: string` with cases `Light = 'light'`, `Medium = 'medium'`, `Strong = 'strong'`. Mapped on the entity via `#[ORM\Column(enumType: ActivityIntensity::class, nullable: true)]`.

#### 2. DiaryEntry entity

**File**: `src/Entity/DiaryEntry.php`

**Intent**: Store one diary entry: required glucose reading + timestamp, optional meal/activity data, and a snapshot of the profile values in effect at creation time.

**Contract**: `#[ORM\Entity]` / `#[ORM\Table(name: 'diary_entries')]`. Fields: `id`, `user` (`ManyToOne` → `User`, not nullable), `glycemiaMgDl` (`int`), `measuredAt` (`\DateTimeImmutable`), `ww` (`?float`), `insulinDose` (`?float`), `activityIntensity` (`?ActivityIntensity`), `activityDurationMinutes` (`?int`), `insulinWwRatioSnapshot` (`float`, not nullable), `baseDoseSnapshot` (`float`, not nullable), `createdAt` (`\DateTimeImmutable`, set once at construction — administrative bookkeeping, distinct from the user-editable `measuredAt`).

Constructor takes only what the controller cannot leave to form binding: `__construct(User $user, float $insulinWwRatioSnapshot, float $baseDoseSnapshot)`. It initializes `glycemiaMgDl = 0` (deliberately invalid, same pattern as `PatientProfile`'s onboarding defaults — forces the form to fail validation until the patient enters a real value) and `measuredAt = new \DateTimeImmutable()` (defaults the timestamp field to "now" so most submissions need no edit). `createdAt` is also stamped at construction and has no setter.

Validation constraints:
- `glycemiaMgDl`: `Assert\GreaterThan(20)` (no upper bound — matches the PRD's explicit "no restrictive upper limit" decision on FR-004).
- `measuredAt`: `Assert\LessThanOrEqual(value: 'now')`.
- `ww`: `Assert\Range(min: 0, max: 20)`.
- `insulinDose`: `Assert\Range(min: 0, max: 50)`.
- `activityDurationMinutes`: `Assert\Range(min: 1, max: 300)`.
- Class-level `Assert\Callback` enforcing that `activityIntensity` and `activityDurationMinutes` are either both null or both set (see Critical Implementation Details).

Getters for every field; setters only where the form needs to write (`glycemiaMgDl`, `measuredAt`, `ww`, `insulinDose`, `activityIntensity`, `activityDurationMinutes`) — `user`, the two snapshot fields, and `createdAt` are constructor-only/immutable.

#### 3. Repository

**File**: `src/Repository/DiaryEntryRepository.php`

**Intent**: Standard Doctrine repository boilerplate, following the existing `PatientProfileRepository` shape. No custom finder methods needed yet — S-05 will add querying.

**Contract**: `class DiaryEntryRepository extends ServiceEntityRepository` with the standard constructor (`parent::__construct($registry, DiaryEntry::class)`), same as `src/Repository/PatientProfileRepository.php:13-18`.

#### 4. Migration

**File**: `migrations/VersionYYYYMMDDHHMMSS.php` (generated)

**Intent**: Create the `diary_entries` table matching the entity mapping.

**Contract**: Generate via `bin/console make:migration`; do not hand-write. Verify the generated SQL creates `diary_entries` with a `user_id` foreign key to `users` and column types matching the entity (see Phase 1 Manual Verification).

### Success Criteria:

#### Automated Verification:

- Migration applies cleanly: `docker compose exec php bin/console doctrine:migrations:migrate --env=test --no-interaction`
- Entity/enum unit tests pass: `docker compose exec php vendor/bin/phpunit --filter DiaryEntryTest`
- Static analysis passes: `docker compose exec php vendor/bin/phpstan analyse`
- Code style passes: `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff`

#### Manual Verification:

- Read the generated migration's `up()` SQL and confirm `diary_entries` has the expected columns/types/FK before it's treated as final.

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 2: Add-Entry Form, Controller, Template, Navigation

### Overview

Wire the create flow end to end: form type, controller action, template, nav
link, and full test coverage (entity edge cases + controller happy/error
paths), matching the depth of `ProfileControllerTest`/`PatientProfileTest`.

### Changes Required:

#### 1. Form type

**File**: `src/Form/DiaryEntryFormType.php`

**Intent**: Expose the six user-editable `DiaryEntry` fields as a form, following `ProfileFormType`'s plain-Twig, `data_class`-bound style.

**Contract**: `data_class` = `DiaryEntry::class`. Fields: `glycemiaMgDl` (`IntegerType`, label "Poziom glikemii (mg/dL)"), `measuredAt` (`DateTimeType`, `widget: single_text`, `input: 'datetime_immutable'` — required to match the entity's `\DateTimeImmutable`-typed setter; Symfony's default `input` produces a mutable `\DateTime` and the typed setter rejects it, label "Data i godzina pomiaru"), `ww` (`NumberType`, `required: false`, `scale: 1`, label "Wymienniki węglowodanowe (WW)"), `insulinDose` (`NumberType`, `required: false`, `scale: 1`, label "Dawka insuliny (j.)"), `activityIntensity` (`EnumType`, `class: ActivityIntensity::class`, `required: false`, `placeholder: 'Brak'`, label "Intensywność wysiłku"), `activityDurationMinutes` (`IntegerType`, `required: false`, label "Czas trwania wysiłku (min)").

#### 2. Controller

**File**: `src/Controller/DiaryController.php`

**Intent**: Create-only action that sources the snapshot values from the patient's profile, binds the form to a fresh `DiaryEntry`, persists on success, and redirects back to itself with a flash message.

**Contract**: `#[Route('/dziennik/nowy', name: 'diary_entry_new', methods: ['GET', 'POST'])]`, `#[IsGranted('ROLE_USER')]`. Fetch the current user's `PatientProfile` via `PatientProfileRepository::findOneByUser()` (defensive 404 if missing, matching `ProfileController`'s existing check — the onboarding gate should already guarantee this, but the controller doesn't rely solely on that). Construct `new DiaryEntry($user, $profile->getInsulinWwRatio(), $profile->getBaseDose())`, bind `DiaryEntryFormType`, `handleRequest`, on `isSubmitted() && isValid()` persist + flush + `addFlash('success', 'Wpis został zapisany.')` + `redirectToRoute('diary_entry_new')`.

#### 3. Template

**File**: `templates/diary/new.html.twig`

**Intent**: Render the form, following `templates/profile/edit.html.twig`'s actual structure (extends `base.html.twig`; `form_start(form)` / `form_widget(form)` / an explicit submit button, e.g. "Zapisz wpis" / `form_end(form)` — not the `{{ form(form) }}` shorthand, no custom CSS).

**Contract**: Extends `base.html.twig`; block `body` renders the form and any flash messages (check whether flashes are already rendered globally in `base.html.twig` — if not, add a flash loop here matching how `templates/profile/edit.html.twig` or `templates/onboarding/index.html.twig` currently do it).

#### 4. Navigation link

**File**: `templates/base.html.twig`

**Intent**: Let a logged-in patient reach the add-entry form from anywhere in the app.

**Contract**: Inside the existing `{% if app.user %}` nav block (`templates/base.html.twig:25-38`), add a link to `path('diary_entry_new')` labeled "Dodaj wpis", placed before the logout form.

#### 5. Access control config

**File**: `config/packages/security.yaml`

**Intent**: Keep `access_control` the authoritative list of protected routes, matching the dual-layer pattern (`access_control` + `#[IsGranted]`) every existing protected route already follows — not functionally required for the gate to work (`#[IsGranted('ROLE_USER')]` alone already redirects anonymous requests to login), but needed for consistency.

**Contract**: Extend the `access_control` regex from `^/(onboarding|profil)$` to `^/(onboarding|profil)$|^/dziennik(/|$)` — anchoring `dziennik` separately (rather than folding it into the existing alternation) keeps `onboarding`/`profil` exact matches while covering the `/dziennik/nowy` sub-route; dropping the `$` from a combined `^/(onboarding|profil|dziennik)` would turn `onboarding`/`profil` into unintended prefix matches (e.g. `/onboarding-extra`, `/profilaktyka`).

### Success Criteria:

#### Automated Verification:

- Full test suite passes: `docker compose exec php vendor/bin/phpunit`
- Static analysis passes: `docker compose exec php vendor/bin/phpstan analyse`
- Code style passes: `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff`

#### Manual Verification:

- Log in as a patient with a completed profile, click "Dodaj wpis", submit a valid entry (glucose only), confirm success flash, form reset, and a persisted row with the correct `insulinWwRatioSnapshot`/`baseDoseSnapshot` copied from the profile.
- Submit an entry with WW, insulin dose, and activity intensity+duration all filled; confirm all fields persist.
- Trigger each validation edge case in the browser and confirm inline errors: glucose ≤ 20, future `measuredAt`, WW/insulin dose out of range, activity duration out of range, activity intensity set without duration (and vice versa).
- Confirm an unauthenticated visit to `/dziennik/nowy` redirects to login, and an authenticated-but-not-onboarded visit redirects to `/onboarding` (existing gate behavior, unchanged).

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Testing Strategy

### Unit Tests:

- `DiaryEntry` validation: glucose boundary (20 fails, 21 passes, no upper
  bound), `measuredAt` future-timestamp rejection, WW/insulin dose/activity
  duration range boundaries, activity intensity/duration pairing (one set
  without the other fails).
- `ActivityIntensity` enum round-trips correctly through Doctrine mapping
  (covered implicitly by a controller test that submits an activity value and
  reloads the entity).

### Integration Tests:

- Full submit-and-persist flow via `WebTestCase` + `loginUser()`, asserting
  the snapshot values match the logged-in patient's current `PatientProfile`.
- Redirect-back-to-form-with-flash behavior on success.
- Onboarding gate still redirects a profile-less authenticated user away from
  `/dziennik/nowy` (regression check — no gate code changes, but worth a
  smoke test since it's a new protected route).

### Manual Testing Steps:

1. Add an entry with only the required glucose + timestamp fields.
2. Add an entry with every optional field filled.
3. Attempt each validation edge case listed in Phase 2 Manual Verification.

## Performance Considerations

None beyond existing patterns — a single-row insert with no computation; the
NFR's 200ms/500ms budgets are not at risk from this slice.

## Migration Notes

New table only; no existing data to migrate.

## References

- Roadmap slice: `context/foundation/roadmap.md` (S-02)
- Prior implementation to follow: `context/changes/patient-onboarding/plan.md`
- Pattern source: `src/Controller/ProfileController.php`,
  `src/Form/ProfileFormType.php`, `src/Entity/PatientProfile.php`
- Create-flow precedent: `src/Controller/RegistrationController.php`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: DiaryEntry Data Model

#### Automated

- [x] 1.1 Migration applies cleanly — 7b0eec5
- [x] 1.2 Entity/enum unit tests pass — 7b0eec5
- [x] 1.3 Static analysis passes — 7b0eec5
- [x] 1.4 Code style passes — 7b0eec5

#### Manual

- [x] 1.5 Generated migration SQL reviewed and confirmed correct — 7b0eec5

### Phase 2: Add-Entry Form, Controller, Template, Navigation

#### Automated

- [x] 2.1 Full test suite passes — 53c478c
- [x] 2.2 Static analysis passes — 53c478c
- [x] 2.3 Code style passes — 53c478c

#### Manual

- [x] 2.4 Valid minimal entry submission verified (flash, reset, snapshot values) — 53c478c
- [x] 2.5 Valid full entry submission verified (all optional fields persist) — 53c478c
- [x] 2.6 All validation edge cases verified in browser — 53c478c
- [x] 2.7 Auth/onboarding gate behavior on the new route verified — 53c478c
