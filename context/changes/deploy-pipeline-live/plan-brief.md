# Deploy pipeline (Railway + GitHub Actions) — Plan Brief

> Full plan: `context/changes/deploy-pipeline-live/plan.md`
> Design + runbook: `context/deployment/deploy-plan.md`

## What & Why

DiaGuide's deploy target (Railway + GitHub Actions) is decided in
`tech-stack.md`/`infrastructure.md`, but the repo-side wiring designed in
`deploy-plan.md` does not exist yet: `php/Dockerfile` never copies the app or installs
vendor, Apache ignores Railway's `$PORT`, and there is no `.dockerignore`, `railway.json`,
or CI/CD. This change implements that wiring so the app builds and runs on Railway —
**without changing how it runs locally**. Roadmap F-02; hard deadline 2026-09-07.

## Starting Point

Local `docker compose` / `run-dev.sh` work today only because the compose bind mount
(`.:/var/www/med`) hides the (codeless) image and `run-dev.sh` runs its own
`composer install` in-container. There are 6 real migrations, a `/login` public route,
a shared `database-test` Postgres for PHPUnit + Playwright E2E, and no `.github/` at all.

## Desired End State

`docker build --target prod` produces a self-contained image that serves `/login` on
`$PORT` given `APP_ENV`/`APP_SECRET`/`DATABASE_URL`. `docker compose` / `run-dev.sh` are
byte-for-byte unchanged at runtime (they build the `dev` stage). `railway.json` drives a
Dockerfile build of the `prod` stage, pre-deploy migrations, and a `/login` health
check. Pushing to `main` runs `CI` (phpstan, php-cs-fixer, phpunit, E2E); a green `CI`
triggers `Deploy` (`railway up`) for that exact commit; `workflow_dispatch` allows an
on-demand redeploy; a red `CI` deploys nothing.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Dev/prod image split | One multi-stage `php/Dockerfile` (`base`/`dev`/`prod`) + `target: dev` in compose | Shared `base` can't drift like two files would; local build stays on `dev` (same speed) | Plan |
| Railway start logic | `php/railway-start.sh`, run only via `railway.json` `startCommand` — no `ENTRYPOINT` | An entrypoint also runs under local compose and would `chown` the host `var/` / crash a vendor-less container | Plan |
| Cache warmup location | In `railway-start.sh` at container start, not `preDeployCommand` | Railway's pre-deploy runs in a throwaway container whose filesystem never reaches runtime | Plan |
| CI vs deploy | Two workflows: `ci.yml` (tests) + `deploy.yml` (`workflow_run` gated on `conclusion == 'success'` + `workflow_dispatch`) | Auto-deploy only on green; manual redeploy always available; no double-deploy race | Plan |
| CI runner | `docker compose` parity, not `shivammathur/setup-php` | Exact same stack as local dev; covers the E2E suite too | Plan |
| `APP_ENV` in prod | Railway service var `APP_ENV=prod` (load-bearing); tracked `.env.prod` is only a convention placeholder | `.env` sets `dev` and wins env selection — `.env.prod` loads only once `APP_ENV` is already `prod` | Plan (rev. after review) |
| PG server version | `server_version: '18'` in `config/packages/doctrine.yaml` | Removes the `?serverVersion=` suffix requirement from the Railway `DATABASE_URL` and a boot-time probe query | Plan |
| Health check path | `/login` | Public, stable 200 for anonymous users; `/` is a redirect chain; `/api/status` was deleted | Design (`deploy-plan.md`) |

## Scope

**In scope:** multi-stage `php/Dockerfile`; `target: dev` on `php` + `php-e2e` in
`docker-compose.yml`; `.dockerignore`; `.env.prod`; `doctrine.yaml` `server_version`;
`php/railway-start.sh`; `railway.json`; `.github/workflows/ci.yml`;
`.github/workflows/deploy.yml`.

**Out of scope:** executing the Railway/GitHub runbook (owner: accounts, billing,
secrets, disabling native auto-deploy); any app code / schema / migration change;
`run-dev.sh`; multi-replica / HA / DR; new tests; GitHub branch-protection config.
**Deliberate fast-follows** (accepted in plan review): prod PHP ini tuning (opcache),
GHA Docker layer caching for `ci.yml`.

## Architecture / Approach

`php/Dockerfile`: `base` (today's setup) → `dev` (empty; compose builds this) → `prod`
(`COPY` app + `composer install --no-dev` + `railway-start.sh`). Railway builds the last
stage (`prod`) per `railway.json`; `railway-start.sh` rewrites Apache's port to `$PORT`,
warms the cache, then `exec apache2-foreground`. `preDeployCommand` runs the 6
migrations before traffic. `ci.yml` spins up the compose stack on the GitHub runner and
runs phpstan → php-cs-fixer → phpunit → Playwright E2E (E2E strictly after PHPUnit —
shared DB). `deploy.yml` keys off `CI`'s `workflow_run` conclusion, checks out the
passing `head_sha`, and runs `railway up`.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Production image + start script | Multi-stage Dockerfile, `target: dev`, `.dockerignore`, `php/railway-start.sh`, `.env.prod`, `doctrine.yaml` pin | A build-context or `.dockerignore` mistake that breaks the local `dev` build or omits a runtime-needed path; `sed` port-rewrite not matching the vhost/`ports.conf` lines |
| 2. Railway config (`railway.json`) | `railway.json` only | `startCommand` path drift vs the Dockerfile `COPY` target |
| 3. CI/CD | `.github/workflows/ci.yml` + `deploy.yml` | Container-start ordering (`php-e2e` before vendor exists); `workflow_run` gating semantics |

**Prerequisites:** local Docker + the running compose stack (`run-dev.sh` already run);
GitHub repo; Railway account for the runbook (separate).
**Estimated effort:** ~3 sessions, one commit per phase, on `feat/deploy-pipeline-live`.

## Open Risks & Assumptions

- `php-cs-fixer` may hard-error on PHP 8.5 in CI → fallback: `PHP_CS_FIXER_IGNORE_ENV: 1`
  on that step only.
- Auto-deploy / red-CI-blocks-deploy behaviour can only be fully verified once the
  branch is on GitHub and Railway secrets exist — verified during/after the runbook.
- Assumes Railway builds the Dockerfile's final stage (no per-stage target flag needed).

## Success Criteria (Summary)

- `docker compose` / `run-dev.sh` still bring the app up locally on 8381/8382 with
  `/login` → 200; `git diff` touches `docker-compose.yml` only for `target: dev`.
- `docker build --target prod` runs and serves `/login` on a custom `$PORT`.
- On GitHub: PR runs `CI`; green `CI` on `main` deploys via `railway up` ("Using
  Dockerfile", 6 migrations applied); a red `CI` deploys nothing; `workflow_dispatch`
  redeploys on demand.
