---
project: "DiaGuide"
version: 1
status: draft
created: 2026-08-16
context_type: greenfield
product_type: web-app
target_scale:
  users: small
  qps: low
  data_volume: small
timeline_budget:
  mvp_weeks: 3
  hard_deadline: 2026-09-07
  after_hours_only: true
---

## Vision & Problem Statement

Samodzielne obliczanie przeliczników insulina/WW oraz właściwe dawkowanie insuliny na podstawie dzienniczków papierowych lub prostych aplikacji jest trudne, podatne na błędy ludzkie i prowadzi do niebezpiecznych wahań poziomu cukru we krwi. Osoba z cukrzycą musi w krótkim czasie podjąć decyzję o dawce insuliny przed posiłkiem lub aktywnością fizyczną, często opierając się na intuicji, a nie na rzetelnej analizie historycznych trendów.

Kluczowym wglądem projektu jest to, że system może dynamicznie uczyć się reakcji organizmu użytkownika na posiłki i wysiłek fizyczny. Zamiast sztywnego kalkulatora, aplikacja automatycznie analizuje historyczne pomiary glikemii po posiłkach, aby korygować przelicznik insulina/WW oraz podpowiadać modyfikacje dawki przy planowanej aktywności fizycznej, jednocześnie dbając o minimalny wysiłek przy wprowadzaniu opcjonalnych danych.

*Uwaga projektowa dotycząca skali:* W przypadku wzrostu skali 100-krotnego (np. do 10 000 użytkowników), algorytmy statystyczne mogą zostać zastąpione zaawansowanym silnikiem uczenia maszynowego (ML/AI) do dynamicznej predykcji dawek oraz spersonalizowanych analiz.

## User & Persona

### Primary persona
- **Nazwa**: Jan (Osoba z cukrzycą typu 1)
- **Rola**: Pacjent samodzielnie zarządzający dawkami insuliny
- **Kontekst**: Wprowadza poziom cukru przed posiłkiem lub treningiem, oblicza potrzebne jednostki insuliny i WW, a po kilku godzinach weryfikuje wpływ tej decyzji na glikemię.
- **Moment sięgnięcia po produkt**: Przed posiłkiem lub planowanym wysiłkiem, aby wyliczyć dawkę, oraz po posiłku w celu zapisu wyniku i analizy.

### Secondary persona
- **Nazwa**: Diabetolog
- **Rola**: Lekarz prowadzący
- **Kontekst**: Chce przeglądać zgromadzone przez pacjenta trendy, wyliczone przeliczniki oraz historię glikemii w celu optymalizacji leczenia na wizycie lekarskiej.

## Success Criteria

### Primary
- Pacjent rejestruje się w aplikacji, podaje początkową dawkę bazową insuliny oraz początkowy przelicznik insulina/WW, a następnie wykonuje minimum 3 wpisy powiązane z posiłkami (glikemia, zjedzone WW, podana insulina). System poprawnie oblicza trendy i sugeruje skorygowany przelicznik insulina/WW.
- Pacjent może wprowadzić informacje o planowanym lub wykonanym wysiłku fizycznym i otrzymać natychmiastowe ostrzeżenie lub poradę dotyczącą ryzyka hipoglikemii przy aktualnej glikemii i zaplanowanej dawce insuliny.

### Secondary
- Prezentacja historii pomiarów i dawek w formie przejrzystego wykresu z ostatnich 7 dni z wyróżnionymi strefami (hipoglikemia, norma, hiperglikemia).

### Guardrails
- **Medyczne bezpieczeństwo (Disclaimer)**: Każda porada i sugerowany przelicznik w aplikacji są wyraźnie oznaczone informacją, że są to jedynie sugestie algorytmiczne i każda zmiana terapii wymaga konsultacji z lekarzem diabetologiem.
- **Bezpieczeństwo danych**: Dane glikemii i historii leczenia jako dane wrażliwe są szyfrowane i niedostępne dla podmiotów trzecich bez wyraźnej zgody pacjenta.
- **Szybkość działania (UX)**: Odpowiedź interfejsu (dodanie wpisu, przejście do rekomendacji) następuje w czasie poniżej 200 ms.

