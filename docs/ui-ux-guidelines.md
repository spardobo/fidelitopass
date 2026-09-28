# FidelitoPass UI/UX Guidelines

This document defines the web interaction model, visual system, page intent, and customer-facing UX constraints.

## Experience principles

1. Make the primary action obvious within a few seconds.
2. Prefer recognition over memory.
3. Keep operational Business flows short.
4. Explain every Promoción in plain language.
5. Keep extra-points weekday and hour choices compact and explicit.
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

Do not use uppercase for long sentences. Promoción labels in Wallet may use short uppercase titles.

## Public landing page

The landing page introduces the product, not the dashboard. When landing content is published before an illustrated capability is available, label that capability as future in product-facing copy; do not present the target journey as currently operational.

### Header

- FidelitoPass wordmark/logo on the left.
- Navigation order: **Beneficios → Cómo funciona → Promociones → El pase → Preguntas frecuentes → Empezar**.
- No header registration button or theme toggle. The header links to page sections; the hero and final section carry the same registration action.

On small screens, keep navigation accessible without adding a competing registration CTA.

### Hero

Use the current marketing copy in `lang/es/landing.php`; do not replace it with a speculative draft. The hero and final CTA both link to registration; sign-in remains subordinate. Show the illustrative Pase and Promoción as conceptual product visuals, not proof of issued Wallet credentials or operational Visit validation. The mock QR is not independently decoded or functional. Do not present conceptual visuals as usable credentials.

### How it works

Explain the customer journey in product terms: register the Business, share its public QR, validate visits and deliver Rewards. Distinguish illustrations from operational controls; do not imply an unavailable operation works.

Each step uses a numbered marker, short title, and one sentence.

### Promoción section

Explain one clear Promoción model:

> Consigue puntos antes de una fecha y desbloquea una recompensa.

Show a clearly labeled conceptual example and an extra-points moment when a Visit could be worth more points. Avoid configurator controls on the landing page.

### Wallet section

Describe a persistent **Pase para Google Wallet**, not a new pass per Promoción. Show the Wallet information hierarchy without implying an illustrative preview is an issued credential.

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
- Registration collects Business name and a visible, confirmable, server-validated IANA timezone alongside owner credentials in one atomic operation; no logo or Promoción configuration is required.

## Business onboarding

For an existing owner without a Business, offer compatible setup with only Business name and visible, server-validated IANA timezone. A logo is optional, not a publication prerequisite. **Resumen** is the ordinary authenticated application home, not a required first stop after sign-in. Keep Profile and logout available; its incomplete-setup CTA uses the same **Pase** route as navigation, not a forced wizard.

Timezone selection should:

- Preselect a browser-suggested timezone when available.
- Display the IANA name in a searchable/selectable control.
- Remain editable in settings.
- Explain briefly that it controls Promoción days and deadlines.

Do not expose UTC offsets as the stored Business identity because offsets can change in many regions.

## Dashboard

**Resumen** leads with the next relevant action and distinguishes missing setup, scheduled, active, ended and cancelled Promociones. Show truthful waiting states rather than fabricated activity. A scheduled Promoción completes setup but is not yet eligible for visits.

### Top section

Show:

- Current/scheduled Promoción.
- Status.
- Local validity dates.
- Primary action appropriate to state.

### Counters

Use only:

- Wallet passes issued (not installations or unique people).
- Points earned in current Promoción.
- Rewards unlocked.
- Rewards redeemed.

Counters are informative, not charts.

### Quick actions

- **Registrar visita** as a prominent global action, reachable on mobile. Disable it until validation is operational, with an honest explanation rather than a dead or deceptive control.
- **Pase** for managing Promociones and appearance.
- **Invitar clientes** for the public acquisition QR.

Avoid advanced analytics, segmentation, trends, or customer lists in MVP.

## Pase and Promoción editor

