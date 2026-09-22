# FidelitoPass Documentation Standard

This document defines how project documentation is written and maintained.

## Purpose

Documentation exists to reduce ambiguity in product, implementation, security, quality, and operation.

It must remain lean enough to be useful during active development.

## Language

- Directory `docs/` is written in English.
- Technical identifiers and source comments are written in English.
- Root `README.md` is written in neutral professional Spanish.
- Product UI and generated customer copy are Spanish.

## Writing profile

Technical documents use controlled English inspired by ASD-STE100. The project does not claim formal certification.

- Use short direct sentences.
- Prefer active voice.
- Use one term for one concept.
- Define domain terms before relying on them.
- Put conditions before actions.
- Prefer tables for exact comparisons.
- Prefer lists for procedures.
- Avoid decorative language.
- Avoid vague claims such as "robust", "fast", or "secure" without a concrete rule.
- Do not restate framework defaults unless FidelitoPass depends on or changes them.

## Knowledge ownership

| Document | Owns |
|---|---|
| `conceptual-design.md` | Product model, actors, lifecycles, invariants, workflows. |
| `product-scope.md` | MVP boundary and non-goals. |
| `challenge-model.md` | Points-based Challenge semantics, point earning, and generated customer copy. |
| `requirements.md` | Observable behaviour and Given-When-Then acceptance. |
| `wallet-presentation.md` | Google Wallet information hierarchy and deterministic states. |
| `ui-ux-guidelines.md` | Web visual/interactions/accessibility/page intent. |
| `architecture/overview.md` | System boundaries, domain responsibilities, transaction/integration shape. |
| `architecture/security.md` | Threat boundaries, credentials, authorization, rate limiting, secret/log policy. |
| `development/database-standard.md` | PostgreSQL schema/time/index/concurrency conventions. |
| `development/laravel-application-standard.md` | Laravel/Livewire/source conventions. |
| `quality-strategy.md` | Verification depth and quality evidence. |
| `delivery-plan.md` | Rolling-wave grouping and sequence. |
| `development/workflow.md` | Work-item flow and documentation routing. |
| `architecture/decisions/*` | One durable cross-cutting choice and consequences. |
| root `README.md` | Spanish public product/engineering entry point. |

## Progressive disclosure

The design dependency is:

```text
Concept
 -> Scope
 -> Challenge semantics
 -> Requirements
 -> UX / Wallet contract
 -> Architecture / Security
 -> Implementation standards
 -> Quality / Delivery
```

Architecture responds to agreed product behaviour. It does not invent product requirements to justify a preferred technical pattern.

## Internal references

Use references only when another document is required to apply the current rule.

Do not add broad "see also" networks.

A loaded document does not imply that its referenced documents must also be loaded.

## Living documents

Living documents describe current agreed knowledge.

Do not include:

- Source-control history.
- Temporary work identifiers.
- Implementation-session narratives.
- Token/tool usage.
- Old product/domain names.

Version control owns historical delivery context.

## Requirement format

Start `requirements.md` with:

- Total count.
- MoSCoW distribution.
- Type distribution.
- Searchable register.

Each detailed requirement contains:

1. Stable ID and title.
2. Priority.
3. Type.
4. Module.
5. Outcome-focused description.
6. User Story in **As / I want / So that** form.
7. One or more acceptance scenarios using explicit **Given / When / Then**.
8. Verification method.

Use a second scenario only for a material rejection, retry, security, concurrency, or failure path.

Do not turn every validation branch into a separate product requirement.

## ADR policy

Create an ADR only when the decision is:

- Cross-cutting.
- Costly to reverse.
- Likely to be questioned again.
- Security/data/time-provider sensitive.

An ADR contains:

1. Context.
2. Options considered.
3. Decision.
4. Rationale.
5. Consequences.

Prefer at least three credible options when the decision genuinely had them. Do not invent weak alternatives only to fill a template.

Accepted ADRs are not rewritten to hide changed assumptions; supersede them when a later decision replaces them.

## Evidence

Prefer primary official sources for framework, PostgreSQL, Google Wallet, and security details.

Keep external source material outside the normative product documentation unless it is necessary to justify a technical decision.

Do not copy large source excerpts.

## Lean maintenance

Update only the document whose knowledge changed.

Examples:

- Challenge/point rule change -> challenge model + affected requirement/presentation.
- Time model change -> conceptual/database/ADR + affected tests.
- New page interaction -> UX + affected requirement.
- New framework convention -> application standard.
- Changed test depth -> quality strategy.

Do not update every document for every feature.

## Validation

Before accepting documentation changes:

- Verify document language.
- Verify requirement IDs/register/detail.
- Verify internal relative links.
- Search for obsolete product names.
- Search for external attribution that does not belong.
- Verify Mermaid syntax where used.
- Verify no implementation claim exceeds actual source/runtime evidence.
- Verify documents do not duplicate authority unnecessarily.
