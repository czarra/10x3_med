# Onboarding Pacjenta (S-01) — Skrót Planu

> Pełny plan: `context/changes/patient-onboarding/plan.md`

## Co i dlaczego (What & Why)

Budowa pierwszego widocznego dla użytkownika wycinka (slice) DiaGuide: pacjent rejestruje się za pomocą adresu e-mail + hasła, loguje się i — zanim przejdzie gdziekolwiek indziej — ustawia dwa początkowe parametry dawkowania (dawkę bazową insuliny oraz przelicznik insulina/WW), od których zależą wszystkie późniejsze funkcjonalności. Jest to etap roadmapy S-01, odblokowany przez F-01 (`auth-scaffold`), który dostarczył encję `User` oraz konfigurację bezpieczeństwa, ale bez faktycznego przepływu uwierzytelniania.

## Punkt wyjścia (Starting Point)

`security.yaml` posiada działający hasher haseł oraz entity user provider, ale firewall `main` nie ma jeszcze authenticatora, a reguły `access_control` są puste — nikt obecnie nie może się zalogować. Trasa wylogowania (logout) jest wstępnie podpięta, lecz nieużywana. `App\Entity\User` nie ma pól dotyczących dawkowania. Komponent formularzy (Symfony Form) nie jest jeszcze zainstalowany; paczki `symfony/maker-bundle`, `symfony/browser-kit` oraz `symfony/css-selector` są dostępne, lecz dotąd nieużywane.

## Pożądany stan końcowy (Desired End State)

Nowy pacjent może się zarejestrować, trafia automatycznie na obowiązkowy ekran onboardingu i nie ma możliwości przejścia do żadnej innej podstrony aplikacji, dopóki nie wprowadzi rzeczywistych wartości dawkowania (formularz domyślnie zawiera `0`/`0`, co celowo nie przechodzi walidacji). Następnie trafia na `/profil`, który pełni rolę tymczasowego pulpitu (dashboardu) i pozwala na edycję tych samych wartości w dowolnym momencie, bez konieczności ponownego uwierzytelniania. Powracający pacjenci logują się i trafiają bezpośrednio na `/profil`.

## Kluczowe podjęte decyzje (Key Decisions Made)

| Decyzja | Wybór | Dlaczego (1 zdanie) | Źródło |
| --- | --- | --- | --- |
| Obsługa formularzy | Komponent Symfony Form | Idiomatyczny, darmowa ochrona CSRF dzięki zainstalowanemu `security-csrf`, zgodny z konwencjami Symfony obecnymi w projekcie | Plan (potwierdzone przez użytkownika) |
| Model danych dawkowania | Osobna encja `PatientProfile` (relacja 1:1 z `User`) | Oddziela kwestie uwierzytelniania od profilu medycznego/klinicznego | Plan (potwierdzone przez użytkownika) |
| Zakres funkcji auth | Wyłącznie rejestracja/logowanie/wylogowanie | Dokładnie odpowiada wymaganiom FR-001/002 z PRD; zapamiętywanie sesji (remember-me)/reset hasła/throttling nie są wymagane | Plan (potwierdzone przez użytkownika) |
| Polityka haseł | Długość 8–4096, cyfra, znak specjalny, `NotCompromisedPassword` | Złożoność zdefiniowana przez użytkownika na bazie rekomendacji Symfony dotyczącej sprawdzania wycieków haseł | Plan (potwierdzone przez użytkownika) |
| Domyślne wartości w onboardingu | Oba pola domyślnie ustawione na `0` | Wymusza aktywne wprowadzenie wartości zamiast cichego zaakceptowania pozornie poprawnej wartości domyślnej | Plan (potwierdzone przez użytkownika) |
| Zakresy walidacji | Dawka bazowa: `>0`–35; przelicznik: `0.1`–10.0 | Ocena kliniczna użytkownika (dawka 30 jednostek niesie już istotne ryzyko) | Plan (potwierdzone przez użytkownika) |
| Bramka onboardingu | Twarde przekierowanie przez subscriber zdarzeń, a nie jednorazowa zachęta | Strukturalnie gwarantuje wymóg FR-001 dotyczący „musi potwierdzić lub zmienić”, brak możliwości obejścia przez bezpośredni URL | Plan (potwierdzone przez użytkownika) |
| Re-autoryzacja przy edycji profilu | Brak ponownego podawania hasła | Są to parametry konfiguracyjne dawkowania, a nie dane uwierzytelniające; wzorzec ponownej autoryzacji zarezerwowany dla zmian danych logowania | Plan (potwierdzone przez użytkownika) |
| Strona docelowa po logowaniu/onboardingu | `/profil` | Prawdziwy pulpit jeszcze nie istnieje (dopiero w S-02+); strona profilu jest najbliższym odpowiednikiem | Plan |
| Narzędzia | `symfony/maker-bundle` (`make:registration-form`, `make:authenticator`, `make:form`) | Kontynuacja precedensu ustanowionego w F-01 za pomocą `make:user`/`make:migration` | Plan |

