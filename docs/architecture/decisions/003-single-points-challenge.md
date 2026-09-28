# ADR-003: Use one points-based Challenge mechanic

## Status

Accepted as a target design. Promotion-owned persistence, behavior, and tests remain pending.

## Context

FidelitoPass needs a loyalty mechanic that is easy for a small Business to configure, easy for a customer to understand in Google Wallet, and small enough to keep the MVP deterministic.

Several distinct Challenge algorithms would increase configuration, presentation states, evaluation logic, and testing scope. A generic rules engine would increase that complexity further.

## Options Considered

1. Several fixed Challenge types with different evaluation rules.
2. One points-based Challenge with simple point-earning configuration.
3. A generic configurable rules engine.

## Decision

Use one Challenge mechanic:

> Earn `N` points before the Challenge ends to unlock one Reward.

A Visit is an immutable fact. Each accepted Visit records immutable `points_awarded` from the active published Promotion's frozen terms. A regular accepted Visit awards exactly one point. A Promotion may own optional recurring x2, x3, or x5 windows by Business-local weekday; each new Promotion starts without inherited rules. For each weekday, use either one whole-day window or distinct, nonoverlapping half-open `[start, end)` intraday windows. Touching endpoints are allowed; split overnight periods across weekdays. Exactly one multiplier applies, never stacked; outside the windows the value is x1.

Drafts remain editable. Save each draft and publish the complete Promotion aggregate atomically. Publication freezes its original local dates, UTC start and exclusive end, timezone snapshot, point goal, Reward title and optional description, and all multiplier weekdays, times, and values. Scheduled Promotions are already published and frozen before they start; Business profile and pass appearance are separate from the aggregate.

Published effective windows for one Business cannot overlap, though endpoints may touch. Cancellation is a lifecycle transition, not an edit: preserve the published snapshot, awarded Visits, and history; stop progress and redemption immediately and release only remaining occupancy. A cancelled interval occupies `[starts_at, min(ends_at, cancelled_at))`, empty if cancelled before its start. Date-only replacement after intraday cancellation cannot start before the next Business-local midnight. Multiple drafts and future scheduled Promotions remain possible.

[ADR-004](004-database-time-and-business-calendar.md) is authoritative for the post-lock PostgreSQL operation clock, timezone snapshot, and Business-local calendar evaluation.

## Rationale

This preserves a single customer mental model:

```text
Visit -> Points -> Challenge progress -> Reward.
```

A Promotion can still encourage weak periods through deterministic multiplier windows without introducing another customer-facing mechanic.

## Consequences

- Wallet always presents progress in points.
- Challenge configuration remains small and predictable.
- Legitimate repeat Visits on the same day can each award points.
- Technical retries remain protected by idempotency.
- Historical awarded points are not recalculated after configuration changes.
- Draft editing, publication, and cancellation need atomic, serialized handling; published terms cannot be edited, including for scheduled Promotions.
- Promotion-owned persistence and behavior tests must cover time boundaries, overlap and cancellation, frozen terms, and immutable awarded points. This decision does not claim that schema or behavior has shipped.
- Additional Challenge mechanics remain future product work rather than MVP configuration.
