---
name: laravel-maintainable-implementation
description: "Use for project-owned Laravel, PHP, Livewire, PostgreSQL, Google Wallet integration, and application-code changes in FidelitoPass."
license: Apache-2.0
metadata:
  author: FidelitoPass
  version: "3.0"
---

## Purpose

Apply FidelitoPass coding and maintainability conventions without loading broad project documentation eagerly.

Framework-specific skills/documentation own framework APIs. This skill owns project-specific implementation constraints.

## Default context

Start with:

1. active work item and acceptance criteria;
2. affected source files;
3. nearby tests;
4. installed framework/package versions when relevant.

Load a project document only when the current implementation decision needs the knowledge it owns.

## Project coding conventions

- Prefer conventional Laravel structure and Eloquent.
- Do not introduce formal architecture layers, generic repositories, generic service buckets, or empty scaffolding.
- Use one focused `<Verb><Subject>Action::handle()` for consequential business commands that coordinate transactions, authorization, idempotency/concurrency, or state-tied external effects.
- Routine operations remain direct until they acquire meaningful business complexity.
- The Action owns its complete transaction boundary.
- Keep network/provider calls outside database transactions.
- Commit authoritative local state first; dispatch Wallet synchronization after commit.
- Use Laravel relationships, casts, scopes, Policies, constraints, transactions, row locks, jobs, scheduling, and query builder where each is the clearest native mechanism.
- Add a Service only for a cohesive reusable capability with a real current responsibility.
- Add an interface only when more than one real implementation/substitution boundary exists.
- Do not create a customer `User` account model for MVP.
- Organizer/Business authorization is based on explicit ownership.

## FidelitoPass domain rules

- MVP has one Challenge mechanic: reach a target number of points before the Challenge ends.
- Do not build a Challenge type catalog or rule engine.
- Each accepted Visit stores immutable `points_awarded` determined at validation time.
- The Business has one regular Visit point value and at most one optional special weekday/time point rule.
- Legitimate repeat Visits on the same day may each be accepted.
- Technical retries are protected by idempotency and must not create duplicate Visit facts.
- `visited_at` is database-generated `timestamptz`.
- Do not add a duplicated local-date Visit column.
- After acquiring required row locks, capture PostgreSQL `clock_timestamp()` once as the operation instant for deadline, point-rule, and validity decisions.
- Use Business-local timezone conversion for the optional special point rule and Challenge deadlines.
- Challenge progress is the sum of immutable awarded points for that Customer pass and Challenge.
- Reward entitlement is unique per Customer pass + Challenge.
- Redemption is one final timestamped state.
- Google Wallet is the only customer-facing pass; PostgreSQL is authoritative.

## Points calculation convention

Keep the MVP calculation direct and explicit:

```text
Visit accepted
  -> resolve current point value
  -> persist points_awarded
  -> sum Challenge points
  -> unlock Reward when target is reached
```

Do not introduce Strategy hierarchies, condition trees, expression languages, or dynamic rule builders for this flow.

## Livewire and presentation

- Use native Livewire multi-file components for new project-owned pages/components when PHP, Blade, and colocated tests form one clear responsibility.
- Preserve stable existing component format when conversion has no product value.
- Keep Livewire responsible for presentation state and interaction orchestration.
- Delegate consequential state changes to Actions.
- Prefer Flux Free components, semantic Blade, Tailwind, and native framework behaviour before custom JavaScript.
- Use Alpine only for small client-only interaction such as disclosure/theme.
- Keep camera/scanner JavaScript isolated.
- Keep page-local interactions in the owning Livewire component's `@script` when appropriate; use the configured Vite entry for shared JavaScript and CSS. Do not add a standalone bundle, inline global script, or duplicate asset pipeline just to move code.
- Make DOM listeners, observers, animation frames, and timers idempotent across Livewire navigation/morphs; release or cancel them when their owner is removed. Respect touch and reduced-motion behavior.
- Client/Livewire state never decides Visit validity, Challenge completion, Reward availability, or Redemption finality.
- Manual-code input remains directly below the scanner; do not hide it behind another interaction.

## Naming, formatting, and source style

- Laravel Pint defines PHP formatting.
- Follow configured PHPStan/Larastan expectations.
- Use native types and descriptive English identifiers.
- Prefer early returns when they make control flow flatter.
- Keep methods cohesive; split by responsibility, not arbitrary length.
- Avoid vague names such as `Manager`, `Helper`, `Handler`, and `Util` when a domain/capability name exists.
- Keep Blade readable: separate header, form, card, navigation, section, and action blocks with indentation and a genuinely empty source line between adjacent logical blocks (not merely text echoes on their own lines). For long views, add brief English HTML comments before major visible regions (for example `<!-- navigation -->` or `<!-- main content -->`) when they make the corresponding UI easy to find; do not comment every element or restate obvious markup. Do not compress multiple ordinary elements, nested directives, or whole sections onto one line or into PHP strings/helpers.
- Use descriptive English identifiers in Blade too, including DOM `id`, fragment targets, `data-*` hooks, and linked `href="#..."`; update every reference and test together rather than translating user-facing copy into identifiers.
- Put visible labels, localized expressions (`{{ __('...') }}`), and other `{{ }}` text on their own indented line between opening and closing tags, including `<flux:label>`, headings, buttons, links, and option text. Keep short self-closing components with attribute-bound labels (`:label="__('...')"`) on one line when readable. Preserve inline whitespace only where it determines rendered text (such as word separators or mixed inline emphasis).
- For JavaScript, apply the same maintainability standard as PHP: descriptive English names, focused functions, early returns, one responsibility per block, and whitespace between steps. Avoid dense one-line handlers, nested callbacks that obscure control flow, magic numbers without named intent, and comments that restate code. Extract helpers only when they remove real complexity or duplication; do not over-engineer a local interaction.
- Write code as a top-to-bottom narrative with whitespace between semantic blocks.
- Extract helpers when they remove meaningful complexity or duplication.

