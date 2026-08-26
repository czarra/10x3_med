---
project: DiaGuide
version: 1
status: draft
created: 2026-08-22
updated: 2026-08-26
prd_version: 1
main_goal: speed
top_blocker: time
---

# Roadmap: DiaGuide

> Wygenerowano na podstawie `context/foundation/prd.md` (v1) oraz automatycznie
> zbadanego stanu bazowego repozytorium.
> Edytuj w miejscu; zarchiwizuj przy pełnej regeneracji.
> Poniższe elementy są uszeregowane wg zależności. Tabela "At a glance" to indeks.

## Vision recap

Samodzielne wyliczanie przelicznika insulina/WW oraz dawkowanie insuliny na
podstawie papierowych dzienniczków jest trudne i podatne na błędy, prowadząc do
niebezpiecznych wahań glikemii. Kluczowy pomysł projektu: system dynamicznie uczy
się reakcji organizmu pacjenta na posiłki i wysiłek, analizując historyczne pomiary
zamiast działać jak sztywny kalkulator — i podpowiada korektę przelicznika oraz
ostrzega przed ryzykiem hipoglikemii, zawsze z zastrzeżeniem, że to sugestia
wymagająca konsultacji z lekarzem.

## North star

**S-03: Sugestia skorygowanego przelicznika insulina/WW** — to dosłownie US-01,
jedyna w pełni rozpisana historyjka w PRD, i najbliższy zapis "kluczowego wglądu"
z Vision: system uczy się z historii pacjenta zamiast być sztywnym kalkulatorem.
Pierwsze (najważniejsze) Kryterium sukcesu w PRD.

> "Gwiazda przewodnia" (north star) to tu najmniejszy kompletny przepływ, którego
> udane dostarczenie dowodzi, że kluczowa hipoteza produktu działa — umieszczony
> tak wcześnie, jak pozwalają na to zależności, bo reszta roadmapy ma sens tylko
> jeśli to zadziała.

## At a glance

| ID   | Change ID                       | Outcome (user can …)                                              | Prerequisites | PRD refs                | Status   |
| ---- | -------------------------------- | ------------------------------------------------------------------ | -------------- | ------------------------ | -------- |
| F-01 | auth-scaffold                    | (foundation) szkielet autoryzacji — bundle security, encja User    | —              | FR-001, FR-002           | done |
| F-02 | deploy-pipeline-live              | (foundation) działający pipeline wdrożeniowy (Railway + CI)         | —              | frontmatter: hard_deadline | ready  |
| S-01 | patient-onboarding                | Pacjent zakłada konto, loguje się i ustawia parametry początkowe    | F-01            | FR-001, FR-002, FR-003   | done |
| S-02 | log-diary-entry                   | Pacjent dodaje wpis do dzienniczka (glikemia + opcjonalne pola)     | S-01            | FR-004, FR-005, FR-006, FR-007 | done |
| S-03 | insulin-ww-ratio-suggestion       | Pacjent dostaje sugestię skorygowanego przelicznika insulina/WW     | S-02            | FR-009, FR-011, US-01    | done |
| S-04 | activity-hypoglycemia-warning     | Pacjent dostaje ostrzeżenie o ryzyku hipoglikemii przy wysiłku      | S-02            | FR-010, FR-011           | done |
| S-05 | diary-history-view                | Pacjent przegląda historię wpisów (lista + wykres 7 dni)            | S-02            | FR-008                   | in-progress |
| S-06 | edit-delete-diary-entry           | Pacjent edytuje/usuwa ostatni wpis (do 24h od utworzenia)            | S-02            | FR-014                   | proposed |
| S-07 | export-diary-history               | Pacjent eksportuje historię i sugerowane przeliczniki do PDF/CSV     | S-05, S-03      | FR-012                   | proposed |

## Streams

Pomoc nawigacyjna — grupuje elementy wg wspólnego łańcucha zależności. Kanoniczna
kolejność wciąż wynika z grafu zależności poniżej; ta tabela to proponowana
kolejność czytania między równoległymi ścieżkami.

