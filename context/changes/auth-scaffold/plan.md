# Auth Scaffold Implementation Plan

## Overview

Scaffold Symfony Security for DiaGuide: install `symfony/security-bundle`, configure password hashing and a `User` entity provider, create the `User` entity + repository + migration. This is foundation-only work (roadmap F-01) — no registration/login controllers or UI. It unblocks S-01 (patient-onboarding), which builds the actual registration/login flow on top of this entity.

## Current State Analysis

The repo is a fresh Symfony 7.4 skeleton (`config/bundles.php` only registers `FrameworkBundle`, `DoctrineBundle`, `DoctrineMigrationsBundle`, `TwigBundle`, `TwigExtraBundle`). There is no `security.yaml`, no `symfony/security-bundle` in `composer.json`, and `src/Entity/` / `migrations/` are empty scaffolds (only `.gitignore` placeholders). `config/packages/doctrine.yaml` already maps `src/Entity` via attributes with the `underscore` naming strategy against PostgreSQL 18. Existing controllers (`src/Controller/HomeController.php`, `src/Controller/Api/StatusController.php`) use PHP 8 attribute routing — the established convention to follow, though this plan adds no controllers.

## Desired End State

`docker compose exec php composer require symfony/security-bundle` outcome is committed: `security.yaml` configures the `auto` password hasher for `App\Entity\User` and an entity-backed user provider keyed by email. A `User` entity (table `users`) exists with `id`, `email` (unique), `password` (hashed), `roles`, `createdAt`, backed by a `UserRepository`, and a Doctrine migration creates the `users` table in both the dev and test databases. `doctrine:schema:validate` reports no mapping errors, and a kernel test proves the entity persists, the configured hasher can hash/verify a password, and `getRoles()` includes `ROLE_PATIENT`.

### Key Discoveries:

- `config/packages/doctrine.yaml:16-22` — entity mapping already wired to `src/Entity`, attribute-based, no changes needed there.
- `tests/Kernel/KernelBootTest.php` — existing `KernelTestCase` pattern to follow for the new kernel test.
- `docker-compose.yml` — services are `php`, `database` (dev), `database-test` (test); `.env.dist` documents `DOCKER_POSTGRES_*` vars feeding `DATABASE_URL`.
- `phpstan.neon` analyzes `src/` only at level 5 — the new entity/repository must pass it; the test file is not analyzed.
- `phpunit.dist.xml` runs with `failOnDeprecation`/`failOnNotice`/`failOnWarning` — any Doctrine/Security deprecation notice fails the suite, not just the new test.

## What We're NOT Doing

- No registration or login controllers, forms, or templates (roadmap slice S-01).
- No email verification field or flow (not in PRD; no consumer exists yet).
- No Diabetolog role or account type (PRD Non-Goals, v2).
- No OAuth/social login (PRD FR-002 resolution: deferred to v2).
- No profile fields (base dose, insulin/WW ratio) on `User` — those belong to S-01's onboarding flow.
- No `access_control` rules or protected routes — nothing exists yet to protect.

## Implementation Approach

Two phases: (1) install the bundle, write `security.yaml`, create the `User` entity/repository, generate and apply the migration; (2) add a kernel test proving the wiring works end-to-end, then run the full quality gate. `symfony/maker-bundle` is added as a dev-only dependency to generate the entity and migration via Symfony's own idioms (`make:user`, `make:migration`), matching how the rest of this skeleton was scaffolded.

## Critical Implementation Details

- **PostgreSQL reserved keyword**: `user` is a reserved word in PostgreSQL. Doctrine's `underscore` naming strategy would otherwise map the `User` class to a table literally named `user`, which requires quoting on every query and recipe-breaks raw SQL. Explicitly set `#[ORM\Table(name: 'users')]` on the entity to sidestep this.
- **Firewall without an authenticator is valid and expected here**: `security.yaml`'s `main` firewall will have a `provider` but no `authenticators` yet (no login form/API token exists). Symfony allows this — the firewall simply won't authenticate anyone until S-01 adds an authenticator. Don't add `access_control` entries in this phase; there are no protected routes to guard.
- **Migrations must be applied to both databases**: `doctrine:migrations:migrate` against the default connection only touches whichever DB `APP_ENV` resolves to. Run it once with the default dev env and once with `APP_ENV=test` so both `database` and `database-test` (per `docker-compose.yml`) have the `users` table — do NOT use `./run-dev.sh` for this, it tears down and rebuilds containers first (per project convention).
- **Pre-existing test-DB connectivity bug (fixed during plan review)**: `config/packages/doctrine.yaml`'s `when@test` block had a `dbname_suffix: '_test%env(default::TEST_TOKEN)%'` left over from the stock Flex recipe. Since `.env.test` already redirects `DATABASE_URL` to a fully separate connection (`database-test` host, dbname `app_test`), the suffix doubled up and made Doctrine resolve to a nonexistent `app_test_test` database under `APP_ENV=test` — verified via `docker compose exec -e APP_ENV=test php bin/console dbal:run-sql "SELECT current_database()"`, which failed with `FATAL: database "app_test_test" does not exist`. This would have broken success criterion 1.4 (migration applied to test) and all of Phase 2 (PHPUnit forces `APP_ENV=test`). Fixed by removing the `when@test` block from `config/packages/doctrine.yaml` — confirmed the same command now resolves to `app_test`, which docker-compose actually provisions.

