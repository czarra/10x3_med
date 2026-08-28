---
change_id: testing-recommendation-algorithm-edge-cases
title: Recommendation-algorithm edge-case test coverage
status: implemented
created: 2026-08-28
updated: 2026-08-28
archived_at: null
---

## Notes

Open a change folder for rollout Phase 2 of context/foundation/test-plan.md: "Recommendation-algorithm edge-case coverage".
Risks covered: #2 — Recommendation algorithms (insulin/WW ratio suggestion, base-dose adjustment, hypoglycemia warning) produce an incorrect medical suggestion on boundary/incomplete data, and the patient acts on it (Impact: High, Likelihood: High).
Test types planned: unit.
Risk response intent:
- #2: prove a suggestion/warning never fires when input doesn't meet the PRD's stated minimum (e.g. fewer than 3 paired entries), and that the adjustment direction matches the PRD's stated rule (glycemia rose → raise ratio; fell → lower it); challenge the assumption that today's threshold constants are correct just because a happy-path test asserts today's output — the oracle must come from the PRD rule, not the service's own math; avoid the oracle problem (copying expected output from the implementation instead of the PRD's rule).