| Stream | Theme                              | Chain                                          | Note                                                                 |
| ------ | ----------------------------------- | ------------------------------------------------ | ---------------------------------------------------------------------- |
| A      | Wejście i dane                       | `F-01` → `S-01` → `S-02`                          | Ścieżka obowiązkowa — nic innego nie da się zademonstrować bez niej.     |
| B      | Silnik rekomendacji (gwiazda)        | `S-02` → `S-03`                                    | North star; najkrótsza droga do zwalidowania kluczowej hipotezy.        |
| C      | Bezpieczeństwo wysiłku                | `S-02` → `S-04`                                    | Drugie pierwszorzędowe Kryterium sukcesu; bezstanowe, szybkie do zweryfikowania. |
| D      | Przegląd, korekta i eksport          | `S-02` → `S-05` → `S-06` / `S-07` (dołącza do Stream B przy `S-03`) | Wspólna powierzchnia "zarządzania własnymi danymi".                    |
| E      | Wdrożenie                             | `F-02`                                              | Niezależne od reszty; musi wylądować przed 2026-09-07 niezależnie od postępu funkcji. |

## Baseline

Stan repozytorium na dzień 2026-08-22 (auto-zbadany + potwierdzony przez
użytkownika). Poniższe Foundations zakładają, że to jest już obecne, i tego nie
odtwarzają.

- **Frontend:** partial — standardowy szkielet Symfony (`templates/base.html.twig`,
  `templates/home/index.html.twig`), brak narzędzi budowania (brak package.json).
- **Backend / API:** partial — tylko `HomeController` (`/`) i `Api/StatusController`
  (`/api/status`, healthcheck bazy). Brak kontrolerów funkcjonalnych.
- **Data:** absent — `src/Entity/`, `src/Repository/`, `migrations/` to puste
  szkielety; `doctrine.yaml` ma gotowe okablowanie połączenia, ale nic nie jest
  jeszcze zmapowane.
- **Auth:** absent — brak `security.yaml`, brak encji User, brak bundla security
  w `composer.json`.
- **Deploy / infra:** partial — cel wdrożenia (Railway + GitHub Actions) już
  zdecydowany w `tech-stack.md`/`infrastructure.md`, ale samo okablowanie
  zaprojektowane w `context/deployment/deploy-plan.md` jeszcze nie istnieje
  (Dockerfile nie kopiuje aplikacji, brak obsługi PORT, brak `.dockerignore`,
  `railway.json`, workflow GitHub Actions). Lokalny `docker-compose` już działa.
- **Observability:** absent — brak konfiguracji monolog poza domyślną, brak
  narzędzia do śledzenia błędów, jedynie prowizoryczny `/api/status`.

## Foundations

### F-01: Szkielet autoryzacji

- **Outcome:** (foundation) Skonfigurowany bundle Security, encja `User` +
  migracja, hashowanie haseł — bez interfejsu rejestracji/logowania.
- **Change ID:** auth-scaffold
- **PRD refs:** FR-001, FR-002 (Access Control: rola Pacjenta)
- **Unlocks:** S-01 (konto i wstępna konfiguracja) — bez tego żaden dalszy
  element roadmapy nie ma zalogowanego pacjenta, do którego mógłby coś przypisać.
- **Prerequisites:** —
- **Parallel with:** F-02
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Absolutnie każdy dalszy element zależy od zalogowanego pacjenta —
  musi wylądować jako pierwszy, mimo presji czasu, bo nic innego nie da się
  zademonstrować bez tego.
- **Status:** done

### F-02: Działający pipeline wdrożeniowy

- **Outcome:** (foundation) Zaimplementowane elementy repo-side z
  `context/deployment/deploy-plan.md` (Dockerfile kopiuje aplikację i instaluje
  vendor, respektuje zmienną `PORT` Railway, `.dockerignore`, `railway.json`,
  workflow GitHub Actions). Faktyczne pierwsze wdrożenie wciąż wymaga ręcznych,
  jednorazowych kroków właściciela projektu w Railway/GitHub.
