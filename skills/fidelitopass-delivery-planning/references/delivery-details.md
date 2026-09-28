# Delivery detail

Start with the relevant work item, affected source, nearby tests and repository state. Do not read the entire docs tree or follow references automatically.

Board: `Backlog -> Active -> Review -> Verify -> Done`. Active is not exclusive: multiple related items can run concurrently on separate branches/worktrees when file ownership and dependencies are coordinated. Map states to the configured provider without embedding provider commands in project docs.

Select dependency-ready requirements into a rolling wave; group jointly addressable outcomes, not fixed requirement-to-wave allocations. Retain all 35 requirement IDs, including historical `REQ-CHL-001`–`005`; CHL does not rename the Promotion product. Detail the current wave only; keep later waves coarse. Each work item names one outcome, IDs, scope, useful exclusions, Given-When-Then acceptance, dependencies and material security/data/time risks. Avoid broad copied documentation and distant speculative items. Prefer vertical outcomes; include technical prerequisites in the Active outcome unless they have independent value or block several immediate outcomes.

| Need | Read only the relevant section |
| --- | --- |
| Wave candidates | `docs/delivery-plan.md` |
| MVP inclusion | `docs/product-scope.md` |
| Domain lifecycle | `docs/conceptual-design.md` |
| Points/Promotion progress | `docs/promotion-model.md` |
| Acceptance | `docs/requirements.md` |
| Wallet copy/state | `docs/wallet-presentation.md` |
| UX/layout | `docs/ui-ux-guidelines.md` |
| Architecture | `docs/architecture/overview.md` |
| Auth/tokens/rate limits/logging | `docs/architecture/security.md` |
| Schema/time/concurrency | `docs/development/database-standard.md` |
| Laravel convention | `docs/development/laravel-application-standard.md` |
| Verification depth | `docs/quality-strategy.md` |
| Durable decision | Only the relevant ADR |

Update owned documentation only when its knowledge changes. Exclude task/review identifiers, temporary branch names, revision hashes, source-control history, implementation-session narratives and tool-usage narratives from enduring product docs.

Done requires met acceptance, passing proportionate automated evidence, covered authorization/data/time/concurrency risks, integrated behaviour, current owned docs when changed, and no unrelated scope. Normal delivery is an approved issue, its own branch and one coherent PR per outcome, not one PR per commit; obtain the applicable human authorization before repository-host actions.