**Pase** is a vertical sequence: compact illustrative Wallet preview, independently saved pass appearance, then Promoción list. Avoid a giant phone mockup or blank full-height preview column. Appearance offers presets, native colour input and synchronized hexadecimal field, with validation and automatically contrasting web text; Google Wallet supports background colour but does not guarantee a chosen native text colour. List drafts, scheduled, active, ended and cancelled Promociones. Only draft terms are editable; cancellation is a separate action.

Open one editor modal with exactly two labelled, keyboard-accessible tabs: **Información general** and **Puntos extra**. Use Flux Free modal/basic fields, native date controls and accessible custom Blade/Alpine tabs (focus, arrow-key navigation and labelled panels); do not promise Pro tabs or date/colour pickers.

### Información general

Show local start/end dates, positive target points, Reward title and optional description, and the current Business IANA timezone as read-only context, not an independent selector. Publication review confirms the timezone; if it changed since review, refresh and ask for confirmation again. Published terms, including scheduled ones, are immutable.

### Puntos extra

State the fixed regular Visit value: 1 point. Keep an inline Add form stable while entries are added: weekday, whole day or start/end hours, and multiplier x2, x3 or x5. Below it show each added entry as a row with weekday, whole-day or hours, multiplier and **Quitar**. Multiple disjoint half-open `[start, end)` windows may touch endpoints but not overlap; whole-day and timed entries cannot coexist on one weekday. Split overnight intervals across days. Never stack rules or offer expressions/a generic builder. No rules are inherited from previous Promociones.

### Shared footer and preview

Use **Cancelar**, **Guardar borrador**, **Publicar** when applicable. Preserve unsaved values across tabs, reveal errors in the hidden tab, warn about incomplete unadded entries and confirm closing a dirty editor. Save/publish the complete Promotion and its extra-points rules atomically; appearance saves separately. Server validation is authoritative. Show a deterministic compact preview with numeric points, regular or applicable extra-points Visit value and no dynamic stamp circles; it is not a native-device colour or issuance guarantee.

## Acquisition QR page

Business view:

- Business identity.
- Permanent public QR with adequate print contrast and quiet zone; avoid promising an unverified physical size.
- Short copy explaining what customers do.
- Download/print action.

Customer join view:

- Business name and optional logo.
- Current Promoción description if active, otherwise a waiting message.
- Reward.
- Local deadline.
- Add to Google Wallet action.

No account-creation form for customers.

## Registrar visita dialog

Once operational, the global action opens one identification → confirmation → result dialog for fast counter use. Until then, keep the action disabled with an honest explanation.

### Required order

1. Dialog title: **Registrar visita**.
2. Scanner/camera area.
3. Manual fallback **immediately below the scanner**.
4. Customer-Pase lookup, explicit confirmation and result card in the same dialog.

Manual fallback:

```text
Código del Pase
[ 482731              ]
[ Buscar pase ]
```

Keep manual entry visible inside this same dialog directly beneath the camera, including camera errors. Scanning and manual lookup are read-only and lead to the same confirmation; release camera and listeners on close/navigation.

### Camera error

Keep the scanner region in place and show:

> No se pudo acceder a la cámara. Revisá los permisos o ingresá el código del Pase.

The manual field remains immediately below.

### Customer-pass result

Show only operationally relevant data:

- Current Promoción.
- Current progress.
- Current Visit point value.
- Result state.
- One primary action.

Do not expose customer identity because none exists.

### Primary action mapping

| State | Primary action |
|---|---|
| Active and can progress | **Confirmar visita** |
| Reward available | **Canjear recompensa** |
| Promoción ended/cancelled | none |
| Waiting for Promoción | none |

Before mutation, explicitly confirm the intended operation. On confirmation, revalidate ownership, eligibility, published terms and database time on the server with an idempotency key; show resulting progress after commit. Provider sync failures must not invite a duplicate Visit or redemption.

## Reward redemption

Redemption is final.

Before confirmation show:

- Reward title.
- Promoción.
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
- Use specific CTA labels: **Publicar**, **Registrar visita**, **Canjear recompensa**.

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
