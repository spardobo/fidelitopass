# FidelitoPass Challenge Model

This document defines the single Challenge model used by the MVP and the point-earning rules that feed it.

## Product rule

FidelitoPass has one Challenge mechanic in MVP:

> Earn a target number of points before the Challenge ends to unlock one Reward.

The Business does not choose between different Challenge algorithms. It may keep any number of draft and future scheduled instances, but at most one is effective Active at an instant.

## Challenge configuration

Every Challenge has:

| Field | Rule |
|---|---|
| `start_date` | Business-local start date. |
| `end_date` | Business-local final date. |
| `target_points` | Positive integer required to complete the Challenge. |
| `reward_title` | Required concise customer-facing benefit. |
| `reward_description` | Optional short clarification. |

On publication:

- The Business IANA timezone is copied to `challenge.timezone`.
- Local `start_date 00:00` becomes inclusive `starts_at`.
- Midnight immediately after `end_date` becomes exclusive `ends_at`.
- The instants are stored as `timestamptz`.
- The original UTC window, timezone snapshot, target and Reward remain immutable from publication, including after cancellation.
- Published effective windows for one Business never overlap; touching endpoints are allowed.

## Point earning

A Visit is the immutable business fact. Points are the progress awarded for that Visit.

Each accepted Visit records the number of points awarded at validation time.

Every accepted regular Visit awards exactly one point. A Business may configure any number of weekly recurring multiplier rules xN (integer N >= 2), keyed by Business-local weekday. A rule covers the whole day or a half-open `[start, end)` time window within that day. Several timed windows on one weekday must be distinct and nonoverlapping; touching endpoints are valid. Whole-day and timed rules cannot coexist on the same weekday. Split overnight promotions into separate weekday rules.

Exactly one multiplier applies at a time: x1 outside configured windows, xN within one window, with no stacking. There is no generic rule builder or condition tree.

Examples:

```text
Regular visit: 1 point.
Wednesday: 2 points all day.
```

```text
Regular visit: 1 point.
Wednesday 14:00-15:00: x2 = 2 points.
Wednesday 17:00-18:00: x3 = 3 points.
Friday all day: x5 = 5 points.
Outside those windows: x1 = 1 point.
```

For future Visit acceptance, the application derives local weekday/time from the active published Challenge's timezone snapshot and the single post-lock PostgreSQL operation instant. Preview shows the multiplier and resulting points; changing rules never rewrites recorded `points_awarded`.

## Multiple Visits on the same day

FidelitoPass does not impose a one-Visit-per-day loyalty rule.

If the customer legitimately returns more than once, each Business-confirmed Visit may award points.

Technical retries of the same validation operation remain idempotent and must not create another Visit.

## Challenge progress

Only points awarded by accepted Visits associated with that Challenge count toward its progress. Cancellation stops new progress and redemption immediately, without removing historical Visits.

```text
progress = sum(points_awarded)
completed = progress >= target_points
```

Historical point values never change if the Business changes its point configuration later.

## Customer-facing copy

Challenge title:

> 🎯 RETO ACTUAL

Description:

> Consigue {target_points} puntos antes del {end_date}.

Progress:

> {progress} / {target_points} puntos

Next action:

> Te faltan {remaining_points} puntos.

Current Visit value:

> Tu visita ahora vale {current_visit_points} punto(s).

When a multiplier window is currently active:

> ⚡ Ahora tu visita vale {current_visit_points} puntos.

Reward:

> 🎁 {reward_title}

## Completion and Reward

When progress first reaches the target:

```text
🎉 RETO COMPLETADO

{progress} / {target_points} puntos

🎁 {reward_title}

Canjéalo antes del {end_date}.
```

The Reward is redeemable only while the Challenge remains valid.

After Redemption:

```text
✅ RECOMPENSA CANJEADA

Gracias por volver.

Tu tarjeta seguirá lista
para el próximo reto.
```

After Challenge expiry without Redemption:

```text
⌛ RETO FINALIZADO

La recompensa ya no está disponible.

Tu tarjeta seguirá lista
para el próximo reto.
```

## MVP exclusions

Do not implement:

- Multiple effective Active Challenges.
- Rule stacking.
- Customer-selected Challenges.
- Alternative Challenge mechanics.
- Visit-count Challenges.
- Random rewards.
- Tiered loyalty.
- Spend-based rules.
- Product/category rules.
- Generic rule builders.
