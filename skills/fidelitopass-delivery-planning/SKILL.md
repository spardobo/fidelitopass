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

- Read only the selected work item first; consult dependencies and overlapping work when relevant. If none is selected, choose from bounded candidates rather than scanning the whole board; inspection does not activate Backlog work. Prefer dependency-ready planned work when relevant; unplanned documentation, maintenance and defects need no plan row or product ID. Candidates are not commitments.
- Read only bounded applicable requirement entries and relevant sources; selected criteria, not all criteria of linked requirements, define the item. Requirement ↔ item is N:N. Do not infer whole-requirement acceptance from item completion.
- For UI-backed selection, inspect the bounded page/menu entry in `docs/ui-ux-guidelines.md`, parent relationship in `docs/conceptual-design.md`, intended persistence in `docs/development/database-standard.md`, and relevant current migrations. Distinguish conceptual parent from physical foreign key; never infer an entity, table or FK from a page name.
- Board state owns planned deliverable progress, not requirement status or issue closure. Bounded minor changes, hotfixes, small bugs and explicitly owner-approved exceptions use an approved issue → branch → PR without a Project item; never use this route for a new or expanded product/roadmap deliverable increment. Issue assignees are the source of truth on both routes. Record issue-only failed gates and recovery on the issue/PR, not in invented board states. Do not reopen completed items or invent historical transitions. For planned work, move In Development → In Review for a review-ready PR; after required PR checks and owner review, require an explicit owner merge order. Guard the latest PR head, checks and unresolved threads before agent squash merge; move to In Verification after integration, and to Done only on successful required CI for the exact resulting main commit, without second consent. For planned work, failed gates move to Blocked with reason/link and recovery stage, never pending checks. Follow `references/delivery-details.md` for draft-first activation, issue/Project identity, PR readiness and safe post-merge branch retirement. Obtain authorization before repository-host actions.

## Decision Gates

| Situation | Action |
| --- | --- |
| Choosing work | Read the selected item and relevant dependencies/overlaps; if none is selected, choose among bounded candidates without duplicating work. |
| UI, domain and implemented schema disagree | Choose the missing parent/setup prerequisite first; if the intended relationship is ambiguous, ask one focused product question before activation. Record page/menu, conceptual parent and migration evidence. |
| Acceptance uncertain | Read only the relevant product section and bounded canonical requirement entry; select criteria in the item. |
| New planned product/roadmap deliverable increment | Create one Project draft in Backlog, get human scope approval, then convert that same draft in place to one issue before In Development; follow reference activation checks. |
| Bounded minor change, hotfix, small bug or owner-approved exception | Scope outcome, exclusions, dependencies and risks in a new approved issue; assign its responsible actor, then branch and PR without a Project item. If it is a new or expanded deliverable, use the draft-first route instead. |
| Defect in open issue scope | Keep fix and evidence in that issue. |
| New defect after delivery | Keep predecessor Done; open a new approved issue linked to predecessor for a bounded bug, without rewriting Done history. New or expanded deliverables require a new Backlog draft and conversion. |
| Product obligation changes | Propose a new requirement ID linked to its predecessor; do not repurpose the old ID. |
| Failed pre-merge gate | For planned work move to Blocked; for issue-only work record blocked cause and recovery on issue/PR. Preserve actionable evidence; fix the existing PR where possible, rerun checks and obtain renewed owner review and merge order. |
| Failed exact-main CI | For planned work move to Blocked; for issue-only work record failure and recovery on issue/PR. Preserve run link; reopen the same issue, use a corrective PR with `Closes #N`, and repeat owner review, guarded merge and exact-main CI. Never rewrite the merged PR or revert automatically. |
| Marking completion | Require agreed outcome and green required CI on the exact resulting main commit; no second owner consent after explicit PR merge order. Only planned work moves to Project Done; pending/unknown checks do not establish completion. Issue closure is insufficient; never infer whole-requirement completion. |

## Execution Steps

1. Read the selected item and relevant dependencies/overlaps, or make a bounded selection; define criteria/IDs, exclusions, risks and a checkable outcome.
2. Use `references/delivery-details.md` for bounded source routing, planned draft activation or the issue-only exception, and issue/PR evidence.
3. Advance board state only for planned work on observed evidence; report issue-only gate failures on issue/PR and remaining requirement criteria.

## Output Contract

Report board state for planned work only; otherwise report the issue-only route. Include selected outcome and criteria/IDs, issue/PR evidence and checks, remaining gaps, risks/blockers and next decision only if required. For UI-backed work, state page/menu entry, conceptual parent and whether its schema exists today.

## References

- `references/delivery-details.md` — bounded routing, activation and completion decisions.
- `docs/delivery-plan.md` — candidate work items.
- `docs/development/workflow.md` — product delivery authority.
