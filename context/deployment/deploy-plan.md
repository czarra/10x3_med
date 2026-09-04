---
project: dia-guide
status: proposed
based_on:
  - context/foundation/infrastructure.md
  - context/foundation/tech-stack.md
platform: Railway
---

# First deployment (Railway) — plan

## Context

`context/foundation/infrastructure.md` (research) and `context/foundation/tech-stack.md`
(contract) already lock the decision: deploy DiaGuide on **Railway**, building the
existing `php/Dockerfile` directly, with GitHub Actions as CI provider and
`auto-deploy-on-merge` as the flow. This document evaluates that research as an actual
deployment plan and fixes what's missing.

`infrastructure.md` explicitly scoped out "Docker image configuration" and "CI/CD
pipeline content" (see its Out of Scope section) — but those are exactly what's needed
to make a first deploy actually work. Auditing the current repo against what Railway
needs turned up several gaps that would make the very first deploy fail or behave
unsafely:

1. **`php/Dockerfile` never copies the app into the image or installs vendor/.** It
   only installs system packages + PHP extensions + Composer binary. Locally this is
   invisible because `docker-compose.yml` bind-mounts `.:/var/www/med` over it. On
   Railway there is no bind mount — the built image would boot Apache against an empty
   `/var/www/med`.
2. **Apache is hardcoded to port 80**, but Railway injects a dynamic `PORT` env var and
   routes to whatever port the container actually listens on. A Dockerfile deploy that
   ignores `PORT` is a well-known cause of "Application failed to respond" on Railway.
3. **No `.dockerignore`** — a naive `COPY . .` would ship `.git/`, `var/`, `postgres_data/`,
   `.idea/`, host `vendor/`, etc. into the image.
4. **No `railway.json`** — the pre-deploy migration hook and healthcheck path
   (`infrastructure.md` step 5) have nowhere to live as reviewable, version-controlled
   config; today they'd only exist as unaudited dashboard clicks.
5. **No `.github/workflows/`** at all, despite `tech-stack.md` recording
   `ci_provider: github-actions` / `ci_default_flow: auto-deploy-on-merge`.
6. **`APP_SECRET`** is required by `config/packages/framework.yaml` but isn't in any
   tracked `.env*` file (correctly — it must be set only as a Railway variable, never
   committed, per this repo's own `CLAUDE.md` rule).
7. Baking `composer install`'s Symfony Flex auto-scripts (`cache:clear`) into the
   Docker **build** stage would require `DATABASE_URL` to already resolve at build
   time — fragile on Railway, where build-time and deploy-time variable availability
   isn't guaranteed to line up on a service's very first build.

The user was asked how CI should relate to the Railway deploy trigger. They chose: CI
must pass on `main` after merge, and only then are changes pushed live — plus they want
the ability to manually redeploy from `main` on demand. That rules out relying on
Railway's own "auto-deploy on every GitHub push" trigger (which is not gated by test
results), so the deploy is driven explicitly from GitHub Actions via the Railway CLI,
and Railway's native auto-deploy-from-GitHub trigger must be left **off** to avoid a
double-deploy race between Railway's own trigger and the Actions-driven one.

This is a deliberate deviation from the literal wording of the two `based_on` docs, and
they should be read as superseded on these points: `infrastructure.md`'s "Out of Scope"
excludes Docker-image config and CI-pipeline content (both delivered here); its "Getting
Started" step 6 and `tech-stack.md`'s `ci_default_flow: auto-deploy-on-merge` describe
Railway's **native** GitHub trigger, whereas here "auto-deploy-on-merge" means "auto,
after the `CI` workflow passes on `main`" (`deploy.yml` keyed off `workflow_run`),
driven by GitHub Actions with Railway's own push trigger disabled.

## Scope split

Two different kinds of work, deliberately kept separate:

- **Repo-side changes:** multi-stage `php/Dockerfile` (+ `target: dev` on the two
  compose services), a Railway-only start script, `.dockerignore`, `railway.json`, and
  two GitHub Actions workflows (`ci.yml` tests, `deploy.yml` release). All reversible,
  local, no external accounts touched. *(Designed here; not yet created — see
  "Implementation status" below.)*
