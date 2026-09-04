---
change_id: deploy-pipeline-live
title: Working Railway + GitHub Actions deployment pipeline
status: archived
created: 2026-08-29
updated: 2026-09-04
archived_at: 2026-09-04
---

## Notes

Executes the repo-side deliverables designed in
[`context/deployment/deploy-plan.md`](../../deployment/deploy-plan.md): multi-stage
`php/Dockerfile` (`base` / `dev` / `prod`), a Railway-only `php/railway-start.sh`,
`.dockerignore`, `railway.json`, and a GitHub Actions `deploy.yml` (CI-gated quality
gate → Railway deploy).

Roadmap: **F-02** (`context/foundation/roadmap.md`), GitHub issue
[`czarra/10x3_med#2`](https://github.com/czarra/10x3_med/issues/2). Hard deadline
**2026-09-07** (PRD `timeline_budget.hard_deadline`).

Scope is repo-side + runbook only — the first live deploy still needs the
owner's one-time Railway/GitHub steps (documented, not executed here).

Reconciliations vs. the deploy-plan as written (2026-08-25):
- Migrations now exist (6 files) — pre-deploy `migrate` is a real schema build,
  not the "safe no-op" the plan's risk register still claims.
- Playwright E2E suite now exists — wired into CI as a step that runs
  sequentially after PHPUnit (shared `database-test` Postgres, never concurrent).
- CI runs via `docker compose` parity, not `shivammathur/setup-php` +
  `postgres:18` service container.
- Health check + verification move to `/login`: the plan's `healthcheckPath` is
  already `/login`, but its `## Verification` section still curls the deleted
  `/api/status` (`src/Controller/Api/StatusController.php`, removed in `153532b`).
- Cache warmup moves from `preDeployCommand` to `php/railway-start.sh` (run via
  `railway.json` `startCommand`) — Railway's pre-deploy runs in a throwaway
  container, so a warmed `var/cache` there never reaches the runtime container.
  `preDeployCommand` keeps only `doctrine:migrations:migrate`.
- `php/Dockerfile` becomes multi-stage (`base` → `dev` → `prod`); compose builds
  `target: dev` (unchanged local image), Railway builds `prod`. No `ENTRYPOINT` —
  keeps the Railway-only start path out of the local stack entirely.
