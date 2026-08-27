<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Eksport historii dziennika do CSV

- **Plan**: context/changes/export-diary-history/plan.md
- **Mode**: Deep
- **Date**: 2026-08-27
- **Verdict**: SOUND
- **Findings**: 0 critical, 1 warning, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | WARNING |
| Plan Completeness | WARNING |

## Grounding

5/5 paths ✓ (`DiaryHistoryService.php:17-40`, `DiaryController.php:115-130`, `security.yaml:30` access_control regex, `security.yaml:20/21/27` login_path, `DiaryControllerTest.php` helpers ~564-637), symbols ✓ (`buildPage`, `history()`, `csrfToken`, `createUser`/`createProfile`/`cleanupUser`), brief↔plan ✓ (decisions table matches plan body 1:1). Progress↔Phase mechanical check: one `## Progress` heading, both phases have matching `### Phase N` blocks, every Automated/Manual bullet count matches its Progress subsection 1:1 — PASS.

## Findings

### F1 — Roadmap/PRD scope narrowing isn't tracked anywhere

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Blind Spots
- **Location**: plan.md "What We're NOT Doing" / roadmap.md S-07
- **Detail**: `roadmap.md` S-07 and `prd.md` FR-012 (priority: must-have) both describe the outcome as exporting *both* diary measurements *and* suggested ratio/base-dose adjustment history, to PDF or CSV. The plan consciously narrows this slice to CSV-only, diary-entries-only — a reasonable, well-documented decision (`plan-brief.md`'s decisions table gives the rationale). But nothing in the plan touches how `roadmap.md`'s S-07 entry gets reconciled afterward. Recent commit history in this repo (`chore(roadmap): close S-06`, `chore(archive): close edit-delete-diary-entry`) shows the convention here is to flip a roadmap item to `done` when its change folder archives. If that happens mechanically for S-07 after this narrower slice ships, a must-have PRD requirement (ratio-history export, PDF) would read as delivered when it isn't.
- **Fix A ⭐ Recommended**: Before this change archives, split S-07 in `roadmap.md`: reword the current S-07 outcome to scope it to "CSV export of diary measurements only," and add a new backlog entry (e.g. S-07b) for the remaining PDF + ratio/base-dose-history export, referencing FR-012.
  - Strength: keeps roadmap and PRD coverage honest; low effort; matches the project's own convention of updating roadmap.md at change-close time.
  - Tradeoff: small process overhead added to this plan's closing steps.
  - Confidence: HIGH — this is exactly the pattern already used for prior change closures in this repo's git history.
  - Blind spot: doesn't know if a separate roadmap slice for the remainder is already intended elsewhere.
- **Fix B**: Leave S-07's `status` at `planning` (not `done`) after this change archives, and add one sentence to `plan-brief.md`'s "Ryzyka i założenia" flagging the residual FR-012 scope as a known future slice.
  - Strength: minimal edit right now, no roadmap restructuring.
  - Tradeoff: relies on whoever runs `/10x-archive` noticing not to flip status to done; less explicit than a tracked backlog item.
  - Confidence: MEDIUM — haven't verified `/10x-archive`'s exact status-transition logic.
  - Blind spot: same as above.
- **Decision**: FIXED (via Fix A) — `roadmap.md` S-07 reworded to CSV/diary-entries-only scope (prerequisites simplified to S-05), new backlog entry **S-07b** added for the remaining PDF + ratio/base-dose-history export (FR-012 rest), referencing FR-012 and depending on S-03 + S-07. Also updated the "At a glance" table, Stream D chain, and Backlog Handoff table for consistency.

### F2 — Two minor Phase-1 test-spec gaps

- **Severity**: 👁️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phase 1 → Success Criteria → Automated Verification
- **Detail**: (1) No success criterion covers hitting `GET /dziennik/eksport?page=1` directly when the user has zero diary entries (the export button is hidden in that case per Phase 2, but the route itself isn't gated on `hasEntries`, so it's still reachable by URL) — expected header-only-CSV behavior is implied but not asserted. (2) `testExportOnlyIncludesRequestingUsersEntries` and `testExportWithSinglePageHasNoOtherUsersData` both describe cross-user isolation with overlapping wording; unclear if they're two distinct scenarios or the same one described twice.
- **Fix**: Add `testExportWithNoEntriesReturnsHeaderOnlyCsv` to the Phase 1 test list, and reword one of the two isolation test descriptions to state the distinct scenario it covers (or merge them if they're the same case).
- **Decision**: FIXED — `plan.md` Phase 1 test list updated: merged the two overlapping isolation-test descriptions into one (`testExportOnlyIncludesRequestingUsersEntries`), and added `testExportWithNoEntriesReturnsHeaderOnlyCsv` covering the direct-URL empty-history case.
