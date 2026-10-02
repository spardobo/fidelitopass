# PHP and Livewire implementation

Apply [shared code quality](code-quality.md) alongside this PHP-specific contract. Native framework skills and installed source own API syntax; project guidance refines decisions, not framework documentation.

## Laravel boundaries

- Use conventional Laravel roots only when real code needs them. Prefer Eloquent relationships, scopes, casts, Policies, validation, constraints, transactions, row locks, Jobs and query builder over bespoke layers. Do not wrap ordinary Eloquent access in repositories or generic CRUD Services.
- Use a focused `<Verb><Subject>Action::handle()` for a consequential command involving a transaction, state-change authorization, idempotency/concurrency, multiple domain writes or an external effect. Keep routine edits direct. Add a Service only for a real cohesive capability; do not add a competing `__invoke()` entry point or generic BaseAction.
- Give one Action the complete transaction boundary; callers and collaborators must not introduce partial competing scopes. Consult [Command boundaries](../../../docs/architecture/overview.md#command-boundaries) and [Transactions and external effects](../../../docs/development/laravel-application-standard.md#transactions-and-external-effects) for authoritative outcomes and provider timing.
- Confine provider SDK/HTTP details to a concrete Integration. Apply the shared interface gate before adding a contract. Read [Google Wallet integration](../../../docs/development/laravel-application-standard.md#google-wallet-integration) and [Background work](../../../docs/architecture/overview.md#background-work) for integration ownership and synchronization outcomes.
- Prefer Laravel queue retry/backoff capabilities over custom retry machinery. Keep Job orchestration cohesive; route duplicate execution and provider-failure guarantees to the owners above.

## Livewire structure and authority

- Prefer native multi-file components for new project-owned full-page components when PHP, Blade and colocated tests share one responsibility. Use the native route/layout mechanisms verified for the installed version. Preserve stable starter authentication/settings formats; do not convert solely for consistency.
- Order meaningful properties, lifecycle hooks, component actions, optional private helpers, then listeners last. Omit empty blocks; keep related members cohesive rather than imposing a method template.
- Keep form state, validation feedback, interaction and loading/disabled state in Livewire. Keep consequential transitions and authoritative rules at the owning server boundary, not in browser or hydrated component state.
- Validate and authorize server-side at the operation boundary. Consult [Authorization](../../../docs/architecture/security.md#authorization), [Input validation](../../../docs/architecture/security.md#input-validation) and [Credential classes](../../../docs/architecture/security.md#credential-classes) for ownership, input trust and public acquisition versus private validation authority.
- For mixed components, select affected presentation/client owners through [Documentation routing](../../../AGENTS.md#documentation-routing). Consult only the relevant screen/state and lifecycle sections; PHP ownership does not authorize duplicating Blade/JavaScript guidance here.

## PHP source and types

- Follow configured Laravel Pint and PHPStan/Larastan rules. Use native PHP types, descriptive English identifiers and cohesive methods. Prefer domain/capability names over vague `Manager`, `Helper`, `Handler` or `Util` classes.
- Use string-backed enums for stable value sets, not phases derived from timestamps. Route stored lifecycle values and database constraints to [Status fields](../../../docs/development/database-standard.md#status-fields).
- Preserve meaningful generic collection types and array shapes for static analysis/IDE use where native types are insufficient. Do not replace specific contracts with uninformative `array` or `Collection` annotations.
- Apply shared responsibility boxes only to useful major multi-element groups; use docblocks for API contracts and nearby lowercase English comments for internal rationale.

## Public API PHPDoc

- Require an IDE-readable class docblock on project-owned service, Action, Job and repository-like classes. State the class responsibility and boundary; do not create a repository or Service merely to satisfy this rule.
- Require a useful docblock on every public method of those classes, even with a fully typed signature. Explain public use, relevant preconditions, return semantics, side effects and exceptions where applicable. Include constructors when they are public methods of these classes; document their meaningful initialization contract rather than narrating assignments.
- Do not extend this blanket requirement to every Laravel model constructor/accessor, Livewire lifecycle hook or unrelated framework method. Outside the named class categories, document contracts when non-obvious information remains.
- Document private methods only for non-obvious contracts. Improve names, types and structure before explaining avoidably unclear code.
- Avoid tautological descriptions and `@param`/`@return` tags that merely repeat native types. Use tags for added generic/array-shape information or meaningful exception contracts. Preserve existing preconditions, units/timezones, concurrency/idempotency, provider timing and side-effect guarantees where applicable.

## Domain and persistence owner selection

Read the affected owner sections before implementing; these links preserve authority, not independent domain definitions:

- For Promotion configuration, publication freeze, timezone reconfirmation and recurring multiplier windows, read [Promotion configuration](../../../docs/promotion-model.md#promotion-configuration) and [Point earning](../../../docs/promotion-model.md#point-earning).
- For legitimate repeats, retry behavior, immutable awarded outcomes, progress, entitlement and redemption, read [Multiple Visits on the same day](../../../docs/promotion-model.md#multiple-visits-on-the-same-day), [Promotion progress](../../../docs/promotion-model.md#promotion-progress), [Completion and Reward](../../../docs/promotion-model.md#completion-and-reward) and the affected acceptance requirement selected through project routing.
- For authoritative operation time, post-lock clock capture, reuse, local calendar evaluation, UTC storage and date casts, read [Timestamps and timezone model](../../../docs/development/database-standard.md#timestamps-and-timezone-model) and [Laravel model date handling](../../../docs/development/database-standard.md#laravel-model-date-handling). Do not invent another clock policy.
- For pass identity/ownership, final integrity, concurrency, audit facts and safe forward migration, read the database standard's [Ownership](../../../docs/development/database-standard.md#ownership), [Uniqueness and concurrency](../../../docs/development/database-standard.md#uniqueness-and-concurrency), [Soft deletes](../../../docs/development/database-standard.md#soft-deletes), [Logging versus audit columns](../../../docs/development/database-standard.md#logging-versus-audit-columns) and [Migrations](../../../docs/development/database-standard.md#migrations) when affected.
- For framework/project scope exclusions, consult [Prohibited defaults](../../../docs/development/laravel-application-standard.md#prohibited-defaults); do not manufacture customer subsystems or generic rule machinery.
- For auth changes, preserve [Retired two-factor persistence](../../../docs/development/laravel-application-standard.md#retired-two-factor-persistence) and consult [Authentication](../../../docs/architecture/security.md#authentication); do not infer credential recovery from rollback.

## Localization, logging and errors

- Use Laravel translations for visible Spanish UI, validation/error messages, accessibility and Wallet text. Keep technical identifiers, enum values, internal comments and log event names English.
- For generated copy shared by previews, acquisition, Wallet and operation results, consult [Customer-facing copy](../../../docs/promotion-model.md#customer-facing-copy) and [Localization](../../../docs/development/laravel-application-standard.md#localization); do not introduce competing wording rules.
- Follow [Structured logging](../../../docs/development/laravel-application-standard.md#structured-logging) and [Structured security logging](../../../docs/architecture/security.md#structured-security-logging) for JSON output, request correlation, event context and secret hygiene. Do not duplicate the sensitive-field inventory here.
- Route domain rejection and unexpected-exception presentation to [Error handling](../../../docs/architecture/security.md#error-handling), and deployment/header policy to [Security headers](../../../docs/architecture/security.md#security-headers).

## Verification and source selection

Select the natural verification boundary through the [Quality strategy](../../../docs/quality-strategy.md#test-design). Use Pest for new project tests where practical; preserve untouched Starter Kit PHPUnit tests. Avoid duplicating assertions across layers without a reason. Browser verification follows [Browser coverage](../../../docs/quality-strategy.md#browser-coverage), not invented timing or selector conventions.

Inspect installed versions/configuration and use narrow official/framework guidance or installed source for uncertain APIs. Report unavailable evidence rather than guessing. Do not use evolving application source as a calibration dependency or load broad framework/domain documentation automatically.

Start with [Documentation routing](../../../AGENTS.md#documentation-routing), not a copied global map. Read necessary owner sections, including multiple owners when required. If a required source is unavailable or intent conflicts, stop before the affected edit and report the exact missing source/conflict. Reconcile implementation drift against current owner-approved requirements and the applicable domain/presentation owner; never rewrite policy to justify code. Global rules govern scoped work without authorizing unrelated rollout.
