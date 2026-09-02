# Repository Guidelines

DiaGuide (`dia-guide`) is a Symfony 7.4 web app (PHP 8.2+, Doctrine ORM, PostgreSQL 18, Twig) — currently a fresh skeleton being built out into an insulin/WW dosing-assistant MVP by a solo developer.

## Agent-specific instructions

- Never commit `.env.local` or real Postgres credentials; `.env.dist` documents the required `DOCKER_POSTGRES_*` and `DATABASE_URL` variables.
- Feature work is tracked under `context/changes/<change-id>/` using the 10xDevs toolkit skills (`/10x-new`, `/10x-plan`, `/10x-implement`); `context/foundation/prd.md` and `context/foundation/tech-stack.md` are the locked source-of-truth contracts — don't contradict them without updating the contract first.
- Follow `@CLAUDE.md` for all Playwright/E2E work (locator rules, wait rules, test independence).

## Project Structure & Module Organization

- `src/Controller/` — HTTP controllers (see `HomeController.php`, `SecurityController.php`).
- `src/Entity/`, `src/Repository/` — Doctrine entities/repositories, currently empty scaffolds.
- `config/packages/` and `config/routes/` — bundle and routing config (attribute-based routing is the default; see `HomeController.php`).
- `templates/` — Twig views.
- `migrations/` — Doctrine migrations (empty so far).
- `tests/` — PHPUnit, mirrors `src/` (e.g. `tests/Kernel/KernelBootTest.php`).
- `tests/e2e/` — Playwright browser E2E (`.ts` specs, `e2e-rules.md`); `tests/Support/E2e/` — PHP test-support (seed command + `/__e2e__/reset`), wired only for `APP_ENV=e2e`.

## Build, Test, and Development Commands

- `./run-dev.sh` — full local bootstrap: rebuilds Docker containers, runs `composer install`, creates/migrates the dev DB, and resets/migrates the test DB.
- `docker compose exec php bin/console <command>` — run any Symfony console command inside the app container.
- `docker compose exec php vendor/bin/phpunit` (or `bin/phpunit`) — run the test suite.
- `docker compose exec php vendor/bin/phpstan analyse --memory-limit=512M` — static analysis (level 5, `src/` only, see `phpstan.neon`). The `--memory-limit` flag is required: the php container's CLI `memory_limit` is 128M and PHPStan's parallel workers crash under it.
- `docker compose exec php vendor/bin/php-cs-fixer fix` — apply the `@Symfony` code style ruleset before committing.
- `docker compose exec playwright npx playwright test` — run the browser E2E suite (single spec: append `tests/e2e/<file>.spec.ts`). First time after checkout: `docker compose exec playwright npm ci`.

## Coding Style & Naming Conventions

- Formatting per `@.editorconfig`; `@Symfony` ruleset via `@.php-cs-fixer.dist.php` (excludes `var/`, `config/bundles.php`, `config/reference.php`).

## Testing Guidelines

- PHPUnit 13, config at `@phpunit.dist.xml`, bootstrap `tests/bootstrap.php`, `KERNEL_CLASS=App\Kernel`.
- The suite runs with `failOnDeprecation`, `failOnNotice`, and `failOnWarning` — any PHP deprecation/notice fails the build, not just uncaught errors.
- Tests run against the `database-test` Postgres service (`APP_ENV=test`, port 4307 on the host).
- Browser E2E (Playwright) runs in the `playwright` container against `php-e2e` (`APP_ENV=e2e`, `.env.e2e`, host port 8382), which **shares** the `database-test` Postgres. The E2E fixtures only ever touch `@e2e.test` rows, so the two suites are safe **as long as they run sequentially, never concurrently** (matters for CI stage ordering).
