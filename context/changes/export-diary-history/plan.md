# Eksport historii dziennika do CSV — Implementation Plan

## Overview

Dodajemy eksport CSV bieżąco przeglądanej strony historii dziennika (`/dziennik/historia?page=N`) — nowy endpoint, mały serwis serializujący i przycisk na stronie historii. To roadmapowy slice **S-07** (`context/foundation/roadmap.md:233-248`), PRD ref **FR-012**.

## Current State Analysis

- `DiaryEntry` (`src/Entity/DiaryEntry.php`) to jedyna encja domenowa dla wpisów dziennika; `DiaryEntryRepository` (`src/Repository/DiaryEntryRepository.php`) ma dwie metody scope'owane po użytkowniku, bez paginacji na poziomie SQL.
- `DiaryHistoryService::buildPage(User $user, int $requestedPage): DiaryHistoryPage` (`src/Service/History/DiaryHistoryService.php:17-40`) już pobiera całą historię, grupuje po dniach i paginuje w PHP (7 grup dziennych/strona) — to dokładnie ten sam wycinek danych, który ma trafić do CSV.
- `DiaryController::history()` (`src/Controller/DiaryController.php:115-130`) to bezpośredni wzorzec: `$user = $this->getUser()`, `$page = max(1, $request->query->getInt('page', 1))`, brak parametrów id w żądaniu.
- `templates/diary/history.html.twig:52-57` — układ kolumn tabeli (Godzina, Glikemia, WW, Insulina, Aktywność), punkt odniesienia dla CSV.
- `RequireOnboardingSubscriber` (`src/EventSubscriber/RequireOnboardingSubscriber.php`) globalnie przekierowuje zalogowanych użytkowników bez profilu na `/onboarding` dla każdej trasy poza `patient_onboarding`/`app_logout` — nowa trasa eksportu automatycznie to dziedziczy, żadna dodatkowa logika w kontrolerze nie jest potrzebna.
- `config/packages/security.yaml:30` — `access_control` regex `^/dziennik(/|$)` już pokrywa dowolną nową trasę pod `/dziennik/*`.
- Brak jakiegokolwiek kodu eksportu/CSV/PDF w repo; brak nowej zależności composera (CSV przez natywny `fputcsv`).

## Desired End State

Pacjent przeglądający `/dziennik/historia?page=N` widzi przycisk „Eksportuj tę stronę (CSV)”. Kliknięcie pobiera plik CSV (separator `;`, przecinek dziesiętny, BOM UTF-8) zawierający dokładnie te same grupy dni/wpisy, które są aktualnie wyświetlone na tej stronie, w tej samej kolejności co na ekranie (dni malejąco, wpisy w dniu malejąco).

Weryfikacja: kliknięcie przycisku w przeglądarce pobiera plik `.csv`, który otwarty w arkuszu kalkulacyjnym z polskimi ustawieniami regionalnymi pokazuje poprawnie rozdzielone kolumny i liczby z przecinkiem.

### Key Discoveries:

- `src/Service/History/DiaryHistoryService.php:17-40` — `buildPage()` już liczy dokładnie potrzebny wycinek danych; zero nowych metod repozytorium.
- `src/EventSubscriber/RequireOnboardingSubscriber.php` — ochrona przed brakiem profilu jest globalna, nie per-akcja.
- `tests/Controller/DiaryControllerTest.php:604-637` — prywatne helpery (`entityManager()`, `createUser()`, `createProfile()`, `createEntry()`, `cleanupUser()`, `csrfToken()`) do bezpośredniego reużycia.
- `config/packages/security.yaml:16-27` — anonimowi użytkownicy trafiają na `app_login` (form_login `login_path: app_login`).

## What We're NOT Doing

- Eksport `RatioAdjustmentHistory`/`BaseDoseAdjustmentHistory` („sugerowane przeliczniki”) — FR-012 wspomina o nich literalnie, ale to świadome zawężenie zakresu tego slice'u; pełniejszy eksport to przyszłe rozszerzenie.
- Eksport PDF — tylko CSV w tym slice.
- Link w menu nawigacyjnym (`templates/base.html.twig`) — tylko przycisk na stronie historii.
- Opcja „eksportuj całą historię” — eksport zawsze dotyczy aktualnie oglądanej strony.
- UI filtrowania po zakresie dat.
- Migracja bazy danych i nowa zależność composera — żadna z nich nie jest potrzebna.

