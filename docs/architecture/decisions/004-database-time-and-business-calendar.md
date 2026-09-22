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

Use PostgreSQL `CURRENT_TIMESTAMP` as the authoritative clock for Challenge/Visit/Reward decisions.

Store a Business IANA timezone and snapshot it on Challenge publication.

Derive local calendar values with `AT TIME ZONE`.

Do not store `visited_on` in MVP.

## Rationale

PostgreSQL stores `timestamptz` instants in UTC while allowing explicit timezone conversion. The Challenge timezone preserves historical local-calendar semantics. Transaction-stable database time also prevents disagreement between application hosts.

## Consequences

- UTC/local-midnight cases require direct PostgreSQL tests.
- Local-date queries use expressions instead of a duplicated date column.
- Customer-pass row locking serializes same-pass Visit validation.
- Business timezone changes affect future Challenges only.
