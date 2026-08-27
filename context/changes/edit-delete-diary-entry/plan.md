# Edit / Delete Diary Entry (S-06, FR-014) Implementation Plan

## Overview

Roadmap slice S-06 / PRD FR-014 lets a patient edit or delete a diary entry they logged,
but only while it's still "fresh" — this preserves the integrity of the insulin-dose and
WW-ratio suggestion algorithms (S-03), which read historical `DiaryEntry` rows to compute
recommendations. Two prior changes (`log-diary-entry`, `diary-history-view`) deliberately
deferred this exact feature. `research.md` in this change folder did a full codebase sweep
and a follow-up round that pinned down the previously-ambiguous business rule directly with
the user. That rule, and every implementation-pattern decision below, is settled; this plan
turns it into phases.

## Current State Analysis

- `DiaryEntry` (`src/Entity/DiaryEntry.php`) already has an immutable `createdAt` (set once
  in the constructor, no setter) — the exact FR-014 anchor, no migration needed.
- `DiaryEntryFormType` (`src/Form/DiaryEntryFormType.php`) already excludes `user`, both
  snapshots, and `createdAt` — directly reusable for editing without any changes.
- `DiaryEntryRepository` has no `findOneByIdAndUser` — not needed under the Voter approach:
  the controller does a plain `find($id)` (inherited from `ServiceEntityRepository`) and lets
  the Voter own the ownership+eligibility decision.
- No route in the app takes a resource `{id}` today; no delete action exists anywhere;
  `src/Security/` doesn't exist (no Voters). All three are first-of-their-kind here.
- `RatioAdjustmentHistoryRepository::findLatestByUser()` / `BaseDoseAdjustmentHistoryRepository::findLatestByUser()` already exist and are exactly what the consumption-cutoff check needs — no new repository methods required anywhere.
- `security.yaml`'s `access_control` regex `^/dziennik(/|$)` already covers any new `/dziennik/*` route — no config change needed.
- Closest existing "edit" precedent: `ProfileController::edit()` (`src/Controller/ProfileController.php:19-42`) — fetch via repository, 404 if null, bind form, `flush()` only (entity already managed, no `persist()`).
- Closest existing "destructive POST + CSRF" precedent: `DashboardController::acceptRatio()`/`acceptBaseDose()` (`src/Controller/DashboardController.php:42-126`) — hidden `_csrf_token` input + plain `<form method="post">`, `isCsrfTokenValid()` check, `createAccessDeniedException()` on failure. The delete action reuses this exact idiom.
- Time-as-explicit-parameter is the established testability convention (`GlucoseHistoryChartService::buildFor($user, $now)`, `DiaryController::history()` calling it with `new \DateTimeImmutable()` at the controller edge). The new editability service follows the same shape: the *service* is the unit-testable core, edges (Voter) supply wall-clock `now`.

## Desired End State

A logged-in patient viewing `/dziennik/historia` sees an "Akcje" column. Rows for entries
that are still within the 24h window **and** not yet consumed by an accepted suggestion show
"Edytuj" and "Usuń" actions; all other rows show neither. Clicking "Edytuj" opens a
pre-filled form (same fields as "new entry") at `/dziennik/{id}/edytuj`; submitting it
updates the six mutable fields in place and redirects back to history with a success flash.
Clicking "Usuń" prompts a native browser confirmation, then POSTs to `/dziennik/{id}/usun`,
removes the row, and redirects back to history with a success flash. Attempting either
action on another user's entry, a nonexistent id, or a no-longer-eligible entry returns 404.
No schema migration is required.

**Verification of end state**: run the full automated suite (`vendor/bin/phpunit`,
`vendor/bin/phpstan analyse`, `vendor/bin/php-cs-fixer fix --dry-run`) and walk through the
Phase 4 manual checklist below.

### Key Discoveries

