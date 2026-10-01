# FidelitoPass Laravel Application Standard

This document defines project-owned Laravel, PHP, Livewire, testing, and source-code conventions.

Official framework documentation remains authoritative for framework APIs. This document defines only FidelitoPass-specific choices.

## Core rule

Prefer the smallest conventional Laravel design that protects the current domain behaviour.

Do not introduce architecture layers for pattern compliance.

## Framework-first structure

Use Laravel conventional roots:

```text
app/
  Actions/
  Enums/
  Integrations/
  Jobs/
  Models/
  Policies/
  Support/
resources/views/
  pages/
```

Create a directory only when real code needs it.

## Eloquent first

Eloquent is the default persistence abstraction.

Use:

- Relationships for ownership/structure.
- Scopes for reusable query semantics.
- Casts for enums and dates.
- Policies for authorization.
- Transactions and locks for shared-state integrity.
- Database constraints for final integrity.

Do not add repositories around ordinary Eloquent access.

## Actions

Use one focused `<Verb><Subject>Action::handle()` when a business command coordinates consequential state.

Expected examples:

```text
PublishPromotionAction
CancelPromotionAction
IssueCustomerPassAction
ValidateVisitAction
RedeemRewardAction
```

An Action is justified when it owns one or more of:

- A complete database transaction.
- Authorization tied to a state change.
- Idempotency/concurrency.
- Multiple domain writes.
- An after-commit external effect.

Routine Business profile edits do not require an Action by default.

Do not add a second `__invoke()` entry point for project Actions.

The Action that owns a transaction owns the whole transaction boundary. Callers and collaborators must not create partial competing transaction scopes.

## Points and Promotion calculation

MVP has one Promotion mechanic: reach a target number of points before the Promotion ends.

Keep the calculation direct and explicit. A separate Strategy hierarchy is not required.

At Visit validation time:

- After locking the relevant Customer pass and Promotion rows, capture one PostgreSQL `clock_timestamp()` operation instant. Use it for validity, point evaluation, and `visited_at`; do not use transaction-start time or a second clock reading.
- Resolve the applicable point value from the active published Promotion's frozen weekly schedule using that instant in its stored IANA timezone. Regular Visits earn one point; a matching x2, x3 or x5 window awards 2, 3 or 5 points. Each weekday has either a whole-day rule or disjoint half-open timed windows, never both. Windows do not stack; outside them award one point. Do not inherit Business-global or previous-Promotion rules.
- Persist that value as `points_awarded` on the accepted Visit.
- Calculate Promotion progress from the sum of awarded points for that Customer pass and Promotion.
- Create the Reward entitlement when the target is reached.

Do not create generic condition objects, expression languages, dynamic rule builders, or several interchangeable Promotion evaluators in MVP.

## Time and date handling

### Application timezone

Keep Laravel application timezone set to UTC.

### Domain validity

Promotion phase, Visit acceptance, Reward expiry, and Redemption validity use PostgreSQL current time inside the owning query/transaction. For lock-sensitive mutations, capture one post-lock `clock_timestamp()` instant and reuse it throughout the operation.

Do not use application `now()` as the authoritative clock for these decisions.

### Date casts

Use `immutable_datetime` casts for rule-relevant timestamps such as:

```text
starts_at
ends_at
visited_at
unlocked_at
redeemed_at
published_at
cancelled_at
```

### Business-local input/output

Use CarbonImmutable/framework date utilities for:

- Parsing Business-local start/end dates.
- Presenting local Promotion dates to users.
- Converting a local publication boundary into a UTC instant.

Use the published Promotion IANA timezone explicitly for historical and active calendar rules; drafts display the current Business timezone.

Do not infer calendar logic from PHP/server system timezone.

### Local-day queries

When the published Promotion's local weekday or time determines a recurring multiplier window, convert the post-lock operation instant in PostgreSQL using `AT TIME ZONE` with the Promotion timezone.

