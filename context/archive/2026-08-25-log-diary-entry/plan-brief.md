# Log Diary Entry (S-02) — Plan Brief

> Full plan: `context/changes/log-diary-entry/plan.md`

## What & Why

Let a logged-in, onboarded patient add a diary entry: a required glucose
reading with timestamp, plus optional WW, insulin dose, and physical-activity
data. This is roadmap slice S-02 (FR-004–FR-007) — the data-entry foundation
every later slice (ratio suggestion, hypoglycemia warning, history view,
export) reads from.

## Starting Point

`patient-onboarding` (S-01) is fully implemented: `User` and `PatientProfile`
(1:1, `baseDose`, `insulinWwRatio`) exist and are validated, an onboarding
gate subscriber redirects any authenticated user without a profile to
`/onboarding`, and `ProfileController`/`ProfileFormType` establish the
Symfony Form conventions this plan reuses. Nothing diary-related exists yet —
no entity, controller, route, form, or template, and the nav has only a
logout button.

## Desired End State

A patient opens "Dodaj wpis" (`/dziennik/nowy`), submits a glucose reading
(required) with any combination of optional fields, and the entry persists
with a snapshot of their current `baseDose`/`insulinWwRatio`. The form clears
and shows a success message, ready for the next entry. Invalid input
re-renders the form with inline errors.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Profile snapshot | Copy `baseDose` + `insulinWwRatio` onto each entry at creation | FR-003 requires historical entries to keep the ratio in effect when they were added; later slices (S-03/S-04) need this without reconstructing profile history | Plan (user-confirmed) |
| Entity shape | Single `DiaryEntry` with nullable optional columns | Matches PRD's framing of one "wpis"; avoids join overhead for an MVP create-only flow | Plan (user-confirmed) |
| Optional-field ranges | WW 0–20, insulin dose 0–50 j., activity duration 1–300 min | Guards against typo-scale data entry errors without PRD-mandated caps to point to | Plan (user-confirmed) |
| Timestamp bound | `measuredAt` must be ≤ now | FR-004's "measurement" is by definition a past/present event; "planned activity" (FR-007) is descriptive metadata on a real-time entry, not a future record | Plan (user-confirmed) |
| Post-save UX | Redirect back to the same empty form + flash | Matches expected multiple-times-a-day usage; no list view exists yet to redirect to | Plan (user-confirmed) |
| Route | `/dziennik/nowy` (`diary_entry_new`), nav link "Dodaj wpis" | Polish path consistent with `/profil`, `/onboarding`; nav already has room | Plan (user-confirmed) |
| Glucose type | Integer (mg/dL) | Real glucometers report whole numbers; avoids meaningless fractional precision | Plan (user-confirmed) |
| Test depth | Full coverage matching `PatientProfileTest`/`ProfileControllerTest` | Keeps the new validation ranges and pairing rule from this planning session actually protected by tests | Plan (user-confirmed) |

## Scope

**In scope:** `DiaryEntry` entity + migration, add-entry form/controller/template, nav link, full test coverage.

**Out of scope:** listing/browsing entries (S-05), editing/deleting entries (S-06), ratio-suggestion or hypoglycemia-warning logic (S-03/S-04), any dashboard summary, CGM/device integration.

## Architecture / Approach

A new `DiaryEntry` entity (`ManyToOne` to `User`) with nullable meal/activity
columns plus two non-nullable snapshot columns, a small `ActivityIntensity`
backed enum, and a `DiaryController::new()` create-only action that follows
`RegistrationController`'s "build a fresh entity, bind a form to it" pattern
rather than `ProfileController`'s "load and edit an existing entity" pattern.
The existing onboarding gate needs no changes — it protects any route not
explicitly excluded, and the new route isn't excluded.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. DiaryEntry data model | Entity, enum, repository, migration | None — foundation only, verified by entity tests |
| 2. Add-entry flow | Form, controller, template, nav link, full tests | Activity intensity/duration pairing and future-timestamp validation are the two non-obvious edge cases |

**Prerequisites:** S-01 (`patient-onboarding`) fully landed — confirmed (`impl_reviewed`, all phases implemented).
**Estimated effort:** ~2 sessions, one per phase, within the after-hours MVP budget.

## Open Risks & Assumptions

- Assumes `templates/base.html.twig` and existing templates don't yet render
  flash messages globally — Phase 2 checks this and adds a flash loop to the
  new template only if needed, rather than duplicating one that already
  exists elsewhere.

## Success Criteria (Summary)

- A patient can add a diary entry with just the required fields, or with any
  combination of optional fields, from a single form reachable from the nav.
- Every validation rule decided in this plan (glucose floor, timestamp
  ceiling, optional-field ranges, activity pairing) is enforced and covered
  by an automated test.
