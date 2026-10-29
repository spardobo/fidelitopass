# FidelitoPass Google Wallet Presentation

This document defines the permanent Wallet credential for MVP. Current Promotion and personal loyalty information belong to the private informational page, not the native card. This is agreed presentation intent, not proof of source or device rollout.

## Presentation hierarchy

Use one stable identification layout across all Promotion and customer states:

1. Business name and logo, using saved shared appearance.
2. Private QR and labelled manual code.
3. A brief, static invitation on the card front: **¡Qué bueno tenerte aquí!** / **Consulta los detalles de tu tarjeta cuando quieras.**
4. A native front action labelled **Ver detalles**, opening the private informational page. The details view retains a relevant **Detalles de mi tarjeta** link without repeating the invitation.

```text
CAFÉ CENTRAL

[private barcode]
Código manual: 48273

¡Qué bueno tenerte aquí!
Consulta los detalles de tu tarjeta cuando quieras.

[Ver detalles → private informational page]
```

Do not show Promotion titles, terms, target, progress, current Visit value, Reward, redemption status or Promotion dates anywhere in Wallet, including details. Do not replace those fields with variable waiting, active, completed or expired messages. The static invitation and action ask the customer to consult the page. They do not claim a positive balance, an active offer, an available Reward or automatic synchronization.