## PHPDoc and comments

Use native types and clear names first.

Add English PHPDoc only when it communicates a contract not obvious from the signature:

- generic/array shape;
- invariant;
- time unit/timezone assumption;
- concurrency/idempotency guarantee;
- security boundary;
- side effect/provider timing;
- exception guarantee.

Do not add rote docblocks to obvious constructors, accessors, framework hooks, or self-explanatory private methods.

Keep internal code and HTML comments in lowercase English; reserve comments for useful major regions, not every element. Comments explain why a non-obvious constraint exists. Refactor ordinary control flow instead of explaining it with long comments.

## Date/time convention

- Keep Laravel timezone UTC.
- Prefer `immutable_datetime` for rule-relevant timestamps.
- Use CarbonImmutable/framework helpers for Business-local input/output conversion.
- Use the captured PostgreSQL `clock_timestamp()` operation instant for authoritative validity decisions; do not re-read time within the operation.
- Use PostgreSQL `AT TIME ZONE` for Business-local weekday/time point rules and Challenge deadlines.
- Do not trust browser/application-host time.

## Persistence convention

- Use Laravel `timestampsTz()` where timezone-aware framework timestamps are appropriate.
- Use database defaults/expressions for rule-relevant event timestamps when specified by the database standard.
- Use PHP string enums + PostgreSQL checks for small lifecycles.
- Do not add SoftDeletes without a concrete recovery requirement.
- Do not add generic audit columns to all tables.
- Attribute domain facts directly when required (`validated_by_user_id`, `redeemed_by_user_id`).

## Structured logging convention

Production logs are JSON and written to `stderr` through Laravel/Monolog.

Use stable event names and contextual fields.

Share one request UUID into log context.

Never log passwords, cookies, authorization headers, raw validation tokens, Wallet private keys, or full sensitive provider payloads.


## Localization

UI is Spanish. Every visible Blade string, including labels, headings, button/link text, placeholder, `alt`, `title`, and accessible names, must come from Laravel i18n (`__()` or the project's existing translation mechanism). Do not hardcode Spanish UI copy in Blade, even for an illustrative card; keep dynamic sample data in translation keys when it is display text. Empty decorative `alt=""` is not visible copy.

Technical documentation, identifiers, comments, enum values, and log event names are English.

Centralize generated Challenge copy so:

- Challenge preview;
- public join page;
- Wallet payload;
- operation result messages

cannot drift independently.

## Testing convention

Use Pest for new project-owned tests where practical.

Preserve untouched Starter Kit PHPUnit tests unless active work requires change.

Use the natural layer:

- evaluator unit/domain;
- PostgreSQL feature/integration;
- Livewire component;
- Wallet boundary fake/contract;
- selected Playwright journey.

Prefer semantic browser selectors and auto-wait; do not use fixed sleeps.

## Lazy-loading route

| Trigger | Read |
|---|---|
| Domain lifecycle/invariant unclear | relevant section of `docs/conceptual-design.md` |
| Points/Challenge calculation unclear | relevant section of `docs/challenge-model.md` |
| User-visible acceptance unclear | relevant requirement in `docs/requirements.md` |
| Wallet state/copy unclear | relevant section of `docs/wallet-presentation.md` |
| Page interaction/visual rule unclear | relevant section of `docs/ui-ux-guidelines.md` |
| Schema/time/index/deletion decision | relevant section of `docs/development/database-standard.md` |
| Auth/token/rate-limit/logging decision | relevant section of `docs/architecture/security.md` |
| Laravel project convention needs detail | relevant section of `docs/development/laravel-application-standard.md` |
| Verification depth unclear | relevant section of `docs/quality-strategy.md` |
| Time model disputed | `docs/architecture/decisions/004-database-time-and-business-calendar.md` |
| Challenge architecture disputed | `docs/architecture/decisions/003-single-points-challenge.md` |

Do not follow references from a loaded document unless the active decision genuinely requires the other document.

## Framework research

When framework behaviour is uncertain:

1. inspect installed versions and local configuration;
2. use targeted official Laravel/Livewire/Flux documentation;
3. use installed Laravel Boost/framework documentation tools for the exact question;
4. inspect installed source when version-specific behaviour still matters;
5. do not load broad framework documentation or infer APIs when targeted evidence is available.

## Output

Report:

- files changed;
- meaningful domain/security/time boundaries affected;
- checks actually run/observed;
- unresolved risk or deliberate trade-off.
