---
name: verify
description: Run this project's full quality gate (phpstan, php-cs-fixer dry-run, phpunit) in one shot inside the php container. Use before committing or when the user asks to "verify", "check everything passes", or "run the test suite and static analysis".
---

Run these three commands, in order, via `docker compose exec php`. Stop and
report immediately if a command fails — don't run the later steps against
code you know is broken.

1. **Static analysis**
   ```
   docker compose exec php vendor/bin/phpstan analyse --memory-limit=512M
   ```
   Reads `phpstan.neon` (level 5, `src/` only — `tests/` and `config/` are not
   covered, so don't expect this step to catch issues there). `--memory-limit`
   is required: the php container's CLI `memory_limit` is 128M and PHPStan's
   parallel workers crash under it (same flag is in `.github/workflows/ci.yml`).

2. **Code style (dry run — do not auto-fix)**
   ```
   docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff
   ```
   Reports `@Symfony`-ruleset violations without modifying files. If this
   surfaces diffs, ask the user before running `php-cs-fixer fix` for real
   (without `--dry-run`) to apply them.

3. **Tests**
   ```
   docker compose exec php vendor/bin/phpunit
   ```
   Runs against the `database-test` Postgres service. The suite fails on any
   PHP deprecation/notice/warning, not just uncaught errors — treat those as
   failures, not noise.

If `docker compose exec` fails outright (e.g. "no such service" / connection
refused), the containers likely aren't up — tell the user to run `./run-dev.sh`
first rather than silently retrying.

Summarize pass/fail for each of the three steps at the end; don't just dump
raw command output.
