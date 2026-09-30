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

These are distinct **roles**, not a near-colour palette to consolidate. Provenance is the supplied 3.2 `DECISIONES-UI-UX.md` (Paleta conservada), `design-tokens.json` (colors), and accepted R00 `DESIGN.md` (Components) / `design.json` (interpreted components); these are local evidence basenames, not required committed links. CSS-specific overrides below follow source order.

| Role | Exact value | Context / state | Source basename / section |
| --- | --- | --- | --- |
| Shell; canvas | `#101010`; `#191919` | Authenticated header; authenticated page, not landing | `DECISIONES-UI-UX.md` / Paleta conservada |
| Neutral surface; raised surface | `#2C2C2C`; `#363636` | Metric/preparation cards; menus | `design-tokens.json` / colors |
| Border; line; panel border | `#525252`; `#484848`; `#525252` | Neutral boundary; panel line; panel-specific boundary (retain separate tokens) | `design-tokens.json` / colors |
| Priority surface; border; ink | `#382B4B`; `#8465AC`; `#E4D6FA` | Active Promotion and general-information card | `design-tokens.json` / colors |
| Control surface; border | `#222222`; `#7D7D7D` | Inputs inside cards; recognizable field outline | `DECISIONES-UI-UX.md` / Paleta conservada |
| Secondary button; border; hover | `#383838`; `#828282`; `#444444` | Neutral action and its hover, not primary lavender | `design-tokens.json` / colors; R00 `DESIGN.md` / Components |
| Primary; secondary; help ink | `#F6F5F2`; `#C7C4CE`; `#B0ACB8` | Reading hierarchy | `DECISIONES-UI-UX.md` / Paleta conservada |
| Accent; hover; pressed | `#A77BFF`; `#B893FF`; `#9566EB` | Primary action state | `design-tokens.json` / colors; R00 `DESIGN.md` / Components |
| Ink on accent; accent text / general focus | `#17131F`; `#CDB0FF` | Primary button text; readable link / general focus-visible outline | `design-tokens.json` / colors; R00 `DESIGN.md` / Components |
| Success ink; surface; border | `#BCE7C9`; `#263D2E`; `#507F5E` | Confirmed operation and completed preparation, with text/icon | `design-tokens.json` / colors |
| Warning ink; surface; border | `#E6D4AB`; `#3A3326`; `#736248` | Draft / pending badge, with label | `design-tokens.json` / colors |
| Danger ink; surface; border | `#FFB4BE`; `#382426`; `#91525A` | Error / cancelled status, with explanation | `design-tokens.json` / colors |
| Scheduled badge | `#403152` | Scheduled status with explicit text | R00 `DESIGN.md` / Components |
| Danger hover; ready operational hover | `#482B32`; `#46355E` | Danger button hover; enabled header registration action hover, not accent hover | R00 `DESIGN.md` / Components (CSS 2357–2362; 2602–2605) |

Focus is context-dependent: within `#main`, the more-specific input/select/textarea `:focus-visible` rule retains a `2px` **`#A77BFF`** outline with `3px` offset and accent border; the later generic focus-visible rule uses a `2px` **`#CDB0FF`** outline with `3px` offset outside that context absent a stronger selector. Do not claim one universal focus colour or copy those mockup selectors into Flux. Source: R00 `DESIGN.md` / Components (`fidelitopass-menu-ajustado.css` 1807–1814, 2371–2373); verify actual installed DOM and focus in browser.

The existing public landing's separate canvas/panels (`#242424` / `#303030`) are inherited presentation context from this guide, not authenticated-token evidence; preserve layout and motion while applying the approved accent when its owning implementation changes.

Keep semantic success, warning and danger surfaces, borders and text separate from the brand accent; success is not a new brand colour. Preserve the current landing layout and motion, including reduced-motion handling; change only the approved accent role across public, authentication and app surfaces when those screens are implemented. Do not globally override landing CSS or recolour a Business's saved pass. A sample pass's lavender fallback is not evidence of a saved appearance.

### Shape and spacing

- Page containers use generous whitespace.
- Use a maximum authenticated workspace width of `68rem`, about `20px` card radius and `11px` control radius; controls and operational targets should be at least `2.75rem` high.
- Avoid sharp rectangular panels.
- Borders are subtle; shadows are light and sparse.
- Prefer breathing room over dense dashboards.

### Approved asset inventory

