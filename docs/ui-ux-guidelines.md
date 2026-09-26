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
9. Address merchants with consistent informal Spanish (tuteo); use neutral Spanish elsewhere.
10. Use the approved dark-only palette with legible contrast and visible focus.

## Visual system

### Approved dark-only palette

The application shell is black; content uses layered charcoal surfaces. Use dark ink on lavender controls and verify contrast for every state.

```text
Shell:          #000000
Canvas:         #181818
Surface:        #1F1F1F
Raised surface: #272727
Border:         #414141
Ink:            #F6F5F2   off-white
Accent:         #B7ABE4   lavender
Accent hover:   #D8CEF5
Public landing: #242424   background
Landing panels: #303030
```

Keep semantic danger/warning feedback distinct from the accent. The landing uses its own charcoal panels, not a light-theme variant.

### Shape and spacing

- Page containers use generous whitespace.
- Cards use rounded corners, typically `16–24px`.
- Inputs/buttons use rounded corners, typically `10–14px`.
- Avoid sharp rectangular panels.
- Borders are subtle; shadows are light and sparse.
- Prefer breathing room over dense dashboards.

### Approved asset inventory

- `public/logo.png`: source artwork; `public/logo-header.webp`: optimized header/footer wordmark.
- `public/logo_icon.svg`: standalone isotipo, including the decorative mark in the landing pass visual.
- `resources/views/partials/head.blade.php`: favicon links to `public/favicon.ico`, `public/favicon.svg`, PNG favicon sizes, and Apple touch icon; `public/site.webmanifest` lists Android icons. Keep the shared head partial as the single integration point.

## Typography

Use the approved Onest Variable sans-serif stack from `resources/css/app.css`.

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
- Navigation order: **Beneficios → Cómo funciona → Retos de puntos → El pase → Preguntas frecuentes → Empezar**.
- No header registration button or theme toggle. The header links to page sections; the hero and final section carry the same registration action.

On small screens, keep navigation accessible without adding a competing registration CTA.

### Hero

Use the current marketing copy in `lang/es/landing.php`; do not replace it with a speculative draft. The hero and final CTA both link to registration; sign-in remains subordinate. Show the illustrative pass and Challenge as conceptual product visuals, not proof of shipped Wallet issuance or Visit validation. The mock QR is not independently decoded or functional. If publishing the page, make the distinction between the target journey and available functionality explicit in product-facing copy.

### How it works

Present registration and Business profile as the available first step. Label the remaining conceptual journey explicitly as future functionality: QR sharing, Visit validation, and Rewards. No Challenge creation or publication is currently available.

Each step uses a numbered marker, short title, and one sentence.

### Challenge section

Explain one clear Challenge model:

> Consigue puntos antes de una fecha y desbloquea una recompensa.

Show a clearly labeled conceptual example and one proposed moment when a Visit could be worth more points. Avoid configurator controls on the landing page.

### Wallet section

Describe a persistent **pase para Google Wallet**, not a tarjeta. Show the target Wallet information hierarchy; issuance remains a future capability.

### Final CTA

Repeat the hero registration action and label from `lang/es/landing.php`.

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

The current dashboard provides Business onboarding and profile access, not operational Challenge controls or analytics. The following operational dashboard guidance describes the future target, not current MVP functionality.

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
