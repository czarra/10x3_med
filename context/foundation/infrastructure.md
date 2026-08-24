---
project: dia-guide
researched_at: 2026-08-22
recommended_platform: Railway
runner_up: Render
context_type: mvp
tech_stack:
  language: PHP 8.5
  framework: Symfony 7.4
  runtime: Docker (php:8.5-apache, per php/Dockerfile)
---

## Recommendation

**Deploy on Railway.**

Railway is the only researched platform that passes all five agent-friendly criteria cleanly: full CLI-driven deploy/logs/rollback, one-click co-located managed PostgreSQL (14–18, with an HA option), `llms.txt`-indexed docs, a deterministic GitHub-triggered deploy pipeline, and an official first-party MCP server plus Claude Code plugin. It also matches this project's existing constraints directly — the interview flagged cost-minimization, co-located Postgres, and existing Railway/Render/Fly.io familiarity as priorities, single-region is sufficient, and no persistent-connection/WebSocket support is required (confirmed against the PRD: `has_realtime: false`, `has_background_jobs: false`). This converges with the `deployment_target: railway` hint already recorded in `context/foundation/tech-stack.md`.

## Platform Comparison

Cloudflare Workers/Pages, Vercel, and Netlify were hard-filtered out before scoring: none support PHP as a request-serving runtime. Cloudflare Workers is JS/TS/Python/Rust only (PHP exists only as an unmaintained WASM proof-of-concept). Vercel supports PHP only through an unofficial community runtime (`vercel-community/php`) that boots Symfony's kernel fresh per request inside a 50MB/60s serverless function — architecturally hostile to a stateful Doctrine ORM app. Netlify has no server-side PHP execution path at all; PHP files are served as static text. None of the three offer a managed PostgreSQL co-located in the way this project needs (Cloudflare only has D1/Hyperdrive-to-external-Postgres; Vercel Postgres is sunset in favor of a Neon marketplace integration; Netlify's newly-GA "Netlify Database" is Neon-backed and irrelevant since the app tier itself can't run there).

The three remaining candidates are container/VM-based PaaS platforms that all run this repo's existing `php/Dockerfile` directly.

| Platform | CLI-first | Managed/Serverless | Agent-readable docs | Stable deploy API | MCP / Integration | Total |
|---|---|---|---|---|---|---|
| Fly.io | Pass | Partial | Partial | Pass | Partial | 2 Pass / 3 Partial |
| Railway | Pass | Pass | Pass | Pass | Pass | 5 Pass |
| Render | Partial | Pass | Pass | Pass | Pass | 4 Pass / 1 Partial |

### Shortlisted Platforms

#### 1. Railway (Recommended)

Passes all five criteria. `railway up` / `railway logs` / dashboard-or-CLI rollback cover the full operational loop; one-click Postgres 14–18 with an HA upgrade path satisfies the co-location preference directly; docs are markdown-indexed via `llms.txt` with a public GitHub source repo (`railwayapp/docs`); GitHub auto-deploy-on-merge is native and matches the `ci_default_flow: auto-deploy-on-merge` already recorded in `tech-stack.md`; and Railway ships an official hosted MCP server (`mcp.railway.com`) plus an official Claude Code plugin — the strongest first-party agent integration of the three. The main caveat is that Railway's PHP auto-detection (Railpack, successor to Nixpacks) is still Beta and, per Railway's own community, defaults to a single-threaded PHP built-in server unfit for production — but this project already ships its own `php/Dockerfile`, which bypasses Railpack's auto-detection entirely and neutralizes the risk.

#### 2. Render

Docker-native (drops onto the existing `php/Dockerfile` with no changes), GA managed Postgres and Redis-compatible Key Value, GA Cron Jobs, and an official MCP server. It loses to Railway on the CLI-first criterion specifically: **rollback is dashboard-only** — Render's own docs describe rollback as "click Rollback" on the Events page with no documented CLI or API equivalent, which fails the core agent-operability test (an agent cannot click). It also has a real footgun for a solo dev on a deadline: free-tier Postgres databases expire and are deleted 30 days after creation (14-day grace period) — an easy default to fall into that would cost this project its patient data.

#### 3. Fly.io