- **Finalized business rule** (confirmed with the user in `research.md`'s follow-up round —
  not up for reinterpretation): a `DiaryEntry` is editable/deletable iff **both**:
  1. `now() - entry.createdAt <= 24h` (recency, anchored to the immutable `createdAt`).
  2. `entry.measuredAt` is strictly after **both** `RatioAdjustmentHistoryRepository::findLatestByUser($user)?->getAcceptedAt()` and `BaseDoseAdjustmentHistoryRepository::findLatestByUser($user)?->getAcceptedAt()` (missing history = no lower bound). If either cutoff is `>= measuredAt`, the entry is permanently locked regardless of the 24h window.
- Reusable DTO/service shape precedent: `src/Service/Warning/HypoglycemiaWarningService.php` + `HypoglycemiaWarningResult.php`.
- Cutoff-query precedent already exists in both suggestion services: `src/Service/Suggestion/InsulinWwRatioSuggestionService.php:29-30`, `src/Service/Suggestion/BaseDoseSuggestionService.php:30,41-51`.

## What We're NOT Doing

- No soft-delete, edit history, versioning, or `updatedAt`/"edited" marker.
- No re-validation of eligibility using the *post-edit* `measuredAt` — the gate is checked once, against the entity's state before the form is applied (see Critical Implementation Details).
- No changes to `InsulinWwRatioSuggestionService`/`BaseDoseSuggestionService` or the accept flow — this is a read-only, one-directional guard against existing `*AdjustmentHistory` data.
- No inline/in-row editing, no JS beyond a single `confirm()` call, no new confirmation page.
- No disabled-button-with-tooltip UI for locked rows — they're simply omitted.
- No admin/staff override of another user's entries.

## Implementation Approach

Four sequential phases, each independently verifiable: (1) a pure, unit-testable
authorization foundation (editability service + Voter) with no user-facing surface yet, then
(2) edit and (3) delete as separate controller actions built on top of that foundation, then
(4) wiring both into the history template. This lets the hardest-to-get-right logic (the
business rule + ownership) be nailed down and tested in isolation before any HTTP/UI code
depends on it.

## Critical Implementation Details