## User Stories

### US-01: Obliczenie i sugestia nowego przelicznika insulina/WW

- **Given** Zalogowany pacjent Jan, który ma skonfigurowany początkowy przelicznik 1.0 j./WW
- **When** Jan wprowadza 3 wpisy posiłkowe, w których poziom cukru po posiłku (po 2 godzinach) znacznie odbiega od normy (np. rośnie o 80 mg/dL za każdym razem mimo poprawnego podania insuliny)
- **Then** System sugeruje podniesienie przelicznika do 1.2 j./WW, opatrując sugestię zastrzeżeniem medycznym (disclaimerem).

#### Acceptance Criteria
- Algorytm wymaga minimum 3 kompletnych par wpisów posiłkowych (posiłek z insuliną krótko działającą + pomiar glikemii przed i po 2h) w celu dokonania sugestii.
- Sugestia wyświetla się w formie karty rekomendacji z przyciskiem "Zapisz nowy przelicznik w profilu".
- Rekomendacja musi zawierać widoczny komunikat o charakterze informacyjno-edukacyjnym (disclaimer).

## Functional Requirements

### Profil i Ustawienia
- FR-001: Niezalogowany użytkownik może założyć konto pacjenta, podając adres e-mail i hasło. Priority: must-have
  > Socrates: Rozstrzygnięcie: Po rejestracji użytkownik jest kierowany na dedykowany ekran konfiguracji parametrów początkowych (dobowa baza i przelicznik). System sugeruje wartości domyślne, ale pacjent musi je świadomie zatwierdzić lub zmienić.
- FR-002: Pacjent może zalogować się do aplikacji za pomocą e-maila i hasła. Priority: must-have
  > Socrates: Rozstrzygnięcie: Rejestracja OAuth (Google/Apple) zostaje przeniesiona do v2 w celu szybszego wdrożenia v1 MVP.
- FR-003: Pacjent może zaktualizować swoje parametry początkowe (dawka bazy, przelicznik) w profilu. Priority: must-have
  > Socrates: Rozstrzygnięcie: Zmiana parametrów w profilu wpływa wyłącznie na nowe wpisy. Wpisy historyczne zachowują przelicznik z momentu ich dodania, aby nie zaburzać analizy historycznej.

### Dzienniczek pomiarów (Wprowadzanie danych)
- FR-004: Pacjent może dodać nowy wpis do dzienniczka, określając poziom glikemii w mg/dL oraz datę i godzinę pomiaru. Priority: must-have
  > Socrates: Rozstrzygnięcie: Wprowadzono walidację dolnej granicy pomiaru (np. powyżej 20 mg/dL), aby uniknąć błędów wprowadzania. Zrezygnowano z restrykcyjnego limitu górnego (np. 600 mg/dL), gdyż w skrajnych przypadkach klinicznych glikemia może przekroczyć te wartości.
- FR-005: Pacjent może opcjonalnie podać liczbę zjedzonych wymienników węglowodanowych (WW) w danym wpisie. Priority: must-have
  > Socrates: Rozstrzygnięcie: Wpisy bez podanego WW są zachowywane i wykorzystywane do analizy trendu glikemii (np. stabilność cukru po 2 i 4 godzinach od innych zdarzeń), chociaż nie wpływają na kalkulator przelicznika posiłkowego.
- FR-006: Pacjent może opcjonalnie podać dawkę insuliny krótko działającej (w jednostkach) przyjętą do posiłku. Priority: must-have
  > Socrates: Rozstrzygnięcie: Wpisy bez podanej dawki insuliny są wykorzystywane do badania naturalnych trendów glikemii (np. po wysiłku), nie są jednak brane pod uwagę przy automatycznym korygowaniu przelicznika posiłkowego.
- FR-007: Pacjent może opcjonalnie wybrać poziom intensywności planowanego lub wykonanego wysiłku fizycznego (Lekki / Średni / Mocny) oraz czas jego trwania. Priority: must-have
  > Socrates: Rozstrzygnięcie: Uproszczono wprowadzanie wysiłku fizycznego do 3 poziomów intensywności i czasu trwania, aby zachęcić pacjenta do regularnego uzupełniania danych bez nadmiernej komplikacji.
