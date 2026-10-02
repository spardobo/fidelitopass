# FidelitoPass Technical Documentation

This directory owns FidelitoPass product outcomes, system contracts and risk policy. Implementation skills own coding HOW; documentation is not a parallel source-code convention manual.

This index explains the design order and knowledge owners without defining their domain rules. The sole intent-to-document route table is [Documentation routing in the project contract](../AGENTS.md#documentation-routing). Documentation follows progressive disclosure: read only the sections required by the current decision, not the full set by default.

## Design order

0. Document `documentation-standard.md` — documentation language, ownership and maintenance policy.
1. Document `conceptual-design.md` — product model, actors, domain language, lifecycles, and invariants.
2. Document `product-scope.md` — MVP boundary and explicit non-goals.
3. Document `promotion-model.md` — Promotion-owned x2/x3/x5 extra-point rules and deterministic point-earning semantics.
4. Document `requirements.md` — observable behaviour and acceptance criteria.
5. Document `wallet-presentation.md` — deterministic Google Wallet states and copy contract.
6. Document `ui-ux-guidelines.md` — web interaction, visual system, accessibility, and page intent.
7. Document `architecture/overview.md` — system boundaries, responsibility allocation and authoritative operations.
8. Document `architecture/security.md` — trust boundaries and security controls.
9. Document `development/database-standard.md` — PostgreSQL and migration conventions.
10. Document `development/laravel-application-standard.md` — application integrity, provider effects, operational logging and retired credential contracts.
11. Document `quality-strategy.md` — proportionate verification strategy.
12. Document `delivery-plan.md` — candidate work items and scoped requirement links.
13. Document `development/workflow.md` — work-item flow, owner approvals and delivery completion gates.
14. Document `README.md` (this index) — navigation over the preceding documents.

The following owners support this progression rather than form later domain specializations:

- `architecture/decisions/` — durable cross-cutting decisions, rationale and consequences. Accepted ADR authority is preserved: ADR-003 establishes the single points mechanic and Promotion-owned extra-point rules; ADR-004 establishes PostgreSQL time and calendar semantics. Domain owners specialize those decisions without replacing them. Related-decision and supersession links are exempt from the substantive dependency order.
- Root `README.md` — Spanish public product/engineering entry point.
- Implementation skills — Laravel/PHP/Livewire server, Blade/Flux/Tailwind presentation and JavaScript/Alpine coding conventions, with one shared editorial reference under `skills/shared/`. Select affected owners through [Intent routing](../AGENTS.md#intent-routing); mixed work can require several. Skills implement agreed outcomes rather than inventing domain rules.

Historical CHL-prefixed requirement IDs remain stable. Read the relevant ADR only when the current decision requires it.

## Documentation boundaries

- Each rule has one primary home: documents own WHAT and contracts; implementation skills own coding HOW.
- Product, domain, presentation, architecture, security and data decisions precede implementation conventions. Quality and delivery define sufficient evidence and acceptance, not a second coding style guide.
- Substantive references point backward in the sequence, except ADR references. The standard is first and this index is last; meta status grants no blanket forward-reference exception.
- Internal links are exceptional and specific.
- Documents do not narrate source-control history, temporary work, or implementation sessions.
- Domain rules do not depend on provider command syntax; delivery policy retains its explicit GitHub issue/Project semantics.
- The root `README.md` is the Spanish public entry point.