Use the native [front web action](https://developers.google.com/wallet/reference/rest/v1/AppLinkData) with concise localized button text of no more than 30 characters. It and the details link must open the same private destination. Use the supported [card and details templates](https://developers.google.com/wallet/retail/loyalty-cards/resources/template) to show the static invitation on the front and only the titled link in details. A link in details alone is not the front action. The invitation and action express the intended reading order; Google controls their exact position, separation, wrapping and device visibility. Provider payload acceptance is not proof of that composition on a device.

Illustrative web examples remain explanatory, nonoperational samples under their own UI contract; they do not establish the content of an issued credential. The linked personal page reuses the shared application roles and Join-style icon surfaces, with restrained violet heading/progress emphasis and a regular-weight solid-star card for applicable extra points, reusing Join's existing emphasized card composition and the global body typography. The page orders progress, temporal information, applicable multiplier and meaningful next steps, omitting absent roles. All dates, including a prior canje shown after cancellation, are temporal facts rather than a further customer instruction. Scheduled offers show their start and next step without a redundant points-target sentence; available Rewards show a temporal redemption deadline followed by a separate next-step instruction without repeating the date. Positive headings, current progress, next-step and recovery accents use the existing `#A77BFF`, not the lighter accent-text role. The progress count and unit sit inside Join's neutral card composition with deliberate count/target separation. The multiplier uses Join's high-contrast priority ink on emphasis, not low-contrast accent text. Date-only temporal information, including actual historical canje dates, uses bold secondary ink. All next-step instructions use normal-weight accent body text, including the available Reward instruction; there is no mixed date/action styling exception. Those web accents do not recolor the saved native Wallet credential or add variable facts to it.

### Credential continuity

Keep the same Object identity, Class binding, private QR and Business-scoped manual code across Promotions and commercial operations. Barcode alternate text retains the labelled manual code. Do not rotate credentials or create a new Object because a Promotion starts, ends or is cancelled, or because points or a Reward change.

The personal link is a separate native signed credential bound to that Customer pass and Business. Never place it in the QR or manual code. Its destination is read-only and contains no duplicate scannable pass or validation credential.

## Native identity and appearance

Native Wallet uses U+200B (ZERO WIDTH SPACE) as `issuerName` and the dynamic Business name as `programName`, the main title. Clear localized issuer/program names that would override this identity. The compact list selects the Business title and raw `accountId`, without the manual-code label; Google can replace the second row when grouping multiple passes. Keep the circular `programLogo`; do not add a wide logo. The default logo reuses the existing glyph in accent `#A77BFF` on an opaque `#1C1C1C` square, independently of the Business card background. Supply a full-bleed PNG of at least 660 × 660 pixels, without premasking. When recolouring the hosted circular icon, preserve its canvas, glyph scale, position, antialiasing and existing safe margins. Do not resize or recenter the artwork. Use one optional public absolute HTTPS override, `GOOGLE_WALLET_LOGO_URL`. In local development, set that variable to the full public Supabase URL for `icon-logo-wallet.png`. When unset or empty, every environment uses normalized `APP_URL` plus `/icon-logo-wallet.png`; this URL must also be public HTTPS. Production can use that fallback to serve `public/icon-logo-wallet.png` or configure another public HTTPS URL with the same filename. `public/` identifies the project directory, not part of the provider URL. Native logo selection never depends on the card background. Normal future issuance reconciles shared identity, logo, static invitation and its front/details layout only when necessary; it does not reset Object credentials or imply immediate device propagation. Web previews retain their separate background-contrast selection. Google controls the holder, font size, weight, line wrapping and final geometry. The field order does not promise exact spacing or a pixel-identical web card. Compact surfaces may truncate; keep identification and link text concise.

Shared Class appearance may change through the existing Business-owned provider contract. It is not a Promotion synchronization channel. Google controls device propagation; provider acceptance does not establish immediate rendering.

Clear obsolete dynamic layout slots when preparing the fresh development format. The shared invitation contains no private destination or customer credential. Keep the signed destination on each individual Object. Preserve unrelated provider content while reconciling the owned static invitation and layout. New Objects must contain no dynamic Promotion fields, points modules or history blocks. Do not retain compatibility selectors or repair previously issued development cards.

## State selection

Promotion phase and personal progress do not select a different Wallet format. Issuance, waiting, scheduled, earning, available/redeemed Reward, expiry, cancellation and a successor all use the same credential. The private page reads the current PostgreSQL state when opened; it performs no Wallet synchronization.

A new active offer applies automatically to eligible passes. No customer enrollment or per-pass bulk participation creation is required. No state transition implies point carryover, a new earning cycle after redemption or Reward grace.

## Localization and presentation roles

Generated native Wallet copy uses Laravel's active locale and configured translation fallback. The caller owns locale selection; presentation must not force a language or change the application's locale. Only the Spanish Wallet catalog ships today. Other languages use Laravel's configured fallback (Spanish by default) until their catalogs exist; regional date formatting still uses the active locale. Additional catalogs must preserve the same semantic keys and replacement parameters. Adding a language must not require changes to presentation-state selection or force the caller's locale.

Business names remain merchant-authored. Credential labels, static invitation, front action and details-link text use the translation catalog. Native localized button text identifies the active translation or its configured fallback language. QR values, manual codes and signed link targets are not translated. Additional catalogs must not change authorization or business-state selection.

## Provider boundaries

Initial issuance remains Google-first before local Customer pass persistence. Shared appearance changes retain their separate bounded provider-confirmed contract. Neither boundary is replaced by this presentation decision.

Visits, Redemptions, publication, cancellation, time passage and personal-page reads require no Google request or individual Object refresh. The page has no sync control, pending-sync spinner or deferred refresh request. Introduce no job, cron, per-Object sweep, automatic retry or durable sync registry.

This is a fresh development format, not a migration or repair contract for previously issued cards. Stored local history and existing points/Reward rules remain unchanged.

## Presentation rules

- Use one permanent card for identification, not an offline copy of personal progress.
- Keep customer text warm, positive and simple. Avoid technical state or synchronization language.
- Keep QR/manual credentials available in every state and preserve existing credential secrecy.
- Keep branding independent of Promotion terms and per-customer facts.
- The informational link exposes no customer Visit, redemption or enrollment action.
- Do not promise proactive Promotion notifications or automatic news delivery; these are outside MVP.
- Provider acceptance does not prove device rendering, and displayed Wallet content never authorizes a commercial operation.