- FR-008: Pacjent może przeglądać listę swoich historycznych wpisów w kolejności chronologicznej. Priority: must-have
  > Socrates: Rozstrzygnięcie: Lista będzie podzielona na dni (czytelne nagłówki) z zastosowaniem stronicowania/leniwego ładowania (lazy loading), aby zachować czytelność przy dużej liczbie wpisów.
- FR-014: Pacjent może edytować lub usunąć wprowadzony wpis w dzienniczku (np. ostatni wpis lub w ciągu 24h od utworzenia), aby skorygować błędy. Priority: must-have
  > Socrates: Rozstrzygnięcie: Zezwolono na edycję/usuwanie ostatnich wpisów w celu szybkiej korekty literówek. Edycja starszych wpisów historycznych jest zablokowana, aby zachować spójność danych analitycznych i trendów.

### Rekomendacje i Algorytmy (Analiza)
- FR-009: System sugeruje skorygowany przelicznik insulina/WW na podstawie analizy historycznych trendów po zebraniu minimum 3 posiłków i odpowiadających im pomiarów glikemii po posiłkach. Priority: must-have
  > Socrates: Rozstrzygnięcie: System sugeruje zmianę przelicznika, lecz wymaga wyraźnej akceptacji i zatwierdzenia zmiany przez pacjenta. Sugestia wyświetla jasny kontekst (np. "Ostatnie 3 posiłki poskutkowały zbyt wysoką glikemią").
- FR-010: System wyświetla ogólne ostrzeżenie przed ryzykiem hipoglikemii przy planowanym wysiłku fizycznym. Priority: must-have
  > Socrates: Rozstrzygnięcie: Ze względów bezpieczeństwa medycznego system nie sugeruje konkretnej redukcji dawki (np. o 20%), a jedynie informuje o ryzyku hipo i zaleca wzmożoną kontrolę cukru lub rozważenie dodatkowych WW.
- FR-011: System wyświetla zalecenie medyczne (disclaimer) o konieczności konsultacji z diabetologiem przy każdej rekomendacji dawki lub przelicznika. Priority: must-have
  > Socrates: Rozstrzygnięcie: Komunikat prawny/medyczny (disclaimer) będzie stale i wyraźnie wyświetlany bezpośrednio pod każdą sugestią modyfikacji dawki lub przelicznika.

### Raporty i Eksport (Współpraca z lekarzem)
- FR-012: Pacjent może wyeksportować historię swoich pomiarów i sugerowanych przeliczników do pliku PDF lub CSV w celu udostępnienia jej lekarzowi. Priority: must-have
  > Socrates: Rozstrzygnięcie: W v1 zastępujemy konto diabetologa prostym, ale funkcjonalnym eksportem danych, co pozwala na dzielenie się wynikami bez konieczności budowy skomplikowanej infrastruktury wielokontowej.
- FR-013: Pacjent może wygenerować kod dostępu do swoich danych dla diabetologa (tylko do odczytu). Priority: nice-to-have
  > Socrates: Rozstrzygnięcie: Funkcjonalność dedykowanych kont dla diabetologów oraz przesyłania danych wewnątrz aplikacji zostaje odłożona do v2 (nice-to-have).

## Non-Functional Requirements

- **Szyfrowanie i bezpieczeństwo danych**: Jako że dane glikemii i dawek insuliny są danymi medycznymi (wrażliwymi), są one szyfrowane zarówno w spoczynku, jak i podczas przesyłania. Dostęp do nich ma wyłącznie uwierzytelniony użytkownik.
- **Stała widoczność zastrzeżenia prawnego (Disclaimer)**: Każda karta rekomendacji, porada algorytmiczna oraz ekran sugestii dawki bezwzględnie i wyraźnie wyświetla zastrzeżenie o charakterze informacyjno-edukacyjnym o konieczności skonsultowania się z lekarzem.
- **Widoczna informacja o trwającej kalkulacji**: Jeżeli obliczenie trendów trwa dłużej niż 500 ms, pacjent w tym czasie otrzymuje czytelną, ciągłą informację zwrotną o tym, że obliczenie wciąż trwa, tak aby nie odniósł wrażenia, że aplikacja przestała odpowiadać.

