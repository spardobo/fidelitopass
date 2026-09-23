# FidelitoPass UI/UX Guidelines

This document defines the web interaction model, visual system, page intent, and customer-facing UX constraints.

## Experience principles

1. Make the primary action obvious within a few seconds.
2. Prefer recognition over memory.
3. Keep operational Business flows short.
4. Explain every Challenge in plain language.
5. Use progressive disclosure for the optional special point rule.
6. Keep one primary action per decision point.
7. Show immediate feedback for every validation/redeem operation.
8. Use text and iconography in addition to colour.
9. Keep customer and Business copy in neutral Spanish.
10. Avoid visually harsh pure-white/pure-black surfaces.

## Visual system

### Primary colour

Use one playful lime-green accent:

```text
Primary:        #B7F34A
Primary hover:  #A8E33F
Primary active: #98D333
```

Use dark text on lime surfaces. Do not use the lime as body text on light surfaces unless contrast is verified.

### Light theme — default

```text
Canvas:        #F4F1E8   warm ivory
Surface:       #FBF9F2   warm near-white
Surface raised:#FFFDF7   soft cream
Text:          #252820   charcoal olive
Text muted:    #686B61
Border:        #DAD6C8
Primary:       #B7F34A
Danger:        semantic muted red
Warning:       semantic muted amber
```

The light theme must not use pure `#FFFFFF` as the page canvas.

### Dark theme — optional

```text
Canvas:        #181B17   charcoal green
Surface:       #20241F
Surface raised:#282D26
Text:          #EEEBDD   warm off-white
Text muted:    #B5B5AA
Border:        #3A4037
Primary:       #B7F34A
```

The dark theme must not use pure `#000000` as its primary canvas.

### Shape and spacing

- Page containers use generous whitespace.
- Cards use rounded corners, typically `16–24px`.
- Inputs/buttons use rounded corners, typically `10–14px`.
- Avoid sharp rectangular panels.
- Borders are subtle; shadows are light and sparse.
- Prefer breathing room over dense dashboards.

## Typography

Use the project/system sans-serif stack unless a deliberate brand font is added later.

Hierarchy:

- Page title: strong, compact.
- Section title: clear but not oversized.
- Card title: concise.
- Body: comfortable reading line height.
- Operational labels: short and explicit.

Do not use uppercase for long sentences. Challenge labels in Wallet may use short uppercase titles.

## Public landing page

The landing page introduces the product, not the dashboard.

### Header

- FidelitoPass wordmark/logo on the left.
- Compact anchors: `Cómo funciona`, `Retos`, `Para negocios`.
- Theme toggle may appear as an icon/control.
- Primary CTA: **Crear mi reto**.
- Sign-in action: **Entrar**.

On small screens, collapse navigation while keeping the primary CTA reachable.

### Hero

Suggested copy:

**Heading**

> Haz que volver sea parte del juego.

**Supporting copy**

> Crea retos de visitas, añade la tarjeta a Google Wallet y recompensa a tus clientes cuando los completan.

Primary CTA:

> Crear mi reto

Secondary action:

> Ver cómo funciona

Hero visual:

- One clean Wallet-card mockup.
- One challenge example.
- No dashboard screenshot collage.

### How it works

Use four fixed steps:

1. **Crea un reto**.
2. **Comparte tu QR**.
3. **Valida visitas**.
4. **Entrega la recompensa**.

Each step uses one icon, short title, and one sentence.

### Challenge section

Explain one clear Challenge model:

> Consigue puntos antes de una fecha y desbloquea una recompensa.

Show one real example and one example of a moment when a Visit is worth more points. Avoid configurator controls on the landing page.

### Wallet section

Explain:

> Una sola tarjeta. Nuevos retos con el tiempo.

Show the stable Wallet information hierarchy.

### Final CTA

One clear action:

> Crea tu primer reto

### Footer

Keep minimal:

- Product name.
- Short product sentence.
- Privacy/legal links when available.
- Sign-in/register links.

## Authentication pages

Use the Starter Kit/Fortify flows with the FidelitoPass visual tokens.

- Keep forms narrow.
- Labels remain visible.
- Validation appears close to the field.
- Do not add decorative side panels that distract from authentication.
- Business registration does not ask for Challenge configuration.

## Business onboarding

After first sign-in, request only the minimum Business setup:

1. Business name.
2. IANA timezone.
3. Logo (optional for initial save; required before polished Wallet publication if Google Wallet branding requires it).

Timezone selection should:

- Preselect a browser-suggested timezone when available.
- Display the IANA name in a searchable/selectable control.
- Remain editable in settings.
- Explain briefly that it controls Challenge days and deadlines.

Do not expose UTC offsets as the stored Business identity because offsets can change in many regions.

## Dashboard

The dashboard is operational, not analytical.

### Top section

Show:

- Current/scheduled Challenge.
- Status.
- Local validity dates.
- Primary action appropriate to state.

### Counters

Use only:

- Wallet passes issued.
- Points earned in current Challenge.
- Rewards unlocked.
- Rewards redeemed.

Counters are informative, not charts.

### Quick actions

- **Validar visita**.
- **Gestionar reto**.
- **Mostrar QR**.

Avoid advanced analytics, segmentation, trends, or customer lists in MVP.

## Challenge builder

The builder uses one stable page and one Challenge mechanic.

### Challenge fields

Show only:

- Start date.
- End date.
- Target points.
- Reward title.
- Optional Reward description.

### Point earning

Keep point earning compact and separate from the Challenge goal.

Show:

- Regular Visit point value.
- Optional special rule toggle.

When the optional rule is enabled, progressively reveal:

- One weekday.
- Full-day or time-range option.
- Start/end time only when time range is selected.
- Special Visit point value.

Do not offer multiple special rules, rule stacking, expressions, or arbitrary conditions.

### Preview

Show a deterministic Wallet preview alongside the form on wide screens and below the form on narrow screens.

The preview always uses points as the progress unit and shows the current Visit point value.

Do not generate dynamic stamp-circle graphics.

## Acquisition QR page

Business view:

- Business identity.
- Permanent QR large enough to print.
- Short copy explaining what customers do.
- Download/print action.

Customer join view:

- Business logo/name.
- Current Challenge title/description if active.
- Reward.
- Local deadline.
- Add to Google Wallet action.

No account-creation form for customers.

## Validate visit page

This page is designed for fast counter use.

### Required order

1. Page title: **Validar visita**.
2. Scanner/camera area.
3. Manual fallback **immediately below the scanner**.
4. Customer-pass result/action card.

Manual fallback:

```text
Código de la tarjeta
[ 482731              ]
[ Buscar tarjeta ]
```

Do not hide manual entry behind a modal, secondary page, accordion, or menu.

### Camera error

Keep the scanner region in place and show:

> No se pudo acceder a la cámara. Revisa los permisos o introduce el código de la tarjeta.

The manual field remains immediately below.

### Customer-pass result

Show only operationally relevant data:

- Current Challenge.
- Current progress.
- Current Visit point value.
- Result state.
- One primary action.

Do not expose customer identity because none exists.

### Primary action mapping

| State | Primary action |
|---|---|
| Active and can progress | **Registrar visita** |
| Reward available | **Canjear recompensa** |
| Challenge ended/cancelled | none |
| Waiting for Challenge | none |

After an action, show immediate success feedback and the resulting progress.

## Reward redemption

Redemption is final.

Before confirmation show:

- Reward title.
- Challenge.
- Validity deadline.
- Explicit confirmation.

Primary CTA:

> Canjear recompensa

After completion:

> Recompensa canjeada

Do not use optimistic UI for redemption.

## Form behaviour

- Server-side validation is authoritative.
- Use client-side hints only for convenience.
- Preserve entered data after recoverable validation errors.
- Prefer validation on blur/submit rather than aggressive per-keystroke error states.
- Error copy states what is wrong and how to fix it.
- Use specific CTA labels: **Publicar reto**, **Registrar visita**, **Canjear recompensa**.

## Loading and perceived responsiveness

- Provide immediate button/loading feedback for network actions.
- Disable only the operation currently in flight.
- Use compact skeletons only where content loading is visible enough to justify them.
- Do not use fixed-duration sleeps in browser behaviour.
- Do not use optimistic updates for Visit creation, Reward unlock, or Redemption.

## Accessibility baseline

Core pages should target WCAG AA interaction expectations:

- Semantic HTML controls.
- Visible focus.
- Associated labels.
- Sufficient contrast.
- Minimum touch targets near 44×44px.
- Status text that does not rely on colour alone.
- Keyboard-accessible navigation and actions.
- Attribute `aria-live`/status semantics for important dynamic operation feedback where appropriate.
- Reduced motion support for non-essential animation.

## Motion

Use motion only to reinforce success or transition.

Allowed examples:

- Subtle progress update.
- Short Reward-unlock emphasis.
- Small card state transition.

Avoid continuous animation in the dashboard, scanner, or Wallet preview.
