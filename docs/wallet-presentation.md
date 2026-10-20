# FidelitoPass Google Wallet Presentation

This document defines the deterministic Google Wallet presentation for MVP. Copy examples use Spanish; generated text and regional dates follow Laravel's active locale.

The same pass identity, Business appearance and private credentials persist across Promotions. Native content shows only the information relevant to the current scenario; omitted presentation never changes stored points or Reward facts.

## Presentation hierarchy

The general web-preview hierarchy places progress directly after the illustrative credentials. Native Wallet uses the compact, scenario-dependent layout below:

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

Válido hasta el 30 sept 2026

PROMOCIÓN
Hamburguesa gratis
Hamburguesa clásica con papas.
```

Initial issuance uses zero progress, not example or image values. The benefit title comes from the actual Reward, never a fictional default offer. Native Wallet controls geometry; this hierarchy does not promise a right-aligned QR, exact typography or a pixel-identical web card.

### Native Wallet layout

Native Wallet uses a compact main card. The following example shows initial issuance during an active Promotion; an existing pass that is still earning uses its actual awarded progress. Web previews retain their separately scoped hierarchy.

1. Business identity and logo.
2. Authoritative point progress above the QR, without a Points caption.
3. Private QR barcode and labelled manual code.
4. **TU PRÓXIMA RECOMPENSA** and the Business-owned Reward title.
5. Final included local date, in place of the Reward description.
6. Current Visit point value after one blank line in the supplied content.

```text
CAFÉ CENTRAL

0 / 15 puntos
[barcode]
Código manual: 48273

TU PRÓXIMA RECOMPENSA
Hamburguesa gratis
Tienes hasta el 30 sept 2026.

