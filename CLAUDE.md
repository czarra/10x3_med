<!-- BEGIN @przeprogramowani/10x-cli -->

## 10xDevs AI Toolkit - Module 3, Lesson 4 (E2E Tests)

**For E2E tests, use the `/10x-e2e` skill.** It is the single source of truth
for the workflow — risk → seed test + rules → generate → review against the five
anti-patterns → re-prompt → verify. The skill's `references/` carry the full
rules, anti-patterns, seed pattern, and prompt-template.

A few hard rules that hold even before you invoke the skill:

- **Locators:** `getByRole` / `getByLabel` / `getByText` first; `getByTestId`
  only when accessibility attributes are ambiguous. Never CSS selectors, XPath,
  or DOM structure.
- **Never `page.waitForTimeout()`.** Wait for state: `toBeVisible()`,
  `waitForURL()`, `waitForResponse()`.
- **Test independence + cleanup.** Each test runs standalone — its own setup,
  action, assertion, and cleanup; unique ids (timestamp suffix) so parallel runs
  and re-runs don't collide.

Two boundaries to keep straight:

- **DOM (snapshot) is the default.** Vision (`--caps=vision`) is a supplement for
  visual-only risks (layout, z-index, animation); for pixel regression prefer
  deterministic tools (`toMatchSnapshot`, Argos, Lost Pixel). VLM model
  selection/cost is a debugging topic (Lesson 5), not testing.
- **Healer helps on selectors, harms on logic.** A changed selector → healer
  re-finds it (route through PR review). A changed business behavior → healer
  masks the bug; that failing-test-to-fix case is Lesson 5.

<!-- END @przeprogramowani/10x-cli -->

## Project notes

For general repo conventions (build/test/lint commands, structure, coding
style), see `AGENTS.md` — this section only covers things Claude would
otherwise get wrong:

- **PHP runtime is 8.5, not 8.2.** `php/Dockerfile` builds on `php:8.5-apache`
  even though `composer.json` only requires `>=8.2` — don't assume 8.2-only
  syntax constraints when writing PHP.
- **`.env`, `.env.dev`, and `.env.test` are git-tracked** (only `.env.local` is
  gitignored), and `.env` already contains real-looking Postgres credentials.
  Never add new secrets to any tracked `.env*` file — only `.env.local`.
- **`run-dev.sh` is destructive, not a quick check.** It always runs
  `docker compose down --remove-orphans` then rebuilds before migrating dev
  and test databases. Don't run it mid-session just to "check something" —
  it tears down running containers first.
- **`phpstan.neon` only analyzes `src/`** at level 5. Type errors in `tests/`
  or `config/` won't surface via `vendor/bin/phpstan analyse`.
- **The E2E section above assumes Playwright tooling that isn't scaffolded
  yet** — there's no `package.json` or Playwright config in this repo. Set
  that up before invoking `/10x-e2e`.


  This file provides guidance to AI Agents when working with code in this repository:
  @AGENTS.md