Do not introduce a duplicated local-date column only to avoid the query.

## Transactions and external effects

Network/provider calls never execute inside the authoritative database transaction.

Pattern:

```text
authorize
validate
begin transaction
commit authoritative domain state
dispatch Wallet synchronization after commit
return domain result
```

If Google Wallet synchronization fails, domain state remains committed.

## Authorization

### Business owner

Use explicit `business.user_id` ownership.

Use Policies or direct server-side authorization at the owning boundary.

Never trust a `business_id` merely because it came from Livewire state or a hidden input.

### Customer pass

Customer passes are not `User` accounts.

Every pass operation is scoped through the authenticated Business before any progress/reward detail is returned.

## Validation

Use Laravel/Livewire server-side validation.

Use fixed schema/range validation for Promotion configurations, including disjoint same-day windows, whole-day/timed exclusivity, and allowed multipliers.

Prefer:

- Enum validation.
- Integer ranges.
- Distinct weekday arrays.
- Valid IANA timezone identifiers.
- Normalized Business-scoped manual-code format.

Client-side validation is UX only.

## Livewire

Use Livewire 4 for server-driven interactivity.

For new project-owned full-page routes, prefer native Livewire multi-file components when PHP, Blade, and colocated tests form one clear component responsibility. Route them with `Route::livewire` and render through the appropriate starter layout (`layouts::public` or `layouts::app`) using its `$slot`.

Preserve the starter's authentication/settings screens and their existing component formats; do not convert stable SFCs solely for consistency.

Livewire owns:

- Form state.
- Validation feedback.
- View interaction.
- Loading/disabled states.

Actions/evaluators own:

- Consequential state transitions.
- Authoritative domain rules.
- Transaction semantics.

## Blade, Flux, Alpine, and Tailwind

