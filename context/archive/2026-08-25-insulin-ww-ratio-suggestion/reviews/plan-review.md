<!-- PLAN-REVIEW-REPORT -->
# Przegląd planu: S-03 — Sugestia skorygowanego przelicznika insulina/WW

- **Plan**: `context/changes/insulin-ww-ratio-suggestion/plan.md`
- **Tryb**: Deep
- **Data**: 2026-08-25
- **Ocena**: SOUND (po poprawkach; pierwotnie REVISE)
- **Znaleziska**: 0 krytycznych, 2 ostrzeżenia — oba naprawione w triage

## Oceny wymiarów

| Wymiar | Ocena |
|-----------|---------|
| End-State Alignment | WARNING (naprawione → F1) |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | PASS |
| Plan Completeness | WARNING (naprawione → F2) |

## Grounding

Grounding: 16/16 ścieżek ✓ (encje, repozytoria, kontrolery, subskryber, security.yaml, phpunit.dist.xml, wszystkie pliki testowe), symbole ✓ (`PatientProfile::$baseDose` jest wciąż `float`, `DiaryEntryRepository` bez custom metod, brak `src/Service/`, `RequireOnboardingSubscriber` bez special-case'ów), brief↔plan ✓.

## Znaleziska

### F1 — FR-009 wymaga "jasnego kontekstu" sugestii, którego plan nigdzie nie projektuje

- **Dotkliwość**: ⚠️ WARNING
- **Wpływ**: 🔎 MEDIUM — realny kompromis; warto się chwilę zastanowić
- **Wymiar**: End-State Alignment
- **Lokalizacja**: Faza 4 (DTOs) i Faza 5 §2 (Template)
- **Szczegóły**: `prd.md` FR-009 (Socrates rozstrzygnięcie, linia 92): "System sugeruje zmianę przelicznika, lecz wymaga wyraźnej akceptacji... Sugestia wyświetla jasny kontekst (np. 'Ostatnie 3 posiłki poskutkowały zbyt wysoką glikemią')." To wymaganie jest osobne od US-01 AC (które wymaga tylko przycisku i disclaimera) i osobne od FR-011 (disclaimer). Plan nigdzie go nie adresuje: `RatioSuggestionResult`/`BaseDoseSuggestionResult` (Faza 4 §2) niosą wyłącznie `available`/`currentX`/`suggestedX` — brak pola na tekst wyjaśniający *dlaczego* system sugeruje zmianę. Kontrakt szablonu (Faza 5 §2) też nie wspomina żadnej treści kontekstowej, tylko wartość + disclaimer + przycisk. Sprawdzone w `research.md` i `plan-brief.md` — temat nigdzie nie został świadomie odrzucony ani odłożony, po prostu nie pojawia się. Wszystkie kryteria sukcesu planu mogą przejść (karta się renderuje, wartość poprawna), a mimo to FR-009 pozostanie tylko częściowo zrealizowane.
- **Naprawa A ⭐ Rekomendowana**: Dodać pole kontekstowe do obu DTO i wyświetlić je w szablonie
  - Zalety: W pełni realizuje FR-009; oba serwisy już znają kierunek triggera (wzrost/spadek dla ratio, powyżej/poniżej pasma dla base-dose), więc wygenerowanie 2-4 wariantów tekstu to mały dodatek do istniejącej logiki, nie nowa funkcja.
  - Koszt: Dotyka 4 pliki (2 DTO, 2 serwisy) + kontrakt szablonu w Fazie 5; wymaga decyzji o dokładnych sformułowaniach PL dla każdego wariantu.
  - Pewność: WYSOKA — dane potrzebne do zbudowania komunikatu (kierunek, liczba par/dni) są już obliczane przez algorytm, nic nowego nie trzeba pobierać z bazy.
  - Ślepy punkt: Dokładne brzmienie komunikatów nie było konsultowane z product ownerem (w przeciwieństwie do reszty algorytmu, który ma 8 worked examples zweryfikowanych wprost z nim).
- **Naprawa B**: Uznać wyświetlane wartości liczbowe (current → suggested) za wystarczający "kontekst" i udokumentować to jako świadomą decyzję zakresu w Open Risks & Assumptions
  - Zalety: Zero dodatkowej pracy implementacyjnej; "np." w Socrates rozstrzygnięciu sugeruje, że dokładny przykładowy tekst nie jest twardym wymaganiem.
  - Koszt: Węższa interpretacja FR-009 niż to, co Socrates rozstrzygnięcie explicite opisuje — ryzyko, że przy przyszłym audycie zgodności z FR to wypadnie jako niedociągnięcie.
  - Pewność: ŚREDNIA — zależy od tego, jak dosłownie traktować przykładowy cytat w PRD.
  - Ślepy punkt: Czy US-01 AC (które nie wspomina kontekstu) powinno przeważać nad FR-009 (które go wspomina) w razie konfliktu — nie rozstrzygnięte nigdzie w dotychczasowej dokumentacji.
- **Decyzja**: FIXED (Naprawa A) — `plan.md` zaktualizowany: DTO `RatioSuggestionResult`/`BaseDoseSuggestionResult` (Faza 4 §2) niosą teraz `readonly ?string $context`; algorytmy (Faza 4 §3 krok 8, §4 krok 8) generują tekst kontekstowy wg kierunku triggera; kontrakt szablonu (Faza 5 §2) wyświetla go nad wartością; testy jednostkowe (Faza 6) sprawdzają tekst dla obu kierunków.

### F2 — `SecurityControllerTest.php` pominięty w liście plików Fazy 1, metoda weryfikacji (grep po "baseDose") nie łapie konstrukcji pozycyjnych

- **Dotkliwość**: ⚠️ WARNING
- **Wpływ**: 🏃 LOW — szybka decyzja; naprawa oczywista i wąsko zakresowa
- **Wymiar**: Plan Completeness
- **Lokalizacja**: Faza 1 §4 (Existing test updates)
- **Szczegóły**: Blast-radius sweep (`grep -rn "new PatientProfile(" src/ tests/`) znalazł `tests/Controller/SecurityControllerTest.php:21` i `:72` — `new PatientProfile($user, 10.0, 1.0)` — których nie ma na liście plików Fazy 1 §4 (`PatientProfileTest`, `DiaryEntryTest`, `DiaryControllerTest`, `ProfileControllerTest`, `OnboardingControllerTest`, `HomeControllerTest`). Powód: to konstrukcja pozycyjna (bez nazwanego argumentu `baseDose: ...`), więc metoda weryfikacji, którą plan sam zaleca (`grep -rn "baseDose" tests/`), jej nie znajduje. W praktyce nieszkodliwe *dzisiaj*: literał `10.0` to pełna liczba, więc przy koercji float→int (kodebase nigdzie nie używa `declare(strict_types=1)`, sprawdzone) nie wywoła deprecation ani błędu — testy przejdą bez zmian. Ale (a) łamie własną normę planu ("Whole-number floats... should also be normalized to int literals for consistency") i (b) ta sama metoda weryfikacji przeoczyłaby analogiczny ułamkowy literał w przyszłości.
- **Naprawa**: Dodać `tests/Controller/SecurityControllerTest.php` do listy plików w Fazie 1 §4 (dwa literały `10.0` → `10`) i zmienić instrukcję weryfikacji z `grep -rn "baseDose" tests/` na `grep -rn "baseDose\|new PatientProfile(\|new DiaryEntry(" tests/`.
- **Decyzja**: FIXED — `plan.md` Faza 1 §4 zaktualizowana: `SecurityControllerTest.php` dodany do listy plików, grep w Contract rozszerzony o `new PatientProfile(\|new DiaryEntry(`, dodany reprezentatywny przykład dla obu linii pliku.
