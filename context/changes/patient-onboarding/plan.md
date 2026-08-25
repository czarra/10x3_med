# Patient Onboarding (S-01) Implementation Plan

## Overview

Build the first user-visible slice of DiaGuide (roadmap S-01): a patient can register with e-mail + password, log in, and — before reaching anywhere else in the app — set the two initial dosing parameters (base insulin dose, insulin/WW ratio) that every later feature depends on. Patients can later revisit and edit those same parameters. This builds directly on F-01 (`auth-scaffold`), which shipped the `security.yaml` wiring, the `User` entity, and password hashing but no auth flow.

## Current State Analysis

`security.yaml`'s `main` firewall has a `provider` (`app_user_provider`, entity-backed, keyed by `email`) but no authenticator and an empty `access_control: []` — nobody can currently log in. `config/routes/security.yaml` already wires `_security_logout` via `security.route_loader.logout`, but the `logout:` key is missing from the `main` firewall, so that loader has nothing to generate yet. `App\Entity\User` (table `users`) has `id`, `email`, `password`, `roles` (`ROLE_PATIENT` + `ROLE_USER`), `createdAt` — no dosing fields. No `symfony/form` is installed; only `symfony/validator`, `symfony/serializer`, `symfony/twig-bundle`. `symfony/security-csrf` is present transitively (via `security-bundle`), so Form-generated CSRF protection works with no extra dependency. `symfony/maker-bundle`, `symfony/browser-kit`, and `symfony/css-selector` are dev dependencies — `make:registration-form`, `make:authenticator`, `make:form`, and functional `WebTestCase` tests are all available, none used yet in this repo (F-01 only used `make:user`/`make:migration` and kernel tests). Existing UI (`templates/home/index.html.twig`) is plain hardcoded Polish text with no translation component and no CSS framework/build tooling — new templates follow that same plain-Twig, hardcoded-Polish-copy convention.

## Desired End State

A visitor can register at `/register` (e-mail + password meeting the policy below), is logged in automatically, and is redirected to `/onboarding`. Any authenticated patient without a saved `PatientProfile` is redirected to `/onboarding` on every request, regardless of URL typed — they cannot reach `/profil` or any other protected page until they submit initial values for base dose and insulin/WW ratio. Once submitted, they land on `/profil`, which shows their current parameters and lets them edit and resave both fields at any time, with no re-authentication required. A returning patient logs in at `/login` and is sent to `/profil` (or back to `/onboarding` if they never completed it) and can log out via `/logout`. `doctrine:schema:validate` is clean, the full `phpunit`/`phpstan`/`php-cs-fixer` gate is green, and functional tests cover the registration/login/onboarding-gate/profile-edit flows end-to-end via `WebTestCase`.

### Key Discoveries:

- `config/packages/security.yaml:12-20` — `main` firewall needs `custom_authenticators` + `logout` added; `access_control: []` needs entries for the new protected routes.
- `config/routes/security.yaml:1-3` — logout route loader is already present; it just needs a `logout:` block in the firewall to have something to generate from.
- `config/packages/validator.yaml:8-11` — `NotCompromisedPassword` is already disabled under `when@test`, so using that constraint on the registration form needs no additional test-env config; it "just works" in tests without hitting the network.
- `tests/Entity/UserTest.php` — established pattern: unique e-mail per run (`uniqid()`), manual cleanup of persisted rows (no DAMADoctrineTestBundle in this repo), kernel-boot-based DB access.
- `phpunit.dist.xml` runs with `failOnDeprecation`/`failOnNotice`/`failOnWarning` — any Symfony Form/Security deprecation fails the whole suite, not just the new tests.
- `phpstan.neon` only analyzes `src/` at level 5 — new controllers/entities/forms/subscribers must pass it; templates and tests are not analyzed by phpstan.
- **[Updated during Phase 3 implementation]** The installed `symfony/maker-bundle` is `v1.67.0`, which has removed `make:authenticator`. It's split into `make:security:form-login` (`vendor/symfony/maker-bundle/src/Maker/Security/MakeFormLogin.php`, generates the built-in `form_login:` YAML key plus a `SecurityController` + login template — no custom authenticator class) and `make:security:custom` (bare token-style `AbstractAuthenticator`, wrong shape for a login form). Phase 3 uses `make:security:form-login`. Verified via `vendor/symfony/security-bundle/Security.php`'s `getAuthenticator()` that `Security::login($user, 'form_login', 'main')` resolves the built-in form_login authenticator by name identically to how a `custom_authenticators` entry would — the "one authenticator per firewall" constraint for the zero/named-arg call is unaffected by which mechanism registers that one authenticator. Symfony's built-in `form_login` authenticator already honors a bounced-from target path via the same `TargetPathTrait` mechanism a hand-written authenticator would use, with `default_target_path` as the configurable fallback — so no hand-written authenticator class is needed to satisfy the original intent.

