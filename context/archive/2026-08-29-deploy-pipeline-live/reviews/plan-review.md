<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Deploy pipeline (Railway + GitHub Actions)

- **Plan**: `context/changes/deploy-pipeline-live/plan.md`
- **Mode**: Deep
- **Date**: 2026-09-02 (pass 2 — re-review)
- **Verdict**: SOUND (pass 1: REVISE → SOUND after triage; pass 2: 1 warning + 2 observations, all addressed)
- **Findings**: pass 1 — 0 critical / 2 warnings / 3 observations · pass 2 — 0 critical / 1 warning / 2 observations

## Pass 1 triage (2026-09-02) — all resolved, fixes verified in-plan

| Finding | Decision |
|---|---|
| F1 — Phase 1 prod build depended on a Phase 2 file (`railway-start.sh`) | FIXED (Fix A): script moved to Phase 1 change #6; Phase 2 = `railway.json` only; criteria renumbered 1.1–1.13 / 2.1–2.4 |
| F2 — `.env.prod` redundant / false rationale | FIXED (Fix B): kept as Symfony-convention placeholder, Intent rewritten, Railway `APP_ENV=prod` flagged load-bearing in Critical Implementation Details + runbook step 4 |
| F3 — CI runtime / no Docker layer cache | ACCEPTED: noted in "What We're NOT Doing" as a fast-follow |
| F4 — prod PHP ini unconfigured | SKIPPED (out of scope): noted in "What We're NOT Doing" as a fast-follow |
| F5 — `.dockerignore` shipped `.env.test` / `.env.e2e` | FIXED: both added to the exclude list |

Pass-2 grounding confirms every pass-1 fix is present in both the plan body and `## Progress`.

## Pass 2 verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | PASS |
| Plan Completeness | WARNING (F1, now fixed) |

## Grounding (pass 2)

6/6 existing paths ✓ (`php/Dockerfile`, `docker-compose.yml`, `config/packages/doctrine.yaml`,
`config/packages/security.yaml`, `run-dev.sh`, `src/Controller/SecurityController.php`,
`composer.json`). 5/5 pending-create paths absent ✓ (`.dockerignore`, `.env.prod`,
`railway.json`, `.github/workflows/ci.yml`, `.github/workflows/deploy.yml`). Symbols ✓:
`#server_version: '16'` present in `doctrine.yaml`; `php` + `php-e2e` use map-form `build:`
(so `target: dev` slots in); the `php` service has **no** healthcheck (so `docker compose
up --wait database database-test php` treats it ready on "running" — no deadlock); 6
migration files present; `/login` is unmatched by any `security.yaml` `access_control`
rule (public, stable 200 for anonymous). brief↔plan ✓.

## Verified OK (no finding)

- `## Progress` mechanically well-formed: one heading; every `## Phase N` has a matching
  `### Phase N`; item counts equal Success-Criteria counts (9 auto + 4 manual = 1.1–1.13;
  3 + 1 = 2.1–2.4; 4 + 4 = 3.1–3.8); Phase blocks carry plain bullets only.
- No "What We're NOT Doing" item reappears in a phase; every Desired-End-State promise
  (prod image serves `/login` on `$PORT`; local `docker compose` / `run-dev.sh`
  unchanged; `railway.json` drives Railway; green-CI-gated deploy; `workflow_dispatch`
  redeploy; red CI = no deploy) has a backing phase.
- `docker compose build php` stopping at `dev` is real: `dev` is `FROM base AS dev` with
  no body and compose passes `--target dev` to the builder — the `prod` stage (the only
  `composer install`) is never entered.
- `/login` GET does not touch the DB (the user provider is queried on POST only), so it
  stays a valid health-check target even when the DB is slow or unreachable.
- CI container ordering is sound: `composer install` in `php` populates the bind-mounted
  host `vendor/`, which `php-e2e` then sees before its `/login` healthcheck runs; E2E
  runs strictly after PHPUnit against the shared `database-test` Postgres.

## Findings (pass 2)

### F1 — Manual verification 1.13 used `host.docker.internal`, which does not resolve on Linux by default

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phase 1 → Success Criteria → Manual Verification (step 1.13)
- **Detail**: The simulated-prod-boot command reached the local test DB via
  `DATABASE_URL=…@host.docker.internal:4307/…`. On Docker Engine for Linux (this project's
  platform), `host.docker.internal` is not populated inside a container unless
  `--add-host=host.docker.internal:host-gateway` is passed or the daemon is configured for
  it. As written, `railway-start.sh`'s `cache:clear` warmup / first DB request fails on
  name resolution — a false failure unrelated to the image under test, at the step meant
  to give confidence before Phase 2.
- **Fix**: Attach the throwaway container to the compose network and target
  `database-test:5432` by service name.
- **Decision**: FIXED — step 1.13's `docker run` now uses `--network symfony_default`
  (with a `docker network ls | grep default` confirmation note) and
  `@database-test:5432/app_test`.

### F2 — `railway up --detach` makes the Deploy job green on upload, not on a healthy release

- **Severity**: 🔵 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Blind Spots
- **Location**: Phase 3 → `deploy.yml` contract
- **Detail**: With `--detach`, `railway up` returns as soon as the build context uploads.
  The "Deploy" job then reports success regardless of whether the Railway build, the
  `preDeployCommand` migrations, or the `/login` health check pass — the only failure
  signal lives in Railway's dashboard/alerts. A green Actions run is not evidence the site
  is up (matters if a badge or branch rule later leans on it).
- **Fix**: Drop `--detach` (job fails on a failed build/deploy) or add a follow-up
  `curl -fsS --retry … https://<domain>/login` health-gate step.
- **Decision**: ACCEPTED for MVP — first deploys are watched in the Railway dashboard and
  gated by the manual `curl /login` check (3.7). Added as a "What We're NOT Doing" bullet;
  dropping `--detach` / a health-gate step is a noted fast-follow.

### F3 — `.dockerignore` still shipped `.env.dev` and `.env.dist` into the prod image

- **Severity**: 🔵 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Lean Execution
- **Location**: Phase 1 change #3 (`.dockerignore` contract)
- **Detail**: The exclude list covered `.env.local`, `.env.*.local`, `.env.test`,
  `.env.e2e` (the pass-1 F5 fix) but not `.env.dev` or `.env.dist`. Both are git-tracked
  and land in the image via `COPY . .`; neither loads under `APP_ENV=prod` nor holds a
  real secret — the same hygiene class as F5. `.env` itself must stay and unavoidably
  carries the local `DOCKER_POSTGRES_*` dev values (shadowed by Railway's variables at
  runtime, useless off the compose network).
- **Fix**: Add `.env.dev` and `.env.dist` to the exclude list; note the `.env` caveat.
- **Decision**: FIXED — `.env.dev` and `.env.dist` added to the `.dockerignore` contract;
  change #3 Intent rewritten to state the `.env` local-credentials caveat explicitly.
