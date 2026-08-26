# Ostrzeżenie o ryzyku hipoglikemii przy wysiłku — Plan implementacji

## Overview

Dodajemy natychmiastowe ostrzeżenie o ryzyku hipoglikemii, wyświetlane pacjentowi
zaraz po zapisaniu wpisu w dzienniczku zawierającego dane o wysiłku fizycznym
(intensywność + czas trwania). Ostrzeżenie nie sugeruje konkretnej redukcji dawki
(FR-010) i zawsze towarzyszy mu stałe zastrzeżenie medyczne (FR-011). Realizuje
S-04 z `context/foundation/roadmap.md`.

## Current State Analysis

- `DiaryEntry` (`src/Entity/DiaryEntry.php`) już przechowuje wszystkie potrzebne
  dane: `glycemiaMgDl` (int, wymagane), `insulinDose` (?float), `activityIntensity`
  (?ActivityIntensity), `activityDurationMinutes` (?int) — pola aktywności są
  sparowane walidacją (`validateActivityPairing`, linie 163-176). Model danych nie
  wymaga żadnych zmian.
- `ActivityIntensity` (`src/Entity/ActivityIntensity.php`) to string-backed enum:
  `Light`, `Medium`, `Strong`.
- `DiaryController::new()` (`src/Controller/DiaryController.php:20-51`) po udanym
  zapisie ustawia flash `success` i przekierowuje na ten sam route
  (`redirectToRoute('diary_entry_new')`) — dokładnie tu ląduje "natychmiastowe"
  ostrzeżenie, jako kolejny typ flash message.
