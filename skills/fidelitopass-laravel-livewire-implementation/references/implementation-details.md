# PHP and Livewire implementation

Apply this contract to generation, modification, refactoring and review, including PHP tests and mixed components. Use available [shared code quality](../../shared/code-quality.md) alongside it. Native framework skills and installed source own API syntax; project guidance refines decisions, not framework documentation. Keep the supplied Apache-2.0 licensing and FidelitoPass authorship; do not treat style changes as a new domain authority.

## Contents

- [Laravel boundaries](#laravel-boundaries)
- [Livewire structure](#livewire-structure-and-authority)
- [Types and contracts](#php-source-and-types)
- [PHPDoc syntax](#phpdoc-syntax)
- [Semantic groups](#semantic-member-groups)
- [Formatting](#conditions-queries-and-payloads)
- [Refactoring](#behavior-preservation)
- [Pest tests](#pest-test-readability)
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
- Order meaningful properties, construction/lifecycle hooks, component actions, optional private helpers, then listeners last. Keep constructors and framework lifecycle hooks at the top without separator boxes; their names and placement already identify their roles. Preserve their PHPDoc, attributes and framework contracts. Omit empty blocks; keep related members cohesive rather than imposing a method template.
- Keep form state, validation feedback, interaction and loading/disabled state in Livewire. Keep consequential transitions and authoritative rules at the owning server boundary, not in browser or hydrated component state.
- Validate and authorize server-side at the operation boundary. Prefer native enum/allowlist validation, integer ranges, distinct weekday arrays, IANA identifiers and normalized Business-scoped manual-code input over custom validation machinery. Consult [Authorization](../../../docs/architecture/security.md#authorization), [Input validation](../../../docs/architecture/security.md#input-validation) and [Credential classes](../../../docs/architecture/security.md#credential-classes) for ownership, input trust and public acquisition versus private validation authority.
- For affected markup, load the [Blade/Flux skill](../../fidelitopass-blade-flux-implementation/SKILL.md); for affected client behavior, load the [JavaScript skill](../../fidelitopass-javascript-alpine-implementation/SKILL.md). Read only necessary screen/state document owners through [Documentation routing](../../../AGENTS.md#documentation-routing). PHP ownership does not authorize duplicating sibling conventions.

## PHP source and types

- Follow configured Laravel Pint and PHPStan/Larastan rules. Use native PHP types, descriptive English identifiers and cohesive methods. Prefer domain/capability names over vague `Manager`, `Helper`, `Handler` or `Util` classes.
- Use string-backed enums for stable value sets, not phases derived from timestamps. Route stored lifecycle values and database constraints to [Status fields](../../../docs/development/database-standard.md#status-fields).
- Preserve meaningful generic collection types and array shapes for static analysis/IDE use where native types are insufficient. Do not replace specific contracts with uninformative `array` or `Collection` annotations.
- Keep public method names in their existing verb form, such as `resolve`, `prepare`, `handle` and `normalizeManualCode`. Third-person verbs belong to documentation, not identifiers.
- Use responsibility boxes only for meaningful semantic groups as specified below. Use docblocks for contracts and nearby lowercase English comments for non-obvious rationale.
- Do not add native parameter types to extracted helpers unless current callers and actual values support them. Preserve existing coercion and avoid stricter closure argument contracts when runtime input types are not verified.

## PHPDoc syntax

- Document every class and named callable, including private methods, constructors and test fixture helpers. Exempt anonymous callbacks from mechanical PHPDoc; give a substantial callback a name only if that responsibility becomes clearer.
- Begin each class/callable summary with a present-tense third-person singular English verb: `Builds`, `Checks`, `Commits`, `Confirms`, `Derives`, `Exposes`, `Identifies`, `Prepares`, `Rejects`, `Resolves`, `Returns`. Use `Checks`, not `Check` or `Cheks`; use `Decides`, not `Decide`. Describe behavior rather than issuing instructions to the caller.
- Add `@param` for each parameter with its meaning, trust constraints and units where relevant, including promoted constructor parameters. Add `@return` for non-void results with meaningful semantics; omit `@return void` and constructor return tags. Document effects of void methods in prose.
- Preserve PHPStan/Larastan generics, collection key/value types, framework relation types and array shapes that native types cannot express. Format substantial shapes over several lines; do not replace them with bare `array` or invent refinements not guaranteed by the implementation.
- Add `@throws` for intentionally thrown exceptions and relevant known failures that propagate. Describe sanitized wrappers at the public boundary and underlying known failures at internal boundaries. Do not invent checked exceptions, enumerate every possible runtime failure or promise all infrastructure failures share one type.
- Document transaction ownership, locks, idempotency/replay, external calls, writes, secret handling and uncertain outcomes where they form the method's contract. Do not claim a provider success is a local commit, a customer Wallet save, or proof of rollback.
- Keep docblocks attached to their declarations. Put the separator and a blank line before PHPDoc, then any attributes and the declaration. Avoid manual column padding in tags. A short accessor still gets a useful concise docblock; do not narrate its return statement line by line.
- Keep internal rationale comments lowercase in English while preserving acronyms and actual identifiers. When an internal comment describes behavior, prefer a third-person opening such as `preserves`, `omits` or `keeps`. Semantic group titles are noun phrases, not method summaries.

## Semantic member groups

Use separators only when at least two distinct semantic groups are useful in the same class or procedural/test file. Each group contains one to N related functions/methods. With only one useful group, omit all separators and rely on names, PHPDoc and blank lines. Do not count groups across unrelated classes or files, or invent a second group merely to permit a banner.

Exclude constructors and framework lifecycle hooks from labeled groups and from this count. Keep them together at the top after state, with normal PHPDoc and spacing. In Livewire, do not add an `initialization`, `construction` or `lifecycle` box, place a hook under an actions/helpers banner, or combine a constructor with an action to justify another group. Two constructors/hooks plus one own capability still means no separator boxes. A private helper called by a hook can belong to a real helper group; the hook itself stays unboxed.

Apply the same exclusion to tests: keep Pest `uses()` configuration, `beforeEach()`, `beforeAll()`, `afterEach()` and `afterAll()`, and PHPUnit setup/teardown methods unboxed above the actual tests. Do not introduce a `test initialization`, `test setup` or `test lifecycle` banner or count these hooks as another group. Label only actual test responsibilities and named fixture/helper groups when at least two useful groups remain.

Use exactly three lines for a separator when this condition is met; the following fragment shows its syntax, not permission to use an isolated banner:

```php
    // -----------------------------------------------------------------------------
    // initial presentation states
    // -----------------------------------------------------------------------------
```

- Use separators to distinguish actual cohesive groups of the class's own operations, such as public actions, accessors, presentation builders, persistence/binding validation, provider configuration, signing, reconciliation or transport. Keep constructors and framework lifecycle hooks outside them. Introduce each selected group consistently; do not label only one of them.
- Choose a specific lowercase English noun phrase. Preserve actual acronym/identifier capitalization, such as `JWT` and `HTTP`.
- Put one blank line immediately above and below the entire block, including before the following docblock. If it is the first member, keep the blank line after the class opening brace; otherwise do not add a leading blank line merely by template.
- Do not place a separator before every method. Do not invent a section for each accessor, empty groups, a `public actions` box over a single method in an otherwise tiny class, or boxes inside method bodies, closures or arrays.
- A group may contain just one method/test when it is meaningfully distinct from the other labeled groups. For example, a `waiting presentation` test group and an `active presentation` test group may each contain one test when that distinction helps a larger file. Setup/teardown is always excluded. A class/file with many methods/tests of one responsibility still needs no separators.
- Do not count a constants/property-only banner as another method group. Keep constants and properties unboxed unless an explicit user convention requires state grouping.
- Keep methods within a group adjacent. Prefer unboxed constants/state and construction/lifecycle methods, followed by public API and related private groups when useful. Preserve framework lifecycle, attribute and reflection contracts when moving members would affect behavior; no universal empty-section template is required.

## Conditions, queries and payloads

- Use four spaces. Follow configured Laravel Pint for braces and spacing, normally class/single-line-signature braces on the next line and multiline-signature braces beside the return type. Do not fight the formatter with repeated manual edits.
- Keep statements for one idea adjacent; separate distinct ideas with one blank line. Avoid both dense wall-of-code blocks and a blank line after every assignment.
- Expand a condition containing several independent clauses: put the opening `if (` on its own line and align subsequent `&&`/`||` clauses. Keep logical subgroups visibly parenthesized. Preserve short-circuit order, comparison operators and parentheses.
- Put each step of a substantial fluent query or HTTP builder on its own line. Keep all filters, relation scope, bindings, selected columns, eager loads, ordering and locks unchanged during refactoring.
- Put one array entry per line in multi-entry arrays and separate nested payload fields by structure. A tiny single-field leaf such as `['fieldPath' => $field]` or a scalar dataset row may remain inline if clearer.
- Split long signatures, constructor nesting and calls by meaningful arguments. Keep `#[SensitiveParameter]` on all existing secret parameters and on new secret-bearing helper parameters.
- Expand complex ternaries, retaining their timing and branches. Do not introduce eager intermediate expressions merely to make a ternary look shorter.
- Use 120 characters as a guide for executable code and documentation, without breaking identifiers, regexes, translation strings or test names just to fit a ruler. Keep externally meaningful text and regex semantics intact.
- Keep translation strings, payload keys, array insertion order, Unicode, JSON flags and log context untouched during refactoring. Formatting must not become a copywriting or provider-contract change.
- Use guard clauses when equivalent, but keep fallback behavior and evaluation order. Never change loose comparisons to strict comparisons as a style operation.

## Behavior preservation

Before refactoring, identify and preserve:

- Public signatures, defaults, visibility, attributes, callback behavior and caller coercions.
- Guards, rejection precedence, short-circuit evaluation, normalization timing and calls to credential getters or random generators.
- Queries and their order, tenant scope, eager loads, ordering, locking, connections and transaction ownership/retry settings.
- Domain writes, Eloquent association and model events, replay returns, cache/limiter consumption and log timing.
- Provider request count/order, redirects, retries, authentication, response inspection, immutable bindings and unknown/definitive outcomes.
- Translation/message text, regex behavior, payload values/order, output formats and privacy guarantees.

Extract only a complete responsibility with a clearer name and contract. Keep a small utility intact; do not produce helpers for `strlen`, one comparison, a single field assignment or each line of an Action. Cohesive state builders such as `waitingPresentation()` and `activePresentation()` are useful when they simplify the public flow. A persistence helper must stay inside the caller-owned transaction and retain serialized reads and writes.

Do not silently add validation, authorization, exception chaining, provider readback, logging, retries, caching, query optimizations or a stricter type while claiming an unchanged refactor. Report suspected defects separately. A clearer diff is not evidence of faster runtime or full behavioral equivalence.

## Pest test readability

- Keep `uses`, setup/teardown hooks, tests/datasets and fixture helpers visibly separated by normal blank lines. Configuration and lifecycle hooks never receive banners or count toward grouping. Add boxes only when at least two useful groups of actual tests or named helpers exist; each may contain one to N callables. A file with setup plus one homogeneous test group uses no boxes. Keep callbacks free of unnecessary docblocks.
- Expand mock expectations, payload fixtures and chained assertions so each expectation remains readable. Preserve test names, datasets, fixtures, assertion order and side-effect checks unless the task authorizes test changes.
- Keep values overridden by array unpacking in their original position; changing spread order changes fixtures. Do not merge several assertions or reorder seeded models merely for visual symmetry.
- Document named fixture helpers with third-person summaries, parameters and returned state. Do not introduce a global helper name that collides with another test file; inspect existing helpers when adding one.
- Add tests for meaningful behavioral risks or missing characterization, not punctuation, helper names or exact implementation shape. Run the natural configured suite; a formatting-only edit does not require a new test framework.

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

Start with [Documentation routing](../../../AGENTS.md#documentation-routing), not a copied global map. Read necessary owner sections, including multiple owners when required. If a required source is unavailable or intent conflicts, pause only the dependent edit and report the exact missing source/conflict. Continue independent formatting or a supplied behavior-preserving refactor. Reconcile implementation drift against current owner-approved requirements and the applicable domain/presentation owner; never rewrite policy to justify code. Global rules govern scoped work without authorizing unrelated rollout.

## Readable PHP paragraphs and naming

Use PascalCase class names, camelCase methods/variables and semantic uppercase constants, while preserving framework, database and external contracts. Name operations with concrete verbs and include scope or units where meaningful; avoid vague Manager/Helper/Util names. Follow existing Pint and static-analysis configuration; do not add competing tools.

Within methods and transaction callbacks, keep statements for one idea adjacent and use one blank line between ideas. Never put decorative separator boxes inside bodies. Extract by a clearer responsibility or contract, not length; keep transaction ordering visible. Do not apply final, readonly or strict types mechanically when framework behavior or caller coercion would change.

Avoid loading all Visits merely to sum or count them, N+1 relation access and repeated queries. Use database aggregates, necessary eager loading and bounded pagination/batches. Include keys needed to hydrate partial relations. Analyze rows, query count and lock duration separately; no cleaner-looking expression proves improved runtime.

Use one database connection for an atomic operation. Establish a consistent lock order across competing writers, and keep network or irreversible effects outside retryable transaction callbacks. After-commit dispatch orders an effect; it does not prove durable delivery. Keep actual integrity and retry requirements with their document owners.

Render/computed paths remain free of domain writes. Do not add Form Objects, persistent computed caches or parallel actions without an actual need.

Before the first code change in a task, read the relevant [examples](readability-examples.md) to calibrate formatting, groups and contracts. Reuse that calibration for subsequent files. Read only the relevant [project recipe](project-recipes.md) for moved syntax/schema guidance. Examples do not authorize scaffolding or replace current migrations and installed APIs.
