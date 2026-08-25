# Dodanie wpisu do dzienniczka (S-02) — Skrót Planu

> Pełny plan: `context/changes/log-diary-entry/plan.md`

## Co i dlaczego (What & Why)

Umożliwienie zalogowanemu pacjentowi z ukończonym onboardingiem dodania wpisu
do dzienniczka: wymagany pomiar glikemii z datą/godziną, opcjonalnie WW,
dawka insuliny oraz dane o aktywności fizycznej. To etap roadmapy S-02
(FR-004–FR-007) — fundament wprowadzania danych, z którego korzystają
wszystkie kolejne etapy (sugestia przelicznika, ostrzeżenie o hipoglikemii,
widok historii, eksport).

## Punkt wyjścia (Starting Point)

`patient-onboarding` (S-01) jest w pełni zaimplementowany: encje `User` i
`PatientProfile` (1:1, `baseDose`, `insulinWwRatio`) istnieją i są
walidowane, subskrybent gejtu onboardingowego przekierowuje każdego
zalogowanego użytkownika bez profilu na `/onboarding`, a `ProfileController`/
`ProfileFormType` ustalają konwencje Symfony Form, z których korzysta ten
plan. Nic związanego z dzienniczkiem jeszcze nie istnieje — brak encji,
kontrolera, trasy, formularza i szablonu, a nawigacja ma tylko przycisk
wylogowania.

## Pożądany stan końcowy (Desired End State)

Pacjent otwiera „Dodaj wpis" (`/dziennik/nowy`), wprowadza pomiar glikemii
(wymagany) z dowolną kombinacją pól opcjonalnych, a wpis zapisuje się wraz z
migawką aktualnego `baseDose`/`insulinWwRatio`. Formularz czyści się i
pokazuje komunikat sukcesu, gotowy na kolejny wpis. Nieprawidłowe dane
ponownie renderują formularz z komunikatami błędów przy polach.

## Kluczowe podjęte decyzje (Key Decisions Made)

| Decyzja | Wybór | Dlaczego (1 zdanie) | Źródło |
| --- | --- | --- | --- |
| Migawka profilu | Kopiuj `baseDose` + `insulinWwRatio` na każdy wpis przy jego tworzeniu | FR-003 wymaga, by wpisy historyczne zachowały przelicznik z momentu dodania; kolejne etapy (S-03/S-04) potrzebują tego bez odtwarzania historii zmian profilu | Plan (potwierdzone przez użytkownika) |
| Kształt encji | Jedna encja `DiaryEntry` z nullable kolumnami opcjonalnymi | Zgodne z ujęciem PRD jako jednego „wpisu"; unika joinów zbędnych w MVP z jednym przepływem tworzenia | Plan (potwierdzone przez użytkownika) |
| Zakresy pól opcjonalnych | WW 0–20, dawka insuliny 0–50 j., czas aktywności 1–300 min | Chroni przed literówkami skalowymi bez odwoływania się do limitów narzuconych wprost przez PRD | Plan (potwierdzone przez użytkownika) |
| Granica czasowa | `measuredAt` musi być ≤ teraz | „Pomiar" z FR-004 jest z definicji zdarzeniem przeszłym/bieżącym; „planowana aktywność" (FR-007) to opisowa metadana przy wpisie w czasie rzeczywistym, nie osobny przyszły rekord | Plan (potwierdzone przez użytkownika) |
| UX po zapisie | Powrót do tego samego pustego formularza + flash | Pasuje do oczekiwanego użycia kilka razy dziennie; nie ma jeszcze widoku listy, na który można by przekierować | Plan (potwierdzone przez użytkownika) |
| Trasa | `/dziennik/nowy` (`diary_entry_new`), link w nav „Dodaj wpis" | Polska ścieżka spójna z `/profil`, `/onboarding`; nav ma już na to miejsce | Plan (potwierdzone przez użytkownika) |
| Typ glikemii | Liczba całkowita (mg/dL) | Realne glukometry zwracają wartości całkowite; unika bezsensownej precyzji ułamkowej | Plan (potwierdzone przez użytkownika) |
| Głębokość testów | Pełne pokrycie wzorem `PatientProfileTest`/`ProfileControllerTest` | Chroni testami nowe zakresy walidacji i regułę parowania ustalone w tej sesji planowania | Plan (potwierdzone przez użytkownika) |

## Zakres

**W zakresie:** encja `DiaryEntry` + migracja, formularz/kontroler/szablon dodawania wpisu, link w nawigacji, pełne pokrycie testami.

**Poza zakresem:** listowanie/przeglądanie wpisów (S-05), edycja/usuwanie wpisów (S-06), logika sugestii przelicznika lub ostrzeżenia o hipoglikemii (S-03/S-04), jakiekolwiek podsumowanie na pulpicie, integracja z CGM/urządzeniami.

## Architektura / Podejście

Nowa encja `DiaryEntry` (`ManyToOne` do `User`) z nullable kolumnami
posiłkowymi/aktywności oraz dwiema nienullowalnymi kolumnami migawki, mały
backed enum `ActivityIntensity`, oraz akcja `DiaryController::new()` typu
„tylko tworzenie", wzorowana na podejściu `RegistrationController` („zbuduj
świeżą encję, podepnij do niej formularz"), a nie na `ProfileController`
(„wczytaj i edytuj istniejącą encję"). Istniejący gejt onboardingowy nie
wymaga zmian — chroni każdą trasę, która nie jest jawnie wykluczona, a nowa
trasa nie jest wykluczona.

## Fazy w skrócie

| Faza | Co dostarcza | Kluczowe ryzyko |
| --- | --- | --- |
| 1. Model danych DiaryEntry | Encja, enum, repozytorium, migracja | Brak — sam fundament, weryfikowany testami encji |
| 2. Przepływ dodawania wpisu | Formularz, kontroler, szablon, link w nav, pełne testy | Parowanie intensywności/czasu aktywności i walidacja przyszłej daty to dwa nieoczywiste przypadki brzegowe |

**Wymagania wstępne:** S-01 (`patient-onboarding`) w pełni wdrożony — potwierdzone (`impl_reviewed`, wszystkie fazy zaimplementowane).
**Szacowany nakład:** ~2 sesje, po jednej na fazę, w ramach budżetu MVP po godzinach.

## Otwarte ryzyka i założenia

- Założenie, że `templates/base.html.twig` i istniejące szablony jeszcze nie
  renderują komunikatów flash globalnie — Faza 2 to sprawdza i dodaje pętlę
  flash tylko do nowego szablonu, jeśli okaże się potrzebna, zamiast
  duplikować taką, która już gdzieś istnieje.

## Kryteria sukcesu (podsumowanie)

- Pacjent może dodać wpis z samymi wymaganymi polami lub z dowolną kombinacją
  pól opcjonalnych, z jednego formularza dostępnego z nawigacji.
- Każda reguła walidacji ustalona w tym planie (dolna granica glikemii,
  górna granica czasowa, zakresy pól opcjonalnych, parowanie aktywności)
  jest wymuszona i pokryta testem automatycznym.
