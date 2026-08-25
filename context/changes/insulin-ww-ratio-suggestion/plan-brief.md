# S-03: Sugestia skorygowanego przelicznika insulina/WW — Plan Brief

> Full plan: `context/changes/insulin-ww-ratio-suggestion/plan.md`
> Research: `context/changes/insulin-ww-ratio-suggestion/research.md`

## What & Why

S-03 is the roadmap's north star: the smallest complete flow proving DiaGuide can learn from a patient's history instead of acting as a rigid calculator. It implements both algorithms described in the PRD's Business Logic section — the roadmap-scoped insulin/WW ratio correction (US-01/FR-009) and the PRD-narrative-only base-dose correction — as suggestion cards a patient must manually accept, always shown with the FR-011 medical disclaimer.

## Starting Point

S-01/S-02 already ship `PatientProfile` (live ratio/base-dose) and `DiaryEntry` (glycemia/WW/insulin/activity history, with profile values snapshotted at write time specifically for this slice to read). No service layer, dashboard, or suggestion logic exists yet — this is greenfield on top of stable data.

## Desired End State

A logged-in patient with enough diary history visits `/pulpit` (linked from nav) and sees up to two recommendation cards — one for ratio, one for base dose — each with the exact suggested new value, a disclaimer, and an Accept button. Accepting updates `/profil` and is never re-suggested from the same data.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Base-dose algorithm scope | Implement both algorithms, not just the roadmap-scoped ratio one | Product owner chose to pull the unscoped PRD narrative into this change rather than park it | Plan |
| Meal-pair window | ±30 min around meal+2h, nearest-match tie-break | Matches the clinical 2h-postprandial anchor while staying tight | Plan |
| Ratio trigger threshold | 45 mg/dL, majority rule (2 of 3 pairs) | Empirically-grounded (ELSA-Brasil median excursion) per research; majority is more responsive than "all 3" while still matching PRD intent | Research → Plan |
| Ratio step magnitude | WW-normalized: proportional to average excess-per-WW, scaled 0.02, clamped [0.05, 0.3], round to 0.05 | Product owner corrected the initial flat-delta design — same excursion at a smaller meal implies a bigger per-WW correction | Plan |
| `baseDose` type | Changed `float` → `int` (PatientProfile + DiaryEntry snapshot) | Product owner corrected mid-design: base-dose changes must be whole units | Plan |
| Base-dose trigger | 3 literal consecutive days outside band [95,145] (120±25) | Product owner replaced the PRD's single 130 mg/dL threshold with a symmetric band | Plan |
| Base-dose step magnitude | Proportional to excess vs fixed target 120, scaled 0.02, clamped [-2,2], min 0.5 to round, whole-unit | Keeps the same proportional design as ratio, adapted for integer output | Plan |
| Fasting-reading definition | First entry of day where gap since last insulin/WW-bearing entry ≥6h; entry's own ww/insulin doesn't disqualify it | Product owner explicitly corrected the initial "entry itself has no ww/insulin" heuristic | Plan |
| Suggestion persistence | Compute live every load; persist only **accepted** changes as history (no pending/dismissed state) | Simplest model meeting the "no dismiss button, only Accept" requirement while still enabling a re-trigger cutoff | Plan |
| Re-trigger rule | Only diary entries logged after the last accepted change of that type count | Prevents the same 3 meals immediately re-triggering another suggestion | Plan |
| Where suggestions surface | New `/pulpit` route, nav-linked only (not the login/home landing page) | Matches PRD's "dashboard" wording without touching already-shipped login/home behavior | Plan |

## Scope

**In scope:**
- Both suggestion algorithms (ratio + base-dose), with exact formulas and worked examples
- `baseDose` float→int conversion across `PatientProfile`/`DiaryEntry` (ripple into existing S-01/S-02 tests)
- New history entities/repositories, `/pulpit` dashboard + two accept routes, security wiring

**Out of scope:**
- Dismiss/reject action on a suggestion card (Accept only)
- Making `/pulpit` the login/home landing page
- CGM integration, automatic dosing, multi-insulin-type support, admin-configurable constants

## Architecture / Approach

Two focused services (`InsulinWwRatioSuggestionService`, `BaseDoseSuggestionService`) under a new `App\Service\Suggestion` namespace, sharing one `SuggestionScaling::FACTOR` constant. Each reads `DiaryEntry` history via one new `DiaryEntryRepository` method and does pairing/grouping in-memory. Each has its own append-only history entity providing the re-trigger cutoff. `DashboardController` renders both results and handles both accept actions, re-deriving suggestions server-side rather than trusting posted values.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. baseDose float→int conversion | Type change + migration + existing S-01/S-02 test updates | Deprecation-triggered PHPUnit failures if any fractional literal is missed |
| 2. History entities/repos/migration | Append-only audit log for both algorithms | Naming/index conventions must match existing tables |
| 3. Repository query addition | Single new `DiaryEntryRepository` finder | None — small, isolated |
| 4. Suggestion service layer | Both algorithms, DTOs, shared constant | Getting the base-dose "unfiltered history" nuance wrong would misclassify fasting readings near the cutoff |
| 5. Controller/routes/templates/security | `/pulpit` dashboard + accept flow | CSRF + server-side re-derivation must be correct to avoid stale/tampered accepts |
| 6. Testing | Unit tests reproducing all 8 worked examples + controller/integration tests | None — mechanical once Phases 1-5 are solid |

**Prerequisites:** S-02 (shipped) — diary entry data to analyze.
**Estimated effort:** ~6 phases, single-developer, roughly a multi-session build given two full algorithms + a schema change touching prior slices.

## Open Risks & Assumptions

- The `0.02` scaling factor and the ISF≈50mg/dL/unit assumption behind it are documented heuristics, not patient-specific — acceptable per FR-011's disclaimer framing, not a clinical claim.
- Base-dose algorithm has no FR/AC in the PRD; if the roadmap is ever audited against FRs strictly, this scope decision should be reflected there too (not automated by this plan).
- PRD's NFR "Widoczna informacja o trwającej kalkulacji" (>500ms feedback requirement) is satisfied trivially by this design: `/pulpit` is a synchronous, full-page GET render (not AJAX), so the browser's native loading indicator covers it — no custom spinner/progress UI is needed at MVP data volumes.

## Success Criteria (Summary)

- A patient with 3 qualifying meal pairs sees the exact predicted ratio suggestion; accepting it updates their profile and doesn't immediately re-trigger.
- A patient with 3 qualifying fasting days sees the exact predicted base-dose suggestion, same accept behavior.
- A patient with insufficient data sees a neutral message, never an error.
