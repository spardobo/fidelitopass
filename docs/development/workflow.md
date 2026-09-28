# FidelitoPass Development Workflow

This document defines product-delivery semantics. It intentionally stays independent from repository hosting and from the internal implementation method used by development tools.

## Delivery objective

Deliver dependency-ready vertical product outcomes while keeping each work item's implementation context focused. A rolling wave is a set of pending related requirements, not a fixed requirement-to-wave schedule. Select requirements by current dependencies and group those jointly addressable into Kanban work items; regroup candidates as dependencies change. Multiple work items may be Active concurrently; there is no numerical or implicit limit on Active items.

Use rolling-wave planning:

- Detail only the current wave's work items.
- Keep the next wave coarse until it becomes relevant.
- Avoid speculative implementation planning for distant work; the delivery plan's outcome groups are illustrative, not permanent allocations.

## Board semantics

```text
Backlog -> Active -> Review -> Verify -> Done
```

| State | Meaning |
|---|---|
| Backlog | Dependency-ready or upcoming scoped work. |
| Active | Implementation currently underway; this state is not exclusive to one item or session. |
| Review | Implementation and focused evidence are ready for review. |
| Verify | Integrated behaviour is checked in real application context. |
| Done | Acceptance criteria and required evidence are satisfied. |

The normal route for an item is an approved issue, its own branch, and one coherent pull request for the outcome—not one PR per commit. Coordinate ownership and dependencies per session before concurrent work: separate sessions may implement different Active items on their own branches or worktrees, including related items when overlap is explicitly coordinated. Concurrency does not require all work items to be independent.

## Work item shape

A useful work item contains only:

- Outcome.
- Requirement IDs.
- Scope.
- Exclusions when material.
- Given-When-Then acceptance criteria.
- Dependencies.
- Material security/data/time risks.

Do not copy whole documents into the work item.

## Vertical slicing

Prefer outcomes that make the product more complete:

- Business setup + landing entry.
- Promotion creation/publication.
- Permanent QR + Wallet issuance.
- Visit validation.
- One Promotion evaluator.
- Reward unlock/redemption.

Avoid isolated refactoring/tooling/documentation work unless it blocks an Active product outcome.

## Documentation routing

Load only the source needed for the current decision.

| Question | Read |
|---|---|
| What is the domain meaning? | relevant section of `docs/conceptual-design.md` |
| Is it in MVP? | `docs/product-scope.md` |
| How do points or Promotion progress work? | relevant section of `docs/promotion-model.md` |
| What behaviour must pass? | relevant requirement in `docs/requirements.md` |
| What must Wallet display? | relevant state in `docs/wallet-presentation.md` |
| What should the page look/behave like? | relevant section of `docs/ui-ux-guidelines.md` |
| Does it change system boundaries? | relevant section of `docs/architecture/overview.md` |
| Does it touch auth/tokens/rate limits/logging? | relevant section of `docs/architecture/security.md` |
| Does it change schema/time/indexes? | relevant section of `docs/development/database-standard.md` |
| Does it need a Laravel project convention? | relevant section of `docs/development/laravel-application-standard.md` |
| What tests/evidence are appropriate? | relevant section of `docs/quality-strategy.md` |
| What comes next? | current relevant candidate section of `docs/delivery-plan.md`, selected by pending dependencies |
| Is a durable decision being challenged? | only the relevant ADR |

Do not follow links/references automatically.

## Documentation maintenance

Update a document only when the knowledge it owns changes.

Do not add:

- Temporary task identifiers.
- Branch/revision history.
- Implementation-session narratives.
- Tool-consumption notes.

Documentation describes FidelitoPass, not how a particular change was produced.

## Done criteria

A product work item is Done when:

- Given-When-Then acceptance criteria are satisfied.
- Proportionate automated evidence passes.
- Applicable authorization/data/time/concurrency risks are covered.
- Integrated behaviour works.
- Owned documentation is updated only if its knowledge changed.
- No unrelated scope was added.
