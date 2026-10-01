---
name: fidelitopass-delivery-planning
description: "Trigger: FidelitoPass work item selection, Kanban board movement, work-item scope, next-work decisions. Route dependency-ready work through bounded requirements and issue/PR evidence."
license: Apache-2.0
metadata:
  author: FidelitoPass
  version: "1.0"
---

## Activation Contract

Use for delivery selection, scoping, board transitions and completion. Do not use for product semantics, Laravel coding rules or provider-specific commands.

## Hard Rules

- Load the selected item and relevant dependencies/overlaps, or bounded dependency-ready candidates. Inspection does not activate Backlog. Unplanned documentation, maintenance and defects need no plan row or product ID.
- Select bounded requirement criteria, not every criterion of linked requirements. Requirement ↔ item is N:N; item completion never accepts a whole requirement.
- For UI-backed selection, inspect the page/menu in `docs/ui-ux-guidelines.md`, parent in `docs/conceptual-design.md`, persistence in `docs/development/database-standard.md` and current migrations. Never infer a table/FK from a page name; conceptual parent is not physical FK.
- Board state owns planned progress, not requirements or issue closure. Issue assignees own responsibility on both routes. Preserve history; never invent transitions or issue-only board states.
- Require human scope approval before activation and authorization before host actions. Require owner review and explicit merge order after required PR checks; guard current PR identity/head, policy checks, unresolved threads and mergeability before squash merge.
- Completion requires agreed outcome and green required CI on the exact resulting main commit, without second consent after that merge order.
- Load `references/delivery-details.md` before activation, host actions, recovery or branch retirement for identity, labels/assignment, mutation evidence and cleanup procedures.

## Decision Gates

| Situation | Action |
| --- | --- |
| UI/domain/schema disagree | Prioritize missing parent/setup; ask before activation only if the intended relationship is ambiguous. |
| Acceptance uncertain | Load relevant owner section and canonical requirement entry; select criteria. |
| New/expanded product or roadmap increment | One Backlog Project draft → human scope approval → convert the same draft in place to one issue → reference activation gates → In Development. |
| Bounded minor change, hotfix, small bug or explicit owner exception | Approved issue with outcome/exclusions/dependencies/risks → issue assignment → branch/PR; no Project item. Never use for a new/expanded deliverable. |
| Defect in open issue scope | Keep fix/evidence there. |
| New defect after delivery | Keep predecessor Done; new approved linked issue. New/expanded deliverables instead need a Backlog draft. |
| Product obligation changes | New requirement ID linked to predecessor; never repurpose IDs. |
| Review-ready planned PR | Move In Development → In Review; after guarded integration move to In Verification while exact-main CI runs. |
| Failed gate | Planned → Blocked with reason/link/recovery stage; issue-only → issue/PR recovery evidence. Follow reference recovery; renew owner review/order. |
| Failed exact-main CI | Reopen same issue; corrective `Closes #N` PR; repeat guarded merge and exact-main gate. Preserve merged history; never revert automatically. |
| Pending/unknown checks | After integration keep planned In Verification, issue-only incomplete; pending is not Blocked. |
| Completion | Exact-main gate above → planned Done or issue-only complete. Issue closure alone is insufficient. |

## Execution Steps

1. Bound selection, criteria/IDs, exclusions, risks and checkable outcome.
2. Apply the reference's selected route and evidence procedures.
3. Advance planned board state only on observed evidence; report remaining criteria and issue-only failures on issue/PR.

## Output Contract

Report planned board state or issue-only route, outcome/criteria/IDs, loaded sections, issue/PR identity and observed gates, gaps/risks and any required decision. For UI-backed work include page/menu, conceptual parent and current schema evidence.

## References

- `references/delivery-details.md` — bounded routing, activation and completion decisions.
- `docs/delivery-plan.md` — candidate work items.
- `docs/development/workflow.md` — product delivery authority.
