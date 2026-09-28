---
name: fidelitopass-delivery-flow
description: "Trigger: FidelitoPass wave selection, Kanban board movement, work-item scope, next-work decisions. Route delivery by dependencies and focused documentation."
license: Apache-2.0
metadata:
  author: FidelitoPass
  version: "1.0"
---

## Activation Contract

Use for delivery selection, scoping, board transitions and completion. Do not use for product semantics, Laravel coding rules or provider-specific commands.

## Hard Rules

- Select dependency-ready vertical outcomes in rolling waves; never assign requirement IDs permanently to numbered waves. Preserve all 35 IDs, including historical CHL IDs for Promotion.
- Load only the relevant work item, source, nearby tests and document **section** needed for the decision; do not traverse references automatically.
- Multiple related items may be Active concurrently when ownership and dependencies are coordinated. Use `Backlog -> Active -> Review -> Verify -> Done`.
- Follow approved issue → own branch → one coherent PR per outcome, subject to human authorization.

## Decision Gates

| Situation | Action |
| --- | --- |
| Choosing current work | Read relevant `docs/delivery-plan.md` section; regroup by pending dependencies. |
| Product, points or acceptance uncertain | Read the relevant section of `docs/conceptual-design.md`, `docs/promotion-model.md` or `docs/requirements.md`, respectively. |
| Technical prerequisite | Include in Active outcome unless independently valuable or blocking several immediate outcomes. |
| Marking Done | Require acceptance, proportionate evidence, integrated behaviour and current owned documentation. |

## Execution Steps

1. Identify current dependency-ready outcome and its requirement IDs; specify scope, exclusions, Given-When-Then criteria and material risks.
2. Route only necessary document sections using `references/delivery-details.md`; coordinate overlapping work before concurrent execution.
3. Advance board state on observed evidence, not intention; report blockers without inventing distant work.

## Output Contract

Report current wave/board state, outcome and IDs, actual checks, blocker/risk, and next decision only if required.

## References

- `references/delivery-details.md` — board meaning, routing and completion details.
- `docs/development/workflow.md` — product delivery authority.
