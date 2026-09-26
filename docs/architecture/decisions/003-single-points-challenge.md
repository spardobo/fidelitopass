# ADR-003: Use one points-based Challenge mechanic

## Status

Accepted.

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

A Visit is an immutable fact. Each accepted Visit receives an immutable `points_awarded` value according to the Business point configuration that applies at validation time.

A regular accepted Visit awards exactly one point. A Business may configure any number of recurring integer xN (N >= 2) multiplier rules by local weekday: one whole-day rule or multiple distinct nonoverlapping half-open intraday windows per day, never stacked. The published Challenge timezone snapshot determines local weekday/time at Visit acceptance; rules affect future Visits only.

Only one Challenge can be effective Active for a Business at an instant; multiple draft and future scheduled instances retain the same points-based mechanic.

## Rationale

This preserves a single customer mental model:

```text
Visit -> Points -> Challenge progress -> Reward.
```

The Business can still encourage weak periods through deterministic multiplier windows without introducing another customer-facing mechanic.

## Consequences

- Wallet always presents progress in points.
- Challenge configuration remains small and predictable.
- Legitimate repeat Visits on the same day can each award points.
- Technical retries remain protected by idempotency.
- Historical awarded points are not recalculated after configuration changes.
- Additional Challenge mechanics remain future product work rather than MVP configuration.