## Implementation Approach

Dwie fazy: (1) backend — serwis serializujący + endpoint + testy, (2) UI — przycisk na stronie historii + test jego obecności/działania. Każdy element podąża 1:1 za istniejącymi wzorcami (`DiaryHistoryService`, `DiaryController::history()`, konwencje testowe z `DiaryControllerTest`).

## Critical Implementation Details

### Format liczb i separator pod polski Excel

Wybrany format CSV używa `;` jako separatora pól i `,` jako separatora dziesiętnego (domyślne ustawienia polskiego Excela). `fputcsv()` domyślnie castuje liczby zmiennoprzecinkowe z kropką — wartości `ww`/`insulinDose` muszą być ręcznie sformatowane (`str_replace('.', ',', (string) $value)`) **przed** przekazaniem do `fputcsv()`. Każde wywołanie `fputcsv()` musi jawnie przekazywać `delimiter: ';'` oraz `escape: ''` (PHP 8.1+ deprecates domyślny znak escape, a pakiet testów uruchamia się z `failOnDeprecation`).

### Eksport musi odpowiadać dokładnie oglądanej stronie

Przycisk na `/dziennik/historia?page=N` musi linkować do `/dziennik/eksport?page=N` (nie do `/dziennik/eksport` bez parametru). Akcja `export()` odczytuje `page` z żądania dokładnie tak jak `history()` (`max(1, $request->query->getInt('page', 1))`) i wywołuje `DiaryHistoryService::buildPage($user, $page)` — dzięki temu wyeksportowane wiersze są gwarantowanie identyczne z tym, co widać na ekranie, łącznie z clampingiem strony w `DiaryHistoryPage` (`[1, totalPages]`).

---

## Phase 1: Serwis eksportu CSV i endpoint

### Overview

Nowy serwis serializujący `DiaryHistoryPage` do CSV oraz nowa akcja kontrolera zwracająca `StreamedResponse`.

### Changes Required:

#### 1. Nowy serwis eksportu

**File**: `src/Service/Export/DiaryExportService.php`

**Intent**: Zserializować grupy dni z `DiaryHistoryPage` do wierszy CSV w formacie polskim (średnik, przecinek dziesiętny), pisząc bezpośrednio do uchwytu strumienia, żeby kontroler mógł go owinąć w `StreamedResponse`.

**Contract**: `final class DiaryExportService` z jedną publiczną metodą `writeCsv(DiaryHistoryPage $historyPage, $handle): void`. Pisze BOM UTF-8, nagłówek `Data i godzina;Glikemia (mg/dL);WW;Insulina (j.);Intensywność aktywności;Czas aktywności (min)`, potem jeden wiersz na wpis (iterując `$historyPage->dayGroups` w istniejącej kolejności — dni malejąco, wpisy w dniu malejąco, tak jak w szablonie). Brakujące `ww`/`insulinDose` → pusty string; brak aktywności → obie kolumny puste. Przykład formatowania liczby (jedyny nieoczywisty fragment):

```php
private function formatDecimal(?float $value): string
{
    return null === $value ? '' : str_replace('.', ',', (string) $value);
}
```

#### 2. Nowa akcja kontrolera

**File**: `src/Controller/DiaryController.php`

**Intent**: Dodać akcję `export()` obok `history()`, reużywającą `DiaryHistoryService::buildPage()` z tym samym stronicowaniem, i strumieniującą wynik `DiaryExportService::writeCsv()` jako plik do pobrania.

