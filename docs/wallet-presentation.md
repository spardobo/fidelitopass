# FidelitoPass Google Wallet Presentation

This document defines the deterministic Google Wallet presentation for MVP.

The pass structure does not change between Challenges. Only Business identity, Challenge values, current Visit point value, progress, Reward state, and deadline change.

## Presentation hierarchy

Every active Challenge pass answers the same questions:

1. What is the current Challenge.
2. How many points are required.
3. How many points the customer has.
4. How many points a Visit is worth now.
5. What Reward will be unlocked.
6. When the Challenge ends.

Example:

```text
CAFÉ CENTRAL

🎯 RETO ACTUAL

Consigue 15 puntos antes del 30 SEP.

9 / 15 puntos

⚡ Ahora tu visita vale 2 puntos.

🎁 Hamburguesa gratis
Válido hasta 30 SEP

[barcode]
Código: 482731
```

## Stable fields

| Field | Example | Source |
|---|---|---|
| Business name | `CAFÉ CENTRAL` | Business. |
| Challenge title | `🎯 RETO ACTUAL` | Platform-generated. |
| Challenge description | `Consigue 15 puntos antes del 30 SEP.` | Platform-generated from Challenge values. |
| Progress | `9 / 15 puntos` | PostgreSQL-authoritative Challenge progress. |
| Current Visit value | `Ahora tu visita vale 2 puntos.` | Current Business-local point rule. |
| Reward | `🎁 Hamburguesa gratis` | Business-owned Reward value. |
| Deadline | `Válido hasta 30 SEP` | Derived from Challenge local end date. |
| Barcode | QR/private value | Private validation token. |
| Manual code | `482731` | Short Business-scoped lookup code. |

## State 1 — Waiting for a Challenge

```text
CAFÉ CENTRAL

👀 PRÓXIMO RETO

Tu tarjeta ya está lista.
Aquí aparecerá el próximo reto del negocio.

[barcode]
Código: 482731
```

Use this state when no Challenge is currently active.

## State 2 — Challenge in progress

```text
CAFÉ CENTRAL

🎯 RETO ACTUAL

Consigue 15 puntos antes del 30 SEP.

9 / 15 puntos

Tu visita ahora vale 1 punto.

🎁 Hamburguesa gratis
Válido hasta 30 SEP

[barcode]
Código: 482731
```

When the optional special point rule is active:

```text
⚡ Ahora tu visita vale 2 puntos.
```

The pass always shows the point value that applies at that moment according to Business-local time.

## State 3 — Reward available

```text
CAFÉ CENTRAL

🎉 RETO COMPLETADO

15 / 15 puntos

¡Lo conseguiste!

🎁 Hamburguesa gratis

Canjéala antes del 30 SEP.

[barcode]
Código: 482731
```

The Reward becomes the visual priority. Do not keep encouraging additional Challenge progress.

## State 4 — Reward redeemed

```text
CAFÉ CENTRAL

✅ RECOMPENSA CANJEADA

Gracias por volver.

Tu tarjeta seguirá lista
para el próximo reto.

[barcode]
Código: 482731
```

## State 5 — Challenge ended without Reward

```text
CAFÉ CENTRAL

⌛ RETO FINALIZADO

Este reto ya terminó.

Tu tarjeta seguirá lista
para el próximo reto.

[barcode]
Código: 482731
```

## State 6 — Reward expired

```text
CAFÉ CENTRAL

⌛ RECOMPENSA VENCIDA

El plazo de este reto terminó
y la recompensa ya no está disponible.

Tu tarjeta seguirá lista
para el próximo reto.

[barcode]
Código: 482731
```

## State 7 — Challenge cancelled

```text
CAFÉ CENTRAL

RETO CANCELADO

Este reto ya no está activo.

Tu tarjeta seguirá lista
para el próximo reto.

[barcode]
Código: 482731
```

## Presentation rules

- Use points as the only progress unit.
- Do not use stamp circles as the primary progress representation.
- Keep Challenge copy generated centrally in Spanish.
- Keep descriptions short enough to scan quickly.
- Show the current Visit point value while a Challenge is active.
- Prefer exact local dates when deadlines matter.
- A state must never imply an action that the server would reject.
- Use icon/emoji plus text; colour alone never communicates state.
- Do not expose raw internal identifiers.
- The barcode contains the private high-entropy validation token; the manual code is a separate Business-scoped lookup identifier.
- Wallet content is a presentation of PostgreSQL state, never an independent business-rule authority.

## Google Wallet structure

Use the Google Wallet loyalty Class/Object model:

- One shared Business loyalty class for Business-level identity/presentation.
- One loyalty object per Customer pass.
- Field `loyaltyPoints` or equivalent structured field for numeric Challenge progress where useful.
- Text modules for Challenge description, current Visit point value, Reward, and validity.
- Barcode for the validation token.
- Alternate barcode text/manual code for quick fallback where suitable.

Avoid custom dynamic progress images in MVP. Numeric progress keeps the presentation stable and easy to synchronize.
