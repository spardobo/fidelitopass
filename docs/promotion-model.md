# FidelitoPass Promotion Model

This document defines the single Promotion model used by the MVP and the point-earning rules that feed it.

## Product rule

FidelitoPass has one Promotion mechanic in MVP:

> Earn a target number of points before the Promotion ends to unlock one Reward.

The Business does not choose between different Promotion algorithms. It may keep any number of editable drafts and future scheduled instances, but at most one is effective Active at an instant.

## Promotion configuration

Every Promotion has:

| Field | Rule |
|---|---|
| `start_date` | Business-local start date. |
| `end_date` | Business-local final date. |
| `target_points` | Positive integer required to complete the Promotion. |
| `reward_title` | Required concise customer-facing benefit. |
| `reward_description` | Optional short clarification. |

On publication:

- The Business IANA timezone is copied to the Promotion timezone snapshot; a change since draft review requires renewed confirmation before publication.
- Local `start_date 00:00` becomes inclusive `starts_at`.
- Midnight immediately after `end_date` becomes exclusive `ends_at`.
- The instants are stored as `timestamptz`.
- The complete published configuration—original local dates and UTC window, timezone snapshot, target, Reward title and optional description, and weekly multiplier windows (weekdays, times and values)—remains immutable, including when scheduled or later cancelled. Drafts remain editable; cancellation is a separate transition.
- Published effective windows for one Business never overlap; touching endpoints are allowed. Cancellation retains the original UTC window but truncates effective occupancy at the cancellation instant; cancelling before a scheduled start releases the entire future window. An intraday cancellation cannot be followed by a date-only replacement before the next Business-local midnight.

## Point earning

A Visit is the immutable business fact. Points are the progress awarded for that Visit.

Each accepted Visit records the number of points awarded at validation time.

Every accepted regular Visit awards exactly one point. Each Promotion may configure its own extra points: any number of weekly recurring x2, x3 or x5 entries keyed by local weekday in its published timezone. No Business-global or previous-Promotion settings are inherited. An entry covers the whole day or a half-open `[start, end)` time window within that day. Several timed windows on one weekday must be distinct and nonoverlapping; touching endpoints are valid. Whole-day and timed entries cannot coexist on the same weekday. Split overnight periods into separate weekday entries.

Exactly one multiplier applies at a time: x1 outside configured windows, x2, x3 or x5 within one window, with no stacking. There is no generic rule builder or condition tree.

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

For future Visit acceptance, the application derives local weekday/time from the active published Promotion's timezone snapshot and the single post-lock PostgreSQL operation instant. Preview shows the multiplier and resulting points; later drafts never rewrite recorded `points_awarded` or a published schedule.

## Multiple Visits on the same day

FidelitoPass does not impose a one-Visit-per-day loyalty rule.

If the customer legitimately returns more than once, each Business-confirmed Visit may award points.

Technical retries of the same validation operation remain idempotent and must not create another Visit.

## Promotion progress

Only points awarded by accepted Visits associated with that Promotion count toward its progress. Cancellation stops new progress and redemption immediately, without removing historical Visits.

```text
progress = sum(points_awarded)
completed = progress >= target_points
```

Historical point values never change when a later Promotion has different multiplier windows.

## Customer-facing copy

Promotion title:

> 🎯 PROMOCIÓN ACTUAL

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
🎉 PROMOCIÓN COMPLETADA

{progress} / {target_points} puntos

🎁 {reward_title}

Canjéalo antes del {end_date}.
```

Completion creates at most one Reward entitlement per Customer pass and Promotion. The Reward is redeemable exactly once, only while the Promotion remains active and before its exclusive end; cancellation also stops redemption. The same Customer pass persists for later Promotions.

After Redemption:

```text
✅ RECOMPENSA CANJEADA

Gracias por volver.

Tu Pase seguirá listo
para la próxima promoción.
```

After Promotion expiry without Redemption:

```text
⌛ PROMOCIÓN FINALIZADA

La recompensa ya no está disponible.

Tu Pase seguirá listo
para la próxima promoción.
```

## MVP exclusions

Do not implement:

- Multiple effective Active Promotions.
- Rule stacking.
- Customer-selected Promotions.
- Alternative Promotion mechanics.
- Visit-count Promotions.
- Random rewards.
- Tiered loyalty.
- Spend-based rules.
- Product/category rules.
- Generic rule builders.
