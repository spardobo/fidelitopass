# FidelitoPass Technical Documentation

This directory is the technical source of truth for FidelitoPass.

Documentation follows progressive disclosure. Read only the document required by the current decision. Do not load the full documentation set into an implementation context by default.

## Design order

1. Document `conceptual-design.md` — product model, actors, domain language, lifecycles, and invariants.
2. Document `product-scope.md` — MVP boundary and explicit non-goals.
3. Document `promotion-model.md` — Promotion-owned x2/x3/x5 extra-point rules and deterministic point-earning semantics.
4. Document `requirements.md` — observable behaviour and acceptance criteria.
5. Document `wallet-presentation.md` — deterministic Google Wallet states and copy contract.
6. Document `ui-ux-guidelines.md` — web interaction, visual system, accessibility, and page intent.
7. Document `architecture/overview.md` — system boundaries and implementation shape.
8. Document `architecture/security.md` — trust boundaries and security controls.
9. Document `development/database-standard.md` — PostgreSQL and migration conventions.
10. Document `development/laravel-application-standard.md` — Laravel, Livewire, and application-code conventions.
11. Document `quality-strategy.md` — proportionate verification strategy.
12. Document `delivery-plan.md` — candidate work items and scoped requirement links.
13. Document `development/workflow.md` — delivery flow and documentation routing.
14. Directory `architecture/decisions/` — durable cross-cutting decisions only.

`documentation-standard.md` defines how these documents are written and maintained. ADR-006 supersedes only ADR-003's Business-global mutable extra-point rule ownership. ADR-003, ADR-004, and ADR-005 remain preserved decision history; historical CHL-prefixed requirement IDs remain stable. Read the relevant ADR only when revisiting that decision.

## Lazy-loading map

| Need | Read |
|---|---|
| Understand the product or a domain term | `conceptual-design.md` |
| Decide whether something belongs in MVP | `product-scope.md` |
| Configure/evaluate Promotion-owned x2/x3/x5 points or progress | relevant section of `promotion-model.md` |
| Confirm user-visible behaviour | relevant requirement in `requirements.md` |
| Map a customer state to Google Wallet | relevant section of `wallet-presentation.md` |
| Design a page or interaction | relevant section of `ui-ux-guidelines.md` |
| Change system boundaries or integration ownership | relevant section of `architecture/overview.md` |
| Change authentication, authorization, tokens, rate limits, or security logging | relevant section of `architecture/security.md` |
| Add or alter persistent data | relevant section of `development/database-standard.md` |
| Implement Laravel, Livewire, Actions, jobs, or integrations | relevant section of `development/laravel-application-standard.md` |
| Decide what verification is sufficient | relevant section of `quality-strategy.md` |
| Select or load delivery work | relevant section of `delivery-plan.md` |
| Interpret the project delivery flow | `development/workflow.md` |
| Revisit a costly cross-cutting decision | only the relevant ADR |

## Documentation boundaries

- Each rule has one primary home.
- Internal links are exceptional and specific.
- Documents do not narrate source-control history, temporary work, or implementation sessions.
- Product and engineering policy remain repository-host independent.
- The root `README.md` is the Spanish public entry point.