## What We're NOT Doing

- Password reset / forgot-password flow — no FR covers it; explicitly deferred alongside other v1 gaps.
- "Remember me" cookies and login throttling — confirmed out of scope for S-01 (register/login/logout only).
- Email verification — already ruled out in F-01's plan (no consumer exists yet).
- OAuth/social login — PRD FR-002 resolution defers this to v2.
- Diabetolog role, share codes, or any second account type — PRD Non-Goals / v2.
- A real dashboard or diary UI (S-02) — the post-onboarding/post-login landing page is `/profil`, standing in for a dashboard that doesn't exist yet.
- Historical snapshotting of the ratio/dose at diary-entry time (PRD FR-003's "past entries keep the old value") — that's S-02's concern once diary entries exist; this slice only stores the patient's *current* profile values.
- Marketing/landing-page content for `/` — out of scope; the existing skeleton home page is left as-is.

## Implementation Approach

Four phases, sequenced to avoid forward references: (1) the `PatientProfile` data model first, since everything else hangs off it; (2) the onboarding/profile-edit screens and the access-gate subscriber, tested via `$client->loginUser()` so they don't need real login yet; (3) login/logout, which wires the `custom_authenticators` entry the firewall needs — tested against directly-persisted `User` rows (the `tests/Entity/UserTest.php` pattern), since registration doesn't exist yet; (4) registration, tested last precisely because it now has both a real `/onboarding` route to redirect into *and* an already-registered authenticator for `Security::login()`'s zero-argument auto-login call to resolve (`Security::login()` throws `LogicException` if the firewall has no authenticator registered — verified against `vendor/symfony/security-bundle/Security.php`). Each phase uses `symfony/maker-bundle` commands where they exist (`make:entity`-style manual entity per F-01 precedent, `make:form`, `make:registration-form`, `make:authenticator`) to stay consistent with how F-01 was built.

## Critical Implementation Details

- **The "0 default" only works if 0 itself is rejected by validation.** The onboarding form pre-fills both fields with `0` specifically so the patient must overwrite them — but if the validation range allows `0` as a valid submission, a patient could accept the default and defeat the purpose (violates FR-001's "must consciously confirm or change"). Resolve this by validating both fields as **strictly positive** (`Positive` constraint, i.e. `> 0`), not `>= 0`: base dose `Positive` + `LessThanOrEqual(35)`, insulin/WW ratio `Positive` + range `[0.1, 10.0]` (the 0.1 floor was explicitly requested for the ratio; for base dose the requirement is just "must be > 0 and no riskier than 35", so `Positive` alone supplies the floor).
- **Onboarding-gate subscriber must not loop or block public routes.** It runs on `kernel.request` for main requests only, and must early-return when: the user isn't an authenticated `App\Entity\User` (anonymous visitors are handled by `access_control`, not this subscriber), the route name is null or starts with `_` (profiler/wdt/asset-adjacent internal routes), or the route is `patient_onboarding` itself or the logout route. Get the route name from `$request->attributes->get('_route')`. Getting any of these exclusions wrong either creates a redirect loop or leaks access to `/login`/`/register` for already-logged-in users hitting the gate unexpectedly (harmless but confusing) — the exclusion list is the load-bearing part of this subscriber, not the redirect itself.
- **`PatientProfile` existence check must not rely on Doctrine's identity map alone.** After `Security::login()` auto-logs a freshly registered user in, the very next request (redirect to onboarding) is a new HTTP request/new `EntityManager` state, so a fresh `PatientProfileRepository::findOneBy(['user' => $user])` query (not an in-memory association check) is what the gate subscriber must use.
- **Migrations must be applied to both databases**, same as F-01: run `doctrine:migrations:migrate --no-interaction` once for dev and once with `APP_ENV=test`, never via `./run-dev.sh` (destructive, tears down containers first).

## Phase 1: PatientProfile data model

### Overview

Add the `PatientProfile` entity (1:1 with `User`) that holds the two initial dosing parameters, its repository, and the migration. No user-facing behavior yet.

### Changes Required:

#### 1. PatientProfile entity

**File**: `src/Entity/PatientProfile.php`

**Intent**: Store a patient's current base insulin dose and insulin/WW ratio, one row per `User`.

**Contract**: `#[ORM\Entity(repositoryClass: PatientProfileRepository::class)]`, `#[ORM\Table(name: 'patient_profiles')]`. Fields: `id` (int, auto), `user` (`#[ORM\OneToOne(targetEntity: User::class)]`, `#[ORM\JoinColumn(nullable: false, unique: true)]` — unidirectional, owning side only, no back-reference needed on `User`), `baseDose` (float, not null), `insulinWwRatio` (float, not null), `createdAt` / `updatedAt` (`DateTimeImmutable`, both set in constructor, `updatedAt` refreshed by the setter methods). Add `#[Assert\Positive]` + `#[Assert\LessThanOrEqual(35)]` on `baseDose`, `#[Assert\Range(min: 0.1, max: 10.0)]` on `insulinWwRatio` (see Critical Implementation Details for why `Positive`, not `Range(min: 0, ...)`).

#### 2. PatientProfile repository

**File**: `src/Repository/PatientProfileRepository.php`

**Intent**: Standard Doctrine repository, plus one convenience finder the gate subscriber and profile controller both need.

**Contract**: `class PatientProfileRepository extends ServiceEntityRepository`. Add `findOneByUser(User $user): ?PatientProfile` (thin wrapper over `findOneBy(['user' => $user])`).

#### 3. Migration

**File**: `migrations/VersionYYYYMMDDHHMMSS.php` (generated)

**Intent**: Create the `patient_profiles` table matching the entity mapping.

**Contract**: Generate with `bin/console make:migration` after the entity is in place; review for a `patient_profiles` table with `id` (serial PK), `user_id` (int, FK to `users.id`, unique index), `base_dose` (float/double precision), `insulin_ww_ratio` (float/double precision), `created_at`, `updated_at`. Apply against both dev and test databases (see Critical Implementation Details).

### Success Criteria:

#### Automated Verification:

- Schema validates: `docker compose exec php bin/console doctrine:schema:validate`
- Migration applied (dev): `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`
- Migration applied (test): `docker compose exec -e APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction`
- Static analysis passes: `docker compose exec php vendor/bin/phpstan analyse`
- Code style passes: `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff`
- Kernel test passes: `docker compose exec php vendor/bin/phpunit tests/Entity/PatientProfileTest.php` — persists a `PatientProfile` for a `User`, asserts the unique constraint on `user_id` is enforced (second profile for the same user throws), asserts a `0` value for either field fails validation via the injected `ValidatorInterface`

#### Manual Verification:

- `docker compose exec php bin/console doctrine:mapping:info` lists `App\Entity\PatientProfile` with no errors
- Inspect the generated migration SQL by eye to confirm table name, FK, and unique index before applying

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 2: Onboarding, profile edit, and the access gate

### Overview

Build the gated screens that read/write `PatientProfile`: the onboarding form (first-time setup) and the profile-edit form (later updates, FR-003), sharing one Form type. Add the subscriber that hard-gates any authenticated patient without a profile back to `/onboarding`, and the `access_control` entries that protect both routes from anonymous access. Tests authenticate via `$client->loginUser()` — this phase doesn't depend on real login existing yet.

### Changes Required:

#### 1. Form dependency

**File**: `composer.json`

**Intent**: Add the Symfony Form component, needed for every form in this plan from here on.

**Contract**: `docker compose exec php composer require symfony/form` (resolves to `7.4.*`).

#### 2. Shared profile form type

**File**: `src/Form/ProfileFormType.php`

**Intent**: One form type for both onboarding (create) and later edits — same two fields, same validation, reused by both controllers.

**Contract**: `class ProfileFormType extends AbstractType`, `data_class` option set to `PatientProfile::class`. Two `NumberType` fields: `baseDose` (label "Dawka bazowa (j.)"), `insulinWwRatio` (label "Przelicznik insulina/WW"). Field-level constraints are already declared on the entity (Phase 1) via `#[Assert\...]`, so the form relies on those rather than redeclaring them, per the codebase's existing pattern of entity-level validation (`config/packages/validator.yaml`'s commented `auto_mapping` note signals this is the intended direction, even though it isn't turned on — declare constraints explicitly on the entity either way since auto_mapping is off).

#### 3. Onboarding controller

**File**: `src/Controller/OnboardingController.php`

**Intent**: First-time setup screen. If the current user already has a `PatientProfile`, redirect to `/profil` (idempotent — no re-onboarding). Otherwise show/handle `ProfileFormType` bound to a new `PatientProfile` pre-filled with `baseDose = 0`, `insulinWwRatio = 0`, wired to `getUser()`; on valid submit, persist and redirect to `/profil`.

**Contract**: `#[Route('/onboarding', name: 'patient_onboarding', methods: ['GET', 'POST'])]`, `#[IsGranted('ROLE_USER')]`.

#### 4. Profile controller

**File**: `src/Controller/ProfileController.php`

**Intent**: Show the patient's current parameters and let them resubmit `ProfileFormType` to update the same row — no password re-entry (confirmed decision).

**Contract**: `#[Route('/profil', name: 'patient_profile', methods: ['GET', 'POST'])]`, `#[IsGranted('ROLE_USER')]`. Loads the existing `PatientProfile` via `PatientProfileRepository::findOneByUser()` (guaranteed to exist here because of the gate subscriber); on valid submit, flushes changes and shows a success flash message.

#### 5. Onboarding-gate subscriber

**File**: `src/EventSubscriber/RequireOnboardingSubscriber.php`

**Intent**: Structurally enforce that an authenticated patient without a `PatientProfile` cannot reach any page except onboarding/logout, regardless of URL. See Critical Implementation Details for the exact exclusion rules this must implement correctly.

**Contract**: `implements EventSubscriberInterface`, subscribes to `KernelEvents::REQUEST`. Injects `Security` (or `TokenStorageInterface`) and `PatientProfileRepository`.

#### 6. Access control

**File**: `config/packages/security.yaml`

**Intent**: Require authentication for the two new routes; everything else stays as-is (public `/`, and `/register`/`/login` added public in Phase 3/4).

**Contract**: Add to `access_control`: `{ path: ^/(onboarding|profil)$, roles: ROLE_USER }` — end-anchored so a future route merely prefixed with `onboarding`/`profil` (e.g. `/onboarding-status`) doesn't silently inherit this rule (Symfony's `access_control` path matching isn't implicitly end-anchored; see `PathRequestMatcher::matches()`).

#### 7. Templates

**Files**: `templates/onboarding/index.html.twig`, `templates/profile/edit.html.twig`

**Intent**: Plain Twig forms matching the existing hardcoded-Polish-copy convention (`templates/home/index.html.twig`). Onboarding copy frames this as required first-time setup ("Ustaw swoje parametry początkowe"); profile-edit copy frames it as an update ("Edytuj swoje parametry").

**Contract**: Both `extend 'base.html.twig'`, render `{{ form(form) }}` for the shared `ProfileFormType`, display validation errors inline (Form's default error rendering is sufficient — no custom theme needed for 2 fields).

### Success Criteria:

#### Automated Verification:

- Static analysis passes: `docker compose exec php vendor/bin/phpstan analyse`
- Code style passes: `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff`
- Functional tests pass: `docker compose exec php vendor/bin/phpunit tests/Controller/OnboardingControllerTest.php tests/Controller/ProfileControllerTest.php` — covering: (a) a logged-in user with no profile hitting `/profil` is redirected to `/onboarding`; (b) submitting the onboarding form with `0`/`0` re-shows validation errors and does not create a profile; (c) submitting valid values creates the profile and redirects to `/profil`; (d) a logged-in user who already has a profile hitting `/onboarding` is redirected to `/profil`; (e) editing on `/profil` persists new values without requiring a password field in the submitted form

#### Manual Verification:

- Log in as a seeded user with no profile (via a throwaway fixture or `bin/console doctrine:query:sql`), confirm every URL in the app bounces back to `/onboarding` until the form is submitted
- Confirm the `0`/`0` defaults render as visibly editable (not blank, not disabled) so the "must overwrite" intent reads clearly in the UI

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 3: Login and logout

### Overview

Let a patient authenticate with e-mail + password, land on `/profil` (or get bounced to `/onboarding` by the Phase 2 gate if they never finished it), and log out. Registration doesn't exist yet at this point in the build order, so this phase's tests create accounts by persisting a `User` directly via `EntityManager` (the pattern already established in `tests/Entity/UserTest.php`), not through an HTTP registration flow.

### Changes Required:

#### 1. Login controller, template, and security wiring (generated)

**Files**: `src/Controller/SecurityController.php` (generated), `templates/security/login.html.twig` (generated), `config/packages/security.yaml`

**Intent**: **[Updated during Phase 3 implementation — see Key Discoveries for why]** Generate via `bin/console make:security:form-login`: controller name `SecurityController`, firewall `main`, user class `App\Entity\User` / username field `email`, confirm "yes" to generating a `/logout` URL. This single command wires the built-in `form_login:` authenticator on `main` (login path/check path both `app_login`, `enable_csrf: true`) plus the `logout:` block (path `app_logout`), and generates the login controller + template together — no separate hand-written authenticator class.

**Contract**: After generation, add `default_target_path: patient_profile` to the `form_login:` block so a login with no bounced-from target path (i.e. not redirected here by `access_control`) lands on `/profil` rather than `/` — Symfony's built-in form_login already honors a bounced-from target path automatically via the same mechanism a hand-written `AbstractLoginFormAuthenticator` would use. Verify `access_control` still has the Phase 2 `^/(onboarding|profil)$` entry unchanged, and that `/login` (`app_login`) is public (the maker adds this). `/register` isn't public yet since Phase 4 hasn't created that route. Adjust the generated template copy to match the app's plain-Polish-Twig convention.

### Success Criteria:

#### Automated Verification:

- Static analysis passes: `docker compose exec php vendor/bin/phpstan analyse`
- Code style passes: `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff`
- Functional tests pass: `docker compose exec php vendor/bin/phpunit tests/Controller/SecurityControllerTest.php` — covering, against `User` rows persisted directly in each test's setup (unique `uniqid()`-suffixed e-mail, cleaned up at the end of the test, per `tests/Entity/UserTest.php`'s pattern): (a) valid login for a user *with* a `PatientProfile` redirects to `/profil`; (b) valid login for a user *without* a `PatientProfile` is bounced to `/onboarding` by the Phase 2 gate; (c) wrong password shows an authentication error and does not log in; (d) logging out and then requesting `/profil` redirects to `/login`

#### Manual Verification:

- Log in as a directly-seeded user through the browser, confirm the correct landing page (`/profil` or `/onboarding`), then log out and confirm `/profil` redirects to `/login`

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 4: Registration

### Overview

Let a new patient create an account with e-mail + password meeting the agreed policy, auto-login them via the authenticator Phase 3 already registered, and land them on the now-existing `/onboarding`. Run the full project quality gate at the end.

### Changes Required:

#### 1. Registration form type

**File**: `src/Form/RegistrationFormType.php`

**Intent**: Collect e-mail + password with the agreed policy. Generate via `bin/console make:registration-form`, declining the maker's email-verification and "agree to terms" prompts (neither is in scope), then adjust the password field's constraints to match the policy below.

**Contract**: `email` field maps to `User::$email` (`NotBlank`, `Email`). `plainPassword` is a non-mapped field (never persisted directly), constraints: `NotBlank`, `Length(min: 8, max: PasswordHasherInterface::MAX_PASSWORD_LENGTH)`, `Regex(pattern: '/\d/', message: '...cyfrę...')`, `Regex(pattern: '/[^a-zA-Z0-9]/', message: '...znak specjalny...')`, `NotCompromisedPassword` (already network-disabled under `when@test`, per Key Discoveries — no extra test wiring needed).

#### 2. Registration controller

**File**: `src/Controller/RegistrationController.php`

**Intent**: Handle the form, hash the password, persist the `User`, log them in for the current firewall, and send them straight to onboarding.

**Contract**: `#[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]`. On valid submit: hash `plainPassword` via `UserPasswordHasherInterface`, set on the new `User`, persist + flush, call `Security::login($user, 'form_login', 'main')` (from `Symfony\Bundle\SecurityBundle\Security`, explicit authenticator name + firewall name — **[Updated during Phase 3 implementation]** Phase 3 now wires the built-in `form_login:` authenticator rather than a `custom_authenticators` class; `'form_login'` is the authenticator-name string `Security::getAuthenticator()` resolves against, per `vendor/symfony/security-bundle/Security.php`; keep an explicit name+firewall rather than the zero-argument `Security::login($user)` overload — the zero-arg lookup only works while the firewall has exactly one authenticator and starts throwing `LogicException('Too many authenticators...')` the moment a second one is ever added, e.g. remember-me; this is also why registration is sequenced after login/logout, since Phase 3 must register that one authenticator first), redirect to `patient_onboarding`. On duplicate e-mail (Doctrine unique constraint violation, or a pre-flush existence check via `UserRepository`), re-show the form with a field-level error rather than a 500.

#### 3. Registration template

**File**: `templates/registration/register.html.twig`

**Intent**: Plain Twig form, same convention as Phase 2's templates.

**Contract**: `extends 'base.html.twig'`, renders `{{ form(registrationForm) }}`.

### Success Criteria:

#### Automated Verification:

- Static analysis passes: `docker compose exec php vendor/bin/phpstan analyse`
- Code style passes: `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff`
- Functional tests pass: `docker compose exec php vendor/bin/phpunit tests/Controller/RegistrationControllerTest.php` — covering: (a) valid registration creates a `User`, logs them in, and redirects to `/onboarding`; (b) duplicate e-mail is rejected with a form error, no second row created; (c) password missing a digit is rejected; (d) password missing a special character is rejected; (e) password under 8 characters is rejected — each using a unique `uniqid()`-suffixed e-mail per project test convention, with created rows cleaned up at the end of the test
- Full suite passes with no regressions or deprecations: `docker compose exec php vendor/bin/phpunit`
- Full quality gate green: `docker compose exec php vendor/bin/phpstan analyse && docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff && docker compose exec php vendor/bin/phpunit`

#### Manual Verification:

- Full manual walk-through in the browser: register → land on onboarding → submit `0`/`0` (see validation errors) → submit valid values → land on `/profil` → log out → log back in → land on `/profil` directly (no onboarding bounce) → edit values → confirm they persist after a page reload
- Read the test output to confirm no deprecation warnings were silently introduced by the Form/Security additions (`phpunit.dist.xml` fails the build on any, so a pass here is sufficient)

---

## Testing Strategy

### Unit Tests:

- `PatientProfile` entity: persistence round-trip, unique constraint on `user_id`, validation rejects `0` on either field (Phase 1).

### Integration Tests:

- Functional `WebTestCase` coverage for every controller added in Phases 2–4, per each phase's Success Criteria above — this is the primary test layer for this plan, since the behavior that matters is HTTP-level (redirects, form re-rendering with errors, session state), not unit-level logic.

### Manual Testing Steps:

1. Full browser walk-through described in Phase 4's Manual Verification.
2. Attempt to reach `/profil` via direct URL entry as an anonymous user — confirm redirect to `/login`.
3. Attempt to reach `/profil` via direct URL entry as a logged-in user with no profile yet — confirm redirect to `/onboarding`, not a 403 or 500.

## Performance Considerations

None beyond the PRD's general UX guardrail (interface response under 200ms) — all operations here are single-row reads/writes with no computation, well within that budget on the target scale (small user base, low QPS per `tech-stack.md`).

## Migration Notes

Fresh table (`patient_profiles`), no existing data. Apply to both `database` (dev) and `database-test` (test) connections as described in Critical Implementation Details — do not use `./run-dev.sh` (destructive).

## References

- Roadmap item: `context/foundation/roadmap.md` § S-01 (Konto i wstępna konfiguracja)
- PRD: `context/foundation/prd.md` § FR-001, FR-002, FR-003 (Profil i Ustawienia), § Access Control, § US-01
- Prior plan / precedent for `make:*` tooling and DB-connectivity gotchas: `context/changes/auth-scaffold/plan.md`
- Existing entity: `src/Entity/User.php`
- Existing test pattern: `tests/Entity/UserTest.php`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: PatientProfile data model

#### Automated

- [x] 1.1 Schema validates: `doctrine:schema:validate` — 6f1f80e
- [x] 1.2 Migration applied (dev) — 6f1f80e
- [x] 1.3 Migration applied (test) — 6f1f80e
- [x] 1.4 Static analysis passes: `vendor/bin/phpstan analyse` — 6f1f80e
- [x] 1.5 Code style passes: `vendor/bin/php-cs-fixer fix --dry-run --diff` — 6f1f80e
- [x] 1.6 Kernel test passes: `vendor/bin/phpunit tests/Entity/PatientProfileTest.php` — 6f1f80e

#### Manual

- [x] 1.7 `doctrine:mapping:info` lists `App\Entity\PatientProfile` with no errors — 6f1f80e
- [x] 1.8 Generated migration SQL reviewed by eye (table, FK, unique index) — 6f1f80e

### Phase 2: Onboarding, profile edit, and the access gate

#### Automated

- [x] 2.1 Static analysis passes: `vendor/bin/phpstan analyse` — 6981dd1
- [x] 2.2 Code style passes: `vendor/bin/php-cs-fixer fix --dry-run --diff` — 6981dd1
- [x] 2.3 Functional tests pass: `OnboardingControllerTest.php` + `ProfileControllerTest.php` — 6981dd1

#### Manual

- [ ] 2.4 Profile-less user bounces to `/onboarding` from every URL
- [ ] 2.5 `0`/`0` defaults render as visibly editable, not blank/disabled

### Phase 3: Login and logout

#### Automated

- [x] 3.1 Static analysis passes: `vendor/bin/phpstan analyse`
- [x] 3.2 Code style passes: `vendor/bin/php-cs-fixer fix --dry-run --diff`
- [x] 3.3 Functional tests pass: `SecurityControllerTest.php`

#### Manual

- [x] 3.4 Directly-seeded user logs in/out via browser, lands correctly, `/profil` redirects to `/login` after logout

### Phase 4: Registration

#### Automated

- [ ] 4.1 Static analysis passes: `vendor/bin/phpstan analyse`
- [ ] 4.2 Code style passes: `vendor/bin/php-cs-fixer fix --dry-run --diff`
- [ ] 4.3 Functional tests pass: `RegistrationControllerTest.php`
- [ ] 4.4 Full suite passes with no regressions/deprecations
- [ ] 4.5 Full quality gate green (phpstan + cs-fixer dry-run + phpunit)

#### Manual

- [ ] 4.6 Full register → onboard → profil → logout → login → profil → edit walk-through
- [ ] 4.7 Test output reviewed for silently-introduced deprecation warnings