⚡ Ahora tu visita vale 2 puntos.
```

Keep one compact Promotion section for the main card. In swipe-up details, keep the Reward title, optional real description and relevant date in separate cells. Order active details as titled progress when earning, manual code, Reward, optional description, validity or redemption date, an untitled contextual message, then genuine personal redemption history. Repeat the current Visit-value, canje guidance or thanks in that contextual cell. Inactive details follow the card: manual code, previous Reward/description/date or its empty message, upcoming Reward/description/date or its empty message, then genuine history. Do not add generated earning sentences or impose a new Business text-length rule.

Earning progress uses one text module headed `TU PROGRESO`, derived from authoritative awarded points; initial issuance starts at zero. The above-barcode selector reads only its body, so the heading appears only in details. Objects omit `loyaltyPoints`, avoiding its default Points caption. Hide progress after a Reward is unlocked or redeemed and when no Promotion is active; never cap or rewrite the stored total. Explicitly leave the extra below-barcode slot empty when updating a Class. This does not remove the labelled manual code in barcode alternate text.

Without an active Promotion, initial and existing passes always show both Business announcement blocks: the last effective Promotion and the nearest announced future Promotion. Neither selection requires participation. When either is absent, retain its heading and show the corresponding friendly empty message without inventing Reward terms or publication dates. Omit progress and current Visit value.

While a Promotion is active, keep its Reward, relevant date and contextual message in one main-card summary. Do not show earlier or upcoming announcements. The secondary row carries an upcoming Promotion only in the inactive overview. Personal redemption history appears only in swipe-up details, never as a third main-card row, and only when a real redemption exists.

Use the configured black program icon in the native white logo holder, independently of card background colour. Reuse the hosted icon asset. Web previews retain their own background-contrast selection. Google controls font size, weight, line wrapping and final geometry. The supplied blank line and field order do not promise exact spacing, larger progress text or a pixel-identical web card. Compact surfaces may truncate; full Reward content remains in details.

Google supports these [native barcode sections, single-item rows, ordered field fallbacks and detail overrides](https://developers.google.com/wallet/reference/rest/v1/ClassTemplateInfo). The [loyalty template guide](https://developers.google.com/wallet/retail/loyalty-cards/resources/template) describes unlabelled barcode-adjacent fields, hidden empty items and explicit detail selection. Do not use first/second values to simulate stacked rows: they display as a combined value.

Each main-card row uses one full-width item, which Google documents as centered. Native detail cells expose no alignment setting; screenshots can still differ by device and native rendering. Do not simulate centering with spaces or HTML. Separate cells improve information hierarchy but do not guarantee that long descriptions remain expanded. Keep Object text modules within the displayed limit of ten, including progress, manual code, summaries, descriptions, dates, context and history.

Changing the shared Class template changes layout references, not individual Object content. Each main row selects one current presentation role. Details select active content first, or the corresponding inactive announcement when that active field is absent. Never reset or patch existing progress from initial zero-progress output. Existing-pass synchronization must select current authoritative state; presentation mapping alone does not complete the lifecycle rollout.

### Scenario visibility

Business identity, logo, QR and labelled manual code remain visible in every scenario. Their presence identifies the pass; it does not authorize a Visit or redemption. Server validation remains authoritative even when Wallet synchronization is delayed.

| Scenario | Main content | Progress above QR | Contextual line on main card |
|---|---|---|---|
| Active, still earning | Reward and final included date. | Actual awarded points; zero on initial issuance. | Original current Visit-value copy, including an applicable multiplier. |
| Reward available | Completed heading, Reward and redemption deadline. | Hidden. | `Muéstranos tu tarjeta y pide tu recompensa.` |
| Reward redeemed while Promotion remains active | Redeemed heading, Reward and actual redemption date. | Hidden. | `¡Gracias por volver!` |
| No active Promotion | Both previous/upcoming blocks, using a friendly empty message for each missing announcement. | Hidden. | No earning or redemption invitation. |

In details, use `FECHA LÍMITE` for an earning/available Reward and `FECHA DEL CANJE` after redemption. The latest real personal redemption remains independent of public announcements; absent history produces no empty block or invitation. Use `QUÉ INCLUYE` for a separate description cell only when real description text exists; no empty cell or fabricated description is added. Keep generated headings uppercase through the translation catalog, without changing merchant-authored names or descriptions. All example copy is localized through the active catalog.

### Credential continuity

Keep the private QR and manual code in both active and inactive presentation. The provider holds the only recoverable validation token, while local storage retains only its hash. Hiding progress or Visit-value content never authorizes removing or replacing credentials.

## Stable fields

| Field | Example | Source |
|---|---|---|
| Business name | `CAFÉ CENTRAL` | Business. |
| Promotion heading | `TU PRÓXIMA RECOMPENSA` | Platform-generated label above the actual Reward title. |
| Progress | `9 / 15 puntos` | PostgreSQL-authoritative Promotion progress. |
| Current Visit value | `Ahora tu visita vale 2 puntos.` | Applicable extra points on the fixed one-point base, evaluated in the active published Promotion timezone. |
| Reward | `Hamburguesa gratis` | Business-owned Reward title, followed by its optional description. |
| Deadline | `Tienes hasta el 30 sept 2026.` | Derived from Promotion local end date. |
| Barcode | QR/private value | Private validation token. |
| Manual code | `48273` | Short Business-scoped lookup code. |

## State selection

Capture one PostgreSQL instant for a presentation build. Use it for current, previous and upcoming Promotion selection.

- With an active published, uncancelled Promotion, show its Reward, relevant date and contextual copy. Show progress and current Visit value only while no Reward entitlement exists. Retain genuine personal redemption history in details. Do not announce earlier or upcoming Promotions.
- Without an active Promotion, always show **NUESTRA ÚLTIMA PROMOCIÓN** and **LO PRÓXIMO PARA TI**. Use actual terms when an announcement exists and its friendly empty message otherwise. Omit progress and current Visit value.
- Previous and upcoming Promotions belong to the Business, not to the customer's participation history. Initial issuance and a pass with no Visits use the same public overview.
- The latest redeemed Reward belongs only to the current pass and Business. An available, expired or cancelled unredeemed entitlement is not redemption history.

### Previous Promotion

Select the most recently ended effective published period, including cancellation during an active period. Use the earlier of the original exclusive end and cancellation instant as the effective end. Order by effective end descending, then identity descending for a deterministic tie.

A Promotion cancelled at or before its start has no positive active period and does not qualify. Drafts do not qualify. Cancellation during the active period is not normal completion:

```text
NUESTRA ÚLTIMA PROMOCIÓN
Capuchino de la casa
Se canceló el 22 oct 2026
```

Keep the common heading; the explicit cancellation line distinguishes the outcome. Use the real local cancellation date, not the original deadline. For normal completion, show `Terminó el {date}` using the last included local calendar date. Convert the exclusive end into the frozen Promotion timezone before subtracting one calendar day.

If no effective Promotion has ended, keep the previous heading and show `¡Qué bueno tenerte por aquí!` followed by `Aún no hay promociones anteriores.`.

### Upcoming Promotion

Select the nearest future published, uncancelled Promotion of the Business. Order by start ascending, then identity ascending. Do not expose drafts or a cancelled scheduled Promotion. Show its actual Reward title and `Empieza el {date}` in that Promotion's frozen timezone. Scheduled does not mean currently eligible.

If none is announced, keep the upcoming heading and show `Aún no hay una promoción programada.` followed by `Cuando haya novedades, las verás aquí.`.

### Latest personal redemption

Use the localized heading `TU ÚLTIMO CANJE`. Show this block only in details when a real personal redemption exists, independent of the current, previous or upcoming Promotion. Select the latest non-null redemption instant for the current pass and Business, with descending identity as a tie-breaker. Use that redeemed Promotion's Reward title and frozen timezone.

For a new pass or absent history, omit the history block. Do not show `Todavía ninguna.` or an invitation to earn a Reward that might already be available.

These choices do not add stored domain statuses, create participation or rewrite historical Visits and entitlement facts. Provider synchronization can lag. Do not imply redemption or completion from presentation alone.

## Generated active-Promotion copy

Active-Promotion heading above the Reward title:

> TU PRÓXIMA RECOMPENSA

Progress:

> {progress} / {target_points} puntos

Current Visit value:

> Tu visita ahora vale {current_visit_points} punto(s).

When a multiplier window is currently active:

> ⚡ Ahora tu visita vale {current_visit_points} puntos.

Primary active-Promotion benefit:

> {reward_title}

Every displayed final date includes its year in the active locale's regional format. The displayed final local date is inclusive. Validity and redemption copy use localized wording, such as **hasta el {end_date}** in Spanish; the exclusive server deadline is the next local midnight. Web previews and newly created native passes omit generated earning sentences while retaining the Reward description, progress and validity.

## State 1 — Waiting for a Promotion

The inactive main card shows both announcement blocks, regardless of participation. With both announcements available:

```text
CAFÉ CENTRAL