- **Change ID:** deploy-pipeline-live
- **PRD refs:** frontmatter: `timeline_budget.hard_deadline: 2026-09-07`
- **Unlocks:** ścieżkę weryfikacji "wdróż i pokaż realnym użytkownikom przed
  twardym terminem" — żaden slice tego wymaga wprost, ale bez tego main_goal
  (`speed`) nie ma jak się zmaterializować, niezależnie od tego, ile funkcji
  zostanie zbudowanych lokalnie.
- **Prerequisites:** —
- **Parallel with:** F-01, oraz równolegle z S-01…S-07 (nie blokuje ani nie jest
  blokowany przez pracę nad funkcjami)
- **Blockers:** Ręczne, jednorazowe kroki właściciela projektu w Railway/GitHub
  (login, provisioning Postgresa, sekrety, wyłączenie natywnego auto-deploy
  Railway) — z natury zewnętrzne/kontowe, nie do wykonania przez agenta;
  udokumentowane jako runbook w `context/deployment/deploy-plan.md`.
- **Unknowns:** —
- **Risk:** Przy `top_blocker: time` wysłanie produktu bez działającej ścieżki
  wdrożenia unieważnia twardy termin niezależnie od stanu funkcji — sekwencjonuj
  wcześnie i równolegle do pracy nad slice'ami, nie odkładaj na koniec.
- **Status:** ready

## Slices

### S-01: Konto i wstępna konfiguracja

- **Outcome:** Pacjent może założyć konto (e-mail + hasło), zalogować się, i
  ustawić początkową dawkę bazową oraz przelicznik insulina/WW.
- **Change ID:** patient-onboarding
- **PRD refs:** FR-001, FR-002, FR-003
- **Prerequisites:** F-01
- **Parallel with:** F-02
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Pierwszy widoczny dla użytkownika element; musi wylądować przed
  czymkolwiek innym, bo każdy kolejny slice potrzebuje zalogowanego pacjenta z
  ustawionymi parametrami początkowymi.
- **Status:** done

### S-02: Dodanie wpisu do dzienniczka

- **Outcome:** Pacjent może dodać wpis: poziom glikemii + data/godzina
  (wymagane), opcjonalnie WW, opcjonalnie dawka insuliny, opcjonalnie
  intensywność i czas wysiłku fizycznego.
- **Change ID:** log-diary-entry
- **PRD refs:** FR-004, FR-005, FR-006, FR-007
- **Prerequisites:** S-01
- **Parallel with:** —
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Kluczowy pionowy element wprowadzania danych — każda rekomendacja,
  widok historii i eksport zależy od istnienia wpisów, więc musi nastąpić
  bezpośrednio po S-01.
- **Status:** done

### S-03: Sugestia skorygowanego przelicznika insulina/WW

- **Outcome:** Po min. 3 kompletnych wpisach posiłkowych system sugeruje
  skorygowany przelicznik insulina/WW, z widocznym zastrzeżeniem medycznym,
  wymagający ręcznej akceptacji pacjenta.
- **Change ID:** insulin-ww-ratio-suggestion
- **PRD refs:** FR-009, FR-011, US-01
- **Prerequisites:** S-02 (potrzebne min. 3 sparowane wpisy: glikemia + WW +
  insulina)
- **Parallel with:** S-04, S-05, S-06
- **Blockers:** —
- **Unknowns:** —
- **Risk:** To jest gwiazda przewodnia — walidacja kluczowej hipotezy produktu.
  Umieszczona tak wcześnie, jak pozwala S-02, bo main_goal (`speed`) wymaga jak
  najwcześniejszego sygnału walidacyjnego, a nie symetrycznej kolejności.
- **Status:** done

### S-04: Ostrzeżenie o ryzyku hipoglikemii przy wysiłku

