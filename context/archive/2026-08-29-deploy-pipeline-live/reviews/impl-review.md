<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Deploy pipeline (Railway + GitHub Actions)

- **Plan**: `context/changes/deploy-pipeline-live/plan.md`
- **Scope**: Full plan — Phases 1–3 of 3
- **Date**: 2026-09-03
- **Verdict**: APPROVED (all 4 findings triaged → FIXED)
- **Findings**: 0 critical, 1 warning, 3 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | PASS |

## Scope detected

Commits on `feat/deploy-pipeline-live`: `6be0537` (p1), `4c1e02c` (p2), `b1c824f`
(p3), `77f72df` (epilogue), `0e4d65d` (phpstan mem-limit fix), `565bf2d` (3.5
flip). Code files: `php/Dockerfile`, `docker-compose.yml`, `.dockerignore`,
`.env.prod`, `config/packages/doctrine.yaml`, `php/railway-start.sh`,
`railway.json`, `.github/workflows/ci.yml`, `.github/workflows/deploy.yml`. Every
changed file is in the plan; nothing planned is missing; no unplanned files.

The Phase 1–2 detail (Dockerfile stages, `target: dev`, `.dockerignore`,
`.env.prod`, `server_version`, `railway-start.sh`, `railway.json`) was verified in
`reviews/impl-review-phase-1-2.md` and is unchanged since — all MATCH. This report
adds Phase 3 and the cross-phase sweep.

## Phase 3 — plan adherence

| Planned (ci.yml Contract) | Verdict | Note |
|---|---|---|
| `name: CI`; `pull_request` + `push` to `main`; one `quality-gate` job on `ubuntu-latest` | MATCH | |
| checkout → compose up `database database-test php` → composer install → phpstan → php-cs-fixer `--dry-run --diff` → test-DB create + migrate → phpunit → compose up `php-e2e playwright` → npm ci → playwright test → `down -v` `if: always()` | MATCH | step order and commands verbatim, except phpstan (see F2) |
| all `exec` use `-T`; no secrets referenced | MATCH | |
| Planned (deploy.yml Contract) | Verdict | Note |
| `name: Deploy`; `workflow_run {workflows:[CI], types:[completed], branches:[main]}` + `workflow_dispatch` | MATCH | |
| guard `github.event_name == 'workflow_dispatch' \|\| github.event.workflow_run.conclusion == 'success'` | MATCH | verbatim |
| checkout `ref: head_sha \|\| github.ref` → `npm i -g @railway/cli` → `railway up --service --environment production --detach` (`RAILWAY_TOKEN` secret, `RAILWAY_SERVICE` var) | MATCH | |

Added beyond the Contract: `permissions: contents: read` on both workflows — a
restriction (least-privilege), not scope creep.

## Scope discipline

All "What We're NOT Doing" guardrails held: runbook not executed; no app/schema/
migration change; `run-dev.sh` untouched; no multi-replica/HA/DR; no new tests;
no branch-protection config; no prod PHP-ini tuning; no GHA layer caching
(`--build` every run, accepted); `--detach` kept (Deploy job green on upload, not
on a healthy release — accepted for MVP).

## Safety & quality

- **`workflow_run` + secrets**: `deploy.yml` runs from the default branch with
  secret access on *any* `workflow_run` completion, but the `branches: [main]`
  filter means it only fires when the triggering CI run's head branch was `main`
  — a fork/branch PR's CI (head = PR branch) never reaches Deploy. The filter is
  load-bearing and present. No fork-PR deploy path.
- No secret echoing; `RAILWAY_TOKEN` only in `env:` of the final step; `ci.yml`
  references no secrets at all (verified: `grep -i railway ci.yml` empty).
- `docker compose down -v` in teardown only affects the ephemeral runner; Postgres
  data is a bind mount, not a named volume.
- `railway-start.sh` `set -e`; `chown` after the root-run `cache:clear`; `sed`
  runs against a fresh image layer per container start. (Phase 1–2 review.)
- `preDeployCommand` = migrations only; warmup in `railway-start.sh`.

## Success criteria — verified

**Automated**: re-run locally at review time — 1.1, 1.3, 1.5, 1.6, 1.8, 1.9, 2.1,
2.2, 3.1, 3.3, 3.4 all PASS; `phpstan analyse --memory-limit=512M` → `[OK] No
errors`. The **full `CI` quality-gate ran green on the branch tip** (`565bf2d`) on
a real GitHub runner — covering 1.2 (build stops at `dev`), phpstan, php-cs-fixer
(no `PHP_CS_FIXER_IGNORE_ENV` needed — the anticipated PHP-8.5 hard-error did not
occur), phpunit, and the Playwright E2E stage end to end.

