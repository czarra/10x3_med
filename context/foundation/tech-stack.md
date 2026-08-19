---
starter_id: symfony
package_manager: composer
project_name: dia-guide
hints:
  language_family: php
  team_size: solo
  deployment_target: railway
  ci_provider: github-actions
  ci_default_flow: auto-deploy-on-merge
  bootstrapper_confidence: best-effort
  path_taken: custom
  quality_override: true
  self_check_answers:
    typed: true
    from_official_starter: true
    conventions: true
    docs_current: true
    can_judge_agent: true
  has_auth: true
  has_payments: false
  has_realtime: false
  has_ai: false
  has_background_jobs: false
---

## Why this stack

Solo developer shipping DiaGuide, a small-scale web MVP in 3 after-hours weeks, with several years of hands-on experience in Symfony and Doctrine. The registry's recommended default for `(web, php)` is Laravel, but it was explicitly rejected at the outset in favor of the user's own framework fluency — for a tight solo timeline, familiarity outweighs the convenience of a registry-verified scaffold. Symfony has no card in this skill's registry, so `bootstrapper_confidence` is recorded as `best-effort` and `quality_override` is `true`: `/10x-bootstrapper` will not have a wired scaffold command, and the initial `composer create-project symfony/skeleton` (or `symfony new`) needs to be run manually. Auth is in scope (FR-001, FR-002) and PostgreSQL was named as a soft preference, both compatible with Symfony + Doctrine. Deployment targets Railway; CI runs on GitHub Actions with auto-deploy-on-merge. The five-point self-check came back clean across all points, so no additional Socratic nudge fired despite the missing registry card.