- Prefer Flux UI Free components where they fit the product behaviour.
- Prefer semantic HTML before custom JavaScript.
- Use the dark-only Onest theme: shared Tailwind `@theme` tokens in `resources/css/app.css` implement the semantic palette roles governed by the [canonical UI/UX guide](../ui-ux-guidelines.md#approved-dark-only-palette) and its reconciled current owner-approved decisions. `resources/views/partials/theme-default.blade.php` initializes dark appearance before Flux loads through the shared head; do not offer a light-mode toggle. Use Alpine only for small client-only interactions such as lightweight disclosure.
- Keep camera/scanner JavaScript isolated to the validation component.
- Never duplicate authoritative Promotion/Reward state in Alpine.
- Use the starter's shared Flux, Tailwind, and Vite pipeline; do not duplicate asset or theme infrastructure. The current authentication wrapper `resources/views/layouts/auth.blade.php` renders `layouts::auth.card`; the application uses `resources/views/layouts/app/header.blade.php` as its header layout.
- Use `wire:navigate` conservatively. Persist shared navigation only outside Livewire components when needed, and keep active-link styling dynamic after navigation.
- Do not add a SPA framework for MVP.

## Scanner implementation

The validation component has one authoritative server operation whether input came from:

- Camera barcode scanner; or.
- Manual code.

The client only provides an identifier/token to the same lookup/validation boundary.

Camera failure must not require route change or modal discovery. Manual code is already visible directly below the scanner.

## Google Wallet integration

Use one project-owned concrete Integration for the Google Wallet provider.

It is responsible for:

- Class/object payload mapping.
- Object creation/update.
- Save-to-Wallet issuance payload.
- Provider error classification.

It is not responsible for:

- Promotion eligibility.
- Visit acceptance.
- Reward completion.
- Redemption authorization.

Do not add a provider interface while only one provider exists unless a real test/substitution boundary requires it.

## Jobs

Use retryable jobs for provider synchronization after authoritative commits.

A sync job:

1. Loads the Customer pass and current authoritative state.
2. Builds the Wallet presentation model.
3. Updates the provider object.
4. Logs success/failure context.
5. Tolerates duplicate execution.

Use queue retry/backoff capabilities before custom retry infrastructure.

## Enums

Use PHP backed string enums for stable value sets such as:

```text
PromotionStatus
WalletPresentationState
```

Store readable strings and protect allowed values with PostgreSQL checks where appropriate.

Do not create enums for phases that are derived from timestamps.

## Source style

- Laravel Pint defines PHP formatting.
- Follow configured PHPStan/Larastan rules.
- Use native PHP types and descriptive English identifiers.
- Prefer early returns when they flatten control flow.
- Keep methods cohesive.
- Split by responsibility, not arbitrary line counts.
- Avoid vague class names such as `Manager`, `Helper`, `Handler`, or `Util` when a domain/capability name exists.
- Keep Blade readable; do not hide normal markup inside PHP string builders. Separate semantic blocks with whitespace, group related attributes, and break attribute lines only when length impedes scanning; do not enforce one attribute per line.
- Scoped Pint `--blade` needs the npm packages `prettier`, `prettier-plugin-blade`, and `prettier-plugin-tailwindcss`, which are not installed here. Its Blade check is not a passing validation or a required dependency; apply the manual conventions above without adding formatter tooling.
- Keep code as a top-to-bottom narrative with whitespace between semantic blocks. In Livewire components, order meaningful properties, lifecycle hooks, component actions, optional private helpers, then listeners last. Do not add empty blocks merely to satisfy the order.
- In Blade, use lowercase semantic HTML comments such as `<!-- form actions -->` where they clarify sections. Use blank lines and multiline attributes when needed for readability; do not compress PHP, Blade, or JavaScript into dense one-liners.

## Component-local JavaScript

Apply the same cohesion, intent naming, early returns, and top-to-bottom narrative defined in [Source style](#source-style). Passing tests does not make dense or tangled source maintainable.

### Readable structure

Keep page-local UI behaviour in the component's `@script`. Scope DOM references to its Livewire root (`$wire.$el`); an IIFE can provide a clear local boundary without introducing globals. Use the configured Vite entry only for genuinely shared behaviour, not merely to move a long script elsewhere.

Organize the script so a developer can follow its purpose, setup, execution, and destruction without tracing a maze of callbacks:

- Group configuration once, with descriptive names and clear units for dimensions, angles, and delays.
- Resolve stable DOM references together within the owning root. Re-resolve references when morphing replaces their nodes rather than retaining stale elements.
- Keep lifecycle state and resource ownership explicit, separate from feature calculations.
- Give each feature a named initializer, such as `initializeNavigation`, `initializeHeroLayout`, or `initializePassInteraction`, with a focused responsibility.
- Keep initialization calls together in dependency order and make the destruction entry point easy to find.

A conceptual reading order for a component script is:

```text
component root and duplicate-initialization guard
configuration and DOM references
lifecycle state and owned resources
small utilities with meaningful purposes
named feature initializers
central destruction routine
initialization calls and lifecycle wiring
```

This is a reading aid, not a required template or naming scheme. Omit unused machinery. A root-local initialization guard should prevent duplicate setup and be released appropriately when that instance is destroyed.

Split by responsibility, not by putting each entire feature into one oversized function. Named handlers and small calculations should expose intent instead of burying it in nested callbacks. Use whitespace between semantic steps and early returns for unsupported or inapplicable cases.

Extract a utility only when it removes real duplication or makes a non-obvious operation clearer: for example, separating pointer presentation calculations from frame rendering, setting a CSS variable, or scheduling tracked work. Preserve when geometry is read, when DOM writes occur, and how pending work checks its owner and current nodes. Avoid generic `helpers.js` collections, ornamental wrappers, and class factories. Use the responsibility headings defined in [PHPDoc and comments](#phpdoc-and-comments) alongside named functions and whitespace, not instead of clear structure. Every abstraction must earn its reading cost.

### Lifecycle ownership and cleanup

Every listener, observer, timer, and animation frame has one identifiable component owner and a cleanup path. Prefer an `AbortController` with signal-bound listeners where supported; track observers and pending timers/frames explicitly when the component owns several of them. Small scripts need only the bookkeeping their actual resources require.

Use one centralized, idempotent destruction routine. Repeated teardown must be harmless, including when navigation and root removal overlap. Teardown must:

- Mark the instance destroyed before cancelling work, so re-entrant callbacks cannot restart it.
- Abort or remove listeners, disconnect observers, cancel queued timers and frames, and clear their registries.
- Reset component-owned transient styles, including interaction transforms and pending reveal states; still-connected content must not remain hidden.
- Release the instance's initialization guard without affecting a replacement instance.

Remove completed timers and frames from tracking as well as cancelling pending ones. Guard delayed callbacks, observer callbacks, and continuations such as font readiness against both destruction and a detached or replaced root before reading or writing DOM or scheduling more work.

Wire teardown to the actual component lifetime, including Livewire navigation and root removal, not just a page-level event. A root-removal `MutationObserver` is an option when needed, not a mandatory API; it too must be owned and disconnected. Keep lifecycle wiring separate from navigation, layout, interaction, and reveal responsibilities.

### Behaviour and review

Readable organization must preserve the component's actual DOM contract, not impose the shape of an example script. Keep semantic HTML and Flux-first composition, server-owned state, and the existing shared styling roles defined in [Blade, Flux, Alpine, and Tailwind](#blade-flux-alpine-and-tailwind).

Preserve usable content without JavaScript or optional observer APIs, natural layout growth, keyboard and anchor access, touch behaviour, and reduced-motion preferences, including preference changes while work is pending. Cleanup must restore a safe visible state rather than strand partially animated content.

Review human readability separately from objective behavioural verification. A reviewer should be able to explain each feature's responsibility, initialization order, resource owner, and destruction path by reading the source top to bottom.

Tests protect observable geometry, fallbacks, interactions, keyboard access, reduced motion, duplicate initialization, and disposal. Do not assert initializer names, helper counts, source ordering, or formatting as if they were behaviour. Refactoring still requires the applicable tests and lifecycle checks; cleaner-looking source is not evidence that cleanup or accessibility works.

## PHPDoc and comments

Use clear code and native types first.

Add English PHPDoc only when it communicates a contract not obvious from the signature:

- Generic/array shape.
- Domain invariant.
- Unit/timezone assumption.
- Concurrency/idempotency guarantee.
- Side effect/provider timing.
- Exception guarantee.

Do not add routine docblocks to obvious constructors, accessors, or framework hooks.

Use **lowercase English section/block headings** to group meaningful responsibilities in PHP and JavaScript, just as semantic region comments do in Blade. For example, a component script may mark `configuration`, `utilities`, `navigation`, `layout`, `pass interaction`, `reveal`, `lifecycle`, and `initialization` where those groups exist. Choose headings that fit the source; no fixed template, separator width, initializer names, or helper count is required.

For major cohesive JavaScript responsibilities, use uniform three-line `//` headings: matching separator lines around a short lowercase English label, indented with the surrounding code. Keep blank lines between responsibilities. For example:

```javascript
// ---------------------------------------------------------------------
// pass interaction
// ---------------------------------------------------------------------
```

These useful separators complement well-named functions and whitespace; they are not ornamental banners. Do not add a heading per helper, narrate every line, or impose empty sections, a fixed helper order, or a class architecture. A small configuration group may use a plain `// configuration` comment when it does not need a major heading. Keep long explanations out of headings; place the actual non-obvious rationale near the constrained operation.

Use **lowercase English internal comments** to explain intent and non-obvious constraints close to the relevant PHP or JavaScript operation. Explain why a constraint exists rather than restating each line. PHPDoc retains the contract-focused rules above.

Review comment usefulness as human readability, separately from behaviour checks; do not add source assertions for comment wording, separators, or section placement.

## Localization

Customer/Business UI uses professional, neutral Spanish translated through Laravel; avoid hard-coded repeated user-facing copy.

Technical documentation, source identifiers, comments, enum values, and log event names are English.

Use Laravel translations for:

- Promotion generated copy.
- Validation/error messages.
- Accessibility labels.
- Wallet presentation text.
- Repeated operational text.

Centralize Promotion copy so UI preview and Wallet mapping cannot drift.

## Structured logging

Laravel logging is based on Monolog.

### Production format

Write one JSON object per log record to `stderr` so container/platform log collectors can ingest it.

Use Monolog `JsonFormatter` through Laravel logging configuration/tap customization.

### Correlation

Assign one UUID request identifier in middleware and share it with log channels using Laravel log context.

Recommended stable fields:

```text
event
request_id
actor_type
actor_id
business_id
promotion_id
customer_pass_id
outcome
reason
```

Not every event needs every field.

Use stable machine-readable event names, for example:

```text
visit.accepted
visit.replayed
visit.rejected
reward.unlocked
reward.redeemed
promotion.cancelled
wallet.sync_failed
auth.throttled
```

### Secret hygiene

Never log:

- Passwords.
- Session/cookie values.
- Authorization headers.
- Raw validation tokens.
- Google Wallet private keys.
- Full save JWTs.
- Sensitive provider payloads.

Local development may use a human-readable channel if desired, but production event semantics stay the same.

## Error handling

Expected domain rejections become clear UI states.

Unexpected exceptions:

- Return a safe generic message.
- Include the request ID when useful for support.
- Write detailed server-side context without secrets.

Do not show stack traces in production.

## Security headers

Prefer framework/deployment mechanisms before custom packages.

Keep the baseline described in the security architecture. Do not add an untested strict CSP that breaks Livewire/Alpine/Vite.

## Retired two-factor persistence

The new retirement migration drops only existing columns among `users.two_factor_secret`, `users.two_factor_recovery_codes`, and `users.two_factor_confirmed_at`. It also succeeds on fresh no-2FA schemas where all three are absent. Its rollback is intentionally a no-op: deleted credential values cannot be recovered, and rollback must not recreate retired columns. The original starter views and original schema migration remain unchanged; Fortify's two-factor feature stays disabled and passkeys remain independently configured.

To re-enable two-factor authentication in a future project, explicitly opt in to Fortify's feature, restore the `TwoFactorAuthenticatable` model trait and appropriate hidden attributes, add a new forward migration for the three credential columns, restore compatible setup/challenge UI and tests, and enroll users with new credentials. Do not treat rollback as credential recovery.

## Testing convention

Use Pest for new project-owned tests where practical.

Preserve untouched Starter Kit PHPUnit tests unless the active work requires changes.

Choose the natural boundary:

- Unit/domain evaluator tests.
- PostgreSQL-backed feature/integration tests.
- Livewire component tests.
- Provider-boundary tests/fakes.
- A small set of Playwright browser journeys.

Do not repeat identical assertions across layers without a reason.

## Framework research

When framework behaviour is version-sensitive:

1. Inspect installed versions and local configuration.
2. Use targeted official Laravel/Livewire/Flux documentation.
3. Use Laravel Boost or other installed framework documentation tools for the exact question.
4. Inspect installed source when necessary.
5. Do not load broad documentation sets when a narrow lookup resolves the uncertainty.

## Prohibited defaults

Do not add without a current demonstrated need:

- Repository pattern around Eloquent.
- Generic Service layer for CRUD.
- CQRS/event sourcing.
- Microservices.
- Generic BaseAction hierarchy.
- Generic rules engine.
- Custom clock service replacing PostgreSQL for domain validity.
- Duplicate local-date persistence.
- Universal SoftDeletes.
- Universal audit columns.
- Customer CRM/profile subsystem.
