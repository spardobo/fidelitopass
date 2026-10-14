# FidelitoPass Google Wallet Presentation

This document defines the deterministic Google Wallet presentation for MVP.

The pass structure does not change between Promotions. Only Business identity, Promotion values, current Visit point value, progress, Reward state, and deadline change.

## Presentation hierarchy

An active Promotion pass places progress directly after the private credentials:

1. Business identity and logo.
2. Current Visit point value, including an applicable multiplier.
3. Private QR barcode and labelled manual code.
4. Authoritative point progress below the manual code, without a Points caption.
5. Final included local date in the first principal row.
6. **PROMOCIÓN**, the Business-owned Reward title and its optional Reward description.

Example for an existing pass with earned progress:

```text
CAFÉ CENTRAL

⚡ Ahora tu visita vale 2 puntos.

[barcode]
Código manual: 48273
9 / 15 puntos

Válido hasta el 30 SEP 2026

PROMOCIÓN
Hamburguesa gratis
Hamburguesa clásica con papas.
```

Initial issuance uses zero progress, not example or image values. The benefit title comes from the actual Reward, never a fictional default offer. Native Wallet controls geometry; this hierarchy does not promise a right-aligned QR, exact typography or a pixel-identical web card.

### Native initial-pass layout

The native initial pass uses a compact main card. Web previews and the general lifecycle examples retain their existing hierarchy.

1. Business identity and logo.
2. Authoritative point progress above the QR, without a Points caption.
3. Private QR barcode and labelled manual code.
4. **PROMOCIÓN** and the Business-owned Reward title.
5. Final included local date, in place of the Reward description.
6. Current Visit point value after one blank line in the supplied content.

```text
CAFÉ CENTRAL

0 / 15 puntos
[barcode]
Código manual: 48273

PROMOCIÓN
Hamburguesa gratis
Válido hasta el 30 SEP 2026

⚡ Ahora tu visita vale 2 puntos.
```

Keep one compact Promotion section for the main card. Preserve the full Reward title and optional real description in a separate detail section for the swipe-up view. Order swipe-up details as progress, manual code, full Reward (or waiting content), validity, then current Visit value. Omit the extra next-action item from this view without deleting its stored text module. Select those details explicitly so the compact summary does not repeat there. Do not add the generated “Consigue X puntos hasta Y” sentence or impose a new Business text-length rule.

Initial progress uses one headerless text module derived from the authoritative initial value. The above-barcode selector reads that body, then the existing loyalty-points balance when the module is absent. New initial Objects omit `loyaltyPoints`, avoiding its default Points caption. Keep the existing `0 / target puntos` value and unit. Clear the former below-barcode reference when updating a Class; omission during a partial update must not leave duplicate progress below the QR. Keep the high-entropy QR value unchanged and retain the actual manual code as labelled barcode alternate text.

A waiting initial pass supplies only its waiting title and help text, without invented progress, Reward, Visit value or deadlines. Use the text-module header for the waiting title and its body for help, without repeating the title in the body. Missing active-only fields remain absent.

Use the configured black program icon in the native white logo holder, independently of card background colour. Reuse the hosted icon asset. Web previews retain their own background-contrast selection. Google controls font size, weight, line wrapping and final geometry. The supplied blank line and field order do not promise exact spacing, larger progress text or a pixel-identical web card. Compact surfaces may truncate; full Reward content remains in details.

