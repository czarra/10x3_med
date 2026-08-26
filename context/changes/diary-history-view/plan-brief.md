# Diary History View — Plan Brief

> Full plan: `context/changes/diary-history-view/plan.md`

## What & Why

Let a logged-in patient browse their past diary entries and see a 7-day
glucose trend at a glance. This is roadmap slice S-05, the Secondary Success
Criterion under FR-008: "przeglądanie historii wpisów w kolejności
chronologicznej," specifically resolved by the PRD as a day-grouped,
paginated list plus a 7-day chart with hipo/norma/hiper zone shading.

## Starting Point

`DiaryEntry` and `DiaryEntryRepository` already exist from S-02
(`log-diary-entry`), including one ascending-order finder the chart reuses
unchanged. There is no listing UI yet — only the "add entry" form at
`/dziennik/nowy`. The app has no charting library and no JS build pipeline;
every screen today is plain server-rendered Twig with one vendored CSS file.

## Desired End State

A patient opens "Historia wpisów" from the nav and sees their entries under
day headers (newest day first, 7 days per page, plain prev/next links) with
full detail per entry (glycemia, WW, insulin, activity). Above the list, a
static inline-SVG chart shows the last 7 days of glucose readings against
three shaded zones. A patient with zero entries sees a friendly empty state
with a link to add their first entry instead.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| Chart rendering | Server-rendered inline SVG | No chart lib/JS pipeline exists; matches the app's pure-server-rendered style everywhere else | Plan |
| Zone thresholds | Hipo <70, norma 70–180, hiper >180 mg/dL | Standard ADA-style non-pregnant adult reference range | Plan |
| List ordering | Newest entry/day first | Matches the "check recent trends" use case in the PRD persona | Plan |
| Pagination mechanics | Page by whole days via `?page=N` links | Keeps day headers intact, no JS needed | Plan |
| Page size | 7 days per page | Mirrors the chart's own 7-day window, keeps pages fast | Plan |
| Empty state | Friendly message + link, chart hidden | Avoids a confusing zero-data chart | Plan |
| List detail | Full row per entry (all fields inline) | No hidden data, no expand/collapse JS needed | Plan |
| Chart points | Plain glycemia line, no insulin/activity annotation | PRD's Secondary Success Criterion says "pomiarów i dawek" but hipo/norma/hiper zone shading only makes sense on a mg/dL scale — doses stay in the entry list below the chart instead; this is an interpretation of the criterion, not its literal wording | Plan |

## Scope

**In scope:**
- `/dziennik/historia` read-only view: day-grouped, paginated entry list
- 7-day glucose chart with zone shading, inline SVG, no new dependencies
- Nav link, empty state, pagination edge-case handling (page clamping)

**Out of scope:**
- Editing/deleting entries (S-06), exporting to PDF/CSV (S-07)
- Any new suggestion/warning logic (already shipped in S-03/S-04)
- Chart interactivity beyond native `<title>` hover tooltips
- Any client-side JS

## Architecture / Approach

Two new services follow the existing `Suggestion`/`Warning` service pattern
(constructor-injected repository, one public method, readonly result DTO,
`KernelTestCase` tests): `DiaryHistoryService` groups/paginates a user's full
history in PHP (no SQL-level pagination — matches existing services and fits
the PRD's small target scale), and `GlucoseHistoryChartService` maps the last
7 days onto fixed SVG pixel coordinates plus static zone bands. A new
`DiaryController::history()` action calls both and renders
`templates/diary/history.html.twig`, which loops the DTOs straight into
`<svg>` markup — no template-side math, no JS.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. History & Chart Data Services | `DiaryHistoryService`, `GlucoseHistoryChartService`, DTOs, repository method — fully unit-tested, no UI | Getting the inverted-Y-axis, fixed-range SVG coordinate math right before any template depends on it |
| 2. Route, Controller, Template, Nav | `/dziennik/historia` end to end: controller action, SVG-rendering template, chart CSS, nav link, full test coverage | Getting day/entry ordering and pagination edge cases (page 0, page past the end) right in the template and controller together |

**Prerequisites:** S-02 (`log-diary-entry`) shipped — already done.
**Estimated effort:** ~1–2 sessions across 2 phases.

## Open Risks & Assumptions

- Fixed Y-axis range (40–300 mg/dL) is a judgment call, not PRD-specified —
  chosen to comfortably bracket the confirmed zone thresholds; an outlier
  reading outside that range clamps to the edge rather than distorting scale.
- Full-history-in-PHP grouping/pagination assumes the PRD's small-scale
  target holds; flagged in the plan's Performance Considerations as the
  first thing to revisit if that assumption changes.

## Success Criteria (Summary)

- A patient can see every past entry, grouped by day, newest first, with no
  entry lost across pagination.
- A patient can see, at a glance, whether their last 7 days of readings fall
  in hipo/norma/hiper zones.
- A patient with no history sees a clear path to add their first entry
  instead of a broken or empty-looking page.
