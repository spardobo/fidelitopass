# ADR-004: Store UTC instants and evaluate calendar rules with PostgreSQL time

## Status

Accepted.

## Context

FidelitoPass rules depend on local Business calendar days:

- Challenge validity windows.
- Business-local weekday/time rules for point earning.
- Customer-facing deadlines.
- Challenge expiry.

UTC date alone cannot represent the customer's local calendar. Persisting both `visited_at` and a duplicated local date would introduce redundant state that can drift.

## Options Considered

1. Store `timestamptz` instants, snapshot Challenge timezone, derive local calendar values in PostgreSQL.
2. Store `visited_at` plus duplicated `visited_on`/local-date fields.
3. Perform all date/time decisions from the application server clock.

## Decision

Use PostgreSQL `timestamptz` for domain instants.

For mutating Challenge/Visit/Reward operations, acquire the relevant row locks first, then capture PostgreSQL `clock_timestamp()` exactly once as `operation_at`. Reuse that one database-derived instant for deadline checks, Business-local point-rule evaluation, domain timestamps, and related audit/event timestamps. Do not use transaction-start `CURRENT_TIMESTAMP` for these lock-sensitive decisions: lock waits can make it stale.

For read-only phase queries, use an explicitly current PostgreSQL wall-clock instant rather than transaction-start `CURRENT_TIMESTAMP`.

Store a Business IANA timezone and snapshot it on Challenge publication.

Derive local calendar values with `AT TIME ZONE`.

Do not store `visited_on` in MVP.

## Rationale

PostgreSQL stores `timestamptz` instants in UTC while allowing explicit timezone conversion. The Challenge timezone preserves historical local-calendar semantics. PostgreSQL wall-clock time prevents disagreement between application hosts, while a single post-lock `operation_at` keeps each mutation internally consistent without accepting a stale transaction-start instant.

## Consequences

- UTC/local-midnight cases require direct PostgreSQL tests.
- Local-date queries use expressions instead of a duplicated date column.
- Customer-pass row locking serializes same-pass Visit validation; lock-sensitive mutations capture one `operation_at` only after their relevant locks are held.
- Read-only phase queries use an explicitly current PostgreSQL wall-clock instant.
- Business timezone changes affect future Challenges only.
