# Deploy pipeline (Railway + GitHub Actions) — Implementation Plan

> Change: `deploy-pipeline-live` · Roadmap: **F-02** · Design + runbook: `context/deployment/deploy-plan.md`

## Overview

Wire the repo so the DiaGuide Symfony app builds and runs on **Railway** while the
**local `docker compose` / `run-dev.sh` workflow keeps working byte-for-byte at
runtime**. Adds a multi-stage production image, a Railway-only start script, Railway
config-as-code, and two GitHub Actions workflows (tests, then CI-gated deploy). No
application code or schema changes.

## Current State Analysis

- `php/Dockerfile` (single stage) installs system libs + PHP extensions
  (`intl pdo_pgsql gd zip` + `apcu`) + Composer binary + `a2enmod rewrite` + an inline
  `<VirtualHost *:80>` + `WORKDIR /var/www/med` + `EXPOSE 80` + `CMD ["apache2-foreground"]`.
  It never `COPY`s the app or runs `composer install` — locally invisible because
  `docker-compose.yml` bind-mounts `.:/var/www/med` over the image; on Railway (no bind
  mount) the container would boot Apache against an empty webroot.
- `docker-compose.yml` services: `php` (8381→80, bind mount, no `APP_ENV` shell var),
  `php-e2e` (8382→80, `APP_ENV=e2e`, `command:` override, healthcheck curls
  `http://localhost/login`), `playwright`, `database` (4306), `database-test` (4307,
  shared by `test` + `e2e`). Both PHP services `build: php/Dockerfile`.
- `run-dev.sh` is destructive (`docker compose down --remove-orphans` → `up -d --build
  --wait` → `composer install` in-container → dev/test DB migrate → `npm ci`). It
  survives one-shot `up --wait` only because the host already has `vendor/`.
- `migrations/` has **6** real migrations (users, patient_profiles, diary_entries, 2
  history tables, indexes). `src/Controller/Api/StatusController.php` (`/api/status`) was
  **deleted** in `153532b`. `/login` (`src/Controller/SecurityController.php:11`,
  `app_login`) is public — no `access_control` rule matches it, returns 200 + form for
  anonymous users. `/` redirects → `patient_profile` → 302 `/login` (redirect chain,
  not a stable 200).
- `config/packages/framework.yaml`: `secret: '%env(APP_SECRET)%'` — required every env.
  `config/packages/doctrine.yaml`: `url: '%env(resolve:DATABASE_URL)%'`, line
  `#server_version: '16'` commented out.
- Tracked env files: `.env` (`APP_ENV=dev`, `DOCKER_POSTGRES_*`, composed `DATABASE_URL`
  with `?serverVersion=18&charset=utf8`), `.env.dev`, `.env.test`, `.env.e2e`
  (`APP_SECRET=e2e_local_only_not_a_secret`), `.env.dist`. `.env.local` is gitignored
  and is the only place a dev `APP_SECRET` lives. **No `.env.prod`.**
- No `.github/` at all. No `railway.json` / `.dockerignore`.
- `phpunit.dist.xml` (forces `APP_ENV=test`), `phpstan.neon` (level 5, `src/` only),
  `.php-cs-fixer.dist.php` (`@Symfony`). `composer.json`: `php: >=8.5`, Symfony `7.4.*`,
  Flex `auto-scripts` = `cache:clear` + `assets:install %PUBLIC_DIR%` on
  `post-install-cmd`.
- `lessons.md`: terminal in Polish, **commit messages in English**.

## Desired End State

- `docker build -f php/Dockerfile --target prod` yields a self-contained image (app +
  `--no-dev` vendor baked in) that, given `PORT` / `APP_ENV=prod` / `APP_SECRET` /
  `DATABASE_URL`, serves `/login` with HTTP 200 on `$PORT`.
- `docker compose build` / `up` / `run-dev.sh` behave exactly as today (they build the
  `dev` stage; bind mount + in-container `composer install` unchanged).