- `public/logo.png`: source artwork; `public/logo-header.webp`: optimized header/footer wordmark.
- `public/logo_icon.svg`: standalone isotipo, including the decorative mark in the landing pass visual.
- `resources/views/partials/head.blade.php`: favicon links to `public/favicon.ico`, `public/favicon.svg`, PNG favicon sizes, and Apple touch icon; `public/site.webmanifest` lists Android icons. Keep the shared head partial as the single integration point.

## Typography

Use locally served Onest as the intended rendered font via the existing application stack; a fallback-font screenshot cannot certify Onest. Keep the root at `16px` equivalent instead of resizing it to change Flux defaults.

| Role | Size / line-height | Weight |
| --- | --- | --- |
| H1 | `24/32px` mobile; `30/36px` from `48rem` | 600 |
| Section / card heading | `20/28px` / `18/28px` | 600 |
| Body, controls and navigation | `16/24px` | 400 reading; 500 actions/labels |
| Help, dates, metadata | `14/20px` | 400–500 |
| Metric number | `36/40px` tabular | 600 |
| Nonessential editorial note | `12/16px` | 400–500 |

### Centralized role sizing

Treat the typography table as a role contract, not a list of values to repeat in individual screens. Page titles, section headings, card headings, body/control text, supporting text and metrics must consume one project-owned, reusable role mapping. Keep font size, line height, weight and responsive changes together in that mapping so a global title-size change updates one shared definition rather than many templates. Do not collapse distinct roles into a single heading size.

For each role, prefer the component's default size or a supported native Flux size variant when it matches or closely approximates the extracted UI/UX specification without compromising hierarchy, readability or accessible target sizes. Inspect each installed component: size names and their rendered values are not universal. Where native variants cannot satisfy the role, define the smallest necessary adjustment once in the shared role mapping; do not scatter fixed pixel values, arbitrary utilities or repeated size props across screens. Centralization does not require overriding every Flux component or changing the root font size.

Implement the mapping through existing project composition where possible; introduce a thin shared Blade wrapper or centralized styling only when needed to provide the role consistently. Choose the concrete implementation in the owning UI work item, not in this documentation-only standard. Shared changes must be checked across affected roles, screen sizes and states before acceptance.

Use sentence case for headings, buttons and labels; preserve proper names, acronyms and Business-authored copy. Do not fade essential pending-state explanations. On initial setup, preparation cards use body scale, not tiny secondary text. Short Wallet-specific uppercase labels may remain where the Wallet presentation contract requires them.

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

## Implementation mapping (installed Flux Free v2.18.0)

Version observed in `composer.lock` (`livewire/flux` v2.18.0), not a promise about other releases. Inspect the installed component API, rendered DOM and existing starter usage before selecting props or selectors. Prefer native Flux composition first, scoped Tailwind utilities second, and central project CSS only for a necessary shared exception; never edit vendor or copy the mockup adapter wholesale.

| Visual element / state | Starting component / integration | Boundary to check |
| --- | --- | --- |
| Header and active destination | `flux:navbar` / `flux:navbar.item` or existing starter navigation | Keep semantic link, accessible current page, full hit area and focus; intrinsic icon + label underline `0.25rem` below line box, `0.125rem` thick, on desktop/mobile. Avoid duplicated native underline. |
| Resumen facts and preparation | Semantic `article` / `section`, Flux heading/text where suitable | No clickable preparation cards or disabled faux buttons; emphasis only for active Promotion. |
| Primary, secondary, danger actions | `flux:button` with supported variant/size | Match semantic state roles; do not assume Flux `sm` equals mockup 16/24 type or 44px targets. |
| Labels, text, code and date entry | `flux:field`, `flux:label`, `flux:input`, `flux:error`; native date/colour where appropriate | Verify actual v2.18 props/DOM, associated error and focus; no presumed Pro picker. |
| Extra-point weekday/multiplier | `flux:select` or native semantic select | Preserve half-open window text, keyboard and error semantics; no Pro-only control. |
| Promotion status | `flux:badge` plus visible label/icon | Distinguish scheduled, draft, cancelled, active and completed beyond colour. |
| Account navigation | Existing starter `flux:dropdown` and `flux:menu` | Profile/security/logout continue to work; do not model demo account dialog as authentication. |
| Operational scanner | `flux:modal` for one dialog; semantic stage content and Livewire action | Focus/return and camera lifecycle require inspection; no tabs or simultaneous scanner/result stack. |
| Invitation QR and illustrative pass | Project-owned semantic content; Flux text/button where useful | Actual QR needs permanent public identity, quiet zone and recovery; preview is not an issued Wallet credential. |

