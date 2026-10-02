# FidelitoPass Database Standard

This document defines durable PostgreSQL persistence guarantees. Migrations own physical schema and SQL; no proposed shape here is a competing schema authority.

## Principles

- Store domain facts, not convenience duplicates.
- Use PostgreSQL constraints for durable row/relationship invariants.
- Use framework conventions before custom infrastructure.
- Use UTC instants for persisted time.
- Derive Business-local calendar meaning using the published Promotion timezone.
- Keep ownership, domain facts, and logs separate.
- Do not add generic audit/status/delete columns to every table.

## Supported database

PostgreSQL 16 is the authoritative database for MVP.

Application and database timezone configuration remain UTC.

## Identifiers

### Internal primary keys

Use conventional bigint internal primary keys for joins.

### Public identifiers

Use UUIDv7 public identifiers only where a record is exposed across an untrusted boundary.

Expected examples:

- Business.
- Promotion.
- Customer pass.

Internal foreign keys continue to use bigint IDs.

### Provider identifiers

Google Wallet object/class identifiers remain integration fields. They never replace domain primary/public IDs.

## Timestamps and timezone model

### General rule

Use `timestamp with time zone` (`timestamptz`) for domain instants.

Migration syntax belongs to source. Preserve timezone-aware storage for domain instants.

PostgreSQL stores `timestamptz` instants internally in UTC. The Business/Promotion IANA timezone is stored separately because a `timestamptz` does not retain the original timezone name.

### Domain clock

Rule-relevant "now" comes from PostgreSQL.

For a mutating operation, acquire the relevant row locks first, then capture one current PostgreSQL wall-clock instant. Reuse that one database-derived instant for every deadline check, Business-local point-rule evaluation, the Visit instant, the redemption instant, and related audit/event timestamps in the operation.

Do not use transaction-start timestamps for lock-sensitive Promotion/Visit/Reward validity: a lock wait can make it stale. Do not take multiple authoritative clock readings within one operation. For read-only phase queries, use an explicitly current PostgreSQL wall-clock instant.

Do not use:

- Browser time.
- Application-host time to decide Promotion validity.
- Request-supplied timestamps for accepted Visits.

### Business timezone

The Business timezone stores a valid IANA identifier such as:

```text
America/La_Paz
Europe/Madrid
```

It is the current timezone used to display drafts and the default for future Promotion publication. Drafts have no independent timezone selector; if the Business timezone changes after draft review, require refreshed review and reconfirmation before publication.

### Promotion timezone snapshot

The published Promotion timezone stores the confirmed Business timezone at publication.

This is intentional duplication because it freezes calendar semantics for that Promotion.

Changing Business settings later must not reinterpret historical Visit timestamps, awarded points, or Promotion deadlines.

### Promotion window

The Business configures a local start date and final local date. A published Promotion persists only the derived UTC
window and frozen IANA timezone for those terms, not separate local-date columns.

The start instant is inclusive.

The end instant is exclusive and represents local midnight immediately after the selected final date. Publication permanently
freezes both UTC instants and the timezone snapshot, which represent the original local-date terms. Cancellation keeps
the original window and timezone and records the cancellation instant; effective occupancy is the original interval truncated at cancellation when earlier than its end,
empty when cancelled before start.

Derive the original local start date by converting the start instant to the published timezone.

Derive the last included local date from the local exclusive-end date minus one calendar day.

Subtract one local calendar date after timezone conversion, not 86400 seconds from the instant. The UI displays these
dates using the frozen Promotion timezone. Do not add redundant derived local-date columns to Visits.

### Visit time

Visits persist one required timezone-aware confirmation instant. Do not persist a duplicated local Visit date in MVP.

When needed, derive the local Visit date by converting its instant to the published timezone.

For mutation calendar calculations, derive the local date from the operation's one captured instant.

For read-only phase queries, use an explicitly current PostgreSQL wall-clock instant.

### Example

Stored Visit instants:

```text
2026-09-22 02:30+00
2026-09-22 05:00+00
```

For `America/La_Paz`:

```text
2026-09-21 22:30
2026-09-22 01:00
```

They are different local calendar days even though both UTC values fall on 22 September.

This is exactly why local-day rules are derived from the Promotion timezone rather than from the UTC date.

## Application date handling

Application date handling keeps rule-relevant values immutable and preserves their timezone meaning. Parsing and local rendering do not replace PostgreSQL's live domain clock. Framework record timestamps are not validity clocks.

## Ownership

Business ownership is an explicit persisted relationship to its owner.

Do not infer ownership from:

- Creator metadata.
- Log context.
- Request/session history.

## Status fields

Add `status` only when a record has a real stored lifecycle.

### Promotion

Stored values:

```text
draft
published
cancelled
```

Use:

- PHP backed enum.
- Readable string database value.
- PostgreSQL `CHECK` constraint.

Do not persist `scheduled`, `active`, or `ended`; they are derived from published status + an explicitly current PostgreSQL wall-clock instant + UTC window.

### Other tables

Do not add generic `active/inactive` fields.

Prefer real facts:

- A final redemption instant.
- Row existence.
- Provider identifier availability.

Native PostgreSQL ENUM types are not the default. PHP enum + string + `CHECK` is easier to evolve through Laravel migrations while keeping database protection.

## Soft deletes

Do not use `SoftDeletes` by default.

MVP policy:

- Visit: immutable; never soft-delete in normal operation.
- Reward entitlement: preserve; redemption is a timestamp, not deletion.
- Published Promotion: cancel instead of delete.
- Draft Promotion: may be hard-deleted before publication.
- Customer pass: preserve while it represents a Wallet object.
- Business/User deletion is not an MVP workflow.

Add soft deletion only when a concrete recovery/legal/product requirement exists.

## Core records

