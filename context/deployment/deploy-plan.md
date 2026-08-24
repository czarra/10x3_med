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

## Scope split

Two different kinds of work, deliberately kept separate:

- **Repo-side changes:** Dockerfile fix, entrypoint script, `.dockerignore`,
  `railway.json`, GitHub Actions workflow. All reversible, local, no external accounts
  touched. *(Not yet implemented as of this document — see "Implementation status"
  below.)*
- **Manual one-time Railway/GitHub setup (project owner runs themselves, documented as
  a runbook):** `railway login` (interactive OAuth — can't be done by an agent),
  `railway init`/`link`, provisioning the Postgres plugin, setting Railway variables
  (`APP_ENV`, `APP_SECRET`, `DATABASE_URL`), generating a Railway project token, adding
  it as a GitHub Actions secret, turning off Railway's GitHub auto-deploy toggle, and
  triggering the first deploy. These are external-account, billing-adjacent, and
  credential-handling actions — out of scope to execute automatically; the runbook
  below documents the exact commands/steps to run.

## Deliverables

### 1. `php/Dockerfile` — make it build a real production image

- Add `COPY . .` (after installing system deps/extensions) and
  `RUN composer install --no-dev --optimize-autoloader --no-scripts` (no-scripts: avoid
  coupling the build stage to `DATABASE_URL` resolvability — cache warmup happens
  later, at deploy time, when real runtime vars are guaranteed present).
- `RUN chown -R www-data:www-data var` so Symfony can write cache/logs at runtime.
- Add `COPY php/docker-entrypoint.sh /usr/local/bin/` + `RUN chmod +x ...`, and set
  `ENTRYPOINT ["docker-entrypoint.sh"]` (keep `CMD ["apache2-foreground"]`).
- Local dev is unaffected: `docker-compose.yml`'s bind mount (`.:/var/www/med`) still
  shadows the baked-in copy, and `run-dev.sh` still does its own `composer install`
  inside the running container.

### 2. `php/docker-entrypoint.sh` (new) — bind Apache to Railway's `$PORT`

Small POSIX script: default `PORT` to `80` if unset, `sed` it into
`/etc/apache2/ports.conf` and the `VirtualHost *:80` block generated in the Dockerfile,
then `exec "$@"`. No-op locally (Railway-only env var), so `docker-compose` behavior is
unchanged.

### 3. `.dockerignore` (new, repo root)

Exclude `.git`, `var/`, `vendor/`, `postgres_data/`, `postgres_test_data/`, `.idea/`,
`.env.local`, `tests/`, `.phpunit.cache/`, `context/`, `.claude/`, `node_modules` (if
ever added) — keep the build context small and avoid leaking host state/secrets into
the image.

### 4. `railway.json` (new, repo root) — config as code

- `build.builder: "DOCKERFILE"`, `build.dockerfilePath: "php/Dockerfile"`.
- `deploy.preDeployCommand`: chain cache warmup + migration, e.g.
  `php bin/console cache:clear --env=prod && php bin/console doctrine:migrations:migrate --no-interaction`
  — runs with real runtime env vars available, addressing gap #7 above and
  `infrastructure.md`'s own migration-ordering risk (pre-deploy hook, not container
  start command).
- `deploy.healthcheckPath: "/api/status"` (reuses the existing
  `src/Controller/Api/StatusController.php` endpoint — already checks DB connectivity).
