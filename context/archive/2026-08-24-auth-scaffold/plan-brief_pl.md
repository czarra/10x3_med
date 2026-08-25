# Szkielet Uwierzytelniania (Auth Scaffold) — Skrót Planu

> Pełny plan: `context/changes/auth-scaffold/plan.md`

## Co i dlaczego (What & Why)

DiaGuide wymaga uwierzytelnionego pacjenta, zanim jakakolwiek inna funkcja będzie mogła powiązać z nim dane. Niniejsza zmiana przygotowuje szkielet dla Symfony Security Bundle, hashowanie haseł oraz encję `User` wraz z migracją — jest to element fundamentu w roadmapie (F-01), od którego zależą wszystkie kolejne etapy (S-01…S-07). Zakres jest celowo ograniczony wyłącznie do szkieletu (scaffold-only): brak interfejsu rejestracji/logowania (UI) (jest to zakres etapu S-01).

## Punkt wyjścia (Starting Point)

Świeży szkielet Symfony 7.4 ze skonfigurowanym Doctrine/Postgres 18, ale bez modułu uwierzytelniania: brak `security.yaml`, brak `symfony/security-bundle`, puste katalogi `src/Entity/` oraz `migrations/`. Mapowanie encji Doctrine (`config/packages/doctrine.yaml`) wskazuje już na `src/Entity` z mapowaniem atrybutami (attributes) i strategią nazewnictwa `underscore`.

## Pożądany stan końcowy (Desired End State)

Paczka `composer require symfony/security-bundle` jest dodana do repozytorium; `security.yaml` konfiguruje hasher haseł `auto` oraz entity user provider kluczowany adresem e-mail; encja `User` (tabela `users`, pola: id/email/password/roles/createdAt) i jej migracja istnieją oraz są zaaplikowane w bazach danych deweloperskiej i testowej. Test kernelowy potwierdza poprawne działanie hashera i logiki ról end-to-end.

## Kluczowe podjęte decyzje (Key Decisions Made)

| Decyzja | Wybór | Dlaczego (1 zdanie) | Źródło |
| --- | --- | --- | --- |
| Identyfikator logowania | Wyłącznie e-mail | Dokładnie odpowiada FR-001/FR-002; w PRD nie ma pojęcia odrębnej nazwy użytkownika (username) | Plan |
| Hasher haseł | Algorytm `auto` | Rekomendowany, bezobsługowy domyślny algorytm w Symfony | Plan |
| Model ról | Stała `ROLE_USER` + `ROLE_PATIENT`, ustawiane w konstruktorze | Nadaje roli „Pacjent” z PRD konkretną nazwę już teraz przy zerowym koszcie, bez budowania mechanizmów dla Diabetologa (v2, poza zakresem) | Plan |
| Pola encji | `id`, `email`, `password`, `roles`, `createdAt` — bez `isVerified` | W PRD nigdzie nie występuje proces weryfikacji e-mail; nieużywana flaga o stałej wartości true byłaby martwym kodem | Plan |
| Nazwa tabeli | Jawne `users` (zamiast `user`) | `user` jest słowem zastrzeżonym w PostgreSQL — unika to problemów z cytowaniem nazw (escaping) | Plan |
| Narzędzia deweloperskie | Dodanie `symfony/maker-bundle` (tylko dev) | Generuje encję/migrację zgodnie z konwencjami Symfony, spójnie ze sposobem wygenerowania reszty szkieletu | Plan |
| Pokrycie testami | Test kernelowy: utrwalenie User w bazie, weryfikacja hasła przez rzeczywisty skonfigurowany hasher, asercja ról | Wykrywa błędy konfiguracyjne w security.yaml, zanim w S-01 powstanie na tym rejestracja | Plan |

## Zakres (Scope)

**W zakresie:** instalacja `symfony/security-bundle` + `symfony/maker-bundle` (dev), konfiguracja `security.yaml`, encja `User` + `UserRepository`, migracja zaaplikowana w bazie dev + test, jeden test kernelowy.

**Poza zakresem:** kontrolery, formularze i szablony rejestracji/logowania (S-01); weryfikacja adresu e-mail; role/konta Diabetologa (v2); logowanie OAuth (v2); pola profilu, takie jak dawka bazowa / przelicznik insulina-WW (S-01); reguły `access_control` (na tym etapie nie ma jeszcze czego chronić).

## Architektura / Podejście (Architecture / Approach)

Standardowy schemat Symfony Security: oparty na encji `UserProvider` kluczowany adresem e-mail, hasher haseł `auto`, firewall `main` z providerem, ale celowo bez authenticatora na tym etapie (nie ma jeszcze procesu uwierzytelniania, dopóki w S-01 nie zostanie dodany przepływ logowania). Encja `User` jest celowo minimalistyczna — zawiera tylko to, czego wymaga Security — pozostawiając wszystkie dane profilu pacjenta dla etapu S-01, który jest wydzielony w roadmapie.

## Przegląd faz (Phases at a Glance)

| Faza | Co dostarcza | Główne ryzyko |
| --- | --- | --- |
| 1. Security bundle, encja User i migracja | Zainstalowany bundle, `security.yaml`, encja `User`/repozytorium, migracja zaaplikowana w obu bazach danych | Kolizja ze słowem zastrzeżonym w Postgres dla nazwy tabeli `user` (rozwiązane: jawna nazwa tabeli `users`) |
| 2. Test weryfikacyjny | Test kernelowy potwierdzający działanie hashera i ról; przejście całego quality gate na zielono | Przeoczone ostrzeżenie o deprecacji z Security bundle powodujące błąd na bramce `failOnDeprecation` |

**Wymagania wstępne:** brak — pierwsza zmiana wprowadzana do tego repozytorium.
**Szacowany nakład pracy:** ~1 sesja, 2 fazy.

## Otwarte ryzyka i założenia (Open Risks & Assumptions)

- Zakłada się, że `composer require symfony/security-bundle` rozwiąże zależności bezproblemowo z `symfony/*: 7.4.*` bez konfliktów wersji (standardowy przepis Flex, niskie ryzyko).
- Zakłada się, że usługi kontenerowe `php` oraz `database`/`database-test` są już uruchomione (`docker compose up`) przed nałożeniem migracji — ten plan ich nie uruchamia (oraz wyraźnie unika `./run-dev.sh`, który przebudowałby kontenery).

## Kryteria sukcesu — podsumowanie (Success Criteria)

- `doctrine:schema:validate` kończy się sukcesem, a tabela `users` istnieje zarówno w bazie deweloperskiej, jak i testowej.
- Test kernelowy dowodzi, że encja `User` może zostać poprawnie zapisana w bazie (persist), jej hasło zweryfikowane przez rzeczywisty skonfigurowany hasher, a obiekt posiada role `ROLE_PATIENT` + `ROLE_USER`.
- Pełny zestaw narzędzi jakości (quality gate: phpstan, php-cs-fixer, phpunit) przechodzi pomyślnie bez żadnych nowych ostrzeżeń o deprecacji.
