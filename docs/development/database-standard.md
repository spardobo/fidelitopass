# FidelitoPass Database Standard

This document defines PostgreSQL persistence conventions for project-owned schema.

## Principles

- Store domain facts, not convenience duplicates.
- Use PostgreSQL constraints for durable row/relationship invariants.
- Use framework conventions before custom infrastructure.
- Use UTC instants for persisted time.
- Derive Business-local calendar meaning using the published Challenge timezone.
- Keep ownership, domain facts, and logs separate.
- Do not add generic audit/status/delete columns to every table.

## Supported database

PostgreSQL 16 is the authoritative database for MVP.

Application and database timezone configuration remain UTC.

## Identifiers

### Internal primary keys

Use Laravel's conventional bigint primary keys for joins:

```php
$table->id();
```

### Public identifiers

Use UUIDv7 `public_id` only where a record is exposed across an untrusted boundary.

Expected examples:

- Business.
- Challenge.
- Customer pass.

Internal foreign keys continue to use bigint IDs.

### Provider identifiers

Google Wallet object/class identifiers remain integration fields. They never replace domain primary/public IDs.

## Timestamps and timezone model

### General rule

Use `timestamp with time zone` (`timestamptz`) for domain instants.

In Laravel migrations, use timezone-aware timestamp columns such as `timestampTz()` / `timestampsTz()` where appropriate.

PostgreSQL stores `timestamptz` instants internally in UTC. The Business/Challenge IANA timezone is stored separately because a `timestamptz` does not retain the original timezone name.

### Domain clock

Rule-relevant "now" comes from PostgreSQL.

Use:

```sql
CURRENT_TIMESTAMP
```

within the owning transaction.

`CURRENT_TIMESTAMP` is stable for the transaction, giving Visit/Reward decisions one consistent instant.

Do not use:

- Browser time.
- JavaScript `Date.now()` for authority.
- Application-host `now()` to decide Challenge validity.
- Request-supplied timestamps for accepted Visits.

### Business timezone

`businesses.timezone` stores a valid IANA identifier such as:

```text
America/La_Paz
Europe/Madrid
```

It is the default timezone for future Challenge publication.

### Challenge timezone snapshot

`challenges.timezone` stores the Business timezone at publication.

This is intentional duplication because it freezes calendar semantics for that Challenge.

Changing Business settings later must not reinterpret historical Visit timestamps, awarded points, or Challenge deadlines.

### Challenge window

The Business configures local dates.

Persist only:

```text
starts_at timestamptz
ends_at   timestamptz
timezone  varchar
```

`starts_at` is inclusive.

`ends_at` is exclusive and represents local midnight immediately after the selected final date.

Do not persist duplicate `starts_on` / `ends_on` date columns in MVP.

The UI can derive/display local dates from the stored instants and Challenge timezone.

### Visit time

Visits persist:

```text
visited_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
```

Do not persist:

```text
visited_on
business_local_date
```

in MVP.

When local date is required:

```sql
(visited_at AT TIME ZONE challenges.timezone)::date
```

When the database-local current Challenge date is required:

```sql
(CURRENT_TIMESTAMP AT TIME ZONE challenges.timezone)::date
```

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

This is exactly why local-day rules are derived from the Challenge timezone rather than from the UTC date.

## Laravel model date handling

Use `immutable_datetime` casts for project-owned rule-relevant timestamps when mutation would be surprising.

Keep Laravel application timezone at UTC.

Carbon/CarbonImmutable may be used to:

- Parse Business-local date input.
- Render local dates to the UI.
- Build deterministic publication boundaries.

Once the Challenge window is persisted, live Challenge/Reward validity uses PostgreSQL time.

Generic Eloquent `created_at` / `updated_at` remain framework timestamps and are not used as domain validity clocks.

## Ownership

Ownership is explicit:

```text
businesses.user_id -> users.id
```

Do not infer ownership from:

- Creator metadata.
- Log context.
- Request/session history.

## Status fields

Add `status` only when a record has a real stored lifecycle.

### Challenge

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

Do not persist `scheduled`, `active`, or `ended`; they are derived from status + database time + UTC window.

### Other tables

Do not add generic `active/inactive` fields.

Prefer real facts:

- Field `redeemed_at`.
- Row existence.
- Provider identifier availability.

Native PostgreSQL ENUM types are not the default. PHP enum + string + `CHECK` is easier to evolve through Laravel migrations while keeping database protection.

