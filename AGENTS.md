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

## Build, Test, and Development Commands

- `./run-dev.sh` — full local bootstrap: rebuilds Docker containers, runs `composer install`, creates/migrates the dev DB, and resets/migrates the test DB.
- `docker compose exec php bin/console <command>` — run any Symfony console command inside the app container.
- `docker compose exec php vendor/bin/phpunit` (or `bin/phpunit`) — run the test suite.
- `docker compose exec php vendor/bin/phpstan analyse` — static analysis (level 5, `src/` only, see `phpstan.neon`).
- `docker compose exec php vendor/bin/php-cs-fixer fix` — apply the `@Symfony` code style ruleset before committing.

## Coding Style & Naming Conventions

- Formatting per `@.editorconfig`; `@Symfony` ruleset via `@.php-cs-fixer.dist.php` (excludes `var/`, `config/bundles.php`, `config/reference.php`).

## Testing Guidelines

- PHPUnit 13, config at `@phpunit.dist.xml`, bootstrap `tests/bootstrap.php`, `KERNEL_CLASS=App\Kernel`.
- The suite runs with `failOnDeprecation`, `failOnNotice`, and `failOnWarning` — any PHP deprecation/notice fails the build, not just uncaught errors.
- Tests run against the `database-test` Postgres service (`APP_ENV=test`, port 4307 on the host).
