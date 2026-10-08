# PHP and Livewire implementation

Apply [shared code quality](../../shared/code-quality.md) alongside this PHP-specific contract. Native framework skills and installed source own API syntax; project guidance refines decisions, not framework documentation.

## Contents

- [Laravel boundaries](#laravel-boundaries)
- [Livewire structure](#livewire-structure-and-authority)
- [Types and contracts](#php-source-and-types)
- [PHPDoc syntax](#phpdoc-syntax)
- [Owner selection](#domain-and-persistence-owner-selection)
- [Readable paragraphs](#readable-php-paragraphs-and-naming)

## Laravel boundaries

- Use conventional Laravel roots only when real code needs them: Actions, Enums, Integrations, Jobs, Models, Policies and Support under `app/`, with page views under `resources/views/pages/`. Prefer Eloquent relationships, scopes, casts, Policies, validation, constraints, transactions, row locks, Jobs and query builder over bespoke layers. Do not wrap ordinary Eloquent access in repositories or generic CRUD Services.
- Use a focused `<Verb><Subject>Action::handle()` for a consequential command involving a transaction, state-change authorization, idempotency/concurrency, multiple domain writes or an external effect. Keep routine edits direct. Add a Service only for a real cohesive capability; do not add a competing `__invoke()` entry point or generic BaseAction.
- Give one Action the complete transaction boundary; callers and collaborators must not introduce partial competing scopes. Consult [Command boundaries](../../../docs/architecture/overview.md#command-boundaries) and [Transactions and external effects](../../../docs/development/laravel-application-standard.md#transactions-and-external-effects) for authoritative outcomes and provider timing.
- Confine provider SDK/HTTP details to a concrete Integration. Apply the shared interface gate before adding a contract. Read [Google Wallet integration](../../../docs/development/laravel-application-standard.md#google-wallet-integration) and [Background work](../../../docs/architecture/overview.md#background-work) for integration ownership and synchronization outcomes.
- Prefer Laravel queue retry/backoff capabilities over custom retry machinery. Keep synchronization Jobs cohesive: reload current authoritative state, build the Wallet presentation, update the provider and log the outcome. Consult the [Wallet presentation contract](../../../docs/wallet-presentation.md#presentation-rules) for mapping; route duplicate execution and provider-failure guarantees to the owners above.

## Livewire structure and authority

- When a non-public collaborator is needed after the initial mount, resolve it for each required request through verified native injection/lifecycle facilities. Treat `mount()` as initial setup; do not assume its non-public assignments survive hydration.
- Keep services out of public serialized component state; making a collaborator public is not a hydration workaround. Verify the installed request reconstruction and hook/injection behavior before choosing a lifecycle entry point; do not refactor unrelated components.
- Prefer native multi-file components for new project-owned full-page components when PHP, Blade and colocated tests share one responsibility. Use `Route::livewire` and the appropriate starter layout (`layouts::public` or `layouts::app`) with its `$slot`, verifying the installed API. Preserve stable starter authentication/settings formats; do not convert solely for consistency.
- Order meaningful properties, lifecycle hooks, component actions, optional private helpers, then listeners last. Omit empty blocks; keep related members cohesive rather than imposing a method template.
- Keep form state, validation feedback, interaction and loading/disabled state in Livewire. Keep consequential transitions and authoritative rules at the owning server boundary, not in browser or hydrated component state.
- Validate and authorize server-side at the operation boundary. Prefer native enum/allowlist validation, integer ranges, distinct weekday arrays, IANA identifiers and normalized Business-scoped manual-code input over custom validation machinery. Consult [Authorization](../../../docs/architecture/security.md#authorization), [Input validation](../../../docs/architecture/security.md#input-validation) and [Credential classes](../../../docs/architecture/security.md#credential-classes) for ownership, input trust and public acquisition versus private validation authority.
- For affected markup, load the [Blade/Flux skill](../../fidelitopass-blade-flux-implementation/SKILL.md); for affected client behavior, load the [JavaScript skill](../../fidelitopass-javascript-alpine-implementation/SKILL.md). Read only necessary screen/state document owners through [Documentation routing](../../../AGENTS.md#documentation-routing). PHP ownership does not authorize duplicating sibling conventions.

## PHP source and types

- Follow configured Laravel Pint and PHPStan/Larastan rules. Use native PHP types, descriptive English identifiers and cohesive methods. Prefer domain/capability names over vague `Manager`, `Helper`, `Handler` or `Util` classes.
- Use string-backed enums for stable value sets, not phases derived from timestamps. Route stored lifecycle values and database constraints to [Status fields](../../../docs/development/database-standard.md#status-fields).
- Preserve meaningful generic collection types and array shapes for static analysis/IDE use where native types are insufficient. Do not replace specific contracts with uninformative `array` or `Collection` annotations.
- Apply shared responsibility boxes only to useful major multi-element groups; use docblocks for API contracts and nearby lowercase English comments for internal rationale.

## PHPDoc syntax

- Follow the [shared callable contract](../../../skills/shared/code-quality.md#callable-documentation) for PHP callable descriptions, parameters, results and applicable exceptions.
- Use PHPDoc where native syntax cannot express useful PHPStan/Larastan generics, collection key/value types or array shapes; preserve accurate framework relation types. For constructors with promoted properties, document the constructor parameter rather than inventing a return annotation.
- Preserve class-level contracts for reusable services, Actions and Jobs when their responsibility, invariants or side effects are not clear from their name and shape.

## Domain and persistence owner selection

Read the affected owner sections before implementing; these links preserve authority, not independent domain definitions:

- For Promotion configuration, publication freeze, timezone reconfirmation and recurring multiplier windows, read [Promotion configuration](../../../docs/promotion-model.md#promotion-configuration) and [Point earning](../../../docs/promotion-model.md#point-earning).
- For legitimate repeats, retry behavior, immutable awarded outcomes, progress, entitlement and redemption, read [Multiple Visits on the same day](../../../docs/promotion-model.md#multiple-visits-on-the-same-day), [Promotion progress](../../../docs/promotion-model.md#promotion-progress), [Completion and Reward](../../../docs/promotion-model.md#completion-and-reward) and the affected acceptance requirement selected through project routing.
- Prefer CarbonImmutable/framework date utilities for Business-local parsing, presentation and publication conversion; never infer calendar meaning from the PHP/server system timezone. For authoritative operation time, post-lock clock capture, reuse, local calendar evaluation and UTC storage, read [Timestamps and timezone model](../../../docs/development/database-standard.md#timestamps-and-timezone-model) and [Application date handling](../../../docs/development/database-standard.md#application-date-handling). Do not invent another clock policy.
- For pass identity/ownership, final integrity, concurrency, audit facts and safe forward migration, read the database standard's [Ownership](../../../docs/development/database-standard.md#ownership), [Uniqueness and concurrency](../../../docs/development/database-standard.md#uniqueness-and-concurrency), [Soft deletes](../../../docs/development/database-standard.md#soft-deletes), [Logging versus audit columns](../../../docs/development/database-standard.md#logging-versus-audit-columns) and [Migrations](../../../docs/development/database-standard.md#migrations) when affected.
- For project scope exclusions, consult [Architecture style](../../../docs/architecture/overview.md#architecture-style) and [Architectural constraints](../../../docs/architecture/overview.md#architectural-constraints); do not manufacture customer subsystems, CQRS/event sourcing or generic rule machinery. Data owners above retain clock, local-date, soft-delete and audit constraints.
- For auth changes, preserve [Retired two-factor persistence](../../../docs/development/laravel-application-standard.md#retired-two-factor-persistence) and consult [Authentication](../../../docs/architecture/security.md#authentication); do not infer credential recovery from rollback. An explicitly authorized re-enablement uses Fortify's feature opt-in, `TwoFactorAuthenticatable`, appropriate hidden attributes, a new forward credential migration, compatible setup/challenge UI and tests, and new enrollment.

## Localization, logging and errors

- Use Laravel translations for visible Spanish UI, validation/error messages, accessibility and Wallet text. Keep technical identifiers, enum values, internal comments and log event names English.
- Centralize generated Promotion translations so UI previews and Wallet mapping cannot drift. Consult [Generated pass copy](../../../docs/wallet-presentation.md#generated-active-promotion-copy) and [Wallet presentation rules](../../../docs/wallet-presentation.md#presentation-rules) for canonical wording/consistency; do not introduce competing product copy.
- Follow [Structured logging](../../../docs/development/laravel-application-standard.md#structured-logging) and [Structured security logging](../../../docs/architecture/security.md#structured-security-logging) for JSON output, request correlation, event context and secret hygiene. Use Laravel's Monolog integration with `JsonFormatter` through logging configuration/tap customization, and assign the request UUID in middleware using Laravel log context. Do not duplicate the sensitive-field inventory here.
- Route domain rejection and unexpected-exception presentation to [Error handling](../../../docs/architecture/security.md#error-handling), and deployment/header policy to [Security headers](../../../docs/architecture/security.md#security-headers). Prefer framework/deployment header mechanisms before custom packages.

## Verification and source selection

Select the natural verification boundary through the [Quality strategy](../../../docs/quality-strategy.md#test-design). Use Pest for new project tests where practical; preserve untouched Starter Kit PHPUnit tests. Avoid duplicating assertions across layers without a reason. Browser verification follows [Browser coverage](../../../docs/quality-strategy.md#browser-coverage), not invented timing or selector conventions.

Inspect installed versions/configuration and use narrow official/framework guidance or installed source for uncertain APIs. Report unavailable evidence rather than guessing. Do not use evolving application source as a calibration dependency or load broad framework/domain documentation automatically.

Start with [Documentation routing](../../../AGENTS.md#documentation-routing), not a copied global map. Read necessary owner sections, including multiple owners when required. If a required source is unavailable or intent conflicts, stop before the affected edit and report the exact missing source/conflict. Reconcile implementation drift against current owner-approved requirements and the applicable domain/presentation owner; never rewrite policy to justify code. Global rules govern scoped work without authorizing unrelated rollout.

## Readable PHP paragraphs and naming

Use PascalCase class names, camelCase methods/variables and semantic uppercase constants, while preserving framework, database and external contracts. Name operations with concrete verbs and include scope or units where meaningful; avoid vague Manager/Helper/Util names. Follow existing Pint and static-analysis configuration; do not add competing tools.

Within methods and transaction callbacks, keep statements for one idea adjacent and use one blank line between ideas. Never put decorative separator boxes inside bodies. Extract by a clearer responsibility or contract, not length; keep transaction ordering visible. Do not apply final, readonly or strict types mechanically when framework behavior or caller coercion would change.

Avoid loading all Visits merely to sum or count them, N+1 relation access and repeated queries. Use database aggregates, necessary eager loading and bounded pagination/batches. Include keys needed to hydrate partial relations. Analyze rows, query count and lock duration separately; no cleaner-looking expression proves improved runtime.

Use one database connection for an atomic operation. Establish a consistent lock order across competing writers, and keep network or irreversible effects outside retryable transaction callbacks. After-commit dispatch orders an effect; it does not prove durable delivery. Keep actual integrity and retry requirements with their document owners.

Render/computed paths remain free of domain writes. Do not add Form Objects, persistent computed caches or parallel actions without an actual need.

Read [examples](readability-examples.md) only to calibrate structure. Read the relevant [project recipe](project-recipes.md) for moved syntax/schema guidance. Examples do not authorize scaffolding or replace current migrations and installed APIs.