Strongest raw compute story — full Firecracker VMs with real CLI control (`fly deploy`, `fly releases`, `fly logs`) and native WebSocket/persistent-process support (not needed here, but headroom for later). It scores lowest on managed services: Fly Postgres is explicitly marketed as **unmanaged** ("This Is Not Managed Postgres" — DIY backups/HA), which is a poor fit for encrypted medical data on a solo timeline; the alternative, Fly's Managed Postgres (MPG), starts at $38/mo — well above the cost-minimizing target and above what Railway or Render charge for the same MVP scale. Docs are markdown-adjacent (copy-as-markdown per page) but the doc source repo is ERB templates, not clean Markdown/MDX.

## Anti-Bias Cross-Check: Railway

### Devil's Advocate — Weaknesses

1. Railpack (Railway's PHP auto-detector, successor to Nixpacks) is still in Beta — if the custom `php/Dockerfile` in this repo ever drifts or is bypassed, deploys silently fall back to a build path Railway's own community has flagged as unfit for production PHP concurrency.
2. No sticky sessions across replicas — irrelevant today (no WebSockets, no realtime per the PRD), but if the v2 "diabetolog read-only access" feature ever adds live updates, this becomes a real constraint requiring an external pub/sub workaround.
3. Cost floor is non-zero from day one ($5 Trial credit expires after 30 days, then $5–20/mo minimum) — worth confirming against Render's genuinely free (if time-boxed) tier or Fly.io's ~$2/mo shared-cpu machine, given the interview's stated cost-minimization priority.
4. Billing is resource-allocation-based (RAM/vCPU/egress), not request-count-based — a misconfigured always-on Postgres + app container could quietly exceed the $5 Hobby tier even at this app's low QPS, if instance sizes aren't right-sized.
5. Migrations must be wired through Railway's pre-deploy hook rather than the container start command — an easy thing to get wrong under a 3-week solo deadline, and a wrong ordering could run the app against a mid-migration schema.

### Pre-Mortem — How This Could Fail

Six months in, DiaGuide's Railway deployment became a headache. The team (a solo dev) never right-sized the Postgres instance, so a Doctrine connection-pool misconfiguration silently pushed monthly cost from $5 to $35 — undetected because nobody set up billing alerts. Railpack's PHP auto-detection had been quietly falling back to its default build path after a Dockerfile path got renamed during a refactor, meaning production was running PHP's built-in single-threaded server for weeks without anyone noticing degraded latency — violating the PRD's <200ms UX guardrail under any real concurrent load. A schema migration for the "diabetolog access code" feature ran via the container start command instead of the pre-deploy hook, racing against a second replica spun up during a traffic blip, and corrupted a foreign key on sensitive glycemia records. Recovery required restoring from a Postgres backup taken before the botched migration, losing several hours of patient-entered data — the kind of failure this app's medical-safety guardrails were explicitly meant to prevent.

### Unknown Unknowns

- Railway bills for *allocated* resources, not idle time the way Render's free tier spins down — an always-on Postgres + app pairing accrues cost 24/7 even with near-zero traffic, unlike Fly.io's autostop/autostart machines.
- The HA (high-availability) Postgres upgrade path only works on Railway's *own* Postgres image — if the project ever needs a custom Postgres extension (e.g., for encryption-at-rest tooling beyond what Railway's base image ships), HA conversion may not be available without a migration.
- Rollback via CLI/dashboard reverts the container image, but may not fully revert environment-variable state changed after that release — a rollback after a botched env var change (e.g., an encryption key rotation) may not actually undo it, silently leaving prod in a half-reverted state.
- Because there's no sticky-session support, any future real-time feature (glucose trend push notifications, live diabetolog dashboard) would need an external pub/sub layer (Redis, etc.) from day one of that feature — not obvious from the pricing page.

**User decision**: proceed with Railway, risks noted — the repo's existing Dockerfile mitigates the Railpack risk, and the billing/migration-ordering risks are addressed as one-time setup steps in the risk register below.

## Operational Story

