<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Deploy pipeline (Railway + GitHub Actions)

- **Plan**: `context/changes/deploy-pipeline-live/plan.md`
- **Scope**: Phases 1–2 of 3 (mid-implementation phase review, invoked from `/10x-implement`)
- **Date**: 2026-09-02
- **Verdict**: APPROVED
- **Findings**: 0 critical, 0 warnings, 0 observations

> Note: `change.md.status` intentionally left at `implementing` (not flipped to
> `impl_reviewed`) — Phase 3 is still pending and `/10x-implement` owns the
> terminal status flip. A full `impl_reviewed` stamp belongs to the post-Phase-3
> review offered at Plan Completion.

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Scope detected

Commits `6be0537` (p1), `4c1e02c` (p2) on `feat/deploy-pipeline-live`. Code files
changed: `php/Dockerfile`, `docker-compose.yml`, `.dockerignore` (new), `.env.prod`
(new), `config/packages/doctrine.yaml`, `php/railway-start.sh` (new, mode 100755),
`railway.json` (new). Every changed file is in the plan; no file in the plan's
Phase 1–2 "Changes Required" is missing from the diff; no unplanned files.

## Plan adherence (per planned change)

| Planned change | Verdict | Evidence |
|---|---|---|
| `php/Dockerfile` → `base`/`dev`/`prod` stages | MATCH | `FROM php:8.5-apache AS base` (today's content verbatim + `CMD`), empty `FROM base AS dev`, final `FROM base AS prod` with the exact `composer install --no-dev --no-scripts --no-autoloader` → `COPY . .` → `dump-autoload` → `COPY railway-start.sh` → `chmod +x` sequence from the plan Contract |
| `docker-compose.yml` → `target: dev` on `php` + `php-e2e` | MATCH | `git diff` = 2 insertions, both `target: dev`; nothing else touched |
| `.dockerignore` (new) | MATCH | Exact plan exclude list incl. the F3 fix (`.env.dev`, `.env.dist`); keeps `composer.*`, `symfony.lock`, `bin`, `config`, `migrations`, `public`, `src`, `templates`, `.env`, `.env.prod` |
| `.env.prod` (new, tracked, secret-free) | MATCH | Header comment (F2 Fix-B framing) + single `APP_ENV=prod`; no `APP_DEBUG`, no `APP_SECRET`/`DATABASE_URL` |
| `config/packages/doctrine.yaml` → `server_version: '18'` | MATCH | `#server_version: '16'` → `server_version: '18'`; matches `postgres:18` compose services and the Railway runbook |
| `php/railway-start.sh` (new) | MATCH | POSIX `sh`, LF, exec bit (`100755`); `PORT` default 80 → `sed` `ports.conf` + vhost → `assets:install public` → `cache:clear` → `chown -R www-data:www-data var` → `exec apache2-foreground`; no `APP_ENV` guard |
| `railway.json` (new) | MATCH | Byte-for-intent identical to the plan Contract JSON (`DOCKERFILE` builder, `php/Dockerfile`, `startCommand`, `preDeployCommand` = migrate only, `/login` healthcheck, `numReplicas: 1`, `ON_FAILURE`) |

## Scope discipline

All "What We're NOT Doing" guardrails respected: `run-dev.sh` untouched; no app
code / entity / migration change; no `.env.local.php` / `composer dump-env`; no
prod PHP-ini tuning; no GHA layer caching; no branch-protection config; the
Railway/GitHub runbook is documented, not executed. No scope creep in the diff.

## Safety & quality

- `railway-start.sh` `set -e` → a failed `assets:install` / `cache:clear` aborts
  start; Railway's `/login` healthcheck then fails loudly. Fail-fast, intended.
- `chown -R www-data:www-data var` runs **after** the root-run `cache:clear`, so
  `var/cache/prod` ends up www-data-owned. Correct ordering.
- `sed` port-rewrite operates on a fresh image layer each container start
  (Railway restarts re-run from the image), so non-idempotent in-place re-runs
  are not reachable. Verified empirically: `PORT=8080` → `Listen 8080` +
  `<VirtualHost *:8080>`.
- No secrets added to any tracked file. `.env` still carries local
  `DOCKER_POSTGRES_*` dev values into the image by necessity (Symfony needs
  `.env`); Railway's `DATABASE_URL` / `APP_SECRET` variables shadow them and the
  `database` host is unreachable off the compose network — documented in the plan
  and the `.dockerignore` header.
- `preDeployCommand` = migrations only; cache warmup is in `railway-start.sh` (runtime
  container), matching the "pre-deploy runs in a throwaway container" constraint.

## Architecture & patterns

- Single multi-stage Dockerfile with a shared `base` — no second file to drift.
  `dev` (compose) and `prod` (Railway) diverge only where they must.
- No `ENTRYPOINT`; the Railway start path is reachable only via `railway.json`
  `startCommand`, never from `docker compose`. Local runtime byte-for-byte
  unchanged (Phase 1 manual checks 1.10–1.13 confirmed by the user).
- Dockerfile comments in Polish — matches the file's existing convention.
  `.env.prod` explanatory header matches the `.env.e2e` style. `railway.json`
  2-space indent matches the repo's JSON/YAML.

## Success criteria — re-verified

**Automated** (re-run at review time): 1.1 ✓, 1.3 ✓, 1.5 ✓, 1.6 ✓, 1.8 ✓, 1.9 ✓,
2.1 ✓ (`jq` absent on host → `python3 -m json.tool` + `php json_decode`), 2.2 ✓,
2.3 ✓. 1.2 / 1.4 / 1.7 (phpunit) were green at implementation time and their
inputs are unchanged since — not re-run (expensive Docker rebuilds).

**Manual**: 1.10–1.13 confirmed complete by the user. 2.4 is runbook-deferred
(needs a live Railway project) — legitimately pending, will be exercised during
the owner runbook.

## Findings

None. Implementation matches the (twice-reviewed) plan exactly; all re-run
success criteria pass; scope guardrails held.