**Contract**: `#[Route('/dziennik/eksport', name: 'diary_entry_export', methods: ['GET'])]`, `#[IsGranted('ROLE_USER')]`, sygnatura `export(Request $request, DiaryHistoryService $diaryHistoryService, DiaryExportService $diaryExportService): StreamedResponse`. Nagłówki odpowiedzi: `Content-Type: text/csv; charset=UTF-8`, `Content-Disposition: attachment` z nazwą pliku `dziennik-eksport-strona-{page}-{Y-m-d}.csv` (przez `$response->headers->makeDisposition(...)`, tak jak reszta kontrolera buduje odpowiedzi). Dodać `use App\Service\Export\DiaryExportService;` i `use Symfony\Component\HttpFoundation\StreamedResponse;` do bloku `use`.

### Success Criteria:

#### Automated Verification:

- `docker compose exec php vendor/bin/phpstan analyse` przechodzi (poziom 5, `src/`)
- `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run` przechodzi
- `docker compose exec php vendor/bin/phpunit` przechodzi, w tym nowe testy:
  - `tests/Controller/DiaryControllerTest.php`: `testExportReturnsCsvForCurrentPageWithHeaderAndDataRows` (nagłówek + wiersze, `Content-Type`/`Content-Disposition` zawierają oczekiwane wartości), `testExportOnlyIncludesRequestingUsersEntries` (izolacja między użytkownikami — dwóch userów, każdy z odróżnialnym wpisem na tej samej stronie 1, CSV zalogowanego usera nie zawiera wpisu drugiego usera; reużywa istniejącego wzorca `createUser`/`createEntry`/`cleanupUser`), `testExportProfilelessAuthenticatedUserIsRedirectedToOnboarding` (analogiczny do istniejącego `testHistoryProfilelessAuthenticatedUserIsRedirectedToOnboarding`), `testExportRequiresAuthentication` (anonimowy request → `assertResponseRedirects` w stronę `app_login`), `testExportWithNoEntriesReturnsHeaderOnlyCsv` (użytkownik bez żadnych wpisów, bezpośrednie `GET /dziennik/eksport?page=1` — CSV zawiera tylko BOM+nagłówek, bez wierszy danych, mimo że przycisk eksportu jest w tym stanie ukryty w UI)
  - nowy plik `tests/Service/Export/DiaryExportServiceTest.php` (mirror stylu `DiaryHistoryServiceTest`: lokalne `boot()`/`createUser()`/`createEntry()`/`cleanup()`): pisanie do `fopen('php://memory', 'r+')`, `rewind()`, `stream_get_contents()`, asercje dokładnej treści nagłówka, formatowania przecinka dziesiętnego, pustych kolumn dla brakujących wartości i pustych kolumn aktywności

#### Manual Verification:

- Zalogowany użytkownik z wpisami: `GET /dziennik/eksport?page=1` pobiera plik `.csv`
- Otwarcie pliku w arkuszu kalkulacyjnym (lub szybki podgląd tekstowy) potwierdza separator `;` i przecinek dziesiętny

---

## Phase 2: Przycisk eksportu w UI historii

### Overview