- **Preview deploys**: Railway supports PR-linked preview environments via its GitHub integration; each PR can spin up an isolated environment with its own Postgres branch. No additional protection layer needed for a solo-dev MVP with no public preview traffic.
- **Secrets**: Environment variables and Postgres credentials live in Railway's project-level variable store, scoped per-environment (production vs. preview); only the project owner (this solo dev) can read them via dashboard or `railway variables`. `DATABASE_URL` and the Symfony `APP_SECRET` should be set there, never in a tracked `.env*` file, per this repo's existing CLAUDE.md rule.
- **Rollback**: `railway redeploy` against a prior deployment ID, or the dashboard's "rollback to any previous version" action — both revert the container image quickly (seconds). Caveat: env var changes made after that release are not automatically reverted (see Unknown Unknowns above); any Doctrine migration applied by the newer release is also not auto-reverted and may require a manual down-migration.
- **Approval**: Production deploys (main-branch merge) are unattended per the `auto-deploy-on-merge` CI flow already recorded in `tech-stack.md`. A human must approve: any Postgres plan/HA tier change, any secret rotation (`APP_SECRET`, `DATABASE_URL` credentials), and any manual database restore. An agent may run `railway logs`, `railway status`, and read-only MCP calls unattended.
- **Logs**: `railway logs --service <name>` tails runtime logs from the CLI; the official Railway MCP server (`mcp.railway.com`) exposes equivalent read-only log/status tools for agent use without shell access.

## Risk Register

| Risk | Source | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| Railpack (PHP auto-detect) silently falls back to single-threaded built-in server if the Dockerfile path breaks | Devil's advocate | L | H | Keep `php/Dockerfile` as the explicit build source in `railway.json`/service settings; verify build logs show "Using Dockerfile" after every deploy config change |
| Resource-allocation billing causes quiet cost creep beyond the $5–20/mo target | Devil's advocate / Pre-mortem | M | M | Right-size the Postgres and app container at launch; set a Railway usage/billing alert at day one, not after the trial credit runs out |
| Migration run via container start command instead of pre-deploy hook races a second replica and corrupts data | Pre-mortem | L | H | Wire Doctrine migrations through Railway's pre-deploy hook exclusively; document this in the deploy runbook before first production deploy |
| Rollback reverts the container image but not env var / secret state changed after that release | Unknown unknowns | L | M | Track env var changes in a changelog or Railway's own variable history; manually verify secrets after any rollback before resuming traffic |
| No sticky sessions — blocks future WebSocket/realtime features (v2 diabetolog live view) from scaling past one replica | Unknown unknowns | L (v1 has no realtime) | M (v2 risk only) | Defer — re-evaluate with a Redis pub/sub layer if/when a v2 realtime feature is scoped |
| Fly Postgres alternative was unmanaged and MPG pricing ($38/mo+) — noted for context, not a Railway risk, but confirms Railway's managed Postgres is the safer choice for encrypted medical data | Research finding | — | — | No action needed; documents why Fly.io was not chosen |

## Getting Started

1. Install the Railway CLI: `curl -fsSL https://railway.app/install.sh | sh` (or `npm i -g @railway/cli`), then `railway login`.
2. From the repo root, link the project: `railway init` (or `railway link` if a Railway project already exists), and confirm the service picks up `php/Dockerfile` as its build source rather than falling back to Railpack auto-detection.
3. Provision a co-located PostgreSQL 18 instance from the Railway dashboard or `railway add` (database plugin), matching the version already used in local Docker Compose.
4. Set required environment variables (`APP_ENV=prod`, `APP_SECRET`, `DATABASE_URL` pointing at the Railway Postgres instance) via `railway variables set` — never commit these to `.env*`.
5. Wire the Doctrine migration step (`bin/console doctrine:migrations:migrate --no-interaction`) into Railway's pre-deploy hook (service settings → Deploy → Pre-deploy Command), not the container start command, before the first production deploy.
6. Connect the GitHub repo in Railway's dashboard to enable auto-deploy-on-merge to `main`, matching the CI flow recorded in `tech-stack.md`.

## Out of Scope

The following were not evaluated in this research:
- Docker image configuration (this project's `php/Dockerfile` is treated as a given, not re-designed)
- CI/CD pipeline setup (GitHub Actions workflow content)
- Production-scale architecture (multi-region, HA beyond Railway's single-click Postgres HA option, DR)