[barcode]
Código manual: 48273

NUESTRA ÚLTIMA PROMOCIÓN
Capuchino de la casa
Terminó el 22 oct 2026

LO PRÓXIMO PARA TI
Desayuno especial
Empieza el 1 nov 2026
```

Replace only a missing announcement with its own message. With neither available:

```text
NUESTRA ÚLTIMA PROMOCIÓN
¡Qué bueno tenerte por aquí!
Aún no hay promociones anteriores.

LO PRÓXIMO PARA TI
Aún no hay una promoción programada.
Cuando haya novedades, las verás aquí.
```

Details keep the same previous-then-upcoming order. Each real announcement expands into Reward, optional description and date cells; each absent announcement remains one message cell. A genuine personal redemption follows separately; no personal-history placeholder is added.

## State 2 — Promotion in progress

```text
CAFÉ CENTRAL

9 / 15 puntos
[barcode]
Código manual: 48273

TU PRÓXIMA RECOMPENSA
Hamburguesa gratis
Tienes hasta el 30 sept 2026.

Tu visita ahora vale 1 punto.
```

The full optional Reward description stays in its own `QUÉ INCLUYE` detail cell after the Reward title, not in the compact summary. The deadline follows in another cell, then the current Visit-value sentence in an untitled cell.

When a multiplier window is active (for example x2):

```text
⚡ Ahora tu visita vale 2 puntos.
```

The pass indicates the point value at that moment: one point outside multiplier windows, or x2, x3 or x5 in one window, without stacking. The server awards points using its operation instant and the published Promotion timezone snapshot. Device display may lag after provider confirmation; never imply instant device rendering or invite duplicate visits.

## State 3 — Reward available

```text
CAFÉ CENTRAL

[barcode]
Código manual: 48273

🎉 ¡LO LOGRASTE!
Hamburguesa gratis
Canjea tu recompensa hasta el 30 sept 2026.