- **Manual one-time Railway/GitHub setup (project owner runs themselves, documented as
  a runbook):** `railway login` (interactive OAuth — can't be done by an agent),
  `railway init`/`link`, provisioning the Postgres plugin, setting Railway variables
  (`APP_ENV`, `APP_SECRET`, `DATABASE_URL`), generating a Railway project token, adding
  it as a GitHub Actions secret, turning off Railway's GitHub auto-deploy toggle, and
  triggering the first deploy. These are external-account, billing-adjacent, and
  credential-handling actions — out of scope to execute automatically; the runbook
  below documents the exact commands/steps to run.

## Deliverables

### 1. `php/Dockerfile` — multi-stage: shared `base`, `dev` = today, `prod` for Railway

Split the single Dockerfile into three stages so dev and prod share one guaranteed-
identical base (PHP version, extensions, Apache/vhost config) with no second file to
drift:

```dockerfile
FROM php:8.5-apache AS base
# everything the file does today: apt system libs, docker-php-ext-install
# (intl pdo_pgsql gd zip) + apcu, Composer binary, a2enmod rewrite, the
# inline <VirtualHost *:80> config, WORKDIR /var/www/med, EXPOSE 80.

FROM base AS dev
# nothing added — this is what docker-compose builds. Inherits
# CMD ["apache2-foreground"] from the base image.

FROM base AS prod
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && mkdir -p var && chown -R www-data:www-data var
COPY php/railway-start.sh /usr/local/bin/railway-start.sh
RUN chmod +x /usr/local/bin/railway-start.sh
```

- `--no-scripts` / no `php bin/console` at build time: a build-time kernel boot would
  run in `APP_ENV=dev` (from `.env`) against a `--no-dev` vendor dir and fail on a
  missing dev-only bundle class. `assets:install` + cache warmup happen at container
  start via the start script (see #2).
- **No `ENTRYPOINT`** anywhere — the `prod` stage keeps the inherited
  `CMD ["apache2-foreground"]`; Railway overrides it via `startCommand` (#4). The `dev`
  stage has no start/port script at all, so local `docker compose` cannot touch it.
- `docker-compose.yml` change (small, required by the split): add `target: dev` to the
  `build:` block of both the `php` and `php-e2e` services. Local build then stops at
  the `dev` stage — **byte-for-byte today's image, same build speed**. Bind mount
  (`.:/var/www/med`) and `run-dev.sh`'s own `composer install` are unchanged.
- Railway builds the **last stage (`prod`)** — `railway.json` points at the same
  `php/Dockerfile` (#4).

### 2. `php/railway-start.sh` (new) — Railway-only start command

A POSIX script `COPY`'d **only into the `prod` stage** and run **only via `railway.json`
`deploy.startCommand`** (see #4). It is not present in the `dev` image and `docker
compose` never invokes it — local containers keep using `CMD ["apache2-foreground"]` —
so local runtime is byte-for-byte unchanged. On Railway it:
- `sed`s `$PORT` into `/etc/apache2/ports.conf` (`Listen 80`) and the `<VirtualHost
  *:80>` block from the Dockerfile (Railway routes to the port the container listens on).
- Runs `php bin/console assets:install public --no-interaction` and
  `php bin/console cache:clear --no-interaction`, then `chown -R www-data:www-data var`
  — warmup belongs here, not in `preDeployCommand`, because Railway's pre-deploy runs
  in a throwaway container whose filesystem never reaches the runtime container.
- `exec apache2-foreground`.

### 3. `.dockerignore` (new, repo root)

Exclude `.git`, `.github`, `var/`, `vendor/`, `node_modules/`, `postgres_data/`,
`postgres_test_data/`, `.idea/`, `.claude/`, `context/`, `.env.local`, `.env.*.local`,
`tests/`, `.phpunit.cache/`, `.php-cs-fixer.cache`, `playwright-report/`,
`test-results/` — keep the build context small and avoid leaking host state/secrets
into the image. Do **not** exclude `composer.json`/`composer.lock`/`symfony.lock`,
`migrations/` (pre-deploy `migrate` needs them), or `.env` (tracked, non-secret; the
real `APP_ENV=prod` Railway var overrides its `APP_ENV=dev`). Excluding `tests/` is
safe — `tests/Support/E2e/` is wired only via `when@e2e`, never loaded under `prod`.

### 4. `railway.json` (new, repo root) — config as code

- `build.builder: "DOCKERFILE"`, `build.dockerfilePath: "php/Dockerfile"`. Railway
  builds the Dockerfile's **final stage**, which is `prod` (#1) — no target flag needed.
- `deploy.startCommand: "sh /usr/local/bin/railway-start.sh"` — the Railway-only start
  path (see #2). This is why the Dockerfile needs no `ENTRYPOINT`.
- `deploy.preDeployCommand`: **migrations only** —
  `php bin/console doctrine:migrations:migrate --no-interaction` — runs with real
  runtime env vars against the real DB before traffic is routed (pre-deploy hook, not
  container start command; addresses `infrastructure.md`'s migration-ordering risk).
  Cache warmup is **not** here — see #2 (pre-deploy filesystem changes don't reach the
  runtime container).
- `deploy.healthcheckTimeout: 120` and `deploy.restartPolicyType: "ON_FAILURE"`.
- `deploy.healthcheckPath: "/login"` (**[Updated 2026-08-25]** the original
  `/api/status` endpoint this pointed at was removed as unused/dead code —
  `src/Controller/Api/StatusController.php` no longer exists. `/login` is a
  simple, always-public, unauthenticated route that proves the app process is
  up and serving requests. DB connectivity is already gated separately by the
  `preDeployCommand` migration step above, which runs against the DB before
  traffic is routed and fails loudly if it's unreachable — so the health check
  itself doesn't need to re-verify DB connectivity).
- `deploy.numReplicas: 1` (explicit, since >1 replica has no sticky-session story yet
  per `infrastructure.md`'s risk register).

### 5. `.github/workflows/ci.yml` + `.github/workflows/deploy.yml` (new — two files)

**`ci.yml`** — `name: CI`. Triggers on `pull_request` and `push` to `main`. One job
`quality-gate` via **`docker compose` parity** (not `shivammathur/setup-php`):
`docker compose up -d --build --wait database database-test php` (not `--wait` on
`php-e2e`/`playwright` yet — they have no vendor until the next step), then inside the
`php` container, in order: `composer install`, `vendor/bin/phpstan analyse`,
`vendor/bin/php-cs-fixer fix --dry-run --diff`, test-DB create + `migrate --env=test`,
`vendor/bin/phpunit`. Then bring up `php-e2e` + `playwright` (`docker compose up -d
--wait php-e2e playwright`) and run — **sequentially, after PHPUnit** (they share the
`database-test` Postgres; never concurrent) — `docker compose exec -T playwright npm ci`
then `docker compose exec -T playwright npx playwright test`. Cleanup with
`docker compose down -v --remove-orphans` in an `if: always()` step. `docker compose`
reads the tracked root `.env` for `${DOCKER_POSTGRES_*}` substitution — no extra CI
secrets needed. `ci.yml` never deploys and needs no Railway secret.

**`deploy.yml`** — `name: Deploy`. Two triggers:
- `workflow_run: { workflows: ["CI"], types: [completed], branches: [main] }` — the
  **automatic** path. The `deploy` job runs
  `if: github.event.workflow_run.conclusion == 'success'`, so a red PHPUnit/E2E/phpstan
  run on `main` produces no deploy.
- `workflow_dispatch` — the **manual** path (Actions UI or `gh workflow run
  deploy.yml`), for redeploying `main` on demand without a new commit. Runs
  unconditionally (operator's call).

The `deploy` job checks out the exact commit that passed CI
(`ref: ${{ github.event.workflow_run.head_sha || github.ref }}`), installs the Railway
CLI (`npm i -g @railway/cli`), authenticates with the `RAILWAY_TOKEN` secret, and runs
`railway up --service "$RAILWAY_SERVICE" --environment production --detach`
(`RAILWAY_SERVICE` as a repo variable, recorded in runbook step 6).

## Manual runbook (one-time setup)

Run from the repo root, after the repo-side changes above are merged.

**Project vs. service:** GitHub Actions CI and the Railway deploy are independent.
`quality-gate` runs on GitHub's runners (`docker compose` + phpstan/php-cs-fixer/
phpunit/playwright) — Railway is not involved. Railway only ever builds what the
`deploy` job sends it via `railway up`, and it builds `php/Dockerfile` (per
`railway.json`) — never a registry image. So the app **service comes from `railway up`**,
not from a dashboard "GitHub Repo" connection; that connection's own trigger ignores
the CI result and would race the Actions-driven deploy, so leave Railway's GitHub
source disconnected.

1. `railway login`. Create the project as an **"Empty Project"** (this is what
   `railway init` makes). Do **not** pick a PHP/Laravel language template (own
   Dockerfile) or "Deploy from Docker Image" (building the repo Dockerfile, not
   pulling an image).
2. `railway init` (or `railway link` if a Railway project already exists) from repo
   root; confirm it detects `php/Dockerfile` via `railway.json`, not Railpack — check
   the build log says "Using Dockerfile".
3. `railway add` → provision the PostgreSQL plugin, version 18 (matches
   `docker-compose.yml`).
4. Set required variables on the `production` environment via `railway variables set`
   (never commit these):
   - `APP_ENV=prod`
   - `APP_SECRET` — generate with `openssl rand -hex 32`
   - `DATABASE_URL` — reference the Postgres plugin (`${{Postgres.DATABASE_URL}}`) and
     **append `?serverVersion=18&charset=utf8`** (Railway's URL has no query string;
     Doctrine otherwise probes the server version on boot). Alternative: uncomment
     `server_version: '18'` in `config/packages/doctrine.yaml`.
5. Do **not** connect a GitHub source for this service — deploys come only from
   `railway up` in `deploy.yml`. If you connect GitHub for dashboard visibility, open
   the service → Settings and **turn off "Deploy on Push"** immediately (a deliberate
   deviation from `infrastructure.md`'s "connect GitHub for auto-deploy" — with
   CI-gated deploys, Railway's own push trigger would race the Actions-driven one).
6. Generate a **project-scoped** Railway token (Project Settings → Tokens), add it as
   the `RAILWAY_TOKEN` GitHub Actions secret (`gh secret set RAILWAY_TOKEN`), and
   record the target service name as a repo variable
   (`gh variable set RAILWAY_SERVICE --body "<service>"`) for `railway up --service`.
7. First deploy — two paths, both available afterwards:
   - **Automatic:** merge to `main` → `CI` runs → on green, `Deploy` fires via
     `workflow_run`.
   - **Manual:** `gh workflow run deploy.yml` (or the Actions UI) — redeploys the
     current `main` on demand, no new commit needed.
   Watch the `deploy` job's logs and/or `railway logs --service <service>`; confirm the
   pre-deploy log shows all 6 migrations applied and the `/login` health check passes.

## Risk register

Carried forward from `infrastructure.md` (still applicable), plus the new
deploy-specific ones this plan introduces:

| Risk | Source | Mitigation |
|---|---|---|
| Railpack silently falls back to single-threaded PHP if the Dockerfile path breaks | infrastructure.md | Keep `php/Dockerfile` as the explicit build source in `railway.json`; verify build logs after every deploy-config change |
| Resource-allocation billing causes quiet cost creep | infrastructure.md | Owner is on the paid **Hobby** plan ($5/mo + $5 included usage; the "$5 Trial credit expires in 30 days" note in `infrastructure.md` is now moot). Right-size the Postgres/app containers at launch; set a **Usage Alert** (Project Settings) on day one — a small app + small Postgres runs ~$5–10/mo |
| Rollback reverts the container image but not env var/secret state changed after that release | infrastructure.md | Track env var changes in a changelog; manually verify secrets after any rollback |
| Double deploy race (Railway native trigger + Actions-driven `railway up`) | This plan | Railway GitHub source left disconnected (or "Deploy on Push" disabled) per runbook step 5 |
| First deploy runs 6 real migrations that build the entire schema (`users`, `patient_profiles`, `diary_entries`, 2 history tables) — a genuine, failure-prone operation, not a no-op | This plan **[Updated 2026-09-02]** | Run via `preDeployCommand` (before traffic, not container start); verify the pre-deploy log shows all 6 applied; keep a Postgres backup/restore path before the first prod deploy |
| PHPUnit and Playwright E2E share the `database-test` Postgres — concurrent runs corrupt each other's fixtures | This plan | CI runs them as sequential steps in one job (E2E strictly after PHPUnit); never parallelize the two |
| `RAILWAY_TOKEN` scope too broad if an account token is used by mistake | This plan | Use a project-scoped token only; document rotation if leaked |
| Cache warmup in `preDeployCommand` is silently ineffective — Railway's pre-deploy runs in a throwaway container whose filesystem never reaches the runtime container | This plan **[Updated 2026-09-02]** | Warmup moved to `php/railway-start.sh` (run via `railway.json` `startCommand`, at container start, real env vars present); `preDeployCommand` keeps migrations only |
| An `ENTRYPOINT`-based `$PORT`/warmup script would also run under local `docker compose` and could `chown` the bind-mounted host `var/` or crash a vendor-less container | This plan **[Updated 2026-09-02]** | No `ENTRYPOINT`; multi-stage split — the `dev` stage (what compose builds via `target: dev`) contains no start/port script at all; it exists only in `prod`, run only via `railway.json` `startCommand` |
| `dev` and `prod` Docker environments drift (PHP version, extensions, vhost) | This plan | Single multi-stage `php/Dockerfile` with a shared `base` stage — not two files; any base change hits both |

## Verification

- `curl -fsS https://<railway-domain>/login` should return **HTTP 200** and the login
  form HTML. (The old `/api/status` JSON check is gone — `StatusController` was deleted
  in `153532b`. DB connectivity is proven by the pre-deploy `migrate` step, not the
  health check.)
- Check the `deploy` GitHub Actions job logs and `railway logs --service <service>` for
  the same deploy — build source should say "Using Dockerfile", not Railpack; the
  pre-deploy log should show all 6 migrations applied.
- Confirm a broken PR (failing `phpstan`/`phpunit`/E2E) fails `ci.yml` and — since the
  PR never merges — `deploy.yml` never runs.
- Confirm a red `CI` run on `main` leaves `deploy.yml`'s `deploy` job skipped
  (`workflow_run.conclusion != 'success'`), and a green one deploys the exact
  `head_sha` that passed.
- Confirm `workflow_dispatch` on `deploy.yml` redeploys `main` on demand without a new
  commit.
- Local sanity (bind-mount dev workflow must be unaffected): `docker compose build php`
  succeeds and stops at the `dev` stage (no `composer install` in its log — same speed
  as today); `docker compose up -d --wait` brings `php` (8381) and `php-e2e` (8382) to
  healthy and `curl -I localhost:8381/login` / `localhost:8382/login` → 200;
  `./run-dev.sh` completes end to end. The only `docker-compose.yml` change in
  `git diff` is `target: dev` on two services.
- Prod image check: `docker build -f php/Dockerfile --target prod -t diaguide:prod .`
  then `docker run --rm diaguide:prod sh -c 'test -f vendor/autoload_runtime.php && echo vendored'`;
  `docker run --rm -e PORT=8080 diaguide:prod sh -c 'sh /usr/local/bin/railway-start.sh true; grep -E "Listen|VirtualHost" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf'`
  shows `8080` substituted.
- `.github/workflows/deploy.yml` is valid YAML (`actionlint` or `gh workflow view`).

## Implementation status

**[Updated 2026-09-02]** This document has been re-validated against the current repo
(migrations exist, E2E suite exists, `/api/status` removed, `docker compose` CI parity,
Hobby plan) — see the dated notes throughout. The 5 repo-side deliverables are
**designed but not yet created**; `php/Dockerfile` is still the original. No live
Railway/GitHub-secret actions have been taken. Implementation + the manual runbook are
a separate, subsequent step, tracked on the `deploy-pipeline-live` change.

## Out of scope

- Production-scale architecture (multi-region, HA beyond Railway's single-click
  Postgres HA option, DR) — unchanged from `infrastructure.md`.
- Actually executing the manual runbook (external account/billing/credential actions —
  left to the project owner).