Google supports these [native barcode sections, single-item rows, ordered field fallbacks and detail overrides](https://developers.google.com/wallet/reference/rest/v1/ClassTemplateInfo). The [loyalty template guide](https://developers.google.com/wallet/retail/loyalty-cards/resources/template) describes unlabelled barcode-adjacent fields, hidden empty items and explicit detail selection. Do not use first/second values to simulate stacked rows: they display as a combined value.

Changing the shared Class template changes layout references for existing Objects, not their content. The main selector tries compact content, then the existing full Reward section, then waiting content. Legacy Objects without the compact section may therefore retain the Reward description on the main card until a separately authorized presentation migration. Compare every ordered fallback path in main rows, details and barcode selectors when reconciling appearance. Never reset or patch existing progress from initial zero-progress output. Existing-pass synchronization must select current authoritative state; this initial-only change does not complete the lifecycle rollout.

### Active-only credential visibility — pending rollout

The desired customer presentation shows private QR and manual-code controls only while a Promotion is active. This is not yet implemented across the pass lifecycle. Initial creation currently retains the barcode and account binding even in the waiting state. Do not interpret the desired visibility as permission to remove those values from existing Objects: the provider holds the only recoverable validation token, while local storage retains only its hash. Safe rollout needs an explicit credential-continuity decision and existing-pass synchronization. Initial presentation mapping alone does not provide those capabilities.

## Stable fields

| Field | Example | Source |
|---|---|---|
| Business name | `CAFÉ CENTRAL` | Business. |
| Promotion heading | `PROMOCIÓN` | Platform-generated label above the actual Reward title. |
| Progress | `9 / 15 puntos` | PostgreSQL-authoritative Promotion progress. |
| Current Visit value | `Ahora tu visita vale 2 puntos.` | Applicable extra points on the fixed one-point base, evaluated in the active published Promotion timezone. |
| Reward | `Hamburguesa gratis` | Business-owned Reward title, followed by its optional description. |
| Deadline | `Válido hasta el 30 SEP 2026` | Derived from Promotion local end date. |
| Barcode | QR/private value | Private validation token. |
| Manual code | `48273` | Short Business-scoped lookup code. |

## State selection

- When a Promotion is active, show its current state for this Customer pass.
- When none is active, retain the applicable terminal result from the last relevant Customer pass–Promotion relationship: redeemed, ended without Reward, Reward expired or cancelled. Show that result together with the waiting message for the next Promotion.
- When none is active and no applicable terminal result exists, show only the waiting state.
- The next active Promotion replaces the prior result in the same persistent pass. A scheduled Promotion alone does not replace it.

These are presentation choices, not new stored domain statuses. Historical Visits and entitlement facts remain unchanged.
Use success language only for genuine success, such as redemption; expiry and cancellation are neutral outcomes, not congratulations.
Selection describes the intended content; provider synchronization after commit may lag.

## Generated active-Promotion copy

Active-Promotion heading above the Reward title:

> PROMOCIÓN

Progress:

> {progress} / {target_points} puntos

Next action:

> Te faltan {remaining_points} puntos.

Current Visit value:

> Tu visita ahora vale {current_visit_points} punto(s).

When a multiplier window is currently active:

> ⚡ Ahora tu visita vale {current_visit_points} puntos.

Primary active-Promotion benefit:

> {reward_title}

Every displayed final date includes its year. The displayed final local date is inclusive. Use **hasta el {end_date}** in validity and redemption copy; the exclusive server deadline is the next local midnight. Web previews and newly created native passes omit generated earning sentences while retaining the Reward description, progress and validity.

## State 1 — Waiting for a Promotion

```text
CAFÉ CENTRAL

⏳ Espera a la próxima promoción

Tu tarjeta ya está lista.
Aquí aparecerá la próxima promoción del negocio.

[barcode]
Código manual: 48273
```

Use this state alone when no Promotion is active and this Customer pass has no applicable prior terminal result,
including when a scheduled Promotion awaits its start. Otherwise retain the applicable result in states 4–7 together
with its waiting message.

## State 2 — Promotion in progress

```text
CAFÉ CENTRAL

Tu visita ahora vale 1 punto.

[barcode]
Código manual: 48273
9 / 15 puntos

Válido hasta el 30 SEP 2026

PROMOCIÓN
Hamburguesa gratis
Hamburguesa clásica con papas.
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

Canjea tu recompensa hasta el 30 SEP 2026.

[barcode]
Código manual: 48273
```

The Reward becomes the visual priority. Do not keep encouraging additional Promotion progress.

## State 4 — Reward redeemed

```text
CAFÉ CENTRAL

✅ RECOMPENSA CANJEADA

Gracias por volver.

Tu tarjeta seguirá lista
para la próxima promoción.

[barcode]
Código manual: 48273
```

## State 5 — Promotion ended without Reward

```text
CAFÉ CENTRAL

⌛ PROMOCIÓN FINALIZADA

Esta promoción ya terminó.

Tu tarjeta seguirá lista
para la próxima promoción.

[barcode]
Código manual: 48273
```

## State 6 — Reward expired

```text
CAFÉ CENTRAL

⌛ RECOMPENSA VENCIDA

El plazo de esta promoción terminó
y la recompensa ya no está disponible.

Tu tarjeta seguirá lista
para la próxima promoción.

[barcode]
Código manual: 48273
```

## State 7 — Promotion cancelled

```text
CAFÉ CENTRAL

PROMOCIÓN CANCELADA

Esta promoción ya no está activa.

Tu tarjeta seguirá lista
para la próxima promoción.

[barcode]
Código manual: 48273
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
