---
date: 2026-08-27T15:30:22+02:00
researcher: Claude Code
git_commit: 5cbdac882edc2e1d37a4e477f1155be621b65f20
branch: main
repository: czarra/10x3_med
topic: "How diary entries are logged and stored, to plan a CSV export"
tags: [research, codebase, diary-entry, export, csv, roadmap-s07]
status: complete
last_updated: 2026-08-27
last_updated_by: Claude Code
---

# Research: How diary entries are logged and stored, to plan export

**Date**: 2026-08-27T15:30:22+02:00
**Researcher**: Claude Code
**Git Commit**: 5cbdac882edc2e1d37a4e477f1155be621b65f20
**Branch**: main
**Repository**: czarra/10x3_med

## Research Question

How are diary entries logged and stored in DiaGuide, and what does that imply for implementing roadmap slice **S-07 — export diary history** (`context/changes/export-diary-history/`, PRD ref FR-012)?

## Summary

Diary entries are a single Doctrine entity (`DiaryEntry`) in a single table (`diary_entries`), always queried scoped to the current user via two simple repository methods with no SQL-level pagination. The existing history view (`DiaryController::history()` + `DiaryHistoryService`) already fetches a user's *entire* history in one query and processes it in PHP — this is the direct precedent for an export feature. There is no export/CSV/PDF code anywhere in the codebase yet, and no PDF or CSV library in `composer.json` (only an unused `symfony/serializer`).

Two scope decisions were confirmed with the user for this slice:
- **Format: CSV only** (no new composer dependency; PDF deferred).
- **Data: diary entries only** — `RatioAdjustmentHistory`/`BaseDoseAdjustmentHistory` ("sugerowane przeliczniki") are explicitly *not* included in this export, even though PRD FR-012's wording covers both. This is a documented scope deviation, not an oversight.

A concrete, minimal implementation approach was designed and validated against the codebase's existing conventions (see [Architecture Insights](#architecture-insights) and [Recommended Implementation Approach](#recommended-implementation-approach)) — this research is ready to feed directly into `/10x-plan`.

## Detailed Findings

### Data model

- **Entity** — `src/Entity/DiaryEntry.php:13-177`, table `diary_entries` (`:10-11`). Fields: `id` (PK), `user` (ManyToOne `User`, not-null, `:20-22`), `glycemiaMgDl` (int, range 21–2000, `:24-26`), `measuredAt` (DateTimeImmutable, must be ≤ now, `:28-30`), `ww` (?float, range 0–20, `:32-34`), `insulinDose` (?float, range 0–50, `:36-38`), `activityIntensity` (?`ActivityIntensity` enum: `light`/`medium`/`strong`, `:40-41`), `activityDurationMinutes` (?int, range 1–300, `:43-45`), `insulinWwRatioSnapshot` (float, no setter — immutable snapshot at creation, `:47-48`, `:148-151`), `baseDoseSnapshot` (int, no setter, `:50-51`, `:153-156`), `createdAt` (DateTimeImmutable, set in constructor, no setter, `:53-54`, `:158-161`). Class-level `#[Assert\Callback('validateActivityPairing')]` (`:12`, `:163-176`) enforces activity intensity/duration are both-null or both-set. No `#[ORM\PrePersist]` hooks, no computed properties.
- **Repository** — `src/Repository/DiaryEntryRepository.php`: `findByUserOrderedByMeasuredAt(User $user, ?\DateTimeImmutable $after = null)` (ASC, `:23-36`) and `findByUserOrderedByMeasuredAtDesc(User $user)` (DESC, full history, `:41-49`). Both filter by `$user` directly in the `WHERE` clause (mandatory parameter) — no id-based lookup path for reads. No SQL-level `LIMIT`/`OFFSET` in either method.
- **Migrations** — `migrations/Version20260825132206.php:23-25` created `diary_entries` with an index on `user_id` and an FK to `users`; `migrations/Version20260826073658.php:23-24` retyped `base_dose_snapshot` from `DOUBLE PRECISION` to `INT`. No composite `(user_id, measured_at)` index exists on `diary_entries` (unlike the two adjustment-history tables — see below), but this isn't a concern for export since both repo methods already return full, unpaginated result sets and the app's `target_scale` is documented as small/low-QPS.
- **Form** — `src/Form/DiaryEntryFormType.php` binds `DiaryEntry` directly for create/edit; no DTO layer exists for diary entries anywhere.

### Related "sugerowane przeliczniki" data (relevant to FR-012's full wording, excluded from this slice)