## Zakres (Scope)

**W zakresie:** rejestracja, logowanie, wylogowanie, obowiązkowa bramka onboardingu, widok i edycja profilu dla dawki bazowej + przelicznika insulina/WW.

**Poza zakresem:** resetowanie hasła, zapamiętywanie sesji (remember-me), ograniczanie prób logowania (throttling), weryfikacja adresu e-mail, OAuth, rola Diabetologa, właściwy interfejs pulpitu/dzienniczka (S-02), zapisywanie historycznych migawek przeliczników przy wpisach dzienniczka (S-02), zmiany treści na stronie głównej (landing page).

## Architektura / Podejście (Architecture / Approach)

Cztery idiomatyczne dla Symfony elementy oparte na istniejącym fundamencie `User`/`security.yaml`: nowa encja `PatientProfile` (relacja 1:1, strona właścicielska), współdzielony formularz `ProfileFormType` używany w onboardingu oraz przy edycji profilu, subscriber zdarzenia `kernel.request` twardo przekierowujący każdego uwierzytelnionego użytkownika bez profilu z powrotem na `/onboarding`, a także przepływ logowania wygenerowany przez `make:authenticator`. Wszystkie formularze to czysty Twig, bez frameworka CSS, spójnie z istniejącym szkieletem projektu.

## Przegląd faz (Phases at a Glance)

| Faza | Co dostarcza | Główne ryzyko |
| --- | --- | --- |
| 1. Model danych PatientProfile | Encja, repozytorium, migracja | Brak — wyłącznie fundament |
| 2. Onboarding, edycja profilu, bramka dostępu | Chronione ekrany, testowane przez `loginUser()` | Logika wykluczeń w subscriberze bramki — błędne wykluczenia spowodują pętle przekierowań |
| 3. Logowanie i wylogowanie | Rzeczywiste uwierzytelnianie, podpięcie authenticatora | Cel przekierowania po udanym logowaniu musi respektować bramkę z Fazy 2 |
| 4. Rejestracja | Tworzenie konta, automatyczne logowanie, przekierowanie do onboardingu, pełne przejście quality gate | Obsługa zduplikowanego adresu e-mail; przypadki brzegowe w polityce haseł |

**Wymagania wstępne:** F-01 (`auth-scaffold`) w pełni wdrożone — potwierdzone (wszystkie punkty w sekcji Progress pliku `context/changes/auth-scaffold/plan.md` są odhaczone).
**Szacowany nakład pracy:** ~4 sesje, po jednej na fazę, w ramach budżetu MVP (3 tygodnie prac po godzinach).

## Otwarte ryzyka i założenia (Open Risks & Assumptions)

- ~~`Security::login()` bez wcześniej zarejestrowanego authenticatora...~~ **Rozwiązane podczas przeglądu planu (2026-08-25)**: `Security::login()` rzuca wyjątek `LogicException`, gdy firewall nie ma zarejestrowanego żadnego authenticatora (potwierdzone w `vendor/symfony/security-bundle/Security.php:243-246` oraz niezależnie przez `make:registration-form`, które w takim przypadku odmawia wygenerowania wywołania automatycznego logowania). Naprawione przez zmianę kolejności: Faza 3 to teraz „Logowanie i wylogowanie” (podpina `custom_authenticators`), a Faza 4 to „Rejestracja” (wywołanie automatycznego logowania odwołuje się teraz do już zarejestrowanego authenticatora).
- Dolna granica walidacji dawki bazowej (`Positive`, tj. dowolna wartość `> 0`) w porównaniu do jawnego progu `0.1` dla przelicznika jest decyzją godzącą dwie odrębne odpowiedzi użytkownika — odnotowano to w sekcji Critical Implementation Details planu, bez ponownego podważania w tym miejscu.

## Kryteria sukcesu — podsumowanie (Success Criteria)

- Pacjent może przejść od rejestracji do zapisanego początkowego profilu bez zobaczenia po drodze jakiejkolwiek niechronionej strony.
- Powracający pacjent loguje się i trafia na swój aktualny profil, który może edytować w dowolnym momencie, przy pełnym przejściu automatycznej bramki jakości (quality gate) na zielono.
