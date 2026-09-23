---
name: fidelitopass-delivery-flow
description: "Use for FidelitoPass wave selection, work-item scope, board movement, and next-work decisions."
license: Apache-2.0
metadata:
  author: FidelitoPass
  version: "1.0"
---

## Purpose

Keep FidelitoPass delivery aligned with the current product wave while minimizing context.

This skill does not own product semantics, Laravel implementation rules, or repository-host-specific commands.

## Default context

Start with only:

1. the active work item;
2. affected source files;
3. nearby tests;
4. current repository state needed for the requested operation.

Do not read the complete `docs/` tree by default.

## Board semantics

Use:

`Backlog -> Active -> Review -> Verify -> Done`

Keep one primary product item Active.

Map these meanings to the configured board/repository provider without making project documentation depend on that provider.

## Work selection

Use rolling-wave delivery.

Read only the current section of `docs/delivery-plan.md` when selecting or loading work.

A work item should contain:

- one outcome;
- requirement IDs;
- scope;
- explicit exclusions when useful;
- Given-When-Then acceptance criteria;
- dependencies;
- material security/data/time risks.

Do not paste broad documentation into the work item.

## Lazy-loading route

| Need | Read |
|---|---|
| Select/load current or next wave | relevant section of `docs/delivery-plan.md` |
| Confirm MVP inclusion | `docs/product-scope.md` |
| Resolve a domain term/lifecycle | relevant section of `docs/conceptual-design.md` |
| Configure/evaluate points or Challenge progress | relevant section of `docs/challenge-model.md` |
| Resolve acceptance behaviour | relevant requirement in `docs/requirements.md` |
| Resolve Wallet copy/state | relevant section of `docs/wallet-presentation.md` |
| Resolve web UX/layout | relevant section of `docs/ui-ux-guidelines.md` |
| Resolve architecture boundary | relevant section of `docs/architecture/overview.md` |
| Resolve auth/token/rate-limit/logging risk | relevant section of `docs/architecture/security.md` |
| Resolve schema/time/concurrency | relevant section of `docs/development/database-standard.md` |
| Resolve Laravel project convention | relevant section of `docs/development/laravel-application-standard.md` |
| Resolve verification depth | relevant section of `docs/quality-strategy.md` |
| Revisit a durable decision | only the relevant ADR |

Do not follow document references automatically.

## Scope discipline

Prefer vertical product outcomes over isolated foundation work.

A technical prerequisite belongs inside the active product outcome unless it has independent value or blocks several immediate outcomes.

Do not create distant speculative work just because later waves exist.

## Documentation discipline

Update documentation only when the knowledge owned by that document changes.

Do not add:

- task/review identifiers;
- temporary branch names;
- revision hashes;
- source-control history;
- implementation-session narratives;
- tool usage narratives.

## Completion

A work item is complete when:

- acceptance criteria are satisfied;
- proportionate automated evidence passes;
- applicable authorization/data/time/concurrency risks are covered;
- integrated behaviour works;
- owned documentation is current when its knowledge changed;
- no unrelated scope was added.

## Output

Report concisely:

- current wave/board state;
- selected outcome and requirement IDs;
- material blocker/risk;
- next decision only when one is required.
