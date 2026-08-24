# Auth Scaffold — Plan Brief

> Full plan: `context/changes/auth-scaffold/plan.md`

## What & Why

DiaGuide needs an authenticated patient before any other feature can attach data to them. This scaffolds Symfony's Security bundle, password hashing, and a `User` entity + migration — the foundation roadmap item (F-01) that every downstream slice (S-01…S-07) transitively depends on. It is intentionally scaffold-only: no registration/login UI (that's slice S-01).

## Starting Point

Fresh Symfony 7.4 skeleton with Doctrine/Postgres 18 wired but no auth: no `security.yaml`, no `symfony/security-bundle`, empty `src/Entity/` and `migrations/`. Doctrine's entity mapping (`config/packages/doctrine.yaml`) is already pointed at `src/Entity` with attribute mapping and the `underscore` naming strategy.

## Desired End State

`composer require symfony/security-bundle` is committed; `security.yaml` configures the `auto` password hasher and an email-keyed entity user provider; a `User` entity (table `users`, fields: id/email/password/roles/createdAt) and its migration exist and are applied to both the dev and test databases. A kernel test proves the hasher and role logic work end-to-end.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Login identifier | Email only | Matches FR-001/FR-002 exactly; no separate username concept in PRD | Plan |
| Password hasher | `auto` algorithm | Symfony's own recommended zero-maintenance default | Plan |
| Roles model | `ROLE_USER` + `ROLE_PATIENT` constant, set in constructor | Gives PRD's "Pacjent" role a concrete name now at zero cost, without building Diabetolog (v2, non-goal) machinery | Plan |
| Entity fields | `id`, `email`, `password`, `roles`, `createdAt` — no `isVerified` | PRD has no email-verification flow anywhere; an always-true unused flag would be dead code | Plan |
| Table name | Explicit `users` (not `user`) | `user` is a PostgreSQL reserved keyword — avoids a quoting footgun | Plan |
| Dev tooling | Add `symfony/maker-bundle` (dev-only) | Generates entity/migration via Symfony's own idioms, matches how the rest of the skeleton was scaffolded | Plan |
| Test coverage | Kernel test: persist User, round-trip password through the real configured hasher, assert roles | Catches security.yaml misconfiguration before S-01 builds registration on top | Plan |

## Scope

**In scope:** `symfony/security-bundle` + `symfony/maker-bundle` (dev) install, `security.yaml`, `User` entity + `UserRepository`, migration applied to dev + test DBs, one kernel test.

**Out of scope:** registration/login controllers, forms, templates (S-01); email verification; Diabetolog role/accounts (v2); OAuth login (v2); profile fields like base dose / insulin-WW ratio (S-01); any `access_control` rules (nothing to protect yet).

## Architecture / Approach

Standard Symfony Security recipe: entity-backed `UserProvider` keyed by email, `auto` password hasher, a `main` firewall with a provider but deliberately no authenticator yet (nothing to authenticate against until S-01 adds a login flow). The `User` entity is deliberately minimal — just what Security needs — leaving all patient-profile data to S-01, which is scoped separately in the roadmap.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Security bundle, User entity, and migration | Installed bundle, `security.yaml`, `User` entity/repository, migration applied to both DBs | Postgres reserved-keyword collision on `user` table name (mitigated: explicit `users` table name) |
| 2. Verification test | Kernel test proving hasher + roles wiring works; full quality gate green | A missed Security bundle deprecation warning failing the `failOnDeprecation` gate |

**Prerequisites:** none — first change to land on this repo.
**Estimated effort:** ~1 session, 2 phases.

## Open Risks & Assumptions

- Assumes `composer require symfony/security-bundle` resolves cleanly against `symfony/*: 7.4.*` with no version conflicts (standard Flex recipe, low risk).
- Assumes the container's `php` service and `database`/`database-test` services are already running (`docker compose up`) before migrations are applied — this plan does not start them (and explicitly avoids `./run-dev.sh`, which would rebuild containers).

## Success Criteria (Summary)

- `doctrine:schema:validate` passes and the `users` table exists in both dev and test databases.
- The kernel test proves a `User` can be persisted, its password verified through the real configured hasher, and it carries `ROLE_PATIENT` + `ROLE_USER`.
- Full quality gate (phpstan, php-cs-fixer, phpunit) passes with no new deprecations.
