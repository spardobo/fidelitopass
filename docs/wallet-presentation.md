# FidelitoPass Google Wallet Presentation

This document defines the deterministic Google Wallet presentation for MVP.

The pass structure does not change between Promotions. Only Business identity, Promotion values, current Visit point value, progress, Reward state, and deadline change.

## Presentation hierarchy

Every active Promotion pass answers the same questions:

1. What is the current Promotion.
2. How many points are required.
3. How many points the customer has.
4. How many points a Visit is worth now.
5. What Reward will be unlocked.
6. When the Promotion ends.

Example:

```text
CAFÉ CENTRAL

🎯 PROMOCIÓN ACTUAL

Consigue 15 puntos hasta el 30 SEP.

9 / 15 puntos

⚡ Ahora tu visita vale 2 puntos.

🎁 Hamburguesa gratis
Válido hasta 30 SEP

[barcode]
Código del Pase: 482731
```

## Stable fields

| Field | Example | Source |
|---|---|---|
| Business name | `CAFÉ CENTRAL` | Business. |
| Promotion title | `🎯 PROMOCIÓN ACTUAL` | Platform-generated. |
| Promotion description | `Consigue 15 puntos hasta el 30 SEP.` | Platform-generated from Promotion values. |
| Progress | `9 / 15 puntos` | PostgreSQL-authoritative Promotion progress. |
| Current Visit value | `Ahora tu visita vale 2 puntos.` | Applicable extra points on the fixed one-point base, evaluated in the active published Promotion timezone. |
| Reward | `🎁 Hamburguesa gratis` | Business-owned Reward value. |
| Deadline | `Válido hasta 30 SEP` | Derived from Promotion local end date. |
| Barcode | QR/private value | Private validation token. |
| Manual code | `482731` | Short Business-scoped lookup code. |

## State selection

- When a Promotion is active, show its current state for this Customer pass.
- When none is active, retain the applicable terminal result from the last relevant Customer pass–Promotion relationship: redeemed, ended without Reward, Reward expired or cancelled. Show that result together with the waiting message for the next Promotion.
- When none is active and no applicable terminal result exists, show only the waiting state.
- The next active Promotion replaces the prior result in the same persistent pass. A scheduled Promotion alone does not replace it.

These are presentation choices, not new stored domain statuses. Historical Visits and entitlement facts remain unchanged.
Use success language only for genuine success, such as redemption; expiry and cancellation are neutral outcomes, not congratulations.
Selection describes the intended content; provider synchronization after commit may lag.

## Generated active-Promotion copy

Promotion title:

> 🎯 PROMOCIÓN ACTUAL

Description:

> Consigue {target_points} puntos hasta el {end_date}.

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

The displayed final local date is inclusive. Use **hasta el {end_date}** in earning and redemption copy; the exclusive server deadline is the next local midnight. The web preview uses the same generated wording and values, not a second copy contract.

## State 1 — Waiting for a Promotion

```text
CAFÉ CENTRAL

👀 PRÓXIMA PROMOCIÓN

Tu Pase ya está listo.
Aquí aparecerá la próxima promoción del negocio.

[barcode]
Código del Pase: 482731
```

Use this state alone when no Promotion is active and this Customer pass has no applicable prior terminal result,
including when a scheduled Promotion awaits its start. Otherwise retain the applicable result in states 4–7 together
with its waiting message.

## State 2 — Promotion in progress

```text
CAFÉ CENTRAL

🎯 PROMOCIÓN ACTUAL

Consigue 15 puntos hasta el 30 SEP.

9 / 15 puntos

Tu visita ahora vale 1 punto.

🎁 Hamburguesa gratis
Válido hasta 30 SEP

[barcode]
Código del Pase: 482731
```

When a multiplier window is active (for example x2):

```text
⚡ Ahora tu visita vale 2 puntos.
```

The pass indicates the point value at that moment: one point outside multiplier windows, or x2, x3 or x5 in one window, without stacking. The server awards points using its operation instant and the published Promotion timezone snapshot. Updates after commit may lag; never imply instant provider synchronization or invite duplicate visits.

## State 3 — Reward available

```text
CAFÉ CENTRAL

🎉 PROMOCIÓN COMPLETADA

15 / 15 puntos

¡Lo conseguiste!

🎁 Hamburguesa gratis

Canjea tu recompensa hasta el 30 SEP.

[barcode]
Código del Pase: 482731
```

The Reward becomes the visual priority. Do not keep encouraging additional Promotion progress.

## State 4 — Reward redeemed

```text
CAFÉ CENTRAL

✅ RECOMPENSA CANJEADA

Gracias por volver.

Tu Pase seguirá listo
para la próxima promoción.

[barcode]
Código del Pase: 482731
```

## State 5 — Promotion ended without Reward

```text
CAFÉ CENTRAL

⌛ PROMOCIÓN FINALIZADA

Esta promoción ya terminó.

Tu Pase seguirá listo
para la próxima promoción.

[barcode]
Código del Pase: 482731
```

## State 6 — Reward expired

```text
CAFÉ CENTRAL

⌛ RECOMPENSA VENCIDA

El plazo de esta promoción terminó
y la recompensa ya no está disponible.

Tu Pase seguirá listo
para la próxima promoción.

[barcode]
Código del Pase: 482731
```

## State 7 — Promotion cancelled

```text
CAFÉ CENTRAL

PROMOCIÓN CANCELADA

Esta promoción ya no está activa.

Tu Pase seguirá listo
para la próxima promoción.

[barcode]
Código del Pase: 482731
```

## Presentation rules

- Use points as the only progress unit.
- Do not use stamp circles as the primary progress representation.
- Keep Promotion copy generated centrally in Spanish.
- Keep descriptions short enough to scan quickly.
- Show the current Visit point value while a Promotion is active.
- Prefer exact local dates when deadlines matter.
- A state must never imply an action that the server would reject.
- Use icon/emoji plus text; colour alone never communicates state.
- Do not expose raw internal identifiers.
- The barcode contains the private high-entropy validation token; the manual code is a separate Business-scoped lookup identifier.
- Wallet content is a presentation of PostgreSQL state, never an independent business-rule authority.

The public acquisition QR is not the private barcode or the separate manual lookup code. A provider update is eventually synchronized after database commit; failures do not roll back an accepted Visit and must not cause a second commercial action. The web preview is illustrative, not an issued credential. Google Wallet accepts a configured background colour (through its provider-supported background setting), but native text colour is provider-controlled and cannot be guaranteed; ensure legible web previews independently.

Avoid custom dynamic progress images in MVP. Numeric progress keeps presentation stable and easy to synchronize. Provider payload fields belong to the integration; presentation semantics remain defined here.
