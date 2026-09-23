# FidelitoPass Challenge Model

This document defines the single Challenge model used by the MVP and the point-earning rules that feed it.

## Product rule

FidelitoPass has one Challenge mechanic in MVP:

> Earn a target number of points before the Challenge ends to unlock one Reward.

The Business does not choose between different Challenge algorithms.

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
- The published Challenge window and Reward semantics remain stable while active.

## Point earning

A Visit is the immutable business fact. Points are the progress awarded for that Visit.

Each accepted Visit records the number of points awarded at validation time.

The Business has a small, deterministic point configuration:

- A regular Visit value.
- At most one optional special rule.
- The special rule applies to one selected weekday.
- The special rule may cover the full selected day or one time range on that day.
- The special rule defines the Visit value while that window is active.

The configuration is intentionally small. There is no rule builder, condition tree, stacking, or combination of several special rules in MVP.

Examples:

```text
Regular visit: 1 point.
Wednesday: 2 points all day.
```

```text
Regular visit: 1 point.
Wednesday 14:00-17:00: 2 points.
```

The application determines the applicable point value from PostgreSQL time and the Business timezone at the moment the Visit is accepted.

## Multiple Visits on the same day

FidelitoPass does not impose a one-Visit-per-day loyalty rule.

If the customer legitimately returns more than once, each Business-confirmed Visit may award points.

Technical retries of the same validation operation remain idempotent and must not create another Visit.

## Challenge progress

Only points awarded by accepted Visits associated with the active Challenge count toward that Challenge.

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

If the special rule is currently active:

> ⚡ Ahora tu visita vale {special_points} puntos.

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

- Multiple simultaneous Challenges.
- Multiple special point rules.
- Rule stacking.
- Customer-selected Challenges.
- Alternative Challenge mechanics.
- Visit-count Challenges.
- Random rewards.
- Tiered loyalty.
- Spend-based rules.
- Product/category rules.
- Generic rule builders.