## Business Logic

**System szacuje optymalny posiłkowy przelicznik insulina/WW oraz sugeruje ewentualne modyfikacje insuliny bazowej na podstawie analizy historycznej glikemii pacjenta.**

Aplikacja opiera swoje działanie na dwóch powiązanych regułach diabetologicznych:
1. **Optymalizacja przelicznika posiłkowego (Insulina/WW)**: System analizuje różnicę glikemii przed posiłkiem oraz 2 godziny po nim. Jeżeli przy wielokrotnych wpisach posiłkowych (minimum 3) różnica ta przekracza założony bezpieczny próg wzrostu (np. glikemia wzrosła o więcej niż 50 mg/dL), system sugeruje zwiększenie przelicznika. W przypadku spadu cukru sugerowane jest jego obniżenie.
2. **Korekta insuliny bazowej**: System monitoruje poziomy glikemii rano na czczo (po przebudzeniu) oraz przed posiłkami oddzielonymi długimi przerwami. Jeśli poziom ten systematycznie odbiega od normy (np. 3 dni z rzędu na czczo cukier przekracza 130 mg/dL), system sygnalizuje potrzebę rewizji dawki dobowej (bazy).

Sugestie te pojawiają się automatycznie na pulpicie głównym, gdy tylko zgromadzona zostanie wystarczająca liczba wpisów spełniających powyższe warunki. Pacjent musi ręcznie zatwierdzić sugestię, co powoduje zaktualizowanie jego parametrów w profilu.

## Access Control

Aplikacja wspiera model dwurzędowy ról:
1. **Pacjent (Jan)**:
   - Rejestruje się i loguje za pomocą adresu e-mail oraz hasła.
   - Posiada pełny dostęp (zapis, odczyt, edycja, usuwanie) do swojej historii pomiarów glikemii, dawek insuliny, posiłków (WW), bazy oraz aktywności fizycznej.
   - Może wygenerować raport PDF/CSV do przekazania lekarzowi.
2. **Diabetolog (v2)**:
   - Loguje się na swoje konto.
   - Po podaniu kodu udostępnienia przez pacjenta, uzyskuje dostęp wyłącznie do odczytu (read-only) do historii glikemii, wykresów, trendów oraz sugerowanych przeliczników danego pacjenta. Nie ma możliwości modyfikacji wpisów pacjenta.
3. **Niezalogowany użytkownik**:
   - Brak dostępu do jakichkolwiek funkcji aplikacji poza ekranem powitalnym (landing page), rejestracji i logowania.

## Non-Goals

Poniższe elementy są kategorycznie wyłączone z zakresu tej wersji MVP:
- **Brak integracji z CGM / ciągłym monitoringiem glikemii**: Wszelkie pomiary glikemii w v1 muszą być wprowadzane przez pacjenta ręcznie (brak bezpośredniego pobierania z sensorów FreeStyle Libre, Dexcom itp.).
- **Brak kont dla diabetologów**: Aplikacja nie posiada osobnego systemu logowania, zaproszeń ani uprawnień dla lekarzy w v1. Zastępuje to prosty moduł eksportu danych (PDF/CSV).
- **Brak wsparcia dla wielu marek/rodzajów insuliny**: Obsługujemy standardowy, jednolity przelicznik krótko działający i dobowy podawany przez pacjenta, bez bazy danych leków i automatycznego różnicowania ich kinetyki.
- **Brak trybu offline**: Aplikacja w v1 wymaga aktywnego połączenia internetowego w celu zapisu danych w chmurze i poprawnego działania algorytmów analitycznych.
- **Brak automatycznej modyfikacji dawek (pętla zamknięta)**: System nigdy nie podaje automatycznie insuliny ani nie podejmuje decyzji za pacjenta — wszelkie zalecenia są wyłącznie sugestiami podlegającymi ręcznemu zatwierdzeniu.

## Open Questions

None. The shape-notes checkpoint records `quality_check_status: accepted`, meaning the closing cross-check in `/10x-shape` surfaced no gaps to route here.
