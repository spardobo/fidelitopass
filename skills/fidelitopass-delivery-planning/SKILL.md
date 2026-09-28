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

- Start with Kanban Active, Review and Verify work and dependencies; after checking unfinished work and overlapping ownership, prefer a dependency-ready candidate from `docs/delivery-plan.md` when relevant, or scope unplanned documentation, maintenance, or defects as one new Project draft item without requiring a plan row. Candidates are not scheduled commitments.
- Read only the relevant work item, affected source/tests and bounded entry for applicable original IDs in `docs/requirements.md`; do not reload the entire requirements master or follow references automatically. Requirement ↔ item is N:N; selected criteria, not every linked criterion, define the item.
- Board state owns progress, not requirement status or issue closure. Never reopen a completed item just because a linked requirement has remaining criteria. Do not rewrite historical cards.
- For new planned or unplanned work not already represented on the board, inspect board and scope, create exactly one Project draft item in Backlog, then obtain human approval of its scope. A draft may lack assignee and repository issue; documentation governance repairs can be standalone without a plan row or product ID. Convert that same draft in place to one repository issue before Active; the Project item retains its distinct identifier/ID and must not become a duplicate card. Apply `status:approved` to the issue to reflect prior approval. Assign the moving actor on the issue and read back both issue assignees and Project Assignees to confirm that actor appears in each; the issue is the source of truth and the Project automatically reflects linked issue assignees. Do not assign the Project separately or promise automatic moving-actor assignment. Only then set the item Active; if conversion, label, assignment, status, or readback fails, stop without claiming activation. An issue form, if present, supports converted content rather than starting a separate issue. Issue ↔ PR is 1:N; intermediate PRs reference the repository issue, and the final PR's `Closes #N` closes repository issue #N after accepted item evidence, not the Project item. Move the Project item independently to Done under board policy or configured built-in GitHub automation on issue closure; closure alone guarantees neither this transition nor acceptance. No direct PR bypass for activated work. Obtain authorization before repository-host actions.

## Decision Gates

| Situation | Action |
| --- | --- |
| Choosing work | Inspect Active/Review/Verify evidence and dependencies, then prefer a ready plan candidate when relevant or scope unplanned documentation, maintenance, or defects in one new Backlog Project draft item without a required plan row; avoid duplicating ownership and refine scope with the product owner before activation. |
| Product or acceptance uncertain | Read only the relevant product document section and bounded canonical requirement entry; record selected criteria in the item issue. |
| Defect in an open issue's agreed scope | Keep the fix and evidence in that issue; no mandatory delivery-plan row for an unplanned bug. |
| Defect or unmet criteria after issue closure | Leave the predecessor Project item Done; create a new Backlog draft item, approve its scope, then convert it to a new issue linked to the predecessor issue. |
| Product obligation genuinely changes | Propose a new requirement ID explicitly linked to its predecessor in the requirements source; do not repurpose the old ID or mutate historical cards. |
| Marking Done | Require agreed item outcome, proportionate checks, integrated evidence and product-owner acceptance with QA/developer evidence in issue/PR; do not infer whole-requirement completion or treat documentation work as proof of REQ-QLT-001. |

## Execution Steps

1. Identify board state and dependencies, choose a ready work item, and define selected IDs/criteria, exclusions, risks and checkable outcome.
2. Use `references/delivery-details.md` to route bounded sources and record evidence in the activated issue and linked PRs.
3. Advance board state only on observed evidence; report blockers and remaining requirement criteria without reopening completed work.

## Output Contract

Report board state, selected work item and criteria/IDs, issue/PR evidence and checks, remaining gaps, blocker/risk and next decision only if required.

## References

- `references/delivery-details.md` — bounded routing and completion decisions.
- `docs/delivery-plan.md` — candidate work items.
- `docs/development/workflow.md` — product delivery authority.