- `railway.json` drives Railway: Dockerfile build of the `prod` stage, migrations as a
  pre-deploy step, `/login` health check, single replica, `railway-start.sh` as the
  start command.
- Pushing to `main` runs `CI` (phpstan, php-cs-fixer, phpunit, Playwright E2E); on green
  `Deploy` runs `railway up` for the exact commit that passed. `Deploy` also runs on
  `workflow_dispatch` for on-demand redeploys. A red `CI` produces no deploy.

### Key Discoveries

- Multi-stage with a shared `base` stage keeps dev/prod on one guaranteed-identical
  foundation (PHP version, extensions, vhost) — no second Dockerfile to drift.
- Railway's Dockerfile builder builds the **final stage** → `prod` must be defined last;
  `docker compose` selects the earlier `dev` stage via `target: dev`.
- No `ENTRYPOINT`: an entrypoint runs under local `docker compose` too (the `php`
  service sets no `APP_ENV` shell var, so a `${APP_ENV:-prod}` guard misfires, `chown`s
  the bind-mounted host `var/`, and crashes a vendor-less container). The Railway start
  logic is reachable only via `railway.json` `startCommand`.
- CI cannot one-shot `docker compose up --wait` with `php-e2e` in the list: `php-e2e`'s
  `/login` healthcheck can't pass before `php`'s `composer install` populates the shared
  bind-mounted `vendor/`. Bring up `database database-test php` first, install, migrate
  test DB, then `up --wait php-e2e playwright`.
- Railway `preDeployCommand` runs in a throwaway container — filesystem changes (warmed
  `var/cache`) never reach the runtime container. Migrations (DB state) belong there;
  cache warmup belongs in `railway-start.sh`.

## What We're NOT Doing