Dodanie punktu wejścia UI na stronie historii, z etykietą jednoznacznie komunikującą, że eksport dotyczy widocznej strony (świadome ograniczenie zakresu tego slice'u).

### Changes Required:

#### 1. Przycisk eksportu

**File**: `templates/diary/history.html.twig`

**Intent**: Dodać link/przycisk eksportujący bieżącą stronę, widoczny tylko w gałęzi z danymi (`{% else %}`, skoro pusta strona nie ma czego eksportować przy zakresie „bieżąca strona”), z tekstem jasno wskazującym zakres.

**Contract**: wewnątrz istniejącego bloku `{% else %}`, bezpośrednio po elemencie `<nav>` paginacji (po istniejącym zamknięciu `</nav>`, przed `{% endif %}`):

```twig
<p><a href="{{ path('diary_entry_export', {page: historyPage.currentPage}) }}" role="button" class="secondary">Eksportuj tę stronę (CSV)</a></p>
```

### Success Criteria:

#### Automated Verification:

- `docker compose exec php vendor/bin/phpunit` przechodzi, w tym nowy test: `tests/Controller/DiaryControllerTest.php::testHistoryShowsExportButtonLinkingToCurrentPage` — sprawdza, że `/dziennik/historia?page=2` renderuje link do `diary_entry_export` z `page=2` w query stringu (nie zahardkodowanym `page=1`)
- `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run` przechodzi

#### Manual Verification:

- Na `/dziennik/historia` przy istniejących wpisach przycisk „Eksportuj tę stronę (CSV)” jest widoczny i klikalny
- Przejście na stronę 2 historii (jeśli jest >7 grup dni) i kliknięcie przycisku pobiera CSV zgodny z zawartością strony 2, nie strony 1
- Pusta historia (brak wpisów) nie pokazuje przycisku eksportu

**Implementation Note**: Po ukończeniu tej fazy i przejściu wszystkich automatycznych weryfikacji, zatrzymaj się i poczekaj na potwierdzenie ręcznych testów przez użytkownika.

---

## Testing Strategy

### Unit Tests:

- `DiaryExportServiceTest`: formatowanie liczb (przecinek dziesiętny), puste komórki dla `null` `ww`/`insulinDose`, puste komórki aktywności gdy brak, kolejność wierszy zgodna z kolejnością `dayGroups`/`entries` z `DiaryHistoryPage`.

### Integration Tests:

- `DiaryControllerTest`: pełny request→response dla `/dziennik/eksport`, nagłówki, izolacja między użytkownikami, przekierowanie profileless/anonim, obecność i poprawność linku eksportu na `/dziennik/historia`.

### Manual Testing Steps:

1. Zalogować się jako pacjent z >7 grupami dni w historii.
2. Na stronie 1 kliknąć „Eksportuj tę stronę (CSV)”, otworzyć plik — sprawdzić, że wiersze odpowiadają wpisom widocznym na stronie 1.
3. Przejść na „Starsze” (strona 2), kliknąć eksport ponownie — sprawdzić, że plik zawiera inne wpisy (ze strony 2), nie duplikat strony 1.
4. Otworzyć plik w arkuszu kalkulacyjnym z polskimi ustawieniami regionalnymi — potwierdzić, że kolumny są rozdzielone poprawnie i liczby (np. WW, Insulina) wyświetlają się z przecinkiem, nie jako tekst w jednej kolumnie.
5. Sprawdzić puste pole „Aktywność” w UI — w CSV powinny być dwie puste kolumny, nie myślnik.

## Performance Considerations

Brak nowego ryzyka wydajnościowego — eksport reużywa `DiaryHistoryService::buildPage()`, który już dziś wykonuje się przy każdym wyświetleniu `/dziennik/historia` dla tego samego użytkownika i tej samej strony (ten sam koszt zapytania, ta sama ilość danych w pamięci).

## Migration Notes

Nie dotyczy — brak zmian w schemacie bazy danych.

## References

- Related research: `context/changes/export-diary-history/research.md`
- Wzorzec serwisu: `src/Service/History/DiaryHistoryService.php:8-41`
- Wzorzec kontrolera: `src/Controller/DiaryController.php:115-130`
- Wzorzec testów: `tests/Controller/DiaryControllerTest.php:604-637`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Serwis eksportu CSV i endpoint

#### Automated

- [x] 1.1 phpstan analyse przechodzi
- [x] 1.2 php-cs-fixer fix --dry-run przechodzi
- [x] 1.3 phpunit przechodzi (nowe testy kontrolera i serwisu eksportu)

#### Manual

- [ ] 1.4 Pobranie CSV dla zalogowanego użytkownika z wpisami działa
- [ ] 1.5 Plik otwiera się poprawnie z separatorem `;` i przecinkiem dziesiętnym

### Phase 2: Przycisk eksportu w UI historii

#### Automated

- [ ] 2.1 phpunit przechodzi (test linku eksportu z poprawnym `page`)
- [ ] 2.2 php-cs-fixer fix --dry-run przechodzi

#### Manual

- [ ] 2.3 Przycisk widoczny i klikalny na stronie historii z wpisami
- [ ] 2.4 Eksport ze strony 2 zawiera dane strony 2, nie strony 1
- [ ] 2.5 Pusta historia nie pokazuje przycisku eksportu