**Manual**: 1.10–1.13 confirmed locally by the user. **3.5** confirmed — CI green
on the PR. Pending (runbook / owner, by design): **2.4**, **3.7**, **3.8** (need a
live Railway project + secrets + merge to `main`); **3.6** (scratch-branch red-CI
check — GitHub-only, optional, not yet run).

## Findings

### F1 — CI and the documented local phpstan command diverge

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Pattern Consistency
- **Location**: `.github/workflows/ci.yml:29` vs `AGENTS.md` + `.claude/skills/verify/SKILL.md:12`
- **Detail**: `ci.yml` runs `vendor/bin/phpstan analyse --memory-limit=512M`
  because the bare command crashes on the `php` container's 128M CLI
  `memory_limit` (PHPStan parallel workers). `AGENTS.md` and the `verify` skill
  still document/run `vendor/bin/phpstan analyse` with no flag — i.e. the
  documented local quality-gate command is currently broken in the same
  container, and CI no longer matches local. This is a pre-existing latent repo
  issue surfaced (not caused) by wiring phpstan into CI for the first time.
- **Fix A ⭐ Recommended**: Sync the call sites — add `--memory-limit=512M` (or
  `-1`) to the phpstan command in `AGENTS.md` and `.claude/skills/verify/SKILL.md`.
  - Strength: one-line-per-file, keeps the fix where the command lives; no image
    change; CI and local converge immediately.
  - Tradeoff: three places now carry the flag (ci.yml + 2 docs) — must stay in
    sync if the value changes.
  - Confidence: HIGH — verified `--memory-limit=512M` → `[OK] No errors` locally.
  - Blind spot: other undocumented phpstan invocations, if any.
- **Fix B**: Raise CLI `memory_limit` in the Dockerfile `base` stage (a
  `docker-php` conf drop-in, e.g. `memory_limit=512M` for CLI), then drop the flag
  from `ci.yml`.
  - Strength: every phpstan/console invocation is fixed at once; no per-call flag.
  - Tradeoff: touches the shared image (dev + prod); adjacent to the plan's
    "no prod PHP-ini tuning" boundary (this is dev-tooling CLI memory, but still
    an image change) — wider blast radius, needs its own verification.
  - Confidence: MED — not tried; interaction with the prod runtime unverified.
  - Blind spot: whether a CLI-only ini override cleanly excludes the Apache SAPI.
- **Decision**: FIXED via Fix A — `--memory-limit=512M` added to `AGENTS.md` and `.claude/skills/verify/SKILL.md`.

### F2 — ci.yml phpstan step deviates from the Phase 3 Contract

- **Severity**: 🔵 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: `.github/workflows/ci.yml:29`
- **Detail**: Phase 3 "Changes Required" specifies the step verbatim as
  `vendor/bin/phpstan analyse`; the implementation adds `--memory-limit=512M`.
  Necessary (see F1), user-approved mid-implementation, recorded in commit
  `0e4d65d`. Phase blocks are read-only during `/10x-implement`, so the Contract
  text still shows the un-flagged form — noted here for traceability.
- **Fix**: None needed — the deviation is intentional and documented. Optionally
  add a one-line addendum under the Phase 3 block next time the plan is edited.
- **Decision**: FIXED — `**Impl deviation** (0e4d65d)` addendum added under the Phase 3 block in `plan.md`.

### F3 — No `timeout-minutes` on either workflow

- **Severity**: 🔵 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality (reliability)
- **Location**: `.github/workflows/ci.yml:12`, `.github/workflows/deploy.yml:15`
- **Detail**: `quality-gate` does a full image build + `docker compose` +
  Playwright; a hung compose/browser step runs to GitHub's 360-minute default,
  burning Actions minutes (Hobby-plan owner). `deploy.yml` less critical but
  `railway up` could also hang.
- **Fix**: `timeout-minutes: 30` on `quality-gate`, `timeout-minutes: 15` on
  `deploy`.
- **Decision**: FIXED — `timeout-minutes: 30` on `quality-gate`, `timeout-minutes: 15` on `deploy`.

### F4 — deploy.yml has no `concurrency` group

- **Severity**: 🔵 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality (reliability)
- **Location**: `.github/workflows/deploy.yml:14`
- **Detail**: Two quick merges to `main` (or a merge racing a `workflow_dispatch`)
  can start two overlapping `railway up` deploys. The plan's "no double-deploy
  race" note covered Railway-native-vs-Actions, not Actions-vs-Actions. Real risk
  is low (`--detach` returns fast; Railway serializes per-service) but not zero.
- **Fix**: Add `concurrency: { group: deploy-production, cancel-in-progress: false }`
  to the `deploy` job (queue, don't cancel — a superseded deploy still finishing
  is fine).
- **Decision**: FIXED — `concurrency: { group: deploy-production, cancel-in-progress: false }` on the `deploy` job.
