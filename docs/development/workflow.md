# FidelitoPass Development Workflow

This document defines product-delivery semantics and the GitHub Projects steps used to represent them, without prescribing internal implementation tooling.

## Delivery objective

Deliver dependency-ready **work items** with reviewable outcomes; prefer vertical scope. `docs/delivery-plan.md` is the canonical lightweight candidate plan, not a Scrum backlog or a fixed schedule. The product owner chooses scope and acceptance with developer and QA evidence. Start with the board's Active, Review, and Verify work and its known dependencies; select a nearest ready candidate that closes a demonstrated gap, coordinate overlaps, and refine only that item. Do not activate every planned item speculatively. Multiple items may be Active concurrently; there is no numerical or implicit limit on Active items.

## Board semantics

```text
Backlog -> Active -> Review -> Verify -> Done
```

| State | Meaning |
|---|---|
| Backlog | Upcoming scoped work; a Project draft item may remain unassigned without a repository issue. |
| Active | Implementation currently underway; not exclusive to one item or session. |
| Review | Implementation and focused evidence are ready for review. |
| Verify | Integrated behaviour is checked in real application context. |
| Done | This item's agreed outcome and required evidence are satisfied. |

The board, not planning documents or requirement columns, owns work progress. Use one route for planned work and unplanned documentation, maintenance, or defects:

1. Inspect current board work, dependencies, ownership, and scope. Create **one GitHub Projects draft item in Backlog** for the new scoped outcome; it may have neither assignee nor repository issue. A documentation governance fix that repairs active authoritative instructions is valid new work without a mandatory `docs/delivery-plan.md` row or product requirement ID.
2. Obtain human approval for the scoped work while it remains a draft. On activation, use **Convert to issue → repository** on that same Project item; its distinct item identifier/ID is not the new repository issue number #N. Verify there is exactly one linked issue and one Project item, without a duplicate card or body to synchronize. The issue form, if available, supports converted issue content; it is not a starting route.
3. Apply `status:approved` to the linked issue to reflect the earlier approval. Assign the person moving the item to Active on the repository issue, then read back both the issue assignee and the Project **Assignees** display to verify that actor appears in both. The linked issue is the assignee source of truth; GitHub Projects reflects its assignees automatically, so do not assign the Project item independently. Do not assume GitHub dynamically assigns the moving actor.
4. Only after successful conversion, label, and both assignment readbacks, set the same item to Active. If any conversion, assignment, status, or readback fails, stop and do not claim activation. Then use PRs referencing the linked issue. Obtain separate authorization before repository-host actions.

Keep issue history when scope changes; do not delete or rewrite historical cards. An issue can have one or more linked PRs (Issue ↔ PR 1:N): intermediate PRs use `Refs`, and the final PR's `Closes #N` closes repository issue #N, not the Project item. The Project item separately moves to Done under board policy or configured built-in GitHub automation on issue closure; neither this transition nor item acceptance is guaranteed by issue closure alone. Activated work never bypasses its issue via a direct PR. This describes linkage, not permission to merge or publish. Coordinate ownership and dependencies per session before concurrent work: separate sessions may implement different Active items on their own branches or worktrees, including related items when overlap is explicitly coordinated. Concurrency does not require independence.

## Work item shape and traceability

A useful item records its outcome, relevant stable requirement IDs and selected criteria, scope/exclusions, checkable Given-When-Then outcome, dependencies, and material security/data/time risks. One item can contribute to many requirements, and one requirement can be served by many items (Requirement ↔ Item N:N). Cross-cutting obligations may recur. Link rather than duplicate the canonical definitions in `docs/requirements.md`; neither a PR nor issue Done proves an entire requirement accepted. Compare all of a requirement's criteria against integrated code, tests, and demonstrations before claiming product acceptance; the product owner decides acceptance with QA/developer evidence. Keep evidence linked to the issue/PR where applicable, not a requirement status table or per-card commit pointers in the plan.

Prefer small demonstrable outcomes: public entry separate from Business auth/profile; Promotion draft/configuration separate from preview/publication and edit/cancel; acquisition, validation, points, Reward, and Wallet changes scoped to their dependencies. Only when a work item is overly broad, split it into vertical delivery slices; each slice becomes its own work item if activated. Supporting security, data, UX, and quality work belongs with affected outcomes or a separately justified integration item. Avoid isolated refactoring/tooling/documentation items unless they unblock a product outcome or repair active authoritative instructions. Unplanned documentation/maintenance work needs no mandatory `docs/delivery-plan.md` row or product requirement ID and follows the same draft-first approval route. An unplanned bug needs no mandatory `docs/delivery-plan.md` row: if it falls within an open issue's agreed scope, resolve it there; if that issue is closed, leave its Project item Done and create a new Backlog draft item, then follow the same approval/conversion route for a new issue linked to the predecessor issue.

## Documentation routing

Load only the source needed for the current decision.

| Question | Read |
|---|---|
| What is the domain meaning? | relevant section of `docs/conceptual-design.md` |
| Is it in MVP? | `docs/product-scope.md` |
| How do points or Promotion progress work? | relevant section of `docs/promotion-model.md` and ADR-003 when ownership/freeze matters |
| What behaviour must pass? | relevant requirement in `docs/requirements.md` |
| What must Wallet display? | relevant state in `docs/wallet-presentation.md` |
| What should the page look/behave like? | relevant section of `docs/ui-ux-guidelines.md` |
| Does it change system boundaries? | relevant section of `docs/architecture/overview.md` |
| Does it touch auth/tokens/rate limits/logging? | relevant section of `docs/architecture/security.md` |
| Does it change schema/time/indexes? | relevant section of `docs/development/database-standard.md` |
| Does it need a Laravel project convention? | relevant section of `docs/development/laravel-application-standard.md` |
| What tests/evidence are appropriate? | relevant section of `docs/quality-strategy.md` |
| What comes next? | Kanban dependencies and relevant candidate work item in `docs/delivery-plan.md` |
| Is a durable decision being challenged? | only the relevant ADR |

Do not follow links/references automatically.

## Documentation maintenance

Update a document only when the knowledge it owns changes. `docs/requirements.md` owns current behaviour and its original stable IDs, not delivery status. Refine criteria under the same ID when clarifying the same obligation; if product scope changes or a distinct obligation is introduced, assign a new ID and record its relationship to predecessor IDs explicitly in the requirements source. Do not repurpose old IDs or delete issue history. Reassess candidate work items and open issues when that contract changes; historical issues/PRs remain historical evidence, not retroactive definitions.

Do not add temporary task identifiers, branch/revision history, implementation-session narratives, tool-consumption notes, or requirement status columns to normative docs. Documentation describes FidelitoPass, not how a particular change was produced.

## Done criteria

A product work item is Done when:

- Its agreed Given-When-Then outcome is satisfied.
- Proportionate automated evidence passes.
- Applicable authorization/data/time/concurrency risks are covered.
- Integrated behaviour works and the product owner accepts this item with QA/developer evidence.
- Owned documentation is updated only if its knowledge changed.
- No unrelated scope was added.

This item-level decision never silently marks every linked requirement complete.