- `src/Entity/RatioAdjustmentHistory.php` (table `ratio_adjustment_histories`, composite index `(user_id, accepted_at)` at `:10`): `oldRatio`, `newRatio`, `acceptedAt`, `user`.
- `src/Entity/BaseDoseAdjustmentHistory.php` (table `base_dose_adjustment_histories`, same composite index shape): `oldBaseDose`, `newBaseDose`, `acceptedAt`, `user`.
- Both repositories (`src/Repository/RatioAdjustmentHistoryRepository.php`, `src/Repository/BaseDoseAdjustmentHistoryRepository.php`) currently expose only `findLatestByUser(User $user)` — no `findAllByUser`. A future export enhancement that includes this data would need to add that method.
- `src/Entity/PatientProfile.php` holds the *current* live `baseDose`/`insulinWwRatio` (OneToOne with `User`) — not history, but relevant context if a future export wants a "current settings" header.

### Existing history view (direct precedent for export)

- **Controller** — `src/Controller/DiaryController.php`: `new()` (`:23-61`), `edit()` (`:63-89`, Voter-gated, manual `isGranted()` → 404 on denial rather than 403), `delete()` (`:91-113`, same pattern + CSRF check), `history()` (`:115-130`, `GET /dziennik/historia`, route `diary_entry_history`). `history()` reads `?page=`, calls `DiaryHistoryService::buildPage($user, $page)` and `GlucoseHistoryChartService::buildFor($user, ...)`, renders `diary/history.html.twig`. Every action scopes via `/** @var User $user */ $user = $this->getUser();` — no cross-user query exists anywhere in the app.
- **Service** — `src/Service/History/DiaryHistoryService.php:8-41`: `buildPage(User $user, int $requestedPage): DiaryHistoryPage` fetches **all** of a user's entries via `findByUserOrderedByMeasuredAtDesc`, groups them by `Y-m-d` in PHP, paginates 7 day-groups/page **in PHP** (`DAYS_PER_PAGE = 7`, `array_slice`, no SQL `LIMIT`). Returns a readonly DTO (`DiaryHistoryPage`: `dayGroups`, `currentPage`, `totalPages`, `hasEntries`); `DiaryDayGroup` (`date`, `entries[]`) is the per-day grouping DTO. This "final class, constructor-injected repo, one public method, plain readonly DTO" shape is the established service pattern across the codebase (also seen in `src/Service/Chart/*`, `src/Service/Suggestion/*`, `src/Service/Warning/*`).
- **Template** — `templates/diary/history.html.twig`: empty state (`:17-21`), inline SVG 7-day chart (`:23-44`), per-day `<table>` (`:46-91`) with columns **Godzina, Glikemia (mg/dL), WW, Insulina (j.), Aktywność, Akcje** — this is the column set an export should mirror (minus Akcje, plus a combined date+time column since a flat CSV isn't day-grouped). Plain `?page=N` pagination links (`:93-104`), no filter/sort UI of any kind exists in the app today.
- **Nav** — `templates/base.html.twig:31-49` — dropdown with links to dashboard/profile/new-entry/history/logout; natural place to add an "Eksportuj CSV" link.

### Security / authorization pattern

- Role gate: `#[IsGranted('ROLE_USER')]` on every diary action, plus `access_control: ^/(onboarding|profil)$|^/dziennik(/|$)|^/pulpit(/|$)` in `config/packages/security.yaml:30` — already covers any new route under `/dziennik/*`, no config change needed.
- Ownership gate: `src/Security/DiaryEntryVoter.php:14-43` — the app's only Voter, used solely for `edit`/`delete` (id-addressed mutations). **Read/list actions don't use the Voter at all** — they're scoped entirely by the repository's `WHERE user = :user` clause. An export action (read-only, always self-scoped, no id param) should follow the read-path pattern, not the Voter pattern.

### Existing export/CSV/PDF capability

**None exists.** No `StreamedResponse`, `fputcsv`, `text/csv`, `Content-Disposition`, `dompdf`/`tcpdf`/`mpdf`, or `league/csv` usage anywhere in `src/` or `templates/`. `composer.json` has `symfony/serializer` (`^7.4`) as a dependency but it's unused (no `config/packages/serializer.yaml`, no `framework.serializer` block in `config/packages/framework.yaml`). `vendor/symfony/serializer/Encoder/CsvEncoder.php` does exist (bundled, no extra `composer require` needed if used), but wiring up `Serializer`/`ObjectNormalizer` for a handful of known scalar fields is *more* moving parts than a hand-rolled `fputcsv` loop — see recommendation below.

### Test conventions

- `tests/Controller/DiaryControllerTest.php` — `WebTestCase`-style with private helpers `entityManager()`, `createUser()`, `createProfile()`, `createEntry(EntityManagerInterface, User, int $glycemiaMgDl, \DateTimeImmutable $measuredAt, ?\DateTimeImmutable $createdAt = null)`, `cleanupUser()` (raw SQL deletes in FK order: adjustment histories → diary_entries → patient_profiles → users).
- `tests/Service/History/DiaryHistoryServiceTest.php` — `KernelTestCase` with its own local `boot()`/`createUser()`/`createEntry()`/`cleanup()` helpers (each test class keeps its own small helper set rather than sharing a base class — consistent codebase style).
- `phpstan.neon` only scans `src/` at level 5 (tests aren't statically checked); `.php-cs-fixer.dist.php` applies `@Symfony` repo-wide (excluding `var/`).

## Code References

- `src/Entity/DiaryEntry.php:13-177` — the DiaryEntry entity, full field list
- `src/Repository/DiaryEntryRepository.php:23-49` — the two user-scoped, unpaginated query methods
- `migrations/Version20260825132206.php:23-25` — `diary_entries` table creation, FK + index
- `src/Controller/DiaryController.php:115-130` — `history()` action, direct precedent for a new `export()` action
- `src/Service/History/DiaryHistoryService.php:8-41` — established service/DTO pattern to follow (or deliberately diverge from, since export is I/O-shaped not data-shaped)
- `templates/diary/history.html.twig:52-57` — column layout to mirror in the CSV
- `templates/base.html.twig:31-49` — nav dropdown, export link placement
- `src/Security/DiaryEntryVoter.php:14-43` — ownership-gate pattern (not needed for export, but shows the boundary between read-path and mutation-path authorization)
- `src/Entity/RatioAdjustmentHistory.php`, `src/Entity/BaseDoseAdjustmentHistory.php` — adjustment-history entities, explicitly excluded from this slice's scope
- `config/packages/security.yaml:30` — `access_control` regex already covers any new `/dziennik/*` route
- `tests/Controller/DiaryControllerTest.php` — helper conventions to reuse for export tests

## Architecture Insights

- **Every list/read query is scoped by a mandatory `$user` parameter baked into the repository's `WHERE` clause** — there is no "fetch by id then check owner" path for reads anywhere in the app, only for the id-addressed `edit`/`delete` mutations. Export must follow the read-path pattern: never accept entry ids from the request, always derive the scope from `$this->getUser()`.
- **The codebase avoids generic/abstract data-transformation layers.** Services are small, final, constructor-injected, one public method, explicit PHP loops (`DiaryHistoryService` hand-loops to group by day rather than using a collection library). This directly favors a hand-rolled `fputcsv` loop over wiring up `symfony/serializer`'s `CsvEncoder`/`ObjectNormalizer` machinery for CSV generation.
- **The existing history view already fetches a user's entire history in one unbounded query and processes it in PHP** (`DiaryHistoryService::buildPage`) — this is direct precedent that an "export everything" query (via `findByUserOrderedByMeasuredAt`) is an accepted, already-used pattern in this codebase, not a new performance risk.
- **No filter/date-range UI exists anywhere today** — "export what's currently filtered" has no filter state to key off. A full-history export (optionally reusing the ASC-ordered repository method for a natural top-to-bottom read) is the only shape that fits the current UI, matching FR-012's plain "historię swoich pomiarów" wording.
- **PHP 8.5 runtime** (per `php/Dockerfile`, though `composer.json` only requires `>=8.2`) — `fputcsv`'s `escape` parameter should be passed explicitly (e.g. `escape: ''`) to avoid deprecation noise under `failOnDeprecation` in the test suite.
- **UTF-8 BOM** is worth writing before the CSV header row — Polish diacritics (e.g. "Aktywność") can mojibake in Excel without it; a one-line addition, not scope creep.

## Recommended Implementation Approach

(Validated by a design pass against the exact conventions above — ready for `/10x-plan` to formalize.)

1. **New service** `src/Service/Export/DiaryExportService.php` — final class, constructor-injects `DiaryEntryRepository`, one public method `writeCsv(User $user, resource $handle): void` that writes a UTF-8-BOM + header row + one `fputcsv` row per entry (reusing `findByUserOrderedByMeasuredAt($user)`, ASC — no new repository method needed). Keeps `StreamedResponse` construction (an HTTP concern) in the controller, matching the existing controller/service split.
2. **New controller action** `DiaryController::export()` — `GET /dziennik/eksport`, route `diary_entry_export`, `#[IsGranted('ROLE_USER')]`, same `$user = $this->getUser()` scoping as `history()`, returns a `StreamedResponse` piping through `DiaryExportService::writeCsv()`, with `Content-Type: text/csv; charset=UTF-8` and a `Content-Disposition: attachment` header, filename `dziennik-eksport-{export-date}.csv`.
3. **CSV columns** (Polish headers, matching the existing table): `Data i godzina | Glikemia (mg/dL) | WW | Insulina (j.) | Aktywność` — missing `ww`/`insulinDose` render as empty string (not the UI's `—`, which reads badly in a numeric spreadsheet column).
4. **UI entry points** — an "Eksportuj CSV" link in `templates/base.html.twig`'s nav dropdown (after the history link) and an "Eksportuj do CSV" button on `templates/diary/history.html.twig` (visible in both empty and populated states — exporting an empty history is harmless, just a header row).
5. **Tests** — extend `tests/Controller/DiaryControllerTest.php` (reusing its existing helpers) with cases for: header+data-row CSV content, empty-history (header row only), **cross-user isolation** (the security-critical case — user A's export must not contain user B's data), and anonymous-access redirect. Add `tests/Service/Export/DiaryExportServiceTest.php` (mirroring `DiaryHistoryServiceTest`'s local-helper style) writing to `php://memory` and asserting exact row formatting and ASC ordering.
6. **No migration, no new composer dependency** — confirmed: pure read + native `fputcsv`/`StreamedResponse`, both already available.

## Historical Context (from prior changes)

- `context/archive/2026-08-26-diary-history-view/plan.md:74-76` explicitly deferred export: *"Exporting history to PDF/CSV (S-07) — that slice packages this same view."* Confirms this slice is expected to package the history view's data shape, not invent a new query — consistent with reusing `DiaryEntryRepository`'s existing methods.
- `context/archive/2026-08-25-log-diary-entry/` — establishes the `DiaryEntry` entity/field conventions this research is built on.
- `context/archive/2026-08-27-edit-delete-diary-entry/` — introduces the Voter pattern and the `*AdjustmentHistory` entities/cutoff logic; confirmed not directly needed for a read-only export.
- `context/foundation/roadmap.md:233-248` — **S-07 "Eksport historii do PDF/CSV"**, change ID `export-diary-history`, PRD ref FR-012, prerequisites S-05 (`diary-history-view`, done) and S-03 (`insulin-ww-ratio-suggestion`, done), framed as *"głównie zadanie serializacji/raportowania, bez nowego ryzyka domenowego"* (mainly a serialization/reporting task, no new domain risk) — consistent with the "no migration, no new dependency" finding above.
- `context/foundation/prd.md:98-102` — **FR-012** (must-have): patient can export "historię swoich pomiarów i sugerowanych przeliczników" to PDF or CSV; Socrates note explicitly frames this as *replacing* a doctor-account feature for v1. **FR-013** (nice-to-have, v2, parked) is a doctor read-only access code — explicitly out of scope.
- `context/foundation/prd.md:104-108` (NFR) — data-at-rest/in-transit encryption for medical data, and a disclaimer-must-be-visible requirement — the disclaimer requirement applies to recommendation/suggestion UI, not to a raw measurement export, so it doesn't directly constrain this CSV-only, entries-only slice.

## Related Research

- `context/archive/2026-08-26-diary-history-view/research.md` (and `plan.md`) — the history view this export packages.
- `context/archive/2026-08-25-log-diary-entry/research.md` — original `DiaryEntry` data-model research.

## Open Questions

- **Scope deviation to confirm at plan time**: this slice exports diary entries only, not `RatioAdjustmentHistory`/`BaseDoseAdjustmentHistory` — narrower than PRD FR-012's literal wording ("pomiarów i sugerowanych przeliczników"). This was an explicit user decision for this slice (not a gap to silently close) and should be recorded as a documented scope deviation in `plan.md`, with the fuller export left as a future enhancement.
- **PDF export** is deferred entirely (CSV-only for this slice) — a future slice would need to add a PDF library (e.g. `dompdf/dompdf`) via `composer require` and verify it builds cleanly on the `php:8.5-apache` image (`php/Dockerfile`).
- Whether a future filter/date-range UI on the history page should also gain an "export what's filtered" option — moot today since no filter UI exists yet.
