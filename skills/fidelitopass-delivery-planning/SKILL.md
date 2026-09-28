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

- Inspect Kanban Active, Review and Verify work, dependencies and ownership before choosing a candidate. Prefer dependency-ready planned work when relevant; unplanned documentation, maintenance and defects need no plan row or product ID. Candidates are not commitments.
- Read only bounded applicable requirement entries and relevant sources; selected criteria, not all criteria of linked requirements, define the item. Requirement ↔ item is N:N. Do not infer whole-requirement acceptance from item completion.
- For UI-backed selection, inspect the bounded page/menu entry in `docs/ui-ux-guidelines.md`, parent relationship in `docs/conceptual-design.md`, intended persistence in `docs/development/database-standard.md`, and relevant current migrations. Distinguish conceptual parent from physical foreign key; never infer an entity, table or FK from a page name.
- Board state owns progress, not requirement status or issue closure. Do not reopen completed items or rewrite historical cards. Follow `references/delivery-details.md` for draft-first activation, issue/Project identity, assignment readbacks, PR evidence and Done. Obtain authorization before repository-host actions.

## Decision Gates

| Situation | Action |
| --- | --- |
| Choosing work | Check existing board work and ownership; choose a dependency-ready plan candidate or scope one unplanned Backlog draft without duplicating work. |
| UI, domain and implemented schema disagree | Choose the missing parent/setup prerequisite first; if the intended relationship is ambiguous, ask one focused product question before activation. Record page/menu, conceptual parent and migration evidence. |
| Acceptance uncertain | Read only the relevant product section and bounded canonical requirement entry; select criteria in the item. |
| New work not represented on board | Create one Project draft in Backlog, get human scope approval, then convert that same draft in place to one issue before Active; follow reference activation checks. |
| Defect in open issue scope | Keep fix and evidence in that issue. |
| Defect or unmet criteria after closure | Keep predecessor Done; scope and approve a new Backlog draft linked to predecessor, then convert to a new issue. |
| Product obligation changes | Propose a new requirement ID linked to its predecessor; do not repurpose the old ID. |
| Marking Done | Require agreed item outcome, proportionate checks, integrated evidence and product-owner acceptance with QA/developer evidence; do not infer whole-requirement completion. |

## Execution Steps

1. Inspect board evidence and dependencies; select one item with criteria/IDs, exclusions, risks and a checkable outcome.
2. Use `references/delivery-details.md` for bounded source routing, draft activation and issue/PR evidence.
3. Advance board state only on observed evidence; report blockers and remaining requirement criteria.

## Output Contract

Report board state, selected item and criteria/IDs, issue/PR evidence and checks, remaining gaps, risks/blockers and next decision only if required. For UI-backed work, state page/menu entry, conceptual parent and whether its schema exists today.

## References

- `references/delivery-details.md` — bounded routing, activation and completion decisions.
- `docs/delivery-plan.md` — candidate work items.
- `docs/development/workflow.md` — product delivery authority.