- **Outcome:** Pacjent wprowadzający planowany lub wykonany wysiłek fizyczny
  otrzymuje natychmiastowe ostrzeżenie o ryzyku hipoglikemii (bez sugerowania
  konkretnej redukcji dawki), z widocznym zastrzeżeniem medycznym.
- **Change ID:** activity-hypoglycemia-warning
- **PRD refs:** FR-010, FR-011
- **Prerequisites:** S-02
- **Parallel with:** S-03, S-05, S-06
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Drugie pierwszorzędowe Kryterium sukcesu, ale nie wybrane jako
  gwiazda przewodnia; bezstanowe (nie wymaga zebrania historii), więc to szybka
  równoległa wygrana zgodna z main_goal (`speed`).
- **Status:** done

### S-05: Przeglądanie historii wpisów

- **Outcome:** Pacjent może przeglądać listę historycznych wpisów pogrupowaną
  wg dni (ze stronicowaniem/leniwym ładowaniem) oraz wykres z ostatnich 7 dni z
  wyróżnionymi strefami (hipoglikemia, norma, hiperglikemia).
- **Change ID:** diary-history-view
- **PRD refs:** FR-008 (prezentacja wykresu — Secondary Success Criterion, bez
  osobnego FR)
- **Prerequisites:** S-02
- **Parallel with:** S-03, S-04, S-06
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Element czysto prezentacyjny, niskie ryzyko — ale musi wylądować
  przed eksportem (S-07), bo eksport pakuje ten sam widok historii.
- **Status:** in-progress

### S-06: Edycja/usunięcie ostatniego wpisu

- **Outcome:** Pacjent może edytować lub usunąć wpis dodany w ciągu ostatnich
  24h od utworzenia; starsze wpisy pozostają zablokowane do edycji.
- **Change ID:** edit-delete-diary-entry
- **PRD refs:** FR-014
- **Prerequisites:** S-02
- **Parallel with:** S-03, S-04, S-05
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Mały, izolowany element korekcyjny (reguła 24h) — bezpieczny do
  zrównoleglenia, nic innego od niego nie zależy.
- **Status:** proposed

### S-07: Eksport historii do PDF/CSV

- **Outcome:** Pacjent może wyeksportować historię swoich pomiarów i
  sugerowanych przeliczników do pliku PDF lub CSV w celu udostępnienia jej
  lekarzowi.
- **Change ID:** export-diary-history
- **PRD refs:** FR-012
- **Prerequisites:** S-05, S-03 (potrzebuje zarówno widoku historii, jak i
  danych o sugerowanych przelicznikach)
- **Parallel with:** S-04, S-06
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Ostatni element ścieżki must-have; naturalnie na końcu, bo pakuje
  dane wyjściowe z S-03 i S-05 — głównie zadanie serializacji/raportowania, bez
  nowego ryzyka domenowego.
- **Status:** proposed

## Backlog Handoff

| Roadmap ID | Change ID                    | Suggested issue title                                              | Ready for `/10x-plan` | Notes |
| ---------- | ------------------------------ | ---------------------------------------------------------------------- | ---------------------- | ----- |
| F-01       | auth-scaffold                  | Szkielet autoryzacji (Security bundle + encja User)                    | yes                     | Uruchom `/10x-plan auth-scaffold` |
| F-02       | deploy-pipeline-live            | Domknięcie pipeline'u wdrożeniowego (Dockerfile, CI, Railway)           | yes                     | Uruchom `/10x-plan deploy-pipeline-live` |
| S-01       | patient-onboarding              | Rejestracja, logowanie i wstępna konfiguracja pacjenta                  | no                      | Czeka na F-01 |
| S-02       | log-diary-entry                  | Dodanie wpisu do dzienniczka pomiarów                                   | no                      | Czeka na S-01 |
| S-03       | insulin-ww-ratio-suggestion      | Sugestia skorygowanego przelicznika insulina/WW (gwiazda przewodnia)    | no                      | Czeka na S-02 |
| S-04       | activity-hypoglycemia-warning    | Ostrzeżenie o ryzyku hipoglikemii przy wysiłku                          | no                      | Czeka na S-02 |
| S-05       | diary-history-view               | Widok historii wpisów (lista + wykres 7 dni)                            | no                      | Czeka na S-02 |
| S-06       | edit-delete-diary-entry          | Edycja/usunięcie ostatniego wpisu                                       | no                      | Czeka na S-02 |
| S-07       | export-diary-history              | Eksport historii do PDF/CSV                                             | no                      | Czeka na S-05, S-03 |