- Executing the Railway/GitHub runbook (owner-only: account, billing, credentials,
  disabling Railway's native auto-deploy). Documented in `deploy-plan.md`.
- Any new migration, entity, or application-code change.
- Touching `run-dev.sh`.
- Multi-replica / sticky sessions / HA Postgres / DR.
- New tests or visual/pixel E2E.
- GitHub branch-protection / required-status-check config (repo setting — in runbook).
- `composer dump-env prod` / `.env.local.php` (chose a plain tracked `.env.prod`).
- **Prod PHP ini tuning** (`opcache.validate_timestamps=0`, `opcache.memory_consumption`,
  `memory_limit`, realpath cache). F-02's goal is a *working* pipeline; ini tuning is a
  deliberate fast-follow once the deploy path is green.
- **GHA Docker layer caching** for `ci.yml`. Accepted: each CI run does a full
  `docker compose build` (apt + `pecl apcu` compile) + dev `composer install` + Playwright
  `npm ci`, ~8–12 min/run. A `buildx` + `cache-from/to` step is an optional fast-follow
  (already noted in `deploy-plan.md`).
- **Deploy-failure signal in GitHub Actions**. `deploy.yml` keeps `railway up --detach`,
  so the `Deploy` job goes green once the build context uploads — not when the Railway
  build, the `preDeployCommand` migrations, or the `/login` health check pass. Accepted
  for the MVP: first deploys are watched in the Railway dashboard and gated by the manual
  `curl https://<domain>/login` check (3.7). Dropping `--detach` (so the job fails on a
  failed build/deploy) or adding a `curl --retry` health-gate step is a fast-follow.

## Implementation Approach

Three phases, each independently verifiable without GitHub or Railway access for the
automated criteria. Phase 1 makes the image real (incl. `railway-start.sh`) and keeps
local intact; Phase 2 adds the one Railway control-plane file (`railway.json`); Phase 3
adds CI + the gated deploy. Implement on branch `feat/deploy-pipeline-live`; one
Conventional-Commits commit (English) per phase.

## Critical Implementation Details

- **`target: dev` + Railway**: `prod` is the last stage; do not append a stage after it.
- **php-cs-fixer on PHP 8.5**: the tool may hard-error on an "unsupported" PHP. If the
  `php-cs-fixer` CI step fails for that reason, set `PHP_CS_FIXER_IGNORE_ENV: 1` on that
  step (do not add it pre-emptively).
- **`.env` stays in the image**: Symfony's runtime expects `.env`; `.env.local*`,
  `.env.test`, `.env.e2e` are `.dockerignore`d. `APP_SECRET` and `DATABASE_URL` are
  Railway variables, never tracked.
- **`APP_ENV=prod` on Railway is load-bearing**: `.env` sets `APP_ENV=dev` and wins env
  selection; `.env.prod` only loads once `APP_ENV` is already `prod` (verified against
  `vendor/symfony/dotenv/Dotenv.php`). The prod env therefore comes solely from the
  Railway service variable `APP_ENV=prod` (runbook step 4) — it is not optional. `.env.prod`
  is a Symfony-convention placeholder for future non-secret prod config, not a fallback.
- **`railway-start.sh` isolation**: `COPY`'d only into `prod`; invoked only by
  `railway.json` `startCommand` (a Railway concept, not the image `CMD`). `docker run
  diaguide:prod` alone runs `apache2-foreground` with no warmup/port-rewrite — the
  script is exercised only when run explicitly. `docker compose` never references it.

---

## Phase 1: Production image + start script

### Overview

Multi-stage `php/Dockerfile` (`base` → `dev` → `prod`), `target: dev` pin on the two
compose services, `.dockerignore`, `php/railway-start.sh` (baked into the `prod` stage),
tracked `.env.prod`, and the Doctrine `server_version` pin. Local build stays on the
`dev` stage — same speed, same runtime. Everything that goes *into the image* lands in
this phase so the `--target prod` build is verifiable at phase close; Phase 2 is only
the Railway control-plane file.

### Changes Required

#### 1. `php/Dockerfile` — split into three stages

**File**: `php/Dockerfile`

**Intent**: Keep every current instruction in a `base` stage; add an empty `dev` stage
(what compose builds) and a `prod` stage that bakes the app + `--no-dev` vendor and the
Railway start script. No `ENTRYPOINT`.

**Contract**: three `FROM ... AS <name>` stages; `base` holds today's content incl.
`CMD ["apache2-foreground"]`; `prod` is the final stage.

```dockerfile
FROM php:8.5-apache AS base
# ... unchanged: apt libs, docker-php-ext-install intl pdo_pgsql gd zip, pecl apcu,
#     composer binary, a2enmod rewrite, inline <VirtualHost *:80>, WORKDIR, EXPOSE 80,
#     CMD ["apache2-foreground"]

FROM base AS dev

FROM base AS prod
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && mkdir -p var && chown -R www-data:www-data var
COPY php/railway-start.sh /usr/local/bin/railway-start.sh
RUN chmod +x /usr/local/bin/railway-start.sh
```

#### 2. `docker-compose.yml` — pin the dev stage

**File**: `docker-compose.yml`

**Intent**: Make both PHP services build the `dev` stage so the local image is
unchanged.

**Contract**: add `target: dev` to the existing `build:` mapping of the `php` and
`php-e2e` services. No other keys touched.

#### 3. `.dockerignore` (new)

**File**: `.dockerignore` (repo root)

**Intent**: Shrink the build context and keep host state / dev-only tooling / every
non-prod `.env*` out of the image. `.env` itself must stay (Symfony's runtime requires
it) and unavoidably carries the local `DOCKER_POSTGRES_*` dev values — those grant
nothing off the compose network, and Railway's `DATABASE_URL` / `APP_SECRET` variables
shadow them at runtime.

**Contract**: excludes `.git`, `.github`, `.idea`, `.vscode`, `.claude`, `context`,
`node_modules`, `vendor`, `var`, `postgres_data`, `postgres_test_data`, `.env.local`,
`.env.*.local`, `.env.dev`, `.env.dist`, `.env.test`, `.env.e2e`, `tests`,
`playwright.config.ts`, `package*.json`,
`phpunit.dist.xml`, `phpstan.neon`, `.php-cs-fixer.dist.php`, `.phpunit.cache`,
`.php-cs-fixer.cache`, `playwright-report`, `test-results`, `run-dev.sh`, `*.md`.
**Must not** list `composer.json`, `composer.lock`, `symfony.lock`, `bin`, `config`,
`migrations`, `public`, `src`, `templates`, `.env`, `.env.prod`.

#### 4. `.env.prod` (new, tracked, no secrets)

**File**: `.env.prod` (repo root)

**Intent**: Symfony-convention placeholder for future *non-secret* prod config (mailer
DSN scheme, trusted proxies, etc.). **Not** a fallback for a missing `APP_ENV` — `.env`'s
`APP_ENV=dev` wins env selection, so the Railway `APP_ENV=prod` variable (runbook step 4)
is what actually puts the app in prod. See Critical Implementation Details.

**Contract**: a header comment noting the above, plus a single `APP_ENV=prod` line
(redundant but self-documenting; matches the stock skeleton). No `APP_DEBUG` (Dotenv
auto-derives `0` for `APP_ENV=prod`). No `APP_SECRET` / `DATABASE_URL` (CLAUDE.md: no
secrets in tracked `.env*`).

#### 5. `config/packages/doctrine.yaml` — pin the PG server version

**File**: `config/packages/doctrine.yaml`

**Intent**: Avoid a version-probe query on boot and let the Railway `DATABASE_URL` be a
bare plugin reference.

**Contract**: replace the commented `#server_version: '16'` under `dbal:` with
`server_version: '18'`.

#### 6. `php/railway-start.sh` (new) — Railway-only start command

**File**: `php/railway-start.sh`

**Intent**: On Railway, bind Apache to `$PORT`, warm the prod cache with real runtime
env vars present, fix `var/` ownership, then hand off to Apache. Created here because it
is `COPY`'d into the `prod` stage (#1); it is wired only via `railway.json` `startCommand`
(Phase 2) and `docker compose` never invokes it.

**Contract**: POSIX `sh`, LF endings, executable bit set. Steps: default `PORT` to 80;
`sed` `$PORT` into `/etc/apache2/ports.conf` (`Listen 80`) and the `<VirtualHost *:80>`
block; `php bin/console assets:install public --no-interaction`; `php bin/console
cache:clear --no-interaction`; `chown -R www-data:www-data var`; `exec apache2-foreground`.
No `APP_ENV` guard (only ever runs in the `prod` image on Railway).

### Success Criteria

#### Automated Verification

- `docker compose config` succeeds (the `target` key is accepted).
- `docker compose build php` succeeds and its log contains no `composer install` line
  (build stopped at `dev`).
- `sh -n php/railway-start.sh` passes; `test -x php/railway-start.sh` exits 0;
  `file php/railway-start.sh` reports no CRLF.
- `docker build -f php/Dockerfile --target prod -t diaguide:prod .` succeeds.
- `docker run --rm diaguide:prod test -f vendor/autoload_runtime.php` exits 0.
- On `diaguide:prod`, running `railway-start.sh`'s port-rewrite with `PORT=8080` yields
  `Listen 8080` in `ports.conf` and `<VirtualHost *:8080>` in `000-default.conf`.
- `docker compose up -d --wait database database-test php` → services healthy;
  `docker compose exec -T php composer install` then `docker compose exec -T php vendor/bin/phpunit` is green.
- `docker compose exec -T php bin/console lint:container` passes.
- `grep -R railway-start docker-compose.yml` → no match; `git diff --stat docker-compose.yml`
  shows only additions (the two `target: dev` lines).

#### Manual Verification

- `./run-dev.sh` completes end to end (user runs it — it is destructive).
- `http://localhost:8381/login` renders the login form; logging in as a seeded user works.
- `http://localhost:8382/login` (php-e2e) returns 200.
- Simulated prod boot against the running local test DB. Attach the container to the
  compose network and reach Postgres by its service name — `host.docker.internal` is not
  populated inside a container on Docker Engine for Linux. Confirm the network name with
  `docker network ls | grep default` (default project name → `symfony_default`):
  `docker run --rm --network symfony_default -e APP_ENV=prod -e APP_SECRET=$(openssl rand -hex 32)
  -e DATABASE_URL='postgresql://app_test:!ChangeMe!@database-test:5432/app_test?serverVersion=18&charset=utf8'
  -e PORT=8080 -p 8080:8080 --entrypoint sh diaguide:prod /usr/local/bin/railway-start.sh`
  → `curl -fsS localhost:8080/login` returns 200 + form.

**Implementation Note**: After Phase 1 automated checks pass, pause for the user to
confirm manual verification before Phase 2.

---

## Phase 2: Railway config (`railway.json`)

### Overview

The Railway control-plane file only — the image and start script already exist from
Phase 1. `railway.json` is not read by `docker compose`, so this phase has zero local
runtime effect.

### Changes Required

#### 1. `railway.json` (new)

**File**: `railway.json` (repo root)

**Intent**: Config-as-code for the Railway service — Dockerfile build, migrations as a
pre-deploy step, `/login` health check, single replica, custom start command.

**Contract**: valid JSON. `deploy.startCommand` path must equal the Dockerfile `COPY`
target from Phase 1 (`/usr/local/bin/railway-start.sh`).

```json
{
  "$schema": "https://railway.com/railway.schema.json",
  "build": { "builder": "DOCKERFILE", "dockerfilePath": "php/Dockerfile" },
  "deploy": {
    "startCommand": "sh /usr/local/bin/railway-start.sh",
    "preDeployCommand": "php bin/console doctrine:migrations:migrate --no-interaction",
    "healthcheckPath": "/login",
    "healthcheckTimeout": 120,
    "numReplicas": 1,
    "restartPolicyType": "ON_FAILURE"
  }
}
```

### Success Criteria

#### Automated Verification

- `jq -e . railway.json` succeeds.
- `railway.json` `deploy.startCommand` references `/usr/local/bin/railway-start.sh` and
  `build.dockerfilePath` is `php/Dockerfile` (grep/`jq` assertions).
- `git diff` for this phase touches `railway.json` only.

#### Manual Verification

- Deferred to first deploy (runbook): Railway build log says "Using Dockerfile", not
  Railpack; `preDeployCommand` applies the 6 migrations; the `/login` health check
  passes and the service goes live.

**Implementation Note**: Pause for user confirmation before Phase 3.

---

## Phase 3: CI/CD

### Overview

`.github/workflows/ci.yml` (tests via `docker compose` parity) and
`.github/workflows/deploy.yml` (auto-deploy gated on green `CI` via `workflow_run`, plus
manual `workflow_dispatch`).

### Changes Required

#### 1. `.github/workflows/ci.yml` (new)

**File**: `.github/workflows/ci.yml`

**Intent**: Run the full quality gate on every PR and every push to `main`, using the
same `docker compose` stack as local dev.

**Contract**: `name: CI`; triggers `pull_request` and `push` to `main`; one job
`quality-gate` on `ubuntu-latest`. Steps in order: checkout → `docker compose up -d
--build --wait database database-test php` → `composer install` → `vendor/bin/phpstan
analyse` → `vendor/bin/php-cs-fixer fix --dry-run --diff` → test-DB
`doctrine:database:create --env=test --if-not-exists` + `doctrine:migrations:migrate
--env=test --no-interaction` → `vendor/bin/phpunit` → `docker compose up -d --wait
php-e2e playwright` → `docker compose exec -T playwright npm ci` → `... npx playwright
test` → `docker compose down -v --remove-orphans` under `if: always()`. All `exec` use
`-T`. No secrets referenced.

#### 2. `.github/workflows/deploy.yml` (new)

**File**: `.github/workflows/deploy.yml`

**Intent**: Deploy to Railway only after `CI` is green on `main`, and on manual demand,
deploying exactly the commit that passed CI.

**Contract**: `name: Deploy`; triggers
`workflow_run: { workflows: [CI], types: [completed], branches: [main] }` and
`workflow_dispatch`. Job `deploy` guard:
`if: github.event_name == 'workflow_dispatch' || github.event.workflow_run.conclusion == 'success'`.
Steps: checkout with `ref: ${{ github.event.workflow_run.head_sha || github.ref }}` →
`npm i -g @railway/cli` → `railway up --service "$RAILWAY_SERVICE" --environment
production --detach` with `RAILWAY_TOKEN` from `secrets` and `RAILWAY_SERVICE` from
`vars`.

### Success Criteria

#### Automated Verification

- `actionlint` (e.g. `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest
  -color`) reports no errors for either file.
- Both files parse as YAML.
- `grep -n -i railway .github/workflows/ci.yml` → no matches.
- `grep -n 'workflow_run' .github/workflows/deploy.yml` and the `conclusion == 'success'`
  guard are present.

#### Manual Verification

- Push `feat/deploy-pipeline-live`, open a PR → `CI` runs and passes.
- On a scratch branch with a deliberately failing test → `CI` is red; confirm `Deploy`
  does not run.
- After merge to `main` (with `RAILWAY_TOKEN` secret + `RAILWAY_SERVICE` var set) →
  `Deploy` triggers via `workflow_run`; `railway up` log says "Using Dockerfile"; the
  pre-deploy log shows all 6 migrations applied; `curl https://<domain>/login` → 200.
- `gh workflow run deploy.yml` on `main` redeploys with no new commit.

**Implementation Note**: The auto-deploy and red-CI checks need `main` + Railway
secrets, so they are exercised during/after the runbook, not inside `/10x-implement`.

---

## Manual runbook (owner — not part of `/10x-implement`)

Full text in `context/deployment/deploy-plan.md` "Manual runbook". Summary:

1. `railway login`; create an **Empty Project** (`railway init` from repo root).
2. `railway link`; confirm the build log says "Using Dockerfile".
3. `railway add` → PostgreSQL **18**.
4. `production` env vars (never committed): **`APP_ENV=prod` (load-bearing — `.env` sets
   `dev` and `.env.prod` only loads once `APP_ENV` is already `prod`)**, `APP_SECRET`
   (`openssl rand -hex 32`), `DATABASE_URL = ${{Postgres.DATABASE_URL}}` (the
   `server_version` is now in `doctrine.yaml`, so no `?serverVersion=` suffix needed).
5. Do **not** connect a GitHub source (or connect it and disable "Deploy on Push").
6. Project-scoped token → `gh secret set RAILWAY_TOKEN`; `gh variable set RAILWAY_SERVICE
   --body "<service>"`.
7. Set a Railway **Usage Alert** (Hobby plan: $5/mo + $5 included usage).
8. First deploy: merge to `main` (auto after green CI) or `gh workflow run deploy.yml`.

## Testing Strategy

### Automated (per phase, above)
- Phase 1: compose config/build (stops at `dev`), `railway-start.sh` `sh -n`/exec-bit/
  no-CRLF, `--target prod` build + `autoload_runtime.php` check, `sed` port-rewrite on
  the prod image, phpunit in container, `lint:container`, compose has no `railway-start`.
- Phase 2: `jq` + `startCommand`/`dockerfilePath` assertions on `railway.json`.
- Phase 3: `actionlint`, YAML parse, CI-has-no-Railway grep.

### Manual
1. `./run-dev.sh` full run → dev + e2e stacks healthy, `/login` 200 on 8381 and 8382.
2. Simulated prod container (`railway-start.sh`, `PORT=8080`, real test DB) → `/login` 200
   (Phase 1).
3. PR → `CI` green; red test → `Deploy` skipped; merge → `Deploy` via `workflow_run`;
   `workflow_dispatch` redeploy (Phase 3).

## Migration Notes

First Railway deploy runs 6 migrations building the whole schema via `preDeployCommand`
(before traffic). Keep a Postgres backup/restore path ready for the first prod deploy.
Local dev/test DBs are unaffected (same migrations already applied by `run-dev.sh`).

## References

- Design + runbook: `context/deployment/deploy-plan.md`
- Change identity: `context/changes/deploy-pipeline-live/change.md`
- `php/Dockerfile`, `docker-compose.yml` (`php` + `php-e2e`), `run-dev.sh`
- `config/packages/doctrine.yaml` (`server_version`), `config/packages/framework.yaml`
  (`APP_SECRET`)
- `/login`: `src/Controller/SecurityController.php:11` (public), `config/packages/security.yaml`
- Convention: `lessons.md` (commits in English), `AGENTS.md` (php-cs-fixer `@Symfony`,
  phpstan level 5)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands.
> Do not rename step titles.

### Phase 1: Production image + start script

#### Automated

- [x] 1.1 `docker compose config` succeeds — 6be0537
- [x] 1.2 `docker compose build php` succeeds with no `composer install` in the log — 6be0537
- [x] 1.3 `sh -n php/railway-start.sh` passes, `test -x` exits 0, no CRLF — 6be0537
- [x] 1.4 `docker build --target prod -t diaguide:prod .` succeeds — 6be0537
- [x] 1.5 `docker run --rm diaguide:prod test -f vendor/autoload_runtime.php` exits 0 — 6be0537
- [x] 1.6 `railway-start.sh` port-rewrite with `PORT=8080` on `diaguide:prod` yields `Listen 8080` + `<VirtualHost *:8080>` — 6be0537
- [x] 1.7 `docker compose up -d --wait database database-test php` healthy; in-container `composer install` + `vendor/bin/phpunit` green — 6be0537
- [x] 1.8 `bin/console lint:container` passes in the `php` container — 6be0537
- [x] 1.9 `grep -R railway-start docker-compose.yml` empty; `git diff --stat docker-compose.yml` shows only the two `target: dev` additions — 6be0537

#### Manual

- [x] 1.10 `./run-dev.sh` completes end to end — 6be0537
- [x] 1.11 `http://localhost:8381/login` renders + login as a seeded user works — 6be0537
- [x] 1.12 `http://localhost:8382/login` returns 200 — 6be0537
- [x] 1.13 Simulated prod boot (`railway-start.sh`, `PORT=8080`, real test DB) → `curl localhost:8080/login` 200 + form — 6be0537

### Phase 2: Railway config (`railway.json`)

#### Automated

- [x] 2.1 `jq -e . railway.json` succeeds — 4c1e02c
- [x] 2.2 `railway.json` `startCommand` = `/usr/local/bin/railway-start.sh`, `build.dockerfilePath` = `php/Dockerfile` — 4c1e02c
- [x] 2.3 `git diff` for this phase touches `railway.json` only — 4c1e02c

#### Manual

- [ ] 2.4 First deploy (runbook): "Using Dockerfile", 6 migrations in pre-deploy log, `/login` health check passes

### Phase 3: CI/CD

#### Automated

- [x] 3.1 `actionlint` clean for `ci.yml` and `deploy.yml` — b1c824f
- [x] 3.2 Both workflow files parse as YAML — b1c824f
- [x] 3.3 `grep -i railway .github/workflows/ci.yml` → no matches — b1c824f
- [x] 3.4 `deploy.yml` has the `workflow_run` trigger and the `conclusion == 'success'` guard — b1c824f

#### Manual

- [x] 3.5 PR from `feat/deploy-pipeline-live` → `CI` runs and passes — 0e4d65d
- [ ] 3.6 Scratch branch with a failing test → `CI` red → `Deploy` does not run
- [ ] 3.7 Merge to `main` → `Deploy` via `workflow_run`; "Using Dockerfile"; 6 migrations in pre-deploy log; `curl https://<domain>/login` 200
- [ ] 3.8 `gh workflow run deploy.yml` on `main` redeploys with no new commit