## Phase 1: Security bundle, User entity, and migration

### Overview

Install the Security bundle and Maker bundle, configure password hashing and the user provider, scaffold the `User` entity + repository, and land the migration against both databases.

### Changes Required:

#### 0. Test database connectivity fix (prerequisite)

**File**: `config/packages/doctrine.yaml`

**Intent**: Remove the `when@test` block's `dbname_suffix`, which broke Doctrine's connection to the test database (see Critical Implementation Details). Without this, no later step that touches Doctrine under `APP_ENV=test` — migration or kernel test — can succeed.

**Contract**: Delete the `when@test:` block entirely (it contained only the `dbal.dbname_suffix` key). `.env.test` already fully specifies the test connection, so no replacement config is needed. Already applied to the working tree; verify with `docker compose exec -e APP_ENV=test php bin/console dbal:run-sql "SELECT current_database()"` → expect `app_test`, not `app_test_test`.

#### 1. Dependencies

**File**: `composer.json`

**Intent**: Add `symfony/security-bundle` (runtime) and `symfony/maker-bundle` (dev-only) so the entity/migration can be generated with Symfony's own tooling and the app can hash/verify passwords.

**Contract**: Run inside the `php` container: `composer require symfony/security-bundle` then `composer require --dev symfony/maker-bundle`. Both should resolve to `7.4.*`-compatible / current stable versions; let Composer pick them. Symfony Flex will auto-register `SecurityBundle` in `config/bundles.php` and may drop a default `config/packages/security.yaml` recipe — overwrite it per below rather than hand-merging.

#### 2. Security configuration

**File**: `config/packages/security.yaml`

**Intent**: Configure the `auto` password hasher for `App\Entity\User`, register an entity-backed user provider keyed by `email`, and define the standard `dev` + `main` firewalls (no authenticator on `main` yet — see Critical Implementation Details).

**Contract**:
```yaml
security:
    password_hashers:
        App\Entity\User:
            algorithm: auto

    providers:
        app_user_provider:
            entity:
                class: App\Entity\User
                property: email

    firewalls:
        dev:
            pattern: ^/(_(profiler|wdt)|css|images|js)/
            security: false
        main:
            lazy: true
            provider: app_user_provider

    access_control: []
```

#### 3. User entity

**File**: `src/Entity/User.php`

**Intent**: Minimal auth-capable entity implementing `UserInterface` and `PasswordAuthenticatedUserInterface`, matching the `security.yaml` provider config. Generate via `bin/console make:user` (answering: entity name `User`, store in database yes, identifier property `email`, has password yes) then adjust to match the field set below.

**Contract**: Class implements `Symfony\Component\Security\Core\User\UserInterface` and `Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface`. `#[ORM\Table(name: 'users')]` (see Critical Implementation Details). Fields: `id` (int, auto), `email` (string, unique, not null — this is `getUserIdentifier()`), `password` (string, not null — hashed, never plaintext), `roles` (json array), `createdAt` (`DateTimeImmutable`, set once in the constructor). Constructor sets `createdAt = new \DateTimeImmutable()` and `roles = [self::ROLE_PATIENT]`. Add `public const ROLE_PATIENT = 'ROLE_PATIENT';`. `getRoles()` returns the stored roles plus `ROLE_USER`, deduplicated (`array_unique([...$this->roles, 'ROLE_USER'])`). `eraseCredentials()` is a no-op (no plaintext ever stored).

#### 4. User repository

**File**: `src/Repository/UserRepository.php`

**Intent**: Standard Doctrine repository generated alongside the entity, extending `ServiceEntityRepository`, implementing `PasswordUpgraderInterface::upgradePassword()` (the idiomatic Symfony pattern `make:user` itself generates) so the hasher can transparently rehash on future algorithm upgrades.

**Contract**: `class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface`, constructor takes `ManagerRegistry`, `upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void` persists and flushes the new hash.

#### 5. Migration

**File**: `migrations/VersionYYYYMMDDHHMMSS.php` (generated filename)

**Intent**: Create the `users` table matching the entity mapping.

**Contract**: Generate with `bin/console make:migration` (or `doctrine:migrations:diff`) after the entity is in place; review the generated SQL for a `users` table with `id` (serial PK), `email` (varchar, unique index), `password` (varchar), `roles` (json), `created_at` (timestamp). Apply with `bin/console doctrine:migrations:migrate --no-interaction` against the dev DB, and again with `APP_ENV=test` against the test DB.

### Success Criteria:

#### Automated Verification:

- Test DB connectivity fixed: `docker compose exec -e APP_ENV=test php bin/console dbal:run-sql "SELECT current_database()"` returns `app_test`
- Dependencies installed: `docker compose exec php composer show symfony/security-bundle` and `composer show symfony/maker-bundle` both resolve
- Schema validates: `docker compose exec php bin/console doctrine:schema:validate` reports mapping is valid
- Migration applied (dev): `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`
- Migration applied (test): `docker compose exec -e APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction`
- Static analysis passes: `docker compose exec php vendor/bin/phpstan analyse`
- Code style passes: `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff`

#### Manual Verification:

- `docker compose exec php bin/console doctrine:mapping:info` lists `App\Entity\User` with no errors
- Inspect the generated migration SQL by eye to confirm the `users` table (not `user`) and column types look correct before applying

---

## Phase 2: Verification test

### Overview

Prove the Security + Doctrine wiring actually works — entity persists, the configured hasher round-trips a password, roles resolve correctly — and run the full project quality gate.

### Changes Required:

#### 1. Kernel test

**File**: `tests/Entity/UserTest.php`

**Intent**: Boot the kernel, persist a `User`, hash a password through the container's configured `UserPasswordHasherInterface`, verify it round-trips, and assert `ROLE_PATIENT` and `ROLE_USER` are both present — proving `security.yaml`'s hasher config and the entity's role logic agree, following the existing `tests/Kernel/KernelBootTest.php` `KernelTestCase` pattern.

**Contract**: `class UserTest extends KernelTestCase`. Test method boots the kernel, constructs a `User`, sets email + a hashed password via `self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword(...)`, persists + flushes via the Doctrine `EntityManagerInterface` from the container, then asserts: `isPasswordValid()`-equivalent check via the hasher's `isPasswordValid()`, and `in_array('ROLE_PATIENT', $user->getRoles())` / `in_array('ROLE_USER', $user->getRoles())`. Use a unique email per run (e.g. `uniqid()` suffix) so re-runs don't collide on the unique constraint, and clean up the persisted row at the end of the test.

### Success Criteria:

#### Automated Verification:

- New test passes: `docker compose exec php vendor/bin/phpunit tests/Entity/UserTest.php`
- Full suite passes (no regressions, no deprecations): `docker compose exec php vendor/bin/phpunit`
- Full quality gate green: `docker compose exec php vendor/bin/phpstan analyse && docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff && docker compose exec php vendor/bin/phpunit`

#### Manual Verification:

- Read the test output to confirm no deprecation warnings were silently introduced by the Security bundle install (`phpunit.dist.xml` fails the build on any, so a pass here is sufficient — no separate manual step needed beyond reading the result)

---

## Testing Strategy

### Unit Tests:

- Entity round-trip: persist/hydrate a `User`, confirm mapped fields survive.

### Integration Tests:

- Kernel test exercising the real configured password hasher (via the DI container), not a mock — proves `security.yaml` config is wired correctly, not just that the entity class compiles.

### Manual Testing Steps:

1. `docker compose exec php bin/console doctrine:mapping:info` — confirm `User` mapping has no errors.
2. Eyeball the generated migration SQL before applying, to confirm table name is `users` and the unique index on `email` exists.
3. `docker compose exec php bin/console doctrine:schema:validate` — confirm mapping matches the actual DB schema after migration.

## Migration Notes

Fresh table, no existing data to migrate. Apply the migration to both `database` (dev) and `database-test` (test) connections as described in Critical Implementation Details — do not use `./run-dev.sh` for this since it is destructive (tears down and rebuilds containers).

## References

- Roadmap item: `context/foundation/roadmap.md` § F-01 (Szkielet autoryzacji)
- PRD: `context/foundation/prd.md` § FR-001, FR-002 (Profil i Ustawienia), § Access Control
- Existing test pattern: `tests/Kernel/KernelBootTest.php`
- Existing Doctrine mapping config: `config/packages/doctrine.yaml:10-22`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Security bundle, User entity, and migration

#### Automated

- [x] 1.0 Test DB connectivity fixed: `dbal:run-sql "SELECT current_database()"` under `APP_ENV=test` returns `app_test` — applied to `config/packages/doctrine.yaml` in working tree, uncommitted
- [x] 1.1 Dependencies installed: `composer show symfony/security-bundle` and `composer show symfony/maker-bundle` resolve
- [x] 1.2 Schema validates: `doctrine:schema:validate` reports mapping is valid
- [x] 1.3 Migration applied (dev)
- [x] 1.4 Migration applied (test)
- [x] 1.5 Static analysis passes: `vendor/bin/phpstan analyse`
- [x] 1.6 Code style passes: `vendor/bin/php-cs-fixer fix --dry-run --diff`

#### Manual

- [x] 1.7 `doctrine:mapping:info` lists `App\Entity\User` with no errors
- [x] 1.8 Generated migration SQL reviewed by eye (table `users`, correct column types)

### Phase 2: Verification test

#### Automated

- [x] 2.1 New test passes: `vendor/bin/phpunit tests/Entity/UserTest.php`
- [x] 2.2 Full suite passes with no regressions/deprecations
- [x] 2.3 Full quality gate green (phpstan + cs-fixer dry-run + phpunit)

#### Manual

- [x] 2.4 Test output reviewed for silently-introduced deprecation warnings