## Open Roadmap Questions

Brak. PRD nie zawiera żadnych otwartych pytań (`quality_check_status: accepted`
w checkpointcie `/10x-shape`), a wywiad Step 5 (cel, gwiazda przewodnia, główne
ryzyko) rozstrzygnął wszystkie trzy kotwice bez pozostałej niejednoznaczności
sekwencjonowania.

## Parked

- **FR-013 (kod dostępu do danych dla diabetologa)** — Why parked: PRD oznacza to
  jako nice-to-have, a sekcja Access Control i Non-Goals wprost odkładają konta
  dla diabetologów do v2.
- **Integracja z CGM / ciągłym monitoringiem glikemii** — Why parked: PRD Non-Goals
  — v1 wymaga ręcznego wprowadzania pomiarów.
- **Konta dla diabetologów** — Why parked: PRD Non-Goals — zastąpione prostym
  eksportem PDF/CSV (S-07) w v1.
- **Wsparcie dla wielu marek/rodzajów insuliny** — Why parked: PRD Non-Goals —
  jeden jednolity przelicznik krótko działający i dobowy.
- **Tryb offline** — Why parked: PRD Non-Goals — v1 wymaga aktywnego połączenia
  internetowego.
- **Automatyczna modyfikacja dawek (pętla zamknięta)** — Why parked: PRD Non-Goals
  — system nigdy nie podaje insuliny automatycznie; wszystko wymaga ręcznej
  akceptacji pacjenta.

## Done

(Puste przy pierwszej generacji. `/10x-archive` doda tu wpis — i przełączy
`Status` danego elementu na `done` — gdy zmiana o pasującym `Change ID` zostanie
zarchiwizowana.)

- **S-01: Pacjent może założyć konto (e-mail + hasło), zalogować się, i ustawić początkową dawkę bazową oraz przelicznik insulina/WW.** — Archived 2026-08-25 → `context/archive/2026-08-25-patient-onboarding/`. Lesson: —.
- **S-02: Pacjent może dodać wpis: poziom glikemii + data/godzina (wymagane), opcjonalnie WW, opcjonalnie dawka insuliny, opcjonalnie intensywność i czas wysiłku fizycznego.** — Archived 2026-08-25 → `context/archive/2026-08-25-log-diary-entry/`. Lesson: —.
- **F-01: (foundation) Skonfigurowany bundle Security, encja `User` + migracja, hashowanie haseł — bez interfejsu rejestracji/logowania.** — Archived 2026-08-25 → `context/archive/2026-08-24-auth-scaffold/`. Lesson: —.
- **S-03: Po min. 3 kompletnych wpisach posiłkowych system sugeruje skorygowany przelicznik insulina/WW, z widocznym zastrzeżeniem medycznym, wymagający ręcznej akceptacji pacjenta.** — Archived 2026-08-26 → `context/archive/2026-08-25-insulin-ww-ratio-suggestion/`. Lesson: —.
- **S-04: Pacjent wprowadzający planowany lub wykonany wysiłek fizyczny otrzymuje natychmiastowe ostrzeżenie o ryzyku hipoglikemii (bez sugerowania konkretnej redukcji dawki), z widocznym zastrzeżeniem medycznym.** — Archived 2026-08-26 → `context/archive/2026-08-26-activity-hypoglycemia-warning/`. Lesson: —.