### Integration and visual fidelity check

Before writing a screen, read only its relevant section here, its acceptance requirement and affected domain doc. Inspect the installed Flux version, actual rendered markup/props and starter composition; confirm locally served Onest loads. Compare against the supplied 3.2 reference at the **same viewport, state and fixture**: layout geometry, spacing, computed role colours, actual font, focus order and narrow-screen reflow. Record intentional deviations and obtain owner approval for material differences. Historical screenshots were rendered with fallback font and are not pixel goldens. A document or source-level check cannot establish browser/pixel fidelity. Do not copy mockup HTML/CSS/JS, demo selectors, fixed clock, sample codes, scenario toolbar/reset, `sessionStorage`, fake QR/stats/permissions or intercepted preview login/register links into production.

## Authentication pages

Use the Starter Kit/Fortify flows with the FidelitoPass visual tokens.

- Keep forms narrow.
- Labels remain visible.
- Validation appears close to the field.
- Do not add decorative side panels that distract from authentication.
- Registration collects Business name and a visible, confirmable, server-validated IANA timezone alongside owner credentials in one atomic operation; no logo or Promoción configuration is required.

## Authenticated navigation

The desktop header offers **Resumen**, **Pase**, **Invitar clientes**, a global **Registrar visita** action and account access; account menu retains Business, Profile, Security and logout destinations. Mobile rearranges visible navigation without shrinking operational text or hiding the action without an accessible alternative. Keep exactly one active link with `aria-current="page"`; appearance editing, Promotion editing/review/detail keep Pase selected. Anchor the active lavender line to the intrinsic icon + label group with `0.25rem` gap and `0.125rem` thickness, not to the bottom of the whole header link; preserve the link rectangle and independent keyboard focus. If the icon hides on mobile, the line tracks the label width. Saving/cancelling appearance or Promotion returns to Pase, not Resumen; dirty editor navigation requests confirmation.

## Business onboarding

For an existing owner without a Business, offer compatible setup with only Business name and visible, server-validated IANA timezone. A logo is optional, not a publication prerequisite. **Resumen** is the ordinary authenticated application home, not a required first stop after sign-in. Keep Profile and logout available; its incomplete-setup CTA uses the same **Pase** route as navigation, not a forced wizard.

Timezone selection should:

- Preselect a browser-suggested timezone when available.
- Display the IANA name in a searchable/selectable control.
- Remain editable in settings.
- Explain briefly that it controls Promoción days and deadlines.

Do not expose UTC offsets as the stored Business identity because offsets can change in many regions.

## Dashboard

**Resumen** is a read-only status view, not an editor or setup wizard. Its preparation cards are informational articles, not disabled clickable controls or checkboxes; the only setup link, **Ir a Pase**, goes to the Pase workspace. Never remember a setup origin to redirect there after saving. Distinguish missing setup, scheduled, active, ended and cancelled Promociones; a scheduled Promoción completes preparation but cannot accept visits yet. Show truthful waiting states rather than fabricated activity.

### Top section

Without an active Promotion show the header and Pase link, two preparation cards, a waiting/next-scheduled state and, when relevant, only a brief identification of the last ended/cancelled Promotion. With an active Promotion show a priority card containing Reward, target **per pass**, local dates and frozen timezone, plus extra-point summary; then four equally weighted neutral metrics, optional next-scheduled line and two secondary completed preparation cards. No global-point-progress bar against one pass's target.

### Counters

Use four Promotion-scoped counters for the authorized Business's **currently active** Promotion: distinct Customer passes with at least one accepted Visit (**Pases con actividad**, not issued passes or people), sum of stored awarded Visit points, created Reward entitlements including redeemed ones, and definitively redeemed entitlements. Confirmed Visits and currently available Rewards may appear as supporting text; do not subtract historical points on redemption. No active Promotion means waiting, not four zeroes; a successful empty query means known zeroes; failed/unavailable aggregate read means `—` and a localized explanation/retry, never zero. A statistics failure alone does not block invitation or authorized operational validation. Avoid analytics, trends and cross-Promotion totals.

### Responsive summary layout

