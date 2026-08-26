<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Diary History View (S-05) Implementation Plan

- **Plan**: context/changes/diary-history-view/plan.md
- **Mode**: Deep
- **Date**: 2026-08-26
- **Verdict**: REVISE (all findings fixed during triage — see Decisions)
- **Findings**: [0 critical] [2 warnings] [1 observation]

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | WARNING |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | WARNING |
| Plan Completeness | PASS |

## Grounding

5/5 paths ✓ (`src/Repository/DiaryEntryRepository.php`, `src/Entity/DiaryEntry.php`, `src/Controller/DiaryController.php`, `templates/base.html.twig`, `config/packages/security.yaml`), 3/3 symbols/behaviors ✓ (`findByUserOrderedByMeasuredAt` signature, `access_control` regex `^/dziennik(/|$)`, onboarding gate confirmed via `src/EventSubscriber/RequireOnboardingSubscriber.php` — global listener, not a per-controller check), brief↔plan consistent except F1.

## Findings

### F1 — Uzasadnienie "glycemia-only chart" nieprecyzyjnie cytuje PRD

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — realny kompromis; warto się zatrzymać
- **Dimension**: End-State Alignment
- **Location**: plan-brief.md "Key Decisions Made" / plan.md Overview
- **Detail**: PRD Secondary Success Criterion (`context/foundation/prd.md:46`) mówi o wykresie "pomiarów i dawek", a plan pokazuje na wykresie tylko glikemię (dawki są w liście pod wykresem) i nazywał to "matches the PRD's exact wording" — sama decyzja (strefy hipo/norma/hiper mają sens tylko dla mg/dL) jest broniona, ale opis był nieścisły.
- **Fix**: Zmieniono zdanie uzasadnienia w plan-brief.md na opis świadomej interpretacji zamiast dosłownej zgodności z PRD.
- **Decision**: FIXED (Fix w planie) — `context/changes/diary-history-view/plan-brief.md`, wiersz "Chart points"

### F2 — `ChartZoneBand.label` zdefiniowane w DTO, ale nigdzie nie renderowane

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — szybka decyzja; poprawka oczywista
- **Dimension**: Blind Spots
- **Location**: Phase 1 §4 (ChartZoneBand) / Phase 2 §2 (kontrakt szablonu)
- **Detail**: `ChartZoneBand.label` nigdy nie był renderowany w kontrakcie szablonu Fazy 2 — trzy kolorowe pasy tła bez tekstowego opisu (dostępność/czytelność w aplikacji medycznej).
- **Fix**: Dopisano do kontraktu szablonu w Fazie 2 renderowanie `<text>` z `band.label` przy każdym pasie.
- **Decision**: FIXED (Fix w planie) — `context/changes/diary-history-view/plan.md`, Phase 2 §2 Template contract

### F3 — Brak automatycznego testu na wyświetlanie "-" przy pustych polach wpisu

- **Severity**: 👁️ OBSERVATION
- **Impact**: 🏃 LOW — szybka decyzja; poprawka oczywista
- **Dimension**: Blind Spots
- **Location**: Testing Strategy → Integration Tests
- **Detail**: Fallback "-" dla brakującego WW/dawki/aktywności był opisany tylko w Manual Verification, nie w Integration Tests — regresja przeszłaby przez CI niezauważona.
- **Fix**: Dopisano zdanie do Integration Tests o asercji WebTestCase sprawdzającej "-" dla wpisu bez WW/dawki/aktywności.
- **Decision**: FIXED (Fix w planie) — `context/changes/diary-history-view/plan.md`, Testing Strategy → Integration Tests
