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
PublishChallengeAction
CancelChallengeAction
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

## Points and Challenge calculation

MVP has one Challenge mechanic: reach a target number of points before the Challenge ends.

Keep the calculation direct and explicit. A separate Strategy hierarchy is not required.

At Visit validation time:

- Resolve the applicable point value from the Business point configuration and Business-local time.
- Persist that value as `points_awarded` on the accepted Visit.
- Calculate Challenge progress from the sum of awarded points for that Customer pass and Challenge.
- Create the Reward entitlement when the target is reached.

Do not create generic condition objects, expression languages, dynamic rule builders, or several interchangeable Challenge evaluators in MVP.

## Time and date handling

### Application timezone

Keep Laravel application timezone set to UTC.

### Domain validity

Challenge phase, Visit acceptance, Reward expiry, and Redemption validity use PostgreSQL current time inside the owning query/transaction.

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
- Presenting local Challenge dates to users.
- Converting a local publication boundary into a UTC instant.

Use the Challenge IANA timezone explicitly.

Do not infer calendar logic from PHP/server system timezone.

### Local-day queries

When Business-local weekday or time affects the optional special point rule, keep the conversion in PostgreSQL using `AT TIME ZONE`.

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

Use fixed schema/range validation for Challenge configurations.

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
- Use the dark-only Onest theme: shared Tailwind `@theme` tokens in `resources/css/app.css` define the black shell, charcoal canvas/surfaces, off-white ink, and lavender accent. `resources/views/partials/theme-default.blade.php` initializes dark appearance before Flux loads through the shared head; do not offer a light-mode toggle. Use Alpine only for small client-only interactions such as lightweight disclosure.
- Keep camera/scanner JavaScript isolated to the validation component.
- Never duplicate authoritative Challenge/Reward state in Alpine.
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

- Challenge eligibility.
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
ChallengeStatus
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
- Keep code as a top-to-bottom narrative with whitespace between semantic blocks.

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

Comments explain **why a non-obvious constraint exists**, not what a line of code does.

## Localization

Customer/Business UI uses professional, neutral Spanish translated through Laravel; avoid hard-coded repeated user-facing copy.

Technical documentation, source identifiers, comments, enum values, and log event names are English.

Use Laravel translations for:

- Challenge generated copy.
- Validation/error messages.
- Accessibility labels.
- Wallet presentation text.
- Repeated operational text.

Centralize Challenge copy so UI preview and Wallet mapping cannot drift.

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
challenge_id
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
challenge.cancelled
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
