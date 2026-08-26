# Ostrzeżenie o ryzyku hipoglikemii przy wysiłku — Plan Brief

> Pełny plan: `context/changes/activity-hypoglycemia-warning/plan.md`

## What & Why

System ma natychmiast ostrzegać pacjenta o ryzyku hipoglikemii, gdy zapisuje w
dzienniczku wpis z planowanym lub wykonanym wysiłkiem fizycznym — bez
sugerowania konkretnej redukcji dawki (FR-010), zawsze ze zastrzeżeniem
medycznym (FR-011). To S-04 z roadmapy, drugie pierwszorzędowe kryterium
sukcesu produktu obok gwiazdy przewodniej S-03.

## Starting Point

`DiaryEntry` już przechowuje wszystkie potrzebne dane (`glycemiaMgDl`,
`insulinDose`, `activityIntensity`, `activityDurationMinutes`) — to czysto
addytywna funkcja, bez zmian w modelu danych. S-03 dostarczył wzorzec
architektoniczny (bezstanowa usługa + niemutowalny DTO wyniku), ale nie
algorytm — PRD nie definiuje dla S-04 żadnego progu liczbowego.

## Desired End State

Pacjent zapisujący wpis z ryzykownym połączeniem glikemii, insuliny i
intensywności wysiłku widzi natychmiast po zapisie komunikat ostrzegawczy i
disclaimer na tej samej stronie. Bezpieczne wpisy (lub wpisy bez aktywności)
nie generują żadnego dodatkowego komunikatu.

## Key Decisions Made

| Decyzja | Wybór | Dlaczego (1 zdanie) |
| --- | --- | --- |
| Algorytm wyzwalający | Warunkowany aktualną glikemią (skorygowaną o insulinę) | PRD nie precyzuje algorytmu, ale FR-010 mówi o "ryzyku", nie o fakcie samego wysiłku |
| Progi glikemii wg intensywności | Light 90 / Medium 110 / Strong 140 mg/dL | Rosnące z intensywnością, zgodnie z ogólną logiką wytycznych o aktywności w cukrzycy |
| Wpływ insuliny | `insulinDose * 45 mg/dL` odejmowane od glikemii przed porównaniem z progiem | Świadoma decyzja użytkownika — bezpieczne, niepersonalizowane założenie, używane wyłącznie do decyzji wyzwolenia, nigdy pokazywane jako liczba |
| Miejsce i moment | Natychmiastowy flash na `/dziennik/nowy` po zapisie | Dosłownie zgodne z "natychmiastowe ostrzeżenie" z roadmapy |
| Treść komunikatu | Stały, ogólny tekst (bez personalizacji wg intensywności/czasu) | Zgodne z "ogólne ostrzeżenie" z FR-010, najprostsze do utrzymania |
| Disclaimer | Wydzielony do wspólnego partiala `_disclaimer.html.twig` | Trzecie powielenie tego samego tekstu to naturalny próg refaktoryzacji |

## Scope

**W zakresie:**
- Usługa `HypoglycemiaWarningService` oceniająca pojedynczy wpis
- Flash ostrzegawczy + disclaimer na formularzu dodawania wpisu
- Wspólny partial disclaimeru (zastępujący 2 istniejące duplikaty na pulpicie)
- Testy jednostkowe i funkcjonalne pokrywające pełną macierz progów

**Poza zakresem:**
- Zmiany w modelu danych (`DiaryEntry`, `PatientProfile`)
- Karta ostrzeżenia na pulpicie (`/pulpit`)
- Personalizacja treści komunikatu
- Jakiekolwiek wyliczanie i pokazywanie pacjentowi konkretnej przewidywanej
  glikemii lub sugerowanej redukcji dawki

## Architecture / Approach

Nowa, bezstanowa usługa w `src/Service/Warning/` (osobna od `Suggestion/`, bo
FR-010 wprost odróżnia "ostrzeżenie" od "sugestii") ocenia jeden `DiaryEntry`
i zwraca DTO `available`/`message`. `DiaryController::new()` wywołuje ją zaraz
po zapisie i dokłada flash `warning`, renderowany razem z disclaimerem na tej
samej stronie formularza.

## Phases at a Glance

| Faza | Co dostarcza | Główne ryzyko |
| --- | --- | --- |
| 1. Usługa domenowa | `HypoglycemiaWarningService` + wynik | Błąd w granicach progów (off-by-one na `<` vs `<=`) |
| 2. Wspólny disclaimer | `_disclaimer.html.twig`, podmiana 2 miejsc na pulpicie | Regresja wizualna na `/pulpit` (pokryta istniejącym testem) |
| 3. Spięcie z formularzem | Flash `warning` na `/dziennik/nowy` | Brak testu na ścieżkę "brak ostrzeżenia" |
| 4. Testy | Pełna macierz jednostkowa + funkcjonalna | Niepokryty branch, jak F5 w impl-review S-03 |

**Prerequisites:** S-02 (już zaimplementowane) — brak innych zależności.
**Estimated effort:** ~1 sesja, 4 małe fazy.

## Open Risks & Assumptions

- Stała `insulinDose * 45 mg/dL` to świadome, niepersonalizowane założenie
  przyjęte przez użytkownika w tej sesji — nie pochodzi z PRD ani z realnych
  danych klinicznych pacjenta; jeśli w przyszłości pojawi się faktyczny ISF w
  profilu pacjenta, tę stałą warto zastąpić wartością z profilu.
- Progi 90/110/140 mg/dL są orientacyjne, niezweryfikowane medycznie w ramach
  tego projektu.

## Success Criteria (Summary)

- Wpis z ryzykownym połączeniem glikemii/insuliny/intensywności wysiłku
  natychmiast pokazuje ostrzeżenie i disclaimer po zapisie.
- Bezpieczny wpis lub wpis bez aktywności nie pokazuje żadnego dodatkowego
  komunikatu.
- Istniejące funkcje (S-02, S-03) nie regresują — w szczególności wygląd
  disclaimeru na pulpicie pozostaje identyczny.