- **Check order in the editability service matters for query cost.** Check the cheap `createdAt`-based 24h condition *first*, and only run the two `findLatestByUser()` lookups (the consumption-cutoff check) when that passes. Since `history.html.twig` calls `is_granted()` per row, and only entries within the last 24h can ever pass the first check, this keeps the extra DB queries bounded to a handful of recent rows instead of firing for every visible row on the page.
- **The editability gate is evaluated once, before the edit is applied**, using the entity's current DB state (its existing `measuredAt`) — not recomputed against whatever new `measuredAt` the submitted form contains. This matches how the rule is described (a permission to touch the row, not a constraint on the new values) and avoids a confusing "was allowed to open the form, then got rejected on submit for unrelated reasons" UX.
- **404 vs the Voter's default 403**: `#[IsGranted(subject:)]` throws `AccessDeniedException` (403) on denial by default. Since the user chose 404 for both "not yours" and "locked" cases, controllers must call `$this->isGranted('DIARY_ENTRY_EDIT', $entry)` manually and `throw $this->createNotFoundException(...)` on `false`, rather than using the `#[IsGranted]` attribute directly on the action.
- **Backdating `createdAt` in tests requires reflection, not a constructor/setter change.** `DiaryEntry::$createdAt` has no setter and no constructor parameter — deliberately, per `research.md:149` (S-02 decision: creation timestamp can't be faked at the API level). Every test that needs an entry older than 24h — Phase 1's boundary-matrix unit tests, Phase 2's expired-entry 404 case, Phase 4's history-view gating test — must set it via reflection: `(new \ReflectionProperty(DiaryEntry::class, 'createdAt'))->setValue($entry, $backdated)`, applied **before** `persist()`/`flush()` so Doctrine writes the backdated value to the DB in `WebTestCase` fixtures. Factor this into one shared private test helper (e.g. `backdateCreatedAt(DiaryEntry $entry, \DateTimeImmutable $createdAt): void`) rather than repeating the reflection call per test.

## Phase 1: Editability rule + authorization foundation

### Overview

Introduce the pure business-rule service and the Voter that wraps it with ownership. No routes or UI yet — this phase is backend-only and fully unit-testable.

### Changes Required

#### 1. `DiaryEntryEditabilityService`

**File**: `src/Service/Editability/DiaryEntryEditabilityService.php` (new)

**Intent**: Encode the finalized two-part rule (24h recency + not-consumed-by-suggestion-acceptance) as a single boolean check, following the `HypoglycemiaWarningService`-style stateless service shape and the `GlucoseHistoryChartService`-style explicit-`$now` testability convention.

**Contract**: `isEditable(DiaryEntry $entry, \DateTimeImmutable $now): bool`. Constructor-injects `RatioAdjustmentHistoryRepository` and `BaseDoseAdjustmentHistoryRepository` (both already expose `findLatestByUser(User $user)`). Derives the owning user via `$entry->getUser()` — no separate `$user` parameter needed. Implements the check-order note from Critical Implementation Details.

#### 2. `DiaryEntryVoter`

**File**: `src/Security/DiaryEntryVoter.php` (new — first file in `src/Security/`)

**Intent**: Single place combining ownership (`entry->getUser() === current user`) with the editability rule, for both edit and delete.

**Contract**: `extends Voter`. Two attribute constants, `EDIT = 'DIARY_ENTRY_EDIT'` and `DELETE = 'DIARY_ENTRY_DELETE'` (both currently delegate to the identical check — kept separate for the idiomatic Symfony subject/attribute shape and future divergence, not because the rule differs today). `supports()` accepts both attributes for a `DiaryEntry` subject. `voteOnAttribute()` denies if the token's user isn't a `User` or doesn't own the entry, otherwise delegates to `DiaryEntryEditabilityService::isEditable($subject, new \DateTimeImmutable())`. Autowired/autoconfigured automatically (`config/services.yaml` already has `autoconfigure: true`), no manual `security.yaml` wiring needed.

### Success Criteria:

#### Automated Verification:

- New unit tests pass: `docker compose exec php vendor/bin/phpunit --filter DiaryEntryEditabilityServiceTest`
- New Voter unit test passes: `docker compose exec php vendor/bin/phpunit --filter DiaryEntryVoterTest` (construct a token/subject directly — no HTTP layer needed; covers "user A cannot touch user B's entry" and "expired entry denies")
- `docker compose exec php vendor/bin/phpstan analyse` passes
- Full suite still green: `docker compose exec php vendor/bin/phpunit`

#### Manual Verification:

- Read through the boundary-matrix test cases (just-under/at/just-over 24h; cutoff just-before/at/just-after `measuredAt`) and confirm they match the finalized rule in this plan's Key Discoveries section.

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 2: Edit flow

### Overview

Add the `/dziennik/{id}/edytuj` route, controller action, and template, gated by the Phase 1 Voter.

### Changes Required

#### 1. `DiaryController::edit()`

**File**: `src/Controller/DiaryController.php`

**Intent**: Mirror `ProfileController::edit()`'s fetch→form→flush shape, adapted for a resource `{id}` instead of a 1:1-per-user lookup.

**Contract**: `#[Route('/dziennik/{id}/edytuj', name: 'diary_entry_edit', methods: ['GET', 'POST'])]`, `#[IsGranted('ROLE_USER')]`, signature takes `int $id`. Flow: `DiaryEntryRepository::find($id)` → 404 if `null` → `$this->isGranted(DiaryEntryVoter::EDIT, $entry)` → 404 if `false` (see Critical Implementation Details on why not `#[IsGranted(subject:)]`) → bind `DiaryEntryFormType` to the fetched (already-managed) entity → on valid submit, `flush()` only (no `persist()`) → flash success → `redirectToRoute('diary_entry_history')`.

#### 2. Edit template

**File**: `templates/diary/edit.html.twig` (new)

**Intent**: Same form-rendering structure as `templates/diary/new.html.twig` (plain `form_start/form_widget/form_end`), pre-filled since the form is bound to an existing entity. Add a link back to history.

### Success Criteria:

#### Automated Verification:

- `docker compose exec php vendor/bin/phpunit --filter DiaryControllerTest` passes, including new cases: happy-path edit (GET renders prefilled form, POST persists changes + flash + redirect), 404 for another user's entry, 404 for an entry outside the editable window, validation-error re-render.
- `docker compose exec php vendor/bin/phpstan analyse` passes.

#### Manual Verification:

- Log in, create a fresh entry, open its edit link, change a field, submit — confirm the history table reflects the new value and the flash message appears.
- Manually hit `/dziennik/{other-users-id}/edytuj` and confirm a 404 page.

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 3: Delete flow

### Overview

Add the `/dziennik/{id}/usun` POST-only route and controller action, reusing the CSRF-form idiom from `DashboardController`.

### Changes Required

#### 1. `DiaryController::delete()`

**File**: `src/Controller/DiaryController.php`

**Intent**: Same fetch/authorize shape as `edit()`, but remove-and-flush instead of bind-and-flush.

**Contract**: `#[Route('/dziennik/{id}/usun', name: 'diary_entry_delete', methods: ['POST'])]`, `#[IsGranted('ROLE_USER')]`. Flow: fetch by `find($id)` → 404 if null → `isGranted(DiaryEntryVoter::DELETE, $entry)` → 404 if false → `isCsrfTokenValid('delete_diary_entry', ...)` (single shared intention string, matching `DashboardController`'s `accept_ratio_suggestion` style — not per-id) → `createAccessDeniedException()` on CSRF failure → `entityManager->remove($entry)` + `flush()` → flash success → `redirectToRoute('diary_entry_history')`.

### Success Criteria:

#### Automated Verification:

- `DiaryControllerTest` new cases pass: happy-path delete (row removed, flash, redirect), 404 for another user's/expired entry, 403 on invalid/missing CSRF token.
- `docker compose exec php vendor/bin/phpstan analyse` passes.

#### Manual Verification:

- Log in, create a fresh entry, delete it by directly POSTing to the endpoint (curl/Postman — the button isn't wired into the UI until Phase 4) — confirm it's gone from the DB and the flash appears.

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 4: History view integration

### Overview

Wire the Edit/Delete affordances into `templates/diary/history.html.twig`, gated per-row via Twig's `is_granted()` (backed by the Phase 1 Voter) — no controller or service changes needed, this phase is template-only plus verification.

### Changes Required

#### 1. History table Actions column

**File**: `templates/diary/history.html.twig`

**Intent**: Add an "Akcje" column, hidden entirely for rows that aren't currently editable — no disabled-state UI, per the confirmed UX decision.

**Contract**: New `<th>Akcje</th>` in the table head. Per row, wrap an "Edytuj" link (`{{ path('diary_entry_edit', {id: entry.id}) }}`) and a "Usuń" POST form (hidden `_csrf_token` input via `csrf_token('delete_diary_entry')`, `action="{{ path('diary_entry_delete', {id: entry.id}) }}"`, `onsubmit="return confirm('...')"`) inside `{% if is_granted('DIARY_ENTRY_EDIT', entry) %}` / `{% if is_granted('DIARY_ENTRY_DELETE', entry) %}` respectively (they'll agree in practice today, but each action checks its own attribute — no shortcut to a single combined check). Rows failing both checks render an empty `<td>` (no dead links, no disabled buttons).

### Success Criteria:

#### Automated Verification:

- A `DiaryControllerTest` (or dedicated history test) case asserts: a freshly-created entry's row *does* contain Edit/Delete controls; an entry manually backdated past 24h (or with a `RatioAdjustmentHistory`/`BaseDoseAdjustmentHistory` cutoff after its `measuredAt`) does *not*.
- Full suite green: `docker compose exec php vendor/bin/phpunit`
- `docker compose exec php vendor/bin/phpstan analyse` and `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run` both pass.

#### Manual Verification:

- Full end-to-end walkthrough in the browser: create an entry → see Edit/Delete on its history row → edit it → verify updated values persist and it's still editable → delete a different entry → confirm the browser prompt appears, then the row disappears after confirming.
- Accept a ratio or base-dose suggestion (via `/pulpit`) that would consume an existing entry, then revisit `/dziennik/historia` and confirm that entry's Edit/Delete controls have disappeared even though it's still within 24h.
- Confirm an entry created >24h ago (adjust manually via DB or a seeded fixture) shows no actions.

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful.

---

## Testing Strategy

### Unit Tests:

- `DiaryEntryEditabilityServiceTest` (plain `TestCase`, no DB, entities built via `new` — matches `HypoglycemiaWarningServiceTest`'s style): boundary matrix on both the 24h window and the dual consumption-cutoff, including "no history yet" (null cutoffs). Uses the reflection-based `backdateCreatedAt()` helper (see Critical Implementation Details) to place entries just-under/at/just-over the 24h line.
- `DiaryEntryVoterTest` (plain `TestCase`): ownership mismatch denies; owned-but-ineligible denies; owned-and-eligible grants; non-`DiaryEntry` subject / unsupported attribute → `ACCESS_ABSTAIN`.

### Integration Tests:

- `DiaryControllerTest` additions, following the file's existing `WebTestCase` + manual-cleanup-in-`finally` conventions: edit happy path, delete happy path, cross-user 404 for both actions, expired-window 404 for both actions, CSRF-failure 403 for delete, history-row visibility gating. The expired-window and history-row-gating cases reuse the same reflection-based `backdateCreatedAt()` helper as the unit tests, applied to the entity before `persist()`/`flush()`.

### Manual Testing Steps:

1. See each phase's Manual Verification above.
2. Phase 4's walkthrough is the full end-to-end pass, including the suggestion-acceptance cutoff interaction.

## Performance Considerations

Per-row `is_granted()` calls in the history template could cause an N+1 query pattern if the editability check always ran its two `findLatestByUser()` lookups. The check-order requirement in Critical Implementation Details (cheap 24h check first) bounds this to a small number of rows (those within the last 24h), so no additional caching/memoization layer is introduced.

## Migration Notes

None — no schema changes anywhere in this plan.

## References

- Related research: `context/changes/edit-delete-diary-entry/research.md`
- Edit precedent: `src/Controller/ProfileController.php:19-42`
- CSRF-form precedent: `src/Controller/DashboardController.php:42-126`, `templates/dashboard/index.html.twig:17-19,32-34`
- Cutoff-query precedent: `src/Service/Suggestion/InsulinWwRatioSuggestionService.php:29-30`, `src/Service/Suggestion/BaseDoseSuggestionService.php:30,41-51`
- DTO/service shape precedent: `src/Service/Warning/HypoglycemiaWarningService.php`, `HypoglycemiaWarningResult.php`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Editability rule + authorization foundation

#### Automated

- [x] 1.1 DiaryEntryEditabilityServiceTest passes — e410b10
- [x] 1.2 DiaryEntryVoterTest passes — e410b10
- [x] 1.3 phpstan analyse passes — e410b10
- [x] 1.4 Full suite green — e410b10

#### Manual

- [x] 1.5 Boundary-matrix test cases reviewed against the finalized rule — f04efe8

### Phase 2: Edit flow

#### Automated

- [x] 2.1 DiaryControllerTest edit cases pass (happy path, cross-user 404, expired 404, validation re-render) — 22d5af0
- [x] 2.2 phpstan analyse passes — 22d5af0

#### Manual

- [x] 2.3 Browser walkthrough: create, edit, verify updated value + flash — 22d5af0
- [x] 2.4 Manual 404 check on another user's entry id — 22d5af0

### Phase 3: Delete flow

#### Automated

- [x] 3.1 DiaryControllerTest delete cases pass (happy path, cross-user/expired 404, CSRF 403)
- [x] 3.2 phpstan analyse passes

#### Manual

- [ ] 3.3 Direct POST delete removes row and shows flash

### Phase 4: History view integration

#### Automated

- [ ] 4.1 History row visibility test (editable vs locked) passes
- [ ] 4.2 Full suite green
- [ ] 4.3 phpstan analyse and php-cs-fixer --dry-run pass

#### Manual

- [ ] 4.4 Full end-to-end browser walkthrough (create, edit, delete)
- [ ] 4.5 Suggestion-acceptance cutoff hides actions on an otherwise-fresh entry
- [ ] 4.6 Entry older than 24h shows no actions
