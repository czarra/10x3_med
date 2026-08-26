---
date: 2026-08-25T19:21:48+02:00
researcher: Radoslaw Michałkiewicz
git_commit: e4a97170a0f44a3127d648bf1ea36e8561f3bf1e
branch: main
repository: symfony
topic: "Sugestia skorygowanego przelicznika insulina/WW (S-03)"
tags: [research, codebase, diary-entry, patient-profile, suggestion-algorithm, s-03]
status: complete
last_updated: 2026-08-25
last_updated_by: Radoslaw Michałkiewicz
last_updated_note: "Added follow-up research on clinical precedent for both algorithms, plus delta-threshold analysis recommending 45 mg/dL over the PRD's example 50 mg/dL (web search)"
---

# Research: Sugestia skorygowanego przelicznika insulina/WW (S-03)

**Date**: 2026-08-25T19:21:48+02:00
**Researcher**: Radoslaw Michałkiewicz
**Git Commit**: e4a97170a0f44a3127d648bf1ea36e8561f3bf1e
**Branch**: main
**Repository**: symfony

## Research Question

`context/foundation/roadmap.md` S-03 (`insulin-ww-ratio-suggestion`): after
≥3 complete meal entries, the system should suggest a corrected insulin/WW
ratio, with a visible medical disclaimer, requiring manual patient
acceptance. Scope for this research (per user's scoping answers): cover
**both** algorithms in the PRD's Business Logic section — meal-time ratio
correction (US-01/FR-009, the roadmap-scoped one) and fasting base-dose
correction (narrative-only, no FR number) — at plan-ready depth, so this
document can be handed directly to `/10x-plan insulin-ww-ratio-suggestion`.

## Summary

- **Data model has no "meal pair" or "fasting" concept.** `DiaryEntry` stores
  one `glycemiaMgDl` + `measuredAt` per row; there's no linkage between a
  pre-meal and a post-meal reading, and no fasting/wake-up flag. Both PRD
  algorithms require deriving these concepts from unlinked rows — this is the
  central open design question for `/10x-plan`.
- **Base-dose correction is materially less specified than ratio correction.**
  It has no FR number, no acceptance criteria, and isn't referenced by S-03's
  `PRD refs` (`FR-009, FR-011, US-01`) or by any roadmap slice at all. It only
  exists as narrative prose in the PRD's Business Logic section. Ratio
  correction, by contrast, has a fully worked `US-01` with concrete
  Given/When/Then numbers and acceptance criteria.
- **Greenfield**: zero existing references to "suggestion", "recommendation",
  "ratio correction", or "disclaimer" anywhere in `src/`, `templates/`, or
  `tests/`. No `src/Service/` directory exists yet — this slice creates the
  first one.
- **No dashboard exists.** PRD says suggestions "appear automatically on the
  main dashboard" (`pulpicie głównym`), but the app currently only has a
  public `HomeController` (`/`), `/dziennik/nowy` (create-only, no listing —
  that's S-05), and `/profil`. Where the suggestion surfaces is an open
  question this research flags but doesn't resolve.
- Reusable precedents exist for every structural piece: snapshot-style
  immutable fields (`insulinWwRatioSnapshot`), the `findOneByUser` repository
  pattern, `KernelTestCase` + raw-DELETE test cleanup, and profile-update
  flow (`ProfileController` already updates `PatientProfile.insulinWwRatio`,
  which is exactly what "accept suggestion" needs to call).

## Detailed Findings

### Current data model (verified at HEAD, commit `e4a9717`)

- **`src/Entity/DiaryEntry.php`** (177 lines): `id`, `user` (ManyToOne User,
  not-null), `glycemiaMgDl` (int, `Assert\Range(21,2000)`), `measuredAt`
  (`\DateTimeImmutable`, `Assert\LessThanOrEqual('now')`), `ww` (`?float`,
  range 0–20), `insulinDose` (`?float`, range 0–50), `activityIntensity`
  (`?ActivityIntensity` enum), `activityDurationMinutes` (`?int`, range
  1–300, paired with intensity via `Assert\Callback`), `insulinWwRatioSnapshot`
  / `baseDoseSnapshot` (float, immutable, constructor-only), `createdAt`.
  Constructor: `__construct(User $user, int $glycemiaMgDl, \DateTimeImmutable
  $measuredAt, float $insulinWwRatioSnapshot, float $baseDoseSnapshot)`.
- **`src/Entity/PatientProfile.php`** (91 lines, `src/Entity/PatientProfile.php:56-72`):
  `baseDose` (float, `Positive`, `≤35`), `insulinWwRatio` (float, range
  0.1–10.0), both with setters that bump `updatedAt` — this is the method
  `/10x-plan` should reuse for "accept suggestion → update profile."
- **`src/Repository/DiaryEntryRepository.php`** — bare `ServiceEntityRepository`,
  zero custom finders. `/10x-plan` will need to add query methods here (e.g.
  fetch a user's entries ordered by `measuredAt`, or fetch candidate
  "meal entries" — rows with both `ww` and `insulinDose` non-null).
- **`src/Repository/PatientProfileRepository.php:20-23`** — only
  `findOneByUser()`.
- No `src/Service/` directory exists — the suggestion algorithm has no prior
  convention to follow beyond ordinary Symfony DI (constructor-injected
  repository, autowired).

### Algorithm 1: Meal-time ratio correction (US-01, FR-009 — roadmap-scoped)

PRD Business Logic rule 1: analyze the glycemia delta between before-meal and
2h-after-meal readings across ≥3 "complete meal pairs" (meal + short-acting
insulin dose + before/after glycemia). If the delta consistently exceeds a
safe threshold (PRD's own example: +50 mg/dL) → suggest raising the ratio;
consistent drops → suggest lowering it. US-01's concrete example: ratio 1.0 →
suggested 1.2 after 3 meals each showing +80 mg/dL despite correct insulin.

**The pairing gap**: nothing in the schema links a "before" row to its "2h
after" row. `DiaryEntry` doesn't distinguish "this reading was taken right
before eating" from "this reading was a routine check." The most consistent
reading of the existing model: a **meal entry** is a `DiaryEntry` with `ww`
and `insulinDose` both non-null (its `glycemiaMgDl`/`measuredAt` is the
*before* reading, matching how `DiaryController` already snapshots the
profile at the moment of entry). The **after** reading is a *separate*,
later `DiaryEntry` for the same user whose `measuredAt` falls within a
tolerance window around `measuredAt + 2h` (e.g. ±30 min) — likely one with
no `ww`/`insulinDose` (a routine follow-up check), though the algorithm
shouldn't require that.

This pairing rule is a genuine design decision, not something this research
resolves — `/10x-plan` needs to pick and justify a concrete tolerance window,
and decide what happens to a meal entry that never gets a matching after-
reading (excluded from the ≥3 count, presumably).

**Threshold**: PRD says "e.g. +50 mg/dL" (exemplary, not a hard spec) —
`/10x-plan` should treat 50 mg/dL as the working threshold unless it decides
otherwise, since no other number appears anywhere in the PRD.

**Acceptance flow** (FR-009, US-01 AC): suggestion is a "recommendation card"
with a "Zapisz nowy przelicznik w profilu" button; accepting it calls
`PatientProfile::setInsulinWwRatio()` (already exists, bumps `updatedAt`) —
no new profile-write path needed, just a new entry point into the existing
setter.

### Algorithm 2: Base-dose correction (PRD narrative only — not in roadmap)

PRD Business Logic rule 2: monitor fasting/morning glycemia (and pre-meal
readings after long gaps). If it systematically deviates (PRD's own example:
3 consecutive days fasting glucose >130 mg/dL) → signal that the base dose
needs review.

**This has no FR number, no acceptance criteria, and S-03's `PRD refs`
(`FR-009, FR-011, US-01`) don't cite it — nor does any other roadmap slice.**
It's the least-specified part of the PRD: no concrete UI ("signals" vs. the
ratio's explicit "card with a button"), no defined "fasting" data point (the
model has no wake-up/fasting flag — would need to be inferred, e.g. first
entry of the day with no `ww`/`insulinDose` and a large gap since the
previous entry), and no stated threshold-count parallel to US-01's "3
meals" (the "3 consecutive days" example is even vaguer than the ratio
rule's already-loose "+50 mg/dL").

Given this gap, `/10x-plan` has three honest options, which this research
surfaces but doesn't pick:
1. Implement it as a second, separately-triggered card on the same
   suggestion surface, using the "first entry of the day" heuristic above,
   accepting the ambiguity as an implementation decision (consistent with
   how the ratio pairing gap is being handled).
2. Scope it out of this change entirely and flag it back to the roadmap as
   a new slice needing its own FR/AC before being planned.
3. Treat it as this change's stretch goal — build the ratio flow first,
   revisit base-dose only if time budget allows (the roadmap's `main_goal:
   speed` / `top_blocker: time` steer toward this).

### Where the suggestion surfaces

No dashboard exists. Current logged-in surfaces: `/dziennik/nowy` (create-only
diary form, no listing), `/profil` (profile edit). The PRD's "pojawiają się
automatically na pulpicie głównym" implies a landing page that doesn't exist
yet. `/10x-plan` will need to either introduce a minimal dashboard route (a
natural, small addition — S-05's history view is a separate, larger slice
not required here) or attach the suggestion card to an existing page
(`/profil` is the closest fit, since accepting a suggestion writes there).

### Reusable patterns confirmed

- **Route/access-control**: `access_control` regex at
  `config/packages/security.yaml:29-30` is
  `^/(onboarding|profil)$|^/dziennik(/|$)` — a new route needs either to fall
  under `/dziennik/...` or a new entry added (same pattern F-01/S-01/S-02
  each extended).
- **Flash messages**: not centralized in `base.html.twig`; each template has
  its own `{% for message in app.flashes('success') %}` loop (only
  `'success'` type is ever read anywhere), e.g. `templates/diary/new.html.twig:8-10`.
  A disclaimer has no existing visual precedent — Pico CSS is vendored but
  unused beyond the stylesheet link, so any `<article>`/`<mark>`-style
  recommendation card is new territory, not a deviation from a convention.
- **Test conventions**: entity tests (`tests/Entity/`) use `KernelTestCase` +
  `ValidatorInterface`, but none test *computed/derived* values yet — only
  constraint boundaries. A ratio-suggestion service needs a new test style:
  persist N `DiaryEntry` rows via `KernelTestCase` + `EntityManagerInterface`,
  assert the service's output, clean up via the established raw-`DELETE`
  pattern (`tests/Controller/DiaryControllerTest.php:160-162`) with
  `uniqid()`-suffixed emails (`tests/Entity/DiaryEntryTest.php:199`,
  `tests/Entity/PatientProfileTest.php:23,59`).
- **NFR reminder**: PRD requires visible "still calculating" feedback past
  500ms — realistically unreachable at MVP data volumes (few entries), but
  worth a one-line note in the plan so it isn't silently dropped.

## Code References

- `src/Entity/DiaryEntry.php` - full entry schema, constructor, validation.
- `src/Entity/PatientProfile.php:56-72` - `getBaseDose()`/`getInsulinWwRatio()`
  and their setters, the write-target for "accept suggestion."
- `src/Repository/DiaryEntryRepository.php` - where new query methods (meal-
  entry lookup, ordered-by-user fetch) will go.
- `src/Repository/PatientProfileRepository.php:20-23` - `findOneByUser()`.
- `config/packages/security.yaml:29-30` - `access_control` regex to extend.
- `templates/diary/new.html.twig:8-10` - the only existing flash-rendering
  precedent.
- `tests/Controller/DiaryControllerTest.php:160-162` - DB cleanup pattern.
- `tests/Entity/DiaryEntryTest.php:199`, `tests/Entity/PatientProfileTest.php:23,59`
  - `uniqid()`-suffixed test email pattern.

## Architecture Insights

- The codebase's consistent convention: business rules live as entity-level
  `Assert\*` constraints or controller-level orchestration — there is no
  precedent yet for a standalone domain service. This slice sets that
  precedent; `/10x-plan` should place the algorithm in `src/Service/` (e.g.
  `InsulinWwRatioSuggestionService`) rather than bloating a controller or
  repository, consistent with Symfony idioms even though nothing in this
  codebase demonstrates it yet.
- Every prior slice (S-01, S-02) snapshotted profile values into `DiaryEntry`
  at write-time specifically so historical analysis stays stable even as
  the profile changes later (FR-003's resolution). The suggestion algorithm
  should read raw `DiaryEntry.glycemiaMgDl`/`ww`/`insulinDose` history, not
  the snapshots — the snapshots record *what ratio was in effect*, not the
  glycemia outcome data the algorithm needs to react to.

## Historical Context (from prior changes)

- `context/archive/2026-08-25-log-diary-entry/plan.md` - explicitly states
  "Any insulin/WW ratio suggestion or hypoglycemia-risk logic (S-03/S-04) —
  this plan only stores the snapshot values those slices will read," under
  "What We're NOT Doing." Confirms S-02 deliberately left this fully open.
- `context/archive/2026-08-25-patient-onboarding/plan.md` - establishes the
  `Positive`/`Range` validation-constraint style and the setter-bumps-
  `updatedAt` pattern that `PatientProfile` already follows.

## Related Research

None — this is the first research artifact for this change.

## Follow-up Research 2026-08-25 (external validation)

**Question**: do algorithms like the two described in the PRD's Business
Logic section actually exist in real diabetes care, or are they invented for
this project?

**Finding**: both have real, documented clinical precedent — this is not
invented logic.

- **Algorithm 1 (meal-time ratio correction)** matches "pattern management" /
  "carb ratio testing", a standard technique taught in structured diabetes
  education (e.g. DAFNE) and described in published algorithms: derive the
  average glucose change across a set of similar meals, then adjust the
  ratio up/down by a fixed or glucose-proportional step (e.g. "1 g/U per 10
  mg/dL deviation") — conceptually identical to the PRD's threshold-based
  correction. More advanced clinical variants also account for protein/fat
  (Pankowska equation, Sieradzki equation) — out of scope for this MVP, but
  confirms the simplified PRD version sits on a real spectrum of established
  methods, not an ad-hoc invention.
- **Algorithm 2 (base-dose correction from fasting glucose)** has an even
  stronger precedent: "3 consecutive fasting readings above target → increase
  basal dose" is close to verbatim the standard clinical "treat-to-target"
  basal-titration algorithm (ADA/ADCES guidance). **iSage Rx**, an FDA-cleared
  digital therapeutic, automates exactly this pattern — a provider configures
  a glycemic target and a day-threshold, and the app proposes basal dose
  changes to the patient on that basis. This is a strong precedent given
  Algorithm 2 currently has no FR/AC in the PRD (see Open Question 2) — it
  strengthens the case for treating it as a real, implementable feature
  rather than scoping it out, if time allows.

**Implication for `/10x-plan`**: the PRD's threshold values ("+50 mg/dL",
"3 consecutive days >130 mg/dL") are simplified but directionally correct
versions of real clinical titration algorithms — no need to treat them as
arbitrary. Worth a line in the recommendation-card disclaimer noting the
suggestion is a simplified heuristic inspired by established titration
protocols, not a medically validated device — reinforces FR-011's existing
disclaimer requirement rather than adding new scope.

**Sources**:
- [Adjust to Target in Type 2 Diabetes: Comparison of a simple algorithm with carbohydrate counting](https://diabetesjournals.org/care/article/31/7/1305/39087/Adjust-to-Target-in-Type-2-DiabetesComparison-of-a)
- [Adjust to Target in Type 2 Diabetes (PMC)](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC2453649/)
- [The Impact of Two Different Insulin Dose Calculation Methods on Postprandial Glycemia (PMC)](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC12567265/)
- [Insulin-to-Carb Ratios: How to Calculate Insulin Doses - Diabetes Strong](https://diabetesstrong.com/insulin-to-carb-ratios/)
- [122-LB: iSage — FDA-cleared digital therapy for basal insulin titration](https://diabetesjournals.org/diabetes/article/68/Supplement_1/122-LB/58922/122-LB-iSage-Successful-Basal-Insulin-Titration)
- [Practical Guidance on Effective Basal Insulin Titration for Primary Care Providers](https://diabetesjournals.org/clinical/article/37/4/368/32741/Practical-Guidance-on-Effective-Basal-Insulin)
- [A Safe and Simple Algorithm for Adding and Adjusting Mealtime Insulin to Basal-Only Therapy](https://diabetesjournals.org/clinical/article/40/4/489/146923/A-Safe-and-Simple-Algorithm-for-Adding-and)

## Follow-up Research 2026-08-25 (delta-threshold analysis: is 50 mg/dL right?)

**Question**: is the PRD's example threshold ("+50 mg/dL") actually the
right number, and how is a rise-from-baseline threshold applied in the
algorithms found above?

**Finding**: most formal clinical titration protocols don't use a
rise-from-baseline delta at all — they target an *absolute* postprandial
value instead:

- ADA: <180 mg/dL at 1–2h post-meal (absolute, not delta).
- A cited titration protocol targets a 2h postprandial range of
  **130–180 mg/dL** (absolute).

The delta/"excursion" framing the PRD actually uses (glycemia before vs. 2h
after) matches a different, patient-education lineage instead — "carb ratio
testing" protocols (e.g. the widely used method from John Walsh's *Pumping
Insulin*): a 2h rise of roughly **30–50 mg/dL** is considered "ratio well
matched"; a rise **>50–80 mg/dL** signals the ratio is too weak.

The most directly useful data point: an observational study (ELSA-Brasil)
measured actual postprandial glucose excursions in people with diabetes and
found a **median rise of 45 mg/dL (IQR 15–76 mg/dL)**.

**Interpretation — why 50 mg/dL is a weaker choice than it looks**: 50 sits
essentially at the population median excursion, meaning roughly half of all
individual meals in a real diabetic population already exceed it. Applied to
a *single* meal, 50 mg/dL would flag "normal" variation constantly. The
PRD's "za każdym razem" (every time, across ≥3 consecutive meals) requirement
is what actually makes the algorithm safe — three consecutive excursions
above threshold is a much stronger signal than any one crossing — but the
threshold itself should still track the *typical* value researchers actually
observed, not an arbitrary round number.

**Recommendation: use 45 mg/dL instead of the PRD's example 50 mg/dL** as
the working threshold for `/10x-plan`. Rationale:
- 45 mg/dL is the directly-cited median excursion from observed clinical
  data (ELSA-Brasil), not an estimate — a stronger empirical anchor than
  PRD's illustrative "np. 50".
- It still sits inside the 30–50 mg/dL "well matched" band used by carb-ratio
  testing protocols, so it doesn't contradict that lineage either — 45 is
  the boundary between "well matched" and "needs adjustment" in that framing.
- The ≥3-consecutive-meals consistency requirement remains the primary
  safety mechanism, so lowering the raw number to 45 doesn't materially
  increase false positives — it just aligns the constant with the actual
  empirical distribution instead of a round-number guess.
- PRD marks 50 as an example ("np."), not a locked spec, so deviating here
  is a legitimate, cited implementation decision for `/10x-plan` to record
  (per PRD's own resolution style — see `prd.md`'s "Socrates: Rozstrzygnięcie"
  entries for precedent on documenting such deviations).

**Sources**:
- [Insulin-to-Carb Ratios: How to Calculate Insulin Doses - Diabetes Strong](https://diabetesstrong.com/insulin-to-carb-ratios/)
- [Glucose and triglyceride excursions following a standardized meal — ELSA-Brasil study (PMC)](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC4329202/)
- [6. Glycemic Targets: Standards of Medical Care in Diabetes—2022 (ADA)](https://diabetesjournals.org/care/article/45/Supplement_1/S83/138927/6-Glycemic-Targets-Standards-of-Medical-Care-in)
- [Post-Meal Glucose Targets: What's Normal for Type 2 Diabetics? - Ubie Doctor's Note](https://ubiehealth.com/doctors-note/normal-sugar-2hs-postmeal-glucose-targets-type2-4744q1)

## Open Questions

1. **Meal-pair definition**: what tolerance window around "2h after" counts
   as the matching post-meal reading? What happens to an unmatched meal
   entry (excluded from the ≥3 count, most likely)?
2. **Base-dose correction scope**: implement now (heuristic-defined
   "fasting" reading), park as a new unscoped roadmap item, or treat as
   stretch scope within this change? (This research's PRD reading finds
   argument for parking it, given it has no FR/AC and no roadmap slice
   references it — but that's a product-scope call, not a research
   finding.)
3. **Where the suggestion surfaces**: new minimal dashboard route, or
   attached to `/profil`?
4. **Threshold values**: PRD's "+50 mg/dL" / "130 mg/dL fasting" / "3
   consecutive days" are stated as examples ("np."), not hard specs — plan
   should either adopt them as-is or record a deliberate deviation.
   **Update (see "Follow-up Research 2026-08-25 (delta-threshold analysis)"
   above): recommend 45 mg/dL over the PRD's example 50 mg/dL** for the
   ratio-correction delta — it's the directly-cited median postprandial
   excursion in an observed diabetic population (ELSA-Brasil), still inside
   the 30–50 mg/dL "well matched" band used by carb-ratio-testing protocols,
   and the ≥3-consecutive-meals consistency rule remains the real safety
   mechanism either way. The base-dose "130 mg/dL fasting" / "3 consecutive
   days" values are unchanged by this research — no equivalent delta
   literature was reviewed for them.