### Business

Constraints:

- The public identifier is unique.
- The owner relationship is unique in MVP.
- Timezone validated in application against supported IANA identifiers.
- Regular accepted Visits have a fixed one-point base; no configurable regular-points column or Business-global multiplier schedule.

### Promotion-owned multiplier windows

Use a lean relational child of each Promotion (table name to be chosen during implementation), not a Business-global rule builder.

- Each multiplier window has one owning Promotion, a required local weekday and an allowed multiplier.
- Row `CHECK`: the weekday is valid and the multiplier is x2, x3 or x5; no other multipliers are accepted.
- Row `CHECK`: both times null for whole day, or both nonnull with start before end for a half-open intraday window; midnight-crossing rules are split across weekdays.
- Multiple timed windows per weekday are allowed, with touching endpoints but no overlap; whole-day and timed windows on that weekday are mutually exclusive. Windows do not stack; outside them a Visit earns one point.
- Cross-row overlap is validated transactionally while serializing edits to the draft Promotion, including concurrent edits. No fixed count of rules and no JSONB configuration. Publication freezes all windows, even when scheduled; new Promotions inherit no prior schedule.

### Promotion

Constraints:

- The public identifier is unique.
- FK to Business.
- Constraint that the start precedes the exclusive end.
- Allowed `status`.
- The required point target is positive.
- Required Reward title.
- The published snapshot is immutable even while scheduled or after cancellation: the inclusive start, exclusive end and timezone represent the original local-date terms; target, Reward title and optional description, and every multiplier weekday/time/value are also frozen. Ended instances remain historical. Drafts remain editable.

Published effective-window overlap is a cross-row rule. Publication and cancellation serialize under the Business row lock, then recompute occupancy from original UTC windows and the cancellation instant; cancellation uses one current post-lock PostgreSQL instant. A cancelled interval occupies the original interval truncated at cancellation when earlier than its end, empty if cancelled before start. Touching endpoints are valid. A date-only replacement after intraday cancellation cannot start before the next Business-local midnight. Do not add database extensions/exclusion constraints only for this rule unless scale/concurrency demonstrates the need.

### Customer pass

Constraints:

- The public identifier is unique.
- The Wallet object identity is unique when present.
- Validation-token hash unique.
- The manual code is unique within its Business.

The manual code is a Business-scoped lookup identifier, not the authoritative secret.

### Visit

Constraints/indexes:

- FKs to Customer pass, Promotion, User.
- Database-enforced same-Business relationship between the referenced Customer pass and Promotion; independent FKs alone do not enforce this invariant. PostgreSQL must reject a cross-Business Visit even when persistence bypasses the UI.
- The confirming actor identifies the authenticated Business owner who explicitly confirms the Visit. Server authorization checks that the User owns that Business.
- The operation identity is unique.
- Awarded points are positive.
- Index real pass/Promotion/time lookup patterns when justified by the implemented queries.

There is intentionally no local-day uniqueness rule. Legitimate repeat Visits on the same Business-local day may each be accepted.

The Visit-validation command serializes operations with relevant row locks. After ownership, credential and operation-identity
checks, an already committed operation returns its prior accepted result without a new Visit. For a new mutation, the
command captures one current PostgreSQL operation instant and checks current eligibility; later
Promotion expiry/cancellation does not veto an authorized replay. Idempotency and database uniqueness prevent one
technical validation request from creating duplicate Visit facts.

### Reward entitlement

Constraints:

- At most one entitlement per Customer pass and Promotion.
- FKs to pass/Promotion/user.
- Database-enforced pass/Promotion relationships retain the same Business; independent foreign keys alone do not establish common ownership. The redeeming actor identifies its authenticated Business owner who explicitly confirms redemption; server authorization checks that the User owns that Business.
- The redeeming actor is absent until redemption.

The unlock and redemption instants are domain instants. Set them from the mutation's one post-lock PostgreSQL instant, the operation instant.

No separate Redemption table is required in MVP because one entitlement has one optional final redemption.

## Foreign keys

Use foreign keys for real relationships.

Choose delete rules deliberately:

- Restrict deletion of parents that have immutable domain history.
- Cascade only when child data has no independent meaning and removal is unquestionably correct.
- Do not cascade-delete Visits or Reward history from an ordinary UI action.

PostgreSQL does not automatically index referencing FK columns; add indexes for real lookup patterns.

## Uniqueness and concurrency

Database uniqueness is the last barrier for exact duplicates where the invariant can be represented directly.

Use transactions + row locks for rules that depend on current state or derived local dates.

Critical concurrent paths:

- Same validation operation retried or submitted concurrently.
- Promotion completion attempted concurrently.
- Reward redeemed twice.
- Initial Wallet provisioning retried.

## JSONB

Do not use `jsonb` for the core Promotion or point-earning configuration in MVP. Keep the Promotion columns explicit and multiplier windows in a lean relational table.

Use `jsonb` only later for genuinely variable external metadata that does not deserve first-class relational fields.

## Logging versus audit columns

Do not add generic creator, updater or audit-reference columns to every table.

Use:

- Explicit ownership for Business.
- Immutable domain facts for Visit/Reward state.
- Structured application/security logs for request/event tracing.
- Conventional framework creation/update timestamps.

Add direct actor attribution only when it is itself a domain fact, such as the owner confirming a Visit or final redemption.

## Migrations

Use Laravel migrations as the schema authority.

- Do not modify migrations already applied to shared/production data.
- For new schema, check the target PostgreSQL database and applied migration history before migration; preserve existing data and use forward-only changes where migrations have already been applied. Test data preservation and relevant constraints proportionately.
- Custom SQL requires a clear reason and focused migration tests.
- Avoid framework-independent schema abstractions that duplicate Laravel's migration API.
