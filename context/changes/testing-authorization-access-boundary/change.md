---
change_id: testing-authorization-access-boundary
title: Authorization & access-boundary hardening — integration tests
status: implementing
created: 2026-08-28
updated: 2026-08-28
archived_at: null
---

## Notes

Open a change folder for rollout Phase 1 of context/foundation/test-plan.md: "Authorization & access-boundary hardening".
Risks covered: #1 (cross-account access to another patient's diary data), #5 (unauthenticated/session-boundary gap).
Test types planned: integration.
Risk response intent:
- #1: prove no patient can view/edit/delete/export another patient's diary entry through any DiaryController action; must challenge that a passing voter test covers every entry point; ground which actions call the ownership check and how.
- #5: prove every patient-only route rejects unauthenticated access and login/registration negative cases fail safely; must challenge that happy-path security tests already cover this.
After creating the folder, follow the downstream continuation rule (suggest /10x-research next).