## Soft deletes

Do not use `SoftDeletes` by default.

MVP policy:

- Visit: immutable; never soft-delete in normal operation.
- Reward entitlement: preserve; redemption is a timestamp, not deletion.
- Published Challenge: cancel instead of delete.
- Draft Challenge: may be hard-deleted before publication.
- Customer pass: preserve while it represents a Wallet object.
- Business/User deletion is not an MVP workflow.

Add soft deletion only when a concrete recovery/legal/product requirement exists.

## Core tables

### businesses

Suggested shape:

```text
id
public_id
user_id
name
timezone
regular_visit_points
special_weekday nullable
special_start_time nullable
special_end_time nullable
special_visit_points nullable
logo_path nullable
created_at
updated_at
```

Constraints:

- Constraint `public_id` unique.
- Constraint `user_id` unique in MVP.
- Timezone validated in application against supported IANA identifiers.
- Regular Visit points must be positive.
- Special point fields are either disabled together or form one valid weekday/full-day/time-range rule.

### challenges

Suggested shape:

```text
id
public_id
business_id
target_points
reward_title
reward_description nullable
timezone
starts_at
ends_at
status
published_at nullable
cancelled_at nullable
created_at
updated_at
```

Constraints:

- Constraint `public_id` unique.
- FK to Business.
- Constraint `starts_at < ends_at`.
- Allowed `status`.
- Required positive `target_points`.
- Required Reward title.


Published-window overlap is a cross-row rule. MVP enforces it in `PublishChallengeAction` while locking the Business row. Do not add database extensions/exclusion constraints only for this rule unless scale/concurrency demonstrates the need.

### customer_passes

Suggested shape:

```text
id
public_id
business_id
wallet_object_id nullable
validation_token_hash
manual_code
issued_at
created_at
updated_at
```

Constraints:

- Constraint `public_id` unique.
- Constraint `wallet_object_id` unique when present.
- Validation-token hash unique.
- Constraint `(business_id, manual_code)` unique.

`manual_code` is a Business-scoped lookup identifier, not the authoritative secret.

### visits

Suggested shape:

```text
id
customer_pass_id
challenge_id
validated_by_user_id
idempotency_key
points_awarded
visited_at
created_at
updated_at
```

Constraints/indexes:

- FKs to Customer pass, Challenge, User.
- Constraint `idempotency_key` unique.
- Constraint `points_awarded > 0`.
- Index `(customer_pass_id, challenge_id, visited_at)`.

There is intentionally no local-day uniqueness rule. Legitimate repeat Visits on the same Business-local day may each be accepted.

`ValidateVisitAction` serializes operations with a row lock on the Customer pass and uses idempotency to prevent one technical validation request from creating duplicate Visit facts.

### reward_entitlements

Suggested shape:

```text
id
customer_pass_id
challenge_id
unlocked_at
redeemed_at nullable
redeemed_by_user_id nullable
created_at
updated_at
```

Constraints:

- Unique `(customer_pass_id, challenge_id)`.
- FKs to pass/challenge/user.
- Field `redeemed_by_user_id` is nullable until redemption.

`unlocked_at` and `redeemed_at` are domain instants. Set them from PostgreSQL current time within their transactions.

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
- Challenge completion attempted concurrently.
- Reward redeemed twice.
- Initial Wallet provisioning retried.

## JSONB

Do not use `jsonb` for the core Challenge or point-earning configuration in MVP. The supported fields are small and stable enough to remain explicit relational columns.

Use `jsonb` only later for genuinely variable external metadata that does not deserve first-class relational fields.

## Logging versus audit columns

Do not add:

```text
register_user_id
last_update_user_id
last_access_control_id
```

to every table.

Use:

- Explicit ownership for Business.
- Immutable domain facts for Visit/Reward state.
- Structured application/security logs for request/event tracing.
- Fields `created_at` / `updated_at` for framework record timestamps.

Add direct actor attribution only when it is itself a domain fact, such as:

```text
visits.validated_by_user_id
reward_entitlements.redeemed_by_user_id
```

## Migrations

Use Laravel migrations as the schema authority.

- Do not modify migrations already applied to shared/production data.
- Before production/shared data exists, controlled consolidation is acceptable when it simplifies the baseline.
- Custom SQL requires a clear reason and focused migration tests.
- Avoid framework-independent schema abstractions that duplicate Laravel's migration API.
