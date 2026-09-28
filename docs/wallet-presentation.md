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

Consigue 15 puntos antes del 30 SEP.

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
| Promotion description | `Consigue 15 puntos antes del 30 SEP.` | Platform-generated from Promotion values. |
| Progress | `9 / 15 puntos` | PostgreSQL-authoritative Promotion progress. |
| Current Visit value | `Ahora tu visita vale 2 puntos.` | Applicable Puntos extra of the fixed one-point base, evaluated in the active published Promotion timezone. |
| Reward | `🎁 Hamburguesa gratis` | Business-owned Reward value. |
| Deadline | `Válido hasta 30 SEP` | Derived from Promotion local end date. |
| Barcode | QR/private value | Private validation token. |
| Manual code | `482731` | Short Business-scoped lookup code. |

## State 1 — Waiting for a Promotion

```text
CAFÉ CENTRAL

👀 PRÓXIMA PROMOCIÓN

Tu Pase ya está listo.
Aquí aparecerá la próxima promoción del negocio.

[barcode]
Código del Pase: 482731
```

Use this state when no Promotion is currently active, including a scheduled Promotion awaiting its start.

## State 2 — Promotion in progress

```text
CAFÉ CENTRAL

🎯 PROMOCIÓN ACTUAL

Consigue 15 puntos antes del 30 SEP.

9 / 15 puntos

Tu visita ahora vale 1 punto.

🎁 Hamburguesa gratis
Válido hasta 30 SEP

[barcode]
Código del Pase: 482731
```

When a Puntos extra window is active (for example x2):

```text
⚡ Ahora tu visita vale 2 puntos.
```

The pass indicates the point value at that moment: one point outside Puntos extra windows, or x2, x3 or x5 in one window, without stacking. The server awards points using its operation instant and the published Promotion timezone snapshot. Updates after commit may lag; never imply instant provider synchronization or invite duplicate visits.

## State 3 — Reward available

```text
CAFÉ CENTRAL

🎉 PROMOCIÓN COMPLETADA

15 / 15 puntos

¡Lo conseguiste!

🎁 Hamburguesa gratis

Canjéala antes del 30 SEP.

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

The public acquisition QR is not the private barcode or the separate manual lookup code. A provider update is eventually synchronized after database commit; failures do not roll back an accepted Visit and must not cause a second commercial action. The web preview is illustrative, not an issued credential. Google Wallet accepts a configured background colour (`hexBackgroundColor`), but native text colour is provider-controlled and cannot be guaranteed; ensure legible web previews independently.

## Google Wallet structure

Use the Google Wallet loyalty Class/Object model:

- One shared Business loyalty class for Business-level identity/presentation.
- One loyalty object per Customer pass.
- Field `loyaltyPoints` or equivalent structured field for numeric Promotion progress where useful.
- Text modules for Promotion description, current Visit point value, Reward, and validity.
- Barcode for the validation token.
- Alternate barcode text/manual code for quick fallback where suitable.

Avoid custom dynamic progress images in MVP. Numeric progress keeps the presentation stable and easy to synchronize.