- `deploy.numReplicas: 1` (explicit, since >1 replica has no sticky-session story yet
  per `infrastructure.md`'s risk register).

### 5. `.github/workflows/deploy.yml` (new)

Two jobs:

- **`quality-gate`** — triggers on `pull_request` and `push` to `main`. Uses
  `shivammathur/setup-php` for PHP 8.5 + required extensions (`pdo_pgsql`, `intl`,
  `zip`, `gd`, `apcu`) — faster and simpler than building the full Apache image just to
  run PHPUnit — plus a `postgres:18` service container. Runs, in order:
  `composer install`, `vendor/bin/phpstan analyse`,
  `vendor/bin/php-cs-fixer fix --dry-run --diff`, `vendor/bin/phpunit`, with
  `DATABASE_URL` pointed at the service container (mirrors `.env.test`'s shape).
- **`deploy`** — `needs: quality-gate`, runs only `if: github.ref == 'refs/heads/main'`
  on a `push` event, **and** on `workflow_dispatch` (satisfies the explicit "manual
  push from main" requirement — run via the Actions UI or
  `gh workflow run deploy.yml`). Installs the Railway CLI, authenticates with the
  `RAILWAY_TOKEN` secret, runs
  `railway up --service <service> --environment production --detach`.

## Manual runbook (one-time setup)

Run from the repo root, after the repo-side changes above are merged:

1. `railway login`
2. `railway init` (or `railway link` if a Railway project already exists) from repo
   root; confirm it detects `php/Dockerfile` via `railway.json`, not Railpack — check
   the build log says "Using Dockerfile".
3. `railway add` → provision the PostgreSQL plugin, version 18 (matches
   `docker-compose.yml`).
4. Set required variables via `railway variables set` (never commit these):
   - `APP_ENV=prod`
   - `APP_SECRET` — generate with `openssl rand -hex 32`
   - `DATABASE_URL` — reference the Postgres plugin's connection variable
5. In the Railway dashboard, connect the GitHub repo but **turn off "Deploy on
   Push"** for this service. This is a deliberate deviation from
   `infrastructure.md`'s original "connect GitHub for auto-deploy" suggestion: with
   CI-gated deploys, Railway's own push trigger would race the Actions-driven one.
6. Generate a Railway **project token** (Project Settings → Tokens), add it as the
   `RAILWAY_TOKEN` GitHub Actions secret (`gh secret set RAILWAY_TOKEN`), and record
   the target service name for the `railway up --service` argument in the workflow.
7. First deploy: push to `main` (or run the workflow manually via the Actions UI /
   `gh workflow run deploy.yml`) and watch the `deploy` job's logs and/or
   `railway logs --service <service>`.

## Risk register

Carried forward from `infrastructure.md` (still applicable), plus the new
deploy-specific ones this plan introduces:

| Risk | Source | Mitigation |
|---|---|---|
| Railpack silently falls back to single-threaded PHP if the Dockerfile path breaks | infrastructure.md | Keep `php/Dockerfile` as the explicit build source in `railway.json`; verify build logs after every deploy-config change |
| Resource-allocation billing causes quiet cost creep | infrastructure.md | Right-size Postgres/app containers at launch; set a Railway billing alert on day one |
| Rollback reverts the container image but not env var/secret state changed after that release | infrastructure.md | Track env var changes in a changelog; manually verify secrets after any rollback |
| Double deploy race (Railway native trigger + Actions-driven `railway up`) | This plan | Native GitHub "Deploy on Push" explicitly disabled in runbook step 5 |
| First deploy has zero Doctrine migrations (`migrations/` is empty) | This plan | Expected for a fresh skeleton; the pre-deploy `migrate` command is a safe no-op; generate real migrations once entities exist |
| `RAILWAY_TOKEN` scope too broad if an account token is used by mistake | This plan | Use a project-scoped token only; document rotation if leaked |
| Cache warmup moved from build time to the pre-deploy hook (to avoid coupling the build stage to `DATABASE_URL`) | This plan | Slightly longer pre-deploy step; acceptable trade-off for a portable, build-env-independent image |

## Verification

- `curl https://<railway-domain>/api/status` should return
  `{"status":"ok","database":true}`.
- Check the `deploy` GitHub Actions job logs and `railway logs --service <service>` for
  the same deploy — build source should say "Using Dockerfile", not Railpack.
- Confirm a broken PR (failing `phpstan`/`phpunit`) blocks `quality-gate` and never
  reaches the `deploy` job.
- Confirm `workflow_dispatch` successfully redeploys `main` on demand without needing a
  new commit.
- Once the 5 repo-side files exist: sanity-check the Dockerfile syntax and that
  `docker compose build php` still succeeds locally (bind-mount workflow should be
  unaffected); confirm `.github/workflows/deploy.yml` is valid YAML.

## Implementation status

Repo-side deliverables (1–5 above) are **designed but not yet created**. No live
Railway/GitHub-secret actions have been taken. This document is the plan to implement
against; the actual file changes and the manual runbook execution are a separate,
subsequent step.

## Out of scope

- Production-scale architecture (multi-region, HA beyond Railway's single-click
  Postgres HA option, DR) — unchanged from `infrastructure.md`.
- Actually executing the manual runbook (external account/billing/credential actions —
  left to the project owner).
