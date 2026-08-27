# Eksport historii dziennika do CSV — Plan Brief

> Pełny plan: `context/changes/export-diary-history/plan.md`
> Research: `context/changes/export-diary-history/research.md`

## Co i po co

Pacjent może pobrać CSV z bieżąco przeglądanej strony historii dziennika, żeby przekazać go lekarzowi (PRD FR-012, roadmap S-07). To pierwszy slice eksportu — CSV only, tylko wpisy dziennika (bez historii sugerowanych przeliczników).

## Punkt startowy

`/dziennik/historia` już renderuje historię pogrupowaną po dniach i stronicowaną (`DiaryHistoryService::buildPage()`, 7 grup dni/strona). Nie istnieje żaden kod eksportu/CSV/PDF ani nowa zależność composera nie jest potrzebna.

## Stan docelowy

Przycisk „Eksportuj tę stronę (CSV)” na `/dziennik/historia?page=N` pobiera plik CSV zawierający dokładnie te wpisy, które są aktualnie widoczne na ekranie — z separatorem `;`, przecinkiem dziesiętnym i BOM UTF-8, gotowy do otwarcia w polskim Excelu.

## Kluczowe decyzje

| Decyzja | Wybór | Dlaczego (1 zdanie) | Źródło |
| --- | --- | --- | --- |
| Format pliku | CSV only (bez PDF) | Zero nowej zależności composera, prostsze na ten slice | Research |
| Zakres danych | Tylko wpisy dziennika | Świadome zawężenie względem pełnego FR-012 (bez ratio/base-dose history) | Research |
| Separator/liczby | `;` + przecinek dziesiętny | Domyślne ustawienia polskiego Excela — otwiera się bez kroku importu | Plan |
| Kolumna aktywności | Dwie osobne kolumny | Dane pozostają strukturalne — możliwe sortowanie/filtrowanie w arkuszu | Plan |
| Zakres eksportu | Tylko bieżąca strona historii | Wybór użytkownika — mniejszy plik, zgodny z tym co widać na ekranie | Plan |
| Wejście UI | Tylko przycisk na stronie historii | Wybór użytkownika — brak dodatkowego linku w menu nawigacji | Plan |

## Zakres

**W zakresie:** nowy endpoint `GET /dziennik/eksport?page=N`, serwis `DiaryExportService`, przycisk na stronie historii, testy kontrolera i serwisu.

**Poza zakresem:** eksport PDF, eksport ratio/base-dose adjustment history, link w menu nawigacyjnym, opcja „cała historia”, filtr zakresu dat, migracja bazy, nowa zależność composera.

## Architektura / podejście

Nowy serwis `DiaryExportService::writeCsv(DiaryHistoryPage, $handle)` reużywa istniejący `DiaryHistoryService::buildPage($user, $page)` (dokładnie ten sam wycinek danych co widok historii), pisze CSV strumieniowo do `StreamedResponse` w nowej akcji `DiaryController::export()`. Brak zmian w warstwie danych.

## Fazy w skrócie

| Faza | Co dostarcza | Kluczowe ryzyko |
| --- | --- | --- |
| 1. Serwis eksportu CSV i endpoint | `DiaryExportService`, akcja `export()`, testy kontrolera+serwisu | Poprawność formatowania liczb (przecinek) i izolacja między użytkownikami |
| 2. Przycisk eksportu w UI historii | Link na `/dziennik/historia` z poprawnym parametrem `page` | Mylące wrażenie pełnego eksportu — zaadresowane etykietą przycisku |

**Wymagania wstępne:** brak (S-05 i S-03, od których zależy roadmapowy S-07, są już `done`).
**Szacowany nakład:** ~1 sesja, 2 fazy.

## Ryzyka i założenia

- Eksport tylko bieżącej strony może zaskoczyć pacjenta, który oczekiwałby pełnej historii — zminimalizowane jawną etykietą przycisku („tę stronę”), ale warto to zaobserwować po wdrożeniu.
- Format `;` + przecinek dziesiętny zakłada polskie ustawienia regionalne odbiorcy (lekarza); w międzynarodowym Excelu może wymagać ręcznego wyboru separatora przy imporcie.

## Kryteria sukcesu (podsumowanie)

- Pacjent pobiera CSV zgodny z zawartością aktualnie oglądanej strony historii.
- Plik otwiera się poprawnie (kolumny, liczby z przecinkiem) w polskim Excelu.
- Dane innego użytkownika nigdy nie pojawiają się w eksporcie (weryfikowane testem).
