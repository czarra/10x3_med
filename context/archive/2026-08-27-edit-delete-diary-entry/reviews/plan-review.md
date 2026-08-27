<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Edit / Delete Diary Entry (S-06, FR-014) Implementation Plan

- **Plan**: `context/changes/edit-delete-diary-entry/plan.md`
- **Mode**: Deep
- **Date**: 2026-08-27
- **Verdict**: REVISE
- **Findings**: 1 critical, 0 warnings, 0 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | FAIL |
| Plan Completeness | PASS |

## Grounding

Grounding: 14/14 paths ✓, 6/6 symbols ✓, brief↔plan ✓

Paths checked: `src/Entity/DiaryEntry.php`, `src/Form/DiaryEntryFormType.php`,
`src/Controller/{ProfileController,DashboardController,DiaryController}.php`,
`src/Service/Warning/HypoglycemiaWarningService(+Result).php`,
`src/Service/Suggestion/{InsulinWwRatioSuggestionService,BaseDoseSuggestionService}.php`,
`src/Repository/{RatioAdjustmentHistoryRepository,BaseDoseAdjustmentHistoryRepository,DiaryEntryRepository}.php`,
`templates/diary/{history,new}.html.twig`, `src/Service/History/*`.
`src/Security/` confirmed absent (plan correctly treats it as first-of-kind).

Symbols checked: `findLatestByUser()` on both adjustment-history
repositories, `getAcceptedAt()` on both history entities, `autoconfigure:
true` in `config/services.yaml`, `access_control` regex in `security.yaml`
(`^/dziennik(/|$)` already covers new routes), 6 fields in
`DiaryEntryFormType` matching the plan's "six mutable fields" claim,
`DiaryEntryRepository::findByUserOrderedByMeasuredAt()` uses `measuredAt >
:after` (strictly greater) — consistent with the plan's "strictly after"
rule. `entry` in `history.html.twig` is a real `DiaryEntry` entity (via
`DiaryDayGroup::$entries`), so `entry.id` and `is_granted(..., entry)` in
Phase 4 work with no further changes needed.

Progress↔Phase mechanical contract: exactly one `## Progress` heading, all 4
phases have matching `### Phase N` subsections, every Success Criteria
bullet has a matching `- [ ] N.M` checklist item, no stray checkboxes
outside Progress. Fully compliant.

## Findings

### F1 — No way to backdate `createdAt` in tests, despite the plan requiring it

- **Severity**: CRITICAL
- **Impact**: MEDIUM — real tradeoff to resolve, but the fix itself is narrowly scoped (test code only)
- **Dimension**: Blind Spots (testing gap)
- **Location**: Phase 1 (boundary-matrix unit tests), Phase 2 (controller: "404 for an entry outside the editable window"), Phase 4 (history view: "entry manually backdated past 24h")

**Detail**: `DiaryEntry::$createdAt` (`src/Entity/DiaryEntry.php:54,63`) is
set once in the constructor to `new \DateTimeImmutable()` — there is no
setter and no constructor parameter for it. `research.md:149` explicitly
confirms this is a deliberate S-02 design decision ("Snapshots and
`createdAt` are deliberately immutable... This is a design decision, not an
oversight").

The plan nonetheless assumes a `DiaryEntry` with a `createdAt` older than
24h can be constructed, in at least three places:
- Phase 1 Success Criteria: "boundary-matrix test cases (just-under/at/just-over 24h)" — plain `TestCase`, entities built via `new`, no DB — impossible without a setter/parameter.
- Phase 2 Success Criteria: "404 for an entry outside the editable window" (controller test).
- Phase 4 Manual Verification: "entry manually backdated past 24h (or adjust manually via DB or a seeded fixture)" — the plan notices the problem here but doesn't resolve it, and doesn't mention it at all against the Phase 1/2 automated criteria.

This is a real blocker: without a decision on "how," Phase 1 — which the
plan itself calls "the hardest-to-get-right logic" requiring boundary tests
— cannot be implemented as the Success Criteria describe.

For comparison, `RatioAdjustmentHistory`/`BaseDoseAdjustmentHistory` (already
used in `tests/Service/Suggestion/*Test.php:177-181`) take `acceptedAt` as a
**constructor parameter** — a deliberately different pattern from `createdAt`.

- **Fix A ⭐ Recommended**: Add a test-only helper using PHP Reflection to
  overwrite `createdAt` *before* `persist()+flush()` (Doctrine will then
  write the backdated value to the DB), e.g.
  `(new \ReflectionProperty(DiaryEntry::class, 'createdAt'))->setValue($entry, $backdated)`.
  One helper covers both the unit tests (no DB) and the integration/`WebTestCase` fixtures.
  - Strength: Zero change to `DiaryEntry`'s production API — preserves exactly the guarantee `research.md` documents as a deliberate S-02 decision ("createdAt can't be faked").
  - Tradeoff: Reflection-based setup is more boilerplate than a constructor arg and is somewhat brittle to property renames (scoped to test code only, though).
  - Confidence: HIGH — the codebase already distinguishes these two patterns (`acceptedAt` as a parameter vs. `createdAt` with no setter); reflection is the only route that respects that asymmetry.
  - Blind spot: None significant — reflection-in-tests is a common, well-understood pattern.
- **Fix B**: Add an optional `?\DateTimeImmutable $createdAt = null`
  constructor parameter to `DiaryEntry` (defaulting to `new
  \DateTimeImmutable()`), mirroring how `acceptedAt` is already
  constructor-injectable on the adjustment-history entities.
  - Strength: No reflection; simpler and type-safe, consistent with the codebase's other timestamp-bearing entities.
  - Tradeoff: Widens `DiaryEntry`'s production constructor so any caller (not just tests) could pass an arbitrary `createdAt`, weakening exactly the guarantee `research.md` calls a deliberate S-02 decision.
  - Confidence: MEDIUM — works technically, but reintroduces the attack surface the entity was deliberately built without.
  - Blind spot: Whether any future production code path could accidentally pass a non-null `createdAt` and silently violate the "created-at is truth" invariant hasn't been checked.
- **Decision**: FIXED (via Fix A) — plan.md updated with a reflection-based `backdateCreatedAt()` test helper, referenced from Critical Implementation Details and both Testing Strategy subsections.

## Recommendation

The plan is solid — four correctly sequenced phases, a business rule
resolved with the user and consistently carried through, existing patterns
(`ProfileController::edit()`, `DashboardController` CSRF idiom,
cutoff-query precedent) correctly identified, and 100% grounding. The only
real gap is F1 — worth resolving (Fix A recommended) before implementation
starts, so Phase 1 has a clear path to writing the boundary tests the plan
itself considers the most important thing to verify in isolation.