- Wzorzec z S-03 (`src/Service/Suggestion/InsulinWwRatioSuggestionService.php`,
  `RatioSuggestionResult.php`) pokazuje architekturę do naśladowania: `final class`
  bez konfiguracji DI (autowiring z `App\` namespace), niemutowalny DTO z prywatnym
  konstruktorem i fabrykami `::suggest()`/`::none()`. S-04 jest jednak prostsze —
  nie potrzebuje repozytoriów ani historii, bo działa wyłącznie na danych z
  pojedynczego, właśnie zapisanego wpisu (roadmap opisuje S-04 jako "bezstanowe").
- Zastrzeżenie medyczne jest dziś zduplikowane inline w dwóch miejscach:
  `templates/dashboard/index.html.twig:24` i `:39`. Trzecie wystąpienie (to, które
  dodajemy) jest okazją do wydzielenia partiala.
- `PatientProfile` (`src/Entity/PatientProfile.php`) przechowuje tylko `baseDose`
  i `insulinWwRatio` — nie ma żadnego współczynnika wrażliwości na insulinę (ISF).
  PRD (`context/foundation/prd.md`, sekcja Business Logic) nie definiuje algorytmu
  dla S-04 — próg i formuła poniżej to decyzja podjęta wspólnie z użytkownikiem
  podczas tej sesji planowania.

## Desired End State

Po zapisaniu wpisu z aktywnością fizyczną, jeśli glikemia (skorygowana o
przewidywany spadek od podanej insuliny) spada poniżej progu bezpieczeństwa
właściwego dla intensywności wysiłku, pacjent natychmiast widzi na stronie
`/dziennik/nowy` komunikat ostrzegawczy oraz zastrzeżenie medyczne — bez żadnej
sugestii konkretnej redukcji dawki. Weryfikacja: zapisanie wpisu z niską glikemią
i intensywnym wysiłkiem pokazuje ostrzeżenie; zapisanie wpisu z bezpieczną
glikemią lub bez aktywności — nie pokazuje.

### Key Discoveries:

- `src/Controller/DiaryController.php:40-46` — miejsce wpięcia natychmiastowego
  ostrzeżenia (po `flush()`, przed `redirectToRoute`).
- `src/Service/Suggestion/RatioSuggestionResult.php` — wzorzec DTO do
  powtórzenia (prywatny konstruktor + `::suggest()`/`::none()` → tu
  `::warn()`/`::none()`).
- `templates/dashboard/index.html.twig:24,39` — dwa istniejące wystąpienia
  disclaimeru do zamiany na `include`.
- `config/packages/security.yaml:30` — `/dziennik` i `/pulpit` są już objęte
  `access_control`; ta zmiana nie dodaje nowych route'ów, więc security.yaml
  zostaje bez zmian.

## What We're NOT Doing

- Brak nowych pól/migracji w `DiaryEntry` ani `PatientProfile`.
- Brak personalizacji treści komunikatu wg intensywności/czasu wysiłku — tekst
  ostrzeżenia jest stały (decyzja z sesji planowania).
- Brak obliczania i pokazywania pacjentowi konkretnej przewidywanej wartości
  glikemii lub sugerowanej redukcji dawki — formuła spadku od insuliny służy
  wyłącznie do wewnętrznej decyzji "pokazać/nie pokazać ostrzeżenie", nigdy nie
  trafia do treści komunikatu.
- Brak karty na pulpicie (`/pulpit`) dla tego ostrzeżenia — tylko natychmiastowy
  flash na `/dziennik/nowy` (decyzja z sesji planowania).
- Brak zmian w `DashboardController` — dotykamy tylko szablonu (ekstrakcja
  disclaimeru).

## Implementation Approach

Nowa, czysto funkcyjna usługa domenowa (`HypoglycemiaWarningService`) ocenia
pojedynczy `DiaryEntry` i zwraca niemutowalny wynik. `DiaryController::new()`
wywołuje ją zaraz po zapisie i — jeśli ryzyko wykryte — dokłada flash `warning`.
Disclaimer zostaje wydzielony do współdzielonego partiala Twig, użytego zarówno
w nowym miejscu, jak i w dwóch istniejących na pulpicie (regresja pokryta przez
istniejące testy `DashboardControllerTest`, które sprawdzają tekst disclaimeru).

## Critical Implementation Details

**Formuła wyzwalająca (ustalona z użytkownikiem)**: dla wpisu z ustawioną
`activityIntensity`, próg bezpieczeństwa glikemii zależy od intensywności:
Light = 90 mg/dL, Medium = 110 mg/dL, Strong = 140 mg/dL. Podana w tym samym
wpisie `insulinDose` obniża "projektowaną" glikemię o 45 mg/dL na jednostkę
(przyjęta bezpiecznie, niepersonalizowana stała — tylko do decyzji wyzwolenia,
nigdy nie prezentowana pacjentowi jako liczba). Ostrzeżenie pojawia się, gdy
`glycemiaMgDl - insulinDose * 45 < próg(intensywność)` (ostra nierówność — wynik
równy progowi NIE wyzwala ostrzeżenia). Brak `activityIntensity` → zawsze
`none()`, niezależnie od pozostałych pól.

## Phase 1: Usługa domenowa ostrzeżenia

### Overview

Czysta, bezstanowa usługa oceniająca ryzyko hipoglikemii na podstawie
pojedynczego `DiaryEntry` — bez zależności od repozytoriów, historii czy
`PatientProfile`.

### Changes Required:

#### 1. Wynik oceny ryzyka

**File**: `src/Service/Warning/HypoglycemiaWarningResult.php`

**Intent**: Niemutowalny DTO wyniku, analogiczny do `RatioSuggestionResult` z
S-03, ale prostszy (bez pól liczbowych — tylko dostępność i gotowy tekst
komunikatu).

**Contract**: `final class` z prywatnym konstruktorem i dwiema statycznymi
fabrykami:
```php
public readonly bool $available;
public readonly ?string $message;

public static function warn(string $message): self;
public static function none(): self;
```

#### 2. Usługa oceniająca

**File**: `src/Service/Warning/HypoglycemiaWarningService.php`

**Intent**: Ocenia jeden `DiaryEntry` wg formuły z sekcji "Critical
Implementation Details" i zwraca `HypoglycemiaWarningResult`.

**Contract**: `final class HypoglycemiaWarningService` bez zależności
konstruktorowych (autowired przez `App\` namespace jak reszta serwisów —
`config/services.yaml:14-19`). Publiczna metoda `evaluate(DiaryEntry $entry):
HypoglycemiaWarningResult`. Progi i stała spadku insuliny jako `public const`
(wzorzec z `InsulinWwRatioSuggestionService::RATIO_THRESHOLD_MGDL` itp.):
`THRESHOLD_LIGHT_MGDL = 90`, `THRESHOLD_MEDIUM_MGDL = 110`,
`THRESHOLD_STRONG_MGDL = 140`, `INSULIN_DROP_PER_UNIT_MGDL = 45`. Treść
komunikatu jako `private const BASE_MESSAGE` — stały, ogólny tekst zgodny z
FR-010 (informuje o ryzyku, zaleca wzmożoną kontrolę cukru i rozważenie
dodatkowych WW, bez konkretnej liczby jednostek/redukcji). `insulinDose` jest
nullable (`?float`) — obliczenie musi użyć
`($entry->getInsulinDose() ?? 0.0) * self::INSULIN_DROP_PER_UNIT_MGDL`, tak
jak analogiczne miejsca w `InsulinWwRatioSuggestionService.php:99` i
`BaseDoseSuggestionService.php:109`, żeby uniknąć błędu PHPStan „Only numeric
types are allowed in *” przy poziomie 5.

### Success Criteria:

#### Automated Verification:

- Nowe testy jednostkowe przechodzą: `docker compose exec php vendor/bin/phpunit --filter HypoglycemiaWarningServiceTest`
- Statyczna analiza: `docker compose exec php vendor/bin/phpstan analyse`
- Styl kodu: `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run`

#### Manual Verification:

- Brak — czysta logika domenowa bez UI, w pełni pokryta testami automatycznymi
  z tej fazy.

---

## Phase 2: Wspólny partial disclaimeru

### Overview

Wydzielenie zdublowanego tekstu zastrzeżenia medycznego do jednego
współdzielonego pliku Twig, przed dodaniem trzeciego miejsca użycia.

### Changes Required:

#### 1. Nowy partial

**File**: `templates/_disclaimer.html.twig`

**Intent**: Jedno źródło prawdy dla tekstu wymaganego przez FR-011.

**Contract**: Zawiera dokładnie istniejący znacznik i tekst z
`templates/dashboard/index.html.twig:24` (`<p><small>Sugestia ma charakter
algorytmiczny i nie zastępuje konsultacji lekarskiej. Każdą zmianę dawkowania
skonsultuj z lekarzem prowadzącym.</small></p>`), bez zmian treści.

#### 2. Podmiana istniejących wystąpień

**File**: `templates/dashboard/index.html.twig`

**Intent**: Usunięcie duplikacji — oba `<article>` (linie 12-25 i 27-40) mają
teraz korzystać ze wspólnego partiala zamiast inline tekstu.

**Contract**: Linie 24 i 39 zastąpione przez `{% include '_disclaimer.html.twig' %}`.

### Success Criteria:

#### Automated Verification:

- Istniejące testy `DashboardControllerTest` nadal przechodzą (sprawdzają
  obecność tekstu disclaimeru — regresja wizualna wykryta automatycznie):
  `docker compose exec php vendor/bin/phpunit --filter DashboardControllerTest`
- Styl kodu: `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run`

#### Manual Verification:

- Wejść na `/pulpit` i potwierdzić, że oba disclaimery nadal się wyświetlają
  identycznie jak przed zmianą.

---

## Phase 3: Spięcie z formularzem dodawania wpisu

### Overview

`DiaryController::new()` wywołuje nową usługę zaraz po zapisaniu wpisu i — gdy
wykryte ryzyko — dokłada flash `warning`, renderowany razem z disclaimerem na
`templates/diary/new.html.twig`.

### Changes Required:

#### 1. Kontroler

**File**: `src/Controller/DiaryController.php`

**Intent**: Po `$entityManager->flush()` (linia 42) ocenić właśnie zapisany
`$entry` i, jeśli `available`, dodać flash `warning` z treścią komunikatu —
przed istniejącym `redirectToRoute('diary_entry_new')` (linia 45).

**Contract**: Nowy parametr `HypoglycemiaWarningService $hypoglycemiaWarningService`
w sygnaturze `new()` (autowired jak pozostałe zależności akcji). `if
($warning->available) { $this->addFlash('warning', $warning->message); }`.

#### 2. Szablon formularza

**File**: `templates/diary/new.html.twig`

**Intent**: Wyrenderować flash `warning` (analogicznie do istniejącego bloku
`app.flashes('success')`, linie 8-10) razem z disclaimerem bezpośrednio pod
komunikatem, zgodnie z FR-011 ("bezpośrednio pod każdą sugestią").

**Contract**: Nowy blok `{% for message in app.flashes('warning') %}` przed
`form_start(form)`, renderujący komunikat oraz `{% include
'_disclaimer.html.twig' %}` wewnątrz pętli.

### Success Criteria:

#### Automated Verification:

- Istniejące testy `DiaryControllerTest` nadal przechodzą (nie regresują
  przepływu zapisu): `docker compose exec php vendor/bin/phpunit --filter DiaryControllerTest`
- Statyczna analiza: `docker compose exec php vendor/bin/phpstan analyse`
- Styl kodu: `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run`

#### Manual Verification:

- Zalogować się jako pacjent, dodać wpis z niską glikemią + intensywnym
  wysiłkiem — ostrzeżenie i disclaimer widoczne natychmiast po zapisie.
- Dodać wpis z bezpieczną glikemią lub bez aktywności — brak ostrzeżenia,
  tylko standardowy komunikat sukcesu.

---

## Phase 4: Testy pokrywające logikę i przepływ

### Overview

Testy jednostkowe całej macierzy przypadków progowych usługi oraz rozszerzenie
istniejącego testu funkcjonalnego kontrolera o scenariusze z ostrzeżeniem i bez
— zgodnie z konwencją "worked example jako test regresyjny" z S-03 oraz
praktyką impl-review S-03 (F5: pokrycie testem każdej gałęzi warunkowej).

### Changes Required:

#### 1. Test jednostkowy usługi

**File**: `tests/Service/Warning/HypoglycemiaWarningServiceTest.php`

**Intent**: Pokryć macierz: brak aktywności → `none()`; dla każdej
intensywności — tuż poniżej progu → `warn()`, dokładnie na progu → `none()`,
tuż powyżej progu → `none()`; wzmocnienie insuliną przesuwające wynik z `none()`
do `warn()`.

**Contract**: Zwykły `PHPUnit\Framework\TestCase` (usługa nie dotyka bazy ani
kernela — szybszy test niż wzorzec `KernelTestCase` z S-03). Przykładowe
przypadki liczbowe do zakodowania:
- Light, glycemia=85, insulinDose=null → warn (85 < 90)
- Light, glycemia=90, insulinDose=null → none (90 !< 90, granica)
- Medium, glycemia=150, insulinDose=1.5 → warn (150 - 67.5 = 82.5 < 110)
- Strong, glycemia=145, insulinDose=null → none (145 !< 140)
- Strong, glycemia=200, insulinDose=2.0 → warn (200 - 90 = 110 < 140)
- activityIntensity=null, glycemia=50 → none (niezależnie od reszty pól)

#### 2. Rozszerzenie testu funkcjonalnego

**File**: `tests/Controller/DiaryControllerTest.php`

**Intent**: Dodać scenariusze potwierdzające, że flash `warning` i disclaimer
faktycznie docierają do użytkownika po zapisie ryzykownego wpisu, oraz że
bezpieczny wpis (lub wpis bez aktywności) go nie pokazuje — zapobiega lukom
jak F5 z impl-review S-03 (niepokryty testem branch strażnika).

**Contract**: Dwa nowe testy w istniejącej klasie, reużywające prywatnych
helperów `createUser`/`createProfile`/`cleanupUser`: jeden submit z
`activityIntensity=Strong`, `glycemiaMgDl` poniżej progu → `assertSelectorTextContains`
na treść ostrzeżenia i disclaimeru po `followRedirect()`; drugi submit z
bezpieczną glikemią → brak tego tekstu w odpowiedzi.

### Success Criteria:

#### Automated Verification:

- Pełna bramka jakości: `docker compose exec php vendor/bin/phpstan analyse`
- `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run`
- `docker compose exec php vendor/bin/phpunit`

#### Manual Verification:

- Przegląd wyniku `phpunit` — brak `failOnDeprecation`/`failOnNotice` w nowym
  kodzie (np. przy porównaniach float/int w formule progu).

**Implementation Note**: Po zakończeniu tej fazy i przejściu wszystkich
automatycznych weryfikacji, zatrzymaj się i poczekaj na potwierdzenie manualnych
testów, zanim ogłosisz zmianę za zakończoną.

---

## Testing Strategy

### Unit Tests:

- Pełna macierz progów × intensywność × obecność insuliny w
  `HypoglycemiaWarningServiceTest` (patrz Phase 1/4).

### Integration Tests:

- Pełny przepływ POST `/dziennik/nowy` → flash `warning` + disclaimer widoczny
  po przekierowaniu (Phase 4).

### Manual Testing Steps:

1. Zalogować się jako pacjent z istniejącym profilem.
2. Dodać wpis: glikemia 80, intensywność Mocny, dawka insuliny 1 j. →
   oczekiwane natychmiastowe ostrzeżenie + disclaimer.
3. Dodać wpis: glikemia 180, intensywność Lekki, bez insuliny → brak
   ostrzeżenia.
4. Dodać wpis bez żadnych danych o aktywności → brak ostrzeżenia.
5. Odwiedzić `/pulpit` i potwierdzić, że oba disclaimery sugestii wyglądają
   identycznie jak przed zmianą (regresja wizualna po ekstrakcji partiala).

## Performance Considerations

Brak — czysta funkcja arytmetyczna na już załadowanej encji, żadnych
dodatkowych zapytań do bazy ani wywołań zewnętrznych.

## Migration Notes

Nie dotyczy — brak zmian schematu bazy danych.

## References

- Roadmap: `context/foundation/roadmap.md` (S-04, linie 187-201)
- PRD: `context/foundation/prd.md` (FR-010, FR-011, linie 93-96)
- Wzorzec do naśladowania: `src/Service/Suggestion/RatioSuggestionResult.php`,
  `src/Service/Suggestion/InsulinWwRatioSuggestionService.php`
- Zarchiwizowany plan S-03: `context/archive/2026-08-25-insulin-ww-ratio-suggestion/plan.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Usługa domenowa ostrzeżenia

#### Automated

- [x] 1.1 Nowe testy jednostkowe przechodzą: `phpunit --filter HypoglycemiaWarningServiceTest` — 2f291b5
- [x] 1.2 Statyczna analiza: `phpstan analyse` — 2f291b5
- [x] 1.3 Styl kodu: `php-cs-fixer fix --dry-run` — 2f291b5

### Phase 2: Wspólny partial disclaimeru

#### Automated

- [x] 2.1 Istniejące testy `DashboardControllerTest` nadal przechodzą
- [x] 2.2 Styl kodu: `php-cs-fixer fix --dry-run`

#### Manual

- [x] 2.3 `/pulpit` — oba disclaimery wyglądają identycznie jak przed zmianą

### Phase 3: Spięcie z formularzem dodawania wpisu

#### Automated

- [ ] 3.1 Istniejące testy `DiaryControllerTest` nadal przechodzą
- [ ] 3.2 Statyczna analiza: `phpstan analyse`
- [ ] 3.3 Styl kodu: `php-cs-fixer fix --dry-run`

#### Manual

- [ ] 3.4 Wpis z niską glikemią + intensywnym wysiłkiem → ostrzeżenie i disclaimer widoczne natychmiast
- [ ] 3.5 Wpis z bezpieczną glikemią lub bez aktywności → brak ostrzeżenia

### Phase 4: Testy pokrywające logikę i przepływ

#### Automated

- [ ] 4.1 `phpstan analyse`
- [ ] 4.2 `php-cs-fixer fix --dry-run`
- [ ] 4.3 `phpunit` (pełny gate)

#### Manual

- [ ] 4.4 Przegląd wyniku `phpunit` pod kątem `failOnDeprecation`/`failOnNotice`
