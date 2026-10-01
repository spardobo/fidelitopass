# FidelitoPass Technical Documentation

This directory is the technical source of truth for FidelitoPass.

This is the sole canonical documentation map. It indexes knowledge owners without defining their domain rules. Documentation follows progressive disclosure: read only the document required by the current decision, not the full set by default.

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
13. Document `development/workflow.md` — work-item flow, owner approvals and delivery completion gates.

The following owners support this progression rather than form later domain specializations:

- `documentation-standard.md` — documentation language, ownership, structure and maintenance conventions.
- `architecture/decisions/` — durable cross-cutting decisions, rationale and consequences. Accepted ADR authority is preserved: ADR-003 establishes the single points mechanic and Promotion-owned extra-point rules; ADR-004 establishes PostgreSQL time and calendar semantics. Domain owners specialize those decisions without replacing them. Related-decision and supersession links are exempt from the substantive dependency order.
- Root `README.md` — Spanish public product/engineering entry point.

Historical CHL-prefixed requirement IDs remain stable. Read the relevant ADR only when the current decision requires it.

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
| Write or maintain documentation | `documentation-standard.md` |

## Documentation boundaries

- Each rule has one primary home.
- Internal links are exceptional and specific.
- Documents do not narrate source-control history, temporary work, or implementation sessions.
- Domain rules do not depend on provider command syntax; delivery policy retains its explicit GitHub issue/Project semantics.
- The root `README.md` is the Spanish public entry point.
