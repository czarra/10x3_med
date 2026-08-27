# Edit / Delete Diary Entry — Plan Brief

> Full plan: `context/changes/edit-delete-diary-entry/plan.md`
> Research: `context/changes/edit-delete-diary-entry/research.md`

## What & Why

Roadmap slice S-06 (PRD FR-014) lets a patient edit or delete a diary entry they logged,
but only while it's still "fresh" — recent enough (24h) and not yet used to compute an
accepted insulin-dose or WW-ratio suggestion. This protects the integrity of the S-03
suggestion algorithms, which read historical entries to compute recommendations. Two prior
changes deliberately deferred this exact feature to S-06.

## Starting Point

`DiaryEntry` already has an immutable `createdAt` (the FR-014 anchor) and `DiaryEntryFormType`
already excludes the fields an edit must never touch (`user`, snapshots, `createdAt`) — both
are reusable as-is. Nothing in the app today takes a resource `{id}` from a URL, has a delete
action, or has an authorization Voter — all three are introduced for the first time here.
The consumption-cutoff data (`RatioAdjustmentHistoryRepository`/`BaseDoseAdjustmentHistoryRepository::findLatestByUser()`) already exists and needs no new repository methods.

## Desired End State

The diary history table shows "Edytuj"/"Usuń" actions only on rows that are still within
the 24h window and haven't been consumed by an accepted suggestion. Editing reuses the same
form as logging a new entry, pre-filled; deleting is a one-click POST with a browser confirm
prompt. Touching another user's entry, a nonexistent one, or a locked one always 404s.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Editability business rule | 24h since `createdAt` **and** `measuredAt` after both suggestion-acceptance cutoffs | User specified the exact rule directly, resolving a PRD/roadmap self-contradiction | Research |
| Ownership + editability enforcement | New `DiaryEntryVoter` | User chose the more idiomatic-Symfony option over the zero-new-abstraction repo-scoped lookup | Plan |
| Locked/not-owned response | 404, not 403 | Matches the codebase's only existing precedent (`createNotFoundException`) | Plan |
| History row actions | Hidden entirely for non-editable rows | User wants no dead links or disabled-button UI to explain | Plan |
| Delete confirmation | Inline POST form + `confirm()` | Reuses `DashboardController`'s existing CSRF-form idiom exactly, no new template | Plan |
| Edit "audit" marker | None | Nothing in the PRD asks for one; avoids an unrequested migration | Plan |
| Edit entry point | Dedicated page, reuses `DiaryEntryFormType` | Zero new form code; matches the "new entry" screen's structure | Plan |

## Scope

**In scope:** edit route/controller/template, delete route/controller, `DiaryEntryEditabilityService`, `DiaryEntryVoter`, history-table Actions column, full test coverage (unit + controller).

**Out of scope:** soft-delete, edit history/audit trail, `updatedAt` column, inline row editing, disabled-button UI, admin override, any change to the suggestion services themselves.

## Architecture / Approach

A pure `DiaryEntryEditabilityService::isEditable(entry, now)` encodes the business rule
(checked cheap-first for query cost). A `DiaryEntryVoter` wraps it with an ownership check
and exposes `DIARY_ENTRY_EDIT`/`DIARY_ENTRY_DELETE` attributes, used both by the controllers
(`isGranted()` → manual 404) and by the Twig template (`is_granted()` → row visibility) — one
rule, two call sites, no duplicated logic.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Editability + authorization foundation | Unit-tested service + Voter, no UI yet | Getting the boundary conditions (24h edge, cutoff edge) wrong — mitigated by a boundary-matrix test suite |
| 2. Edit flow | `/dziennik/{id}/edytuj` route, controller, template | First resource-`{id}` route in the app — ownership/404 wiring must be correct |
| 3. Delete flow | `/dziennik/{id}/usun` route, controller | First delete action in the app — CSRF + irreversibility |
| 4. History view integration | Actions column wired via `is_granted()` | Per-row Voter calls must stay query-cheap (addressed by check ordering) |

**Prerequisites:** none beyond what's already in `main` — no migration, no new dependencies.
**Estimated effort:** ~1 session across 4 phases.

## Open Risks & Assumptions

- Assumes the browser-native `confirm()` dialog is acceptable delete-confirmation UX for this MVP (no design review requested).
- Assumes `DIARY_ENTRY_EDIT`/`DIARY_ENTRY_DELETE` staying identical today is fine long-term; if they ever diverge, only the Voter needs to change, not callers.

## Success Criteria (Summary)

- A patient can edit or delete only entries that are within 24h and not yet consumed by an accepted suggestion; everything else is unreachable (404) and shows no UI affordance.
- Full automated suite (PHPUnit, PHPStan, php-cs-fixer) stays green with no schema migration.