For the four equal-weight metrics use **four columns above `64rem`**, two at `64rem` down to above `36rem`, and one at `36rem` or less. Preparation cards use two desktop columns and one at `48rem` or less; active Promotion facts stack on mobile. Wrap long names and terms rather than hiding numbers, status or reducing operational type. Informational cards do not gain pointer/hover affordances. Source: supplied 3.2 `DECISIONES-UI-UX.md` / Responsive nuevo; accepted R00 `DESIGN.md` / Layout and responsive.

### Quick actions

- **Registrar visita** as a prominent global action, reachable on mobile. Disable it until validation is operational, with an honest explanation rather than a dead or deceptive control.
- **Pase** for managing Promociones and appearance.
- **Invitar clientes** for the public acquisition QR.

Avoid advanced analytics, segmentation, trends, or customer lists in MVP.

## Pase and Promoción editor

**Pase** is a vertical sequence: compact illustrative Wallet preview (up to `360px`, `3:2`), independently saved pass appearance, then Promoción list. The preview may show clearly illustrative, truthful sample terms, never fake current customers, statistics, codes, QR credentials or issued Wallet content. Avoid a giant phone mockup or blank full-height preview column. Appearance offers presets, native colour input and synchronized hexadecimal field, with validation and automatically contrasting web text; Google Wallet supports background colour but does not guarantee a chosen native text colour. List drafts, scheduled, active, ended and cancelled Promociones. Only draft terms are editable; cancellation is a separate action.

Open a dedicated full-page Promotion editor within Pase, with **Información general** and optional **Puntos extra** sections. Use native date controls and accessible Flux Free/basic fields; no modal, tabs, or Pro-only date/colour picker. Review and read-only detail remain within Pase.

### Información general

Show local start/end dates, positive target points, Reward title and optional description, and the current Business IANA timezone as read-only context, not an independent selector. Publication review confirms the timezone; if it changed since review, refresh and ask for confirmation again. Published terms, including scheduled ones, are immutable.

### Puntos extra

State the fixed regular Visit value: 1 point. Keep an inline Add form stable while entries are added: weekday, whole day or start/end hours, and multiplier x2, x3 or x5. Below it show each added entry as a row with weekday, whole-day or hours, multiplier and **Quitar**. Multiple disjoint half-open `[start, end)` windows may touch endpoints but not overlap; whole-day and timed entries cannot coexist on one weekday. Split overnight intervals across days. Never stack rules or offer expressions/a generic builder. No rules are inherited from previous Promociones.

### Shared actions and preview

Use **Cancelar**, **Guardar borrador**, **Publicar** when applicable. Preserve unsaved values between sections and review, show errors within the relevant section, warn about incomplete unadded entries and confirm navigation away from a dirty editor. Save/publish the complete Promotion and its extra-points rules atomically; appearance saves separately. Server validation is authoritative. Show a deterministic compact preview with numeric points, regular or applicable extra-points Visit value and no dynamic stamp circles; it is not a native-device colour or issuance guarantee.

## Invitation and operational availability

After the first **saved** appearance, **Ver QR para invitar** is available from Pase, with or without an active Promotion; merely previewing a default colour does not qualify. The permanent public join page shows an active Promotion or an honest save-your-pass waiting state, not a new QR for each Promotion. A QR-generation error is distinct from invitation eligibility.

Enable **Registrar visita** only when appearance is saved, the current Promotion is active, and server-authorized validation is operational. Keep its disabled reason legible and its header access visible. Known zero Visits and failed statistics queries never disable an otherwise authorized operation. Unknown operational phase/authorization must not be inferred from an old badge; server rechecks on confirmation. No local mock capability flag proves production readiness.

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

During identification only, keep manual entry visible directly beneath the scanner including camera errors. Scanning and manual lookup are read-only and lead to the same confirmation. Once identified, hide scanner and manual together; display only the relevant confirmation, then only the result after a confirmed commit. **Volver a leer** restores a clean identification. Pause/ignore stale reads after lookup; release camera and listeners on close/navigation.

### Camera error

Keep the scanner region in place and show:

> No se pudo acceder a la cámara. Revisá los permisos o ingresá el código del Pase.

The manual field remains immediately below.

### Customer-pass result

Show only operationally relevant data in the focused stage: current Promoción, current pass progress, applicable Visit point value or Reward deadline, stage result and one primary action. Move focus from reader instructions to confirmation heading to result heading; on close restore focus to the trigger and preserve the originating page's unsaved inputs.

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
