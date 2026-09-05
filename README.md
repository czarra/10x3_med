# DiaGuide

**DiaGuide** (`dia-guide`) to webowa aplikacja MVP — asystent dawkowania insuliny
i wyliczania przelicznika insulina/WW dla osób z cukrzycą typu 1.

Pacjent zapisuje w dzienniczku pomiary glikemii, zjedzone wymienniki
węglowodanowe (WW), dawki insuliny krótko działającej oraz aktywność fizyczną.
Aplikacja analizuje historyczne trendy glikemii po posiłkach i:

- sugeruje **skorygowany przelicznik insulina/WW** (po zebraniu min. 3 posiłków),
- sygnalizuje potrzebę **rewizji dawki bazowej** insuliny,
- **ostrzega przed ryzykiem hipoglikemii** przy planowanym wysiłku fizycznym.

Każda sugestia jest opatrzona zastrzeżeniem medycznym i wymaga ręcznej akceptacji
pacjenta — system nigdy nie zmienia terapii automatycznie. Pełny opis produktu:
[`context/foundation/prd.md`](context/foundation/prd.md).

> ⚠️ Projekt kursowy / MVP. Nie jest wyrobem medycznym i nie zastępuje konsultacji
> z lekarzem diabetologiem.

## Stack

- **PHP 8.5** + **Symfony 7.4**, Doctrine ORM, Twig (widoki renderowane po stronie serwera)
- **PostgreSQL 18**
- Docker / Docker Compose — całe środowisko uruchamiane w kontenerach
- PHPUnit (testy), Playwright (E2E), PHPStan + PHP-CS-Fixer (jakość)
- Wdrożenie: Railway; CI: GitHub Actions

Uzasadnienie wyboru stacku: [`context/foundation/tech-stack.md`](context/foundation/tech-stack.md).

## Uruchomienie lokalne

Wymagany jest tylko **Docker** z Docker Compose — PHP, Composer i bazy działają
w kontenerach.

### Pierwszy start

```bash
./run-dev.sh
```

Skrypt buduje kontenery, uruchamia `composer install`, tworzy i migruje bazę
`dev`, resetuje i migruje bazę `test` oraz instaluje zależności Playwright.

> `run-dev.sh` jest **destrukcyjny** — zaczyna od `docker compose down
> --remove-orphans`. Używaj go do bootstrapu lub czystego resetu, nie do
> „szybkiego sprawdzenia".

Aplikacja: **http://localhost:8381**

### Codzienna praca

```bash
docker compose up -d                              # start
docker compose down                               # stop
docker compose exec php bin/console <polecenie>   # konsola Symfony
```

### Porty

| Usługa                          | Host | Kontener |
| ------------------------------- | ---- | -------- |
| aplikacja (`php`)               | 8381 | 80       |
| aplikacja E2E (`php-e2e`)       | 8382 | 80       |
| Postgres `dev` (`database`)     | 4306 | 5432     |
| Postgres `test` (`database-test`) | 4307 | 5432   |

### Konfiguracja

`.env`, `.env.dev` i `.env.test` są w repozytorium z działającymi lokalnie
wartościami (zmienne `DOCKER_POSTGRES_*` i `DATABASE_URL` — dokumentuje je
`.env.dist`). Lokalne nadpisania rób wyłącznie w `.env.local` (jedyny plik
`.env*` w `.gitignore`).

## Jak korzystać

1. Wejdź na http://localhost:8381 i **załóż konto pacjenta** (`/register`) —
   e-mail + hasło.
2. **Onboarding** (`/onboarding`): ustaw początkową dobową dawkę bazową insuliny
   oraz początkowy przelicznik insulina/WW.
3. **Dodawaj wpisy** do dzienniczka (`/dziennik/nowy`): glikemia w mg/dL + data
   i godzina pomiaru; opcjonalnie liczba WW, dawka insuliny krótko działającej
   oraz aktywność fizyczna (Lekka / Średnia / Mocna + czas trwania).
4. **Pulpit** (`/pulpit`): po zebraniu ≥ 3 kwalifikujących się posiłków pojawia
   się karta z sugerowanym przelicznikiem i ewentualnym sygnałem korekty dawki
   bazowej — zaakceptuj, aby zaktualizować profil. Tu też wyświetlają się
   ostrzeżenia o ryzyku hipoglikemii przy planowanym wysiłku.
5. **Historia** (`/dziennik/historia`): lista wpisów pogrupowana dniami plus
   wykres glikemii z ostatnich 7 dni ze strefami hipo / norma / hiper.
6. **Korekta i eksport**: ostatni wpis możesz edytować lub usunąć do 24 h od
   dodania; bieżącą stronę historii wyeksportujesz do CSV (`/dziennik/eksport`).

Profil początkowy zmienisz w `/profil` (zmiana wpływa tylko na nowe wpisy —
historyczne zachowują przelicznik z momentu ich dodania).

Zakres funkcji i ich status: [`context/foundation/roadmap.md`](context/foundation/roadmap.md).

## Testy i jakość

```bash
docker compose exec php vendor/bin/phpunit                              # testy jednostkowe/integracyjne
docker compose exec php vendor/bin/phpstan analyse --memory-limit=512M  # analiza statyczna (level 5, src/)
docker compose exec php vendor/bin/php-cs-fixer fix                     # styl @Symfony
docker compose exec playwright npx playwright test                      # E2E w przeglądarce
```

> Suity PHPUnit i Playwright współdzielą bazę `database-test` — uruchamiaj je
> sekwencyjnie, nigdy równolegle.

Repozytorium ma też lokalne hooki jakości (per-edit dla agenta AI oraz bramkę
`pre-commit` uruchamiającą php-cs-fixer + PHPStan na staged plikach) — opis
w [`AGENTS.md`](AGENTS.md) i [`CLAUDE.md`](CLAUDE.md).

## Dokumentacja

| Plik | Zawartość |
| ---- | --------- |
| [`context/foundation/prd.md`](context/foundation/prd.md)         | wymagania produktowe (PRD) |
| [`context/foundation/roadmap.md`](context/foundation/roadmap.md) | roadmapa i status funkcji |
| [`context/foundation/tech-stack.md`](context/foundation/tech-stack.md) | wybór i uzasadnienie stacku |
| [`AGENTS.md`](AGENTS.md)                                          | konwencje repo, polecenia build/test/lint |
| [`CLAUDE.md`](CLAUDE.md)                                          | wskazówki dla agentów AI |
