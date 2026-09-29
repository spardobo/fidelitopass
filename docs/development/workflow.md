# FidelitoPass Development Workflow

This document defines product-delivery semantics and the GitHub Projects steps used to represent them, without prescribing internal implementation tooling.

## Delivery objective

Deliver dependency-ready **work items** with reviewable outcomes; prefer vertical scope. `docs/delivery-plan.md` is the canonical lightweight candidate plan, not a Scrum backlog or a fixed schedule. The product owner approves scoped work as a Backlog draft, then reviews the delivery PR and explicitly orders its merge. That order accepts the selected item outcome conditional on required CI passing for the exact resulting main commit; it does not accept an entire linked requirement. Start with the selected work item; read only its evidence, and consult dependencies or overlapping work when relevant. If no item is selected, make a bounded choice among relevant candidates rather than scanning the entire board. Refine only the selected item. Inspection does not activate Backlog work. Do not activate every planned item speculatively. Multiple items may be In Development concurrently; there is no numerical or implicit limit.

## Board semantics

```text
Backlog -> In Development -> In Review -> In Verification -> Done
             failed gate -> Blocked -> recorded recovery stage
```

| State | Meaning |
|---|---|
| Backlog | Upcoming scoped work; a Project draft item may remain unassigned without a repository issue. |
| In Development | Implementation underway; not exclusive to one item or session. |
| In Review | PR checks and owner review precede the owner's explicit merge order. |
| In Verification | The exact resulting main commit's required CI is being checked. |
| Blocked | A failed gate has an actionable reason/link and named recovery stage; pending checks alone are not failure. |
| Done | This item's agreed outcome and required exact-main CI evidence are satisfied. |

The board, not planning documents or requirement columns, owns work progress. Use one route for planned work and unplanned documentation, maintenance, or defects:

1. Inspect the selected item's scope and relevant dependencies or overlaps; if none is selected, choose from bounded candidates. Create **one GitHub Projects draft item in Backlog** for a new scoped outcome; it may have neither assignee nor repository issue. A documentation governance fix that repairs active authoritative instructions is valid new work without a mandatory `docs/delivery-plan.md` row or product requirement ID.
2. Obtain human approval for the scoped work while it remains a draft. On activation, use **Convert to issue → repository** on that same Project item; its distinct item identifier/ID is not the new repository issue number #N. Confirm there is one linked issue on the same Project item, without a duplicate card or body to synchronize. The issue form, if available, supports converted issue content; it is not a starting route.
3. Apply `status:approved` to the linked issue to reflect the earlier approval. Assign the person moving the item to In Development on the repository issue. The linked issue is the assignee source of truth; do not independently assign the Project item or invent a separate Project assignee requirement. Do not assume GitHub dynamically assigns the moving actor.
4. Only after confirmed conversion, label, and issue assignment, set the same item to In Development. Trust successful `gh` mutation output for the entity and result it actually confirms; do not issue an immediate duplicate GET. Query only missing or uncertain results, potentially stale critical state before merge, or asynchronous main CI. If an outcome remains uncertain or fails, stop and do not claim activation. Then use PRs referencing the linked issue. Obtain separate authorization before repository-host actions.

Keep issue history when scope changes; do not delete or rewrite historical cards. An issue can have one or more linked PRs (Issue ↔ PR 1:N): intermediate PRs use `Refs`, and the final PR's `Closes #N` closes repository issue #N, not the Project item. Reconcile the Project item separately: issue closure never establishes Done or item acceptance. Activated work never bypasses its issue via a direct PR. The owner's explicit order, after PR review, authorizes the delivering agent to guard and squash merge that PR under existing branch rules; it does not authorize autonomous GitHub Actions or unrelated publishing. Coordinate ownership and dependencies per session before concurrent work: separate sessions may implement different In Development items on their own branches or worktrees, including related items when overlap is explicitly coordinated. Concurrency does not require independence.

## Work item shape and traceability

A useful item records its outcome, relevant stable requirement IDs and selected criteria, scope/exclusions, checkable Given-When-Then outcome, dependencies, and material security/data/time risks. One item can contribute to many requirements, and one requirement can be served by many items (Requirement ↔ Item N:N). Cross-cutting obligations may recur. Link rather than duplicate the canonical definitions in `docs/requirements.md`; neither a PR nor issue Done proves an entire requirement accepted. Compare all of a requirement's criteria against integrated code, tests, and demonstrations before claiming product acceptance; item-level CI acceptance does not establish whole-requirement acceptance. Keep evidence linked to the issue/PR where applicable, not a requirement status table or per-card commit pointers in the plan.

Prefer small demonstrable outcomes: public entry separate from Business auth/profile; Promotion draft/configuration separate from preview/publication and edit/cancel; acquisition, validation, points, Reward, and Wallet changes scoped to their dependencies. Only when a work item is overly broad, split it into vertical delivery slices; each slice becomes its own work item if activated. Supporting security, data, UX, and quality work belongs with affected outcomes or a separately justified integration item. Avoid isolated refactoring/tooling/documentation items unless they unblock a product outcome or repair active authoritative instructions. Unplanned documentation/maintenance work needs no mandatory `docs/delivery-plan.md` row or product requirement ID and follows the same draft-first approval route. An unplanned bug needs no mandatory `docs/delivery-plan.md` row: if it falls within an open issue's agreed scope, resolve it there; if a previously completed item has a new defect or unmet criterion, leave it Done and create a new Backlog draft linked to its predecessor. A failed post-merge CI gate for the item still in verification is different: reopen the same issue and correct it through a new PR.

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
- The owner reviewed the PR and explicitly ordered its merge for this selected outcome, conditional on required CI passing on the exact resulting main commit.
- Owned documentation is updated only if its knowledge changed.
- No unrelated scope was added.

In Review, obtain required PR checks and owner review, then an explicit owner merge order. Immediately before the agent squash merges, read back the latest PR head, required checks, unresolved review threads, mergeability and issue link; stop if evidence is stale, failing, unresolved or unknown. A confirmed pre-merge failure moves the item to Blocked with a reason/link and recovery stage In Review (or In Development if implementation is needed). Fix the existing PR where possible, rerun checks and obtain renewed owner review and merge order. Pending checks remain in their current stage, not Blocked.

After merge, move to In Verification and read back required CI for the **exact resulting main commit**, recording the item, commit and CI evidence. On green, the agent moves the same Project item to Done without a second owner consent; the earlier merge order accepted only this selected outcome conditionally. On failed main CI, move to Blocked with the failing run link/reason and recovery stage In Verification; reopen the same issue, create a corrective PR with `Closes #N`, and repeat PR checks, owner review/order, guarded squash merge and exact-main CI. Leave the original merged PR in history; never revert automatically. Unknown or pending main checks keep the item In Verification, never Done. A passing PR check or issue closure alone is insufficient, and Done never implies whole-requirement acceptance.