Muéstranos tu tarjeta y pide tu recompensa.
```

The Reward becomes the visual priority. Hide progress and Visit-value copy without changing earned points. Keep identification available for redemption.

## State 4 — Reward redeemed

While the redeemed Promotion is still active, show the `✅ RECOMPENSA CANJEADA` heading, Reward title, actual redemption date and `¡Gracias por volver!` on the main card. Hide progress and Visit-value copy. Keep QR/manual credentials. Details use the redemption-date label, repeat the thanks in an untitled cell and retain genuine personal history last.

When no Promotion is active, use the Business overview. The redeemed Reward appears in the independent personal-history block; it does not replace either public Promotion announcement. A later cancellation does not erase a completed redemption.

## State 5 — Promotion ended without Reward

Use the inactive Business overview. The previous Promotion can be displayed without customer participation and without earning a Reward. Do not replace it with a personal failure headline or imply that every customer participated.

## State 6 — Reward expired

Expired, unredeemed entitlement facts remain unchanged but do not appear as successful redemptions. Use the same inactive overview; the latest-redemption block can retain an older genuine canje. Do not invite redemption of an expired Reward.

## State 7 — Promotion cancelled

A published Promotion cancelled during its effective active period can occupy the previous-Promotion block. Mark it `Se canceló el {date}`. Do not show its original end date as normal completion or invite further earning/redemption. A cancellation at or before the start is excluded from the public previous-Promotion selection.

When no Promotion is active, show the upcoming Promotion on the main card if announced and the latest real personal redemption in details if one exists. Cancellation never deletes previously recorded Visits or completed canjes.

## Localization and presentation roles

Generated native Wallet copy uses Laravel's active locale and configured translation fallback. The caller owns locale selection; presentation must not force a language or change the application's locale. Only the Spanish Wallet catalog ships today. Other languages use Laravel's configured fallback (Spanish by default) until their catalogs exist; regional date formatting still uses the active locale. Additional catalogs must preserve the same semantic keys and replacement parameters. Adding a language must not require changes to presentation-state selection or force the caller's locale.

Format dates with the locale's native medium date-only style, including the year. Do not force day-month-year order, uppercase month names or punctuation removal. For example, the same local day can appear as `20 ago 2030` in Spanish, `Aug 20, 2030` in English, `20 Aug 2030` in British English, or `2030/08/20` in Japanese. The Promotion's frozen timezone still determines the local calendar day, regardless of language or region.

Merchant-authored Reward titles and descriptions pass through unchanged. Credential labels are translated, but QR values and manual codes are not. This contract does not add language negotiation, stored pass-language preferences or locale switching to unrelated screens.

The primary card row shows the current Promotion or the previous-announcement block; the secondary row shows the upcoming-announcement block only when inactive. Inactive blocks remain present with empty copy when no announcement exists. Details follow the same order and insert optional descriptions and state-specific dates beside their Reward, rather than concatenating them into one collapsed block. The active contextual sentence has its own untitled detail cell. Real redemption history is details-only. Progress appears only while earning; QR and manual code remain present in every state.

Reconcile the Class template before inserting Objects. Support only the current application presentation format; do not retain selectors or duplicate modules for superseded development formats. Ordered alternatives in details serve the current active and inactive layouts, not backward compatibility.

Class changes do not recompute existing Object content. Development passes created with superseded formats have no presentation-compatibility guarantee.

## Existing-object synchronization

Saving Business appearance updates only the shared Class background for already issued cards. It does not rewrite Object content, credentials, points or Reward facts. Provider confirmation gates the local appearance save, but does not guarantee immediate device rendering. With no issued passes, appearance is saved locally for future issuance.

Update the same persistent pass from current authoritative state. Initial zero-progress content must never replace earned progress. Preserve identity and credentials and remove active-only content when inactive.

A Class-template change does not recompute existing Object content. Publication, cancellation, Visits, canjes and time boundaries require their own synchronization integration. This presentation change alone does not establish that jobs, scheduling, retries or device rollout exist.

## Presentation rules

Use warm, direct customer-facing language. Celebrate an available Reward and thank the customer after redemption. Keep dates, cancellation and credential labels explicit. Do not promise unannounced Promotions or imply participation, consumption or eligibility that the stored facts do not establish. Keep messages compact and adapt their tone to each supported language.

- Use points as the only progress unit.
- Do not use stamp circles as the primary progress representation.
- Keep Promotion copy in translation catalogs resolved through Laravel's active locale.
- Keep descriptions short enough to scan quickly.
- Show the current Visit point value only while earning in an active Promotion, on the main card and in a separate untitled detail cell.
- Prefer exact local dates when deadlines matter.
- A state must never imply an action that the server would reject.
- Use icon/emoji plus text; colour alone never communicates state.
- Do not expose raw internal identifiers.
- The barcode contains the private high-entropy validation token; the manual code is a separate Business-scoped lookup identifier.
- Wallet content is a presentation of PostgreSQL state, never an independent business-rule authority.

The public acquisition QR is not the private barcode or the separate manual lookup code. A provider update is eventually synchronized after database commit; failures do not roll back an accepted Visit and must not cause a second commercial action. The web preview is illustrative, not an issued credential. Google Wallet accepts a configured background colour (through its provider-supported background setting), but native text colour is provider-controlled and cannot be guaranteed; ensure legible web previews independently.

Avoid custom dynamic progress images in MVP. Numeric progress keeps presentation stable and easy to synchronize. Provider payload fields belong to the integration; presentation semantics remain defined here.
