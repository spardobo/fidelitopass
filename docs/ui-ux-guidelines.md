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

The application shell follows the dark-gray page canvas; all neutral cards, including the hero, share one neutral surface. Use dark ink on lavender controls and verify contrast for every state.

These are distinct **roles**, not a near-colour palette to consolidate, except where neutral and large-neutral cards explicitly share a value. The exact table governs the whole application, not sampled preview pixels or legacy mockup colours. Global definitions do not certify adoption on every runtime surface. Keep all role and state distinctions.

| Role | Exact value | Context / state |
| --- | --- | --- |
| Shell; canvas | `#242424`; `#242424` | Page-colored shell/header/footer and canvas |
| Neutral surface; large neutral; raised surface | `#2E2E2E`; `#2E2E2E`; `#363636` | All neutral cards/FAQ/auth panels and hero/large neutral panels share one target; retain the distinct raised role |
| Border; line; panel border | `#525252`; `#484848`; `#525252` | Neutral boundary; panel line; panel-specific boundary (retain separate tokens) |
| Priority surface; border; ink | `#3D2E55`; `#8465AC`; `#E4D6FA` | Active Promotion and general-information card |
| Control surface; border | `#222222`; `#7D7D7D` | Inputs inside cards; recognizable field outline |
| Secondary button; border; hover | `#383838`; `#828282`; `#444444` | Neutral action and its hover, not primary lavender |
| Primary; secondary; help ink | `#F6F5F2`; `#C7C4CE`; `#B0ACB8` | Reading hierarchy |
| Accent; hover; pressed | `#A77BFF`; `#B893FF`; `#9566EB` | Primary action state |
| Ink on accent; accent text / general focus | `#17131F`; `#CDB0FF` | Primary button text; readable link / general focus-visible outline |
| Success ink; surface; border | `#BCE7C9`; `#263D2E`; `#507F5E` | Confirmed operation and completed preparation, with text/icon |
| Warning ink; surface; border | `#E6D4AB`; `#3A3326`; `#736248` | Draft / pending badge, with label |
| Danger ink; surface; border | `#FFB4BE`; `#382426`; `#91525A` | Error / cancelled status, with explanation |
| Scheduled surface | `#403152` | Scheduled context outside the scoped Pase status-badge mapping below |
| Danger hover; ready operational hover | `#482B32`; `#46355E` | Danger button hover; enabled header registration action hover, not accent hover |

Focus is context-dependent: main-content inputs/selects/textareas use a `2px` **`#A77BFF`** outline with `3px` offset and accent border; general focus uses a `2px` **`#CDB0FF`** outline with `3px` offset outside that context absent a stronger component rule. Do not claim one universal focus colour or copy mockup selectors into Flux. Verify actual installed DOM and focus in browser.

The table is the single normative palette, including authentication and private screens; legacy literals do not authorize an alternate palette. Marketing composition and editorial typography may differ from forms and workspaces. Keep one shared application mapping for canvas, neutral surface, emphasis and accent, with one definition per role. Screens and landing aliases consume that mapping without competing palette literals. Token names and emission mechanisms belong to source. The public header and transparent footer follow the application canvas, not a separate black bar.

Unmigrated authentication/private surfaces require their own authorized work item; global token availability is not proof of complete rollout. Do not recolor those screens indirectly or introduce a header on login.

The first emphasized benefit and the mechanics (La mecánica) panel both consume the canonical app emphasis surface and priority border `#8465AC`, with suitable on-dark text from the canonical reading/priority ink roles above; do not use a light neutral panel or force ink-on-accent `#17131F` onto purple. Primary CTAs and the illustrative pass retain accent `#A77BFF` with ink-on-accent `#17131F`. Verify computed roles and contrast, including the cascade over older lavender classes, rather than assuming a class proves the rendered colour. These are role expectations, not a claim that current source already matches.

Keep semantic success, warning and danger surfaces, borders and text separate from the brand accent; success is not a new brand colour. Preserve the current landing layout and motion, including reduced-motion handling; change only the approved accent role across public, authentication and app surfaces when those screens are implemented. Do not globally override landing CSS or recolour a Business's saved pass. A sample pass's lavender fallback is not evidence of a saved appearance.

### Shape and spacing

- Application margins, padding and gaps use an 8 CSS px base, with 4 CSS px fine increments for compact or internal spacing. Select spacing by semantic grouping, not by rounding every legacy value to the nearest step. Common values include `4px`, `8px`, `12px`, `16px`, `24px`, `32px`, `40px`, `48px` and `64px`; prefer 8px multiples for layout groups and use 4px increments where tighter component rhythm needs them. This is a web CSS-pixel adaptation of [Material Design 2's 8dp layout measurements](https://m2.material.io/design/layout/understanding-layout.html) and [4dp fine grid](https://m2.material.io/design/layout/spacing-methods.html), not a device-pixel rule or a replacement component system. Use the existing Tailwind spacing scale. This rule concerns spacing only; typography, radii and borders retain their separate contracts.
- This spacing contract applies to application-authored margins, padding and gaps, including the public landing page. Normalize existing values through scoped work; do not round every legacy value mechanically. Preserve native Flux/component spacing and each page's approved composition.
- Page containers use generous whitespace. Match the header-to-hero opening gap with the last-CTA-to-footer closing gap. Keep landing main left/right padding `24px` / `32px` from `48rem` and top `32px` / `40px`, with the existing capped `80px` rhythm. Preserve hero viewport availability, widths, margins and independent section spacing. Without JavaScript or after teardown, use the natural `32px` / `40px` base. Header content row and footer use equal internal vertical padding (`24px` top/bottom), distinct from exterior gaps. Exact fitting calculations belong to source.
- Use a maximum authenticated workspace width of `68rem`, about `20px` card radius and `11px` control radius; controls and operational targets should be at least `2.75rem` high.
- Avoid sharp rectangular panels.
- Borders are subtle; shadows are light and sparse. Public hero, benefit, step and FAQ panels retain a `1px` neutral boundary `#525252`; emphasized benefit and mechanics panels use `#8465AC`. The mechanics example uses a priority divider; FAQ answers and the footer use the line role `#484848`. Decorative framing does not replace the essential interactive focus outline.
- Prefer breathing room over dense dashboards.

### Approved brand assets

Reuse the approved wordmark, isotipo and favicon family. Keep shared page metadata as the single integration boundary; source owns asset paths and optimized formats.

### Custom interface icons

Use the installed Flux Heroicons outline pack for interface glyphs in the public landing, navigation, and pass preview. Set `variant="outline"` on standalone `flux:icon` components and `icon:variant="outline"` on menu-item icon slots. Keep interface glyphs decorative when adjacent text already provides their meaning.

Brand marks are not interface glyphs. Keep their approved geometry and proportions unchanged; do not redraw them to match the icon pack. A brand mark may use its own public SVG and CSS mask when it needs to inherit a foreground color, but its source shape remains a separate branding asset.

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

The mapping must preserve hierarchy, readability and accessible target sizes without changing the root font size.
Check shared role changes across affected roles, screen sizes and states before acceptance. The authorized UI work item
chooses the implementation; this contract does not require overriding every component.

### Business workspace role normalization

Summary, the Business Pase overview, the appearance editor and the Promoción editor share the existing Business workspace roles. Choose a role by meaning, not by whether the text sits inside a card. Reuse the existing classes; do not create a parallel page-specific scale.

| Purpose | Existing role / composition | Size / line / weight | Ink | Spacing | Example |
| --- | --- | --- | --- | --- | --- |
| Page title | `app-heading` / `app-role-title` | `32/40px`, 700 | Primary | Existing header group | Resumen, Pase |
| Page introduction | `app-role-intro` | `18/28px`, 400 | Secondary | Existing header group | Page description |
| Named section, including a standalone card section | `app-role-section` | `20/28px`, 600 | Primary | `16px` section content gap | Promoción, Puntos extra, Próxima promoción |
| Subordinate entity or item heading | `app-role-card` | `18/28px`, 600 | Primary | `4px` Reward-description gap; `8px` in Pase article bodies | Reward name, preparation item |
| Reading text | `app-role-body` | `16/24px`, 400 | Primary or contextual secondary | Existing body group | Business context or description |
| Action, label or compact empty-state message | `app-role-action`, or body with medium weight | `16/24px`, 500 | Contextual | Keep associated content together | Metric label, empty Promotion message |
| Supporting description or field help | `app-role-support` | `14/20px`, 400 | Secondary; help ink for field help | Adjacent to the content it explains | Reward description, metric description |
| Explanatory informational note | `app-note-with-icon`, support text, help ink | `14/20px`, 400 | Help | `8px` icon/text gap; `16px` outline glyph centered in a `16×20px` line box | Scope, waiting guidance, Wallet note |
| Metric value | `app-role-metric` | `36/40px`, 600, tabular | Primary | Existing metric content gap | Current Promotion activity |
| Status or count badge | Native Flux variant with shared support typography | Native `14/20px`, 500 | Native semantic variant | Center against associated text | Activa, Pendiente, completion count |

Place a group heading outside its grouped cards. Put a standalone named section heading inside its own card. Do not repeat the same heading outside and inside. A named section remains a section even when contained in a card; a Reward or item name remains subordinate. Normal descriptions and field help are not informational alerts and do not gain information icons.

Summary cards reuse Pase's neutral-container padding: `p-4 sm:p-6`. Keep `16px` between card content groups and the existing page/grid gaps. Do not flatten contextual rhythms: Reward title/description uses `4px`, informational icon/text uses `8px`, and Pase article body content retains its existing `8px` rhythm. Informational notes use a decorative `inline-flex h-5 w-4` icon wrapper with a `size-4` outline information glyph. Warning banners and metadata icons retain their distinct roles and sizes. Native Flux neutral badges own their background and ink; do not replace them with the card surface. Preserve existing colored variants and compact Pase list badges.

Business identification uses body scale and accent ink; intros use canonical secondary ink. These values supersede the generic title scale only in the existing Business workspace context. Reuse shared semantic roles instead of page-specific title and intro overrides.

Keep generic authentication/modal titles and public marketing roles unchanged. This shared mapping does not authorize unrelated screen rollout.

### Business Pase page normalization

The following composition and preview rules remain scoped to the Business Pase overview and appearance editor; shared workspace typography does not expand their scope.

Keep the authenticated workspace max width, `16px` horizontal padding (`24px` from `sm`), and `32px` workspace gap. The overview/editor breadcrumb uses body scale and sits `8px` before the title and intro. The overview panel uses `16px` padding (`24px` from `sm`) and a `24px` gap, without a larger `lg` padding tier. Keep existing touch targets and Flux control geometry. The right overview column uses 16px body/action scale with `8px` between heading and description and `16px` between content groups. Status badges retain Flux’s native `14/20px` sizing and muted public color variants; informational helper notes use `14/20px` support text and help ink. The Promotions section has `16px` outer spacing and a `4px` heading/helper gap. Promotion cards use `16px` padding (`24px` from `sm`), `16px` outer gap, a `48px` icon, and `8px` content rhythm.

In the saved-appearance promotion card, leave `24px` between the explanatory text and the create-promotion action.

### Public marketing and sample-pass roles

The landing's intentionally large display heading is a marketing exception to the general authenticated H1 scale. Preserve its `36/40px` mobile size, `40/44px` at `lg`, and `44/48px` from `70rem`. Standard marketing section headings use `28/36px` by default and `32/40px` from `md`. The distinct “Una meta con fecha. Una visita que suma.” marketing statement shares that section-heading scale and uses app accent for every animated word. The editorial tagline “Cada visita acerca a tu cliente a una recompensa. El vínculo con tu negocio permanece.” retains its three animated semantic color groups: primary ink, canonical app accent for the reward-word group, and help ink for the final group. Landing benefit and step card headings use separate semantic roles at `20/28px` by default and `24/32px` from `md`, all semibold. Keep these scoped roles separate; do not resize general card or other marketing section roles. Marketing H1/H2s use canonical primary app ink on neutral surfaces. Small landing section-intro/eyebrow text and the login link use canonical app accent. The Mechanics eyebrow is a scoped exception: it uses the semantic marketing-eyebrow role mapped to app accent-text for contrast on the emphasized panel. The accent CTA retains its contrast-safe on-accent ink. Body copy keeps its contextual secondary roles and help notes keep help ink.

The landing sample pass is a public marketing visual; the Business preview is a compact authenticated workspace visual. Use separate marketing-pass and Business-preview typography roles. The landing pass uses `20/28px` bold brand text, `18/28px` semibold promotion label, `14/20px` detail text, `30/36px` bold tabular progress, and `16/24px` bold reward text. The Business preview uses `20/28px` bold business name, `14/20px` semibold promotion label, `14/20px` regular details, `28/32px` bold tabular progress, and `14/20px` bold reward text. Allow details to wrap and the card to grow naturally at narrow widths; do not reduce them to a separate 12px query. The landing pass must retain its marketing roles; do not normalize them to the smaller Business preview roles. At mobile landing thumbnail widths of `320px` and `375px`, compensate for preview scaling so the card retains at least `16px` of rendered horizontal inner padding; keep the desktop source padding at `24px`. Both samples share the business/brand header, promotion/progress middle zone, and reward/deadline footer; use `tag` for the promotion, `bolt` for extra points, and `gift` for the reward. Landing benefit cards use `arrow-path-rounded-square` for “Un motivo” and `arrow-trending-up` for “Progreso”. The landing example may add a mock QR in the middle zone's right column and manual code in the reward footer's right column; center each relative to that zone's left content. The Business preview does not show either. Use “Un consumo de cortesía” for the shared restaurant/cafe sample reward. Keep actual Wallet and customer state database-authoritative; simulated progress is allowed only in the clearly captioned example card and is never live Business/customer state.

Use three stacked zones for the illustrative pass: header, middle terms/progress, and reward footer. Center each zone's content vertically while keeping the business name/logo on opposite sides and the copy left-aligned. The grid proportions are `1:2:1` (25% / 50% / 25%) when content fits. Give each zone the same vertical padding. Use intrinsic fractional sizing; do not let minimum-content floors distort the proportions. On narrow cards, remove the fixed aspect-ratio constraint and let intrinsic max-content sizing grow the card while preserving the `1:2:1` zones. Never clip or shrink content to force the preferred `3:2` aspect ratio.

Use sentence case for headings, buttons and labels; preserve proper names, acronyms and Business-authored copy. Landing registration CTAs use the shared `16/24px`, `500` action role and existing `44px` control minimum-height token; preserve their current variants, palette and shapes without forcing new padding. Do not fade essential pending-state explanations. On initial setup, preparation cards use body scale, not tiny secondary text. Short Wallet-specific uppercase labels may remain where the Wallet presentation contract requires them.

## Public landing page

The landing page introduces the product, not the dashboard. When landing content is published before an illustrated capability is available, label that capability as future in product-facing copy; do not present the target journey as currently operational.

### Header

- FidelitoPass wordmark/logo on the left, with the page-colored shell; preserve the existing header height and composition.
- Navigation order: **Beneficios → Cómo funciona → Promociones → El pase → Preguntas frecuentes → Empezar**.
- No header registration button or theme toggle. The header links to page sections; the hero and final section carry the same registration action.

On small screens, keep navigation accessible without adding a competing registration CTA.

The authenticated Business header uses the same FidelitoPass wordmark size as the public landing header. At desktop widths (`900px` and above), match the public header's `91px` outer height and center the wordmark and `44px`-minimum navigation targets in the row. At narrower widths, preserve the existing two-row authenticated header and its touch targets; do not compress it to the shorter public mobile header. Preserve routes and actions.

### Hero

Use the approved localized marketing copy; do not replace it with a speculative draft. The hero and final CTA both link to registration; sign-in remains subordinate. Show the illustrative Pase and Promoción as conceptual product visuals, not proof of issued Wallet credentials or operational Visit validation. The mock QR is not independently decoded or functional. Do not present conceptual visuals as usable credentials.

### FAQ disclosure

Retain native `details` / `summary` keyboard and focus semantics. Hide the browser's left marker and place an aria-hidden chevron on the right of the question, rotating it for the open state without a duplicate JavaScript toggle. Reduced motion disables the icon transition; the visible question remains the accessible label.

### Editorial statement

Keep the approved locale-owned word sequence intact. Group its primary proposition, reward and secondary relationship clause through presentation metadata, not duplicated translated phrases or word matching in Blade. Use persistent primary ink `#F6F5F2`, readable reward accent `#CDB0FF` (not the filled-control accent), and secondary ink `#B0ACB8`. Entrance animation may temporarily dim words, but its final state, reduced motion, no-JavaScript fallback and teardown must retain these distinct roles.

The public editorial role is `2.25rem` / approximately `1.222223`, weight 600, with a `48rem` maximum line length. Adapt to `1.875rem` below `50rem` and `1.75rem` below `40rem` for mobile legibility. This is not the authenticated H1 role; longer approved copy may naturally wrap onto additional lines rather than being shortened or given forced breaks.

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

Repeat the hero registration action and label from the approved marketing copy.

### Footer

Keep minimal:

- Product name.
- Short product sentence.
- Privacy/legal links when available.
- Sign-in/register links.

## Integration and visual fidelity check

Before accepting a screen, consult its relevant section here, its acceptance requirement and affected domain doc.
`composer.lock` records the resolved dependency version, not proof of the installed runtime; use installed source and
configuration to establish the actual Flux version and API behavior. Confirm locally served Onest loads. Compare against
the applicable owner-approved design reference at the **same viewport, state and fixture**: layout geometry, spacing,
computed role colours, actual font, focus order and narrow-screen reflow. Record intentional deviations and obtain owner
approval for material differences. The normative palette above takes precedence over raw mockup backgrounds; acceptance
does not require a 100% pixel copy. Fallback-font screenshots are not pixel goldens. A document or source-level check
cannot establish browser/pixel fidelity.

Mockup adapters and demo behavior are not production contracts. Do not transfer demo selectors, fixed clock, sample
codes, scenario toolbar/reset, `sessionStorage`, fake QR/stats/permissions or intercepted preview login/register links
into production. A demo account dialog does not replace authentication.

## Authentication pages

Use the Starter Kit/Fortify flows with the FidelitoPass visual tokens.

- Keep forms narrow.
- Labels remain visible.
- Validation appears close to the field.
- Do not add decorative side panels that distract from authentication.
- Registration collects Business name and a visible, manually editable, server-validated IANA timezone alongside owner credentials in one atomic operation; no logo or Promoción configuration is required. Fresh registration may prefill a supported browser-configured timezone. Explain that this is a suggestion to check against the Business, not verified physical location. Explicit registration submission confirms the selection. If detection is unavailable or unsupported, leave manual selection; preserve entered values after validation errors.
- Preserve email verification and normal intended destinations after authentication. **Resumen** is the ordinary authenticated application home, not a required first stop.

## Authenticated navigation

The desktop header offers **Resumen**, **Pase**, **Invitar clientes**, a global **Registrar visita** action and account access; account menu retains Business, Profile, Security and logout destinations. Mobile rearranges visible navigation without shrinking operational text or hiding the action without an accessible alternative. Keep exactly one active link with `aria-current="page"`; appearance editing, Promotion editing/review/detail keep Pase selected. Anchor the active lavender line to the intrinsic icon + label group with `0.25rem` gap and `0.125rem` thickness, not to the bottom of the whole header link; preserve the link rectangle and independent keyboard focus. If the icon hides on mobile, the line tracks the label width. Saving/cancelling appearance or Promotion returns to Pase, not Resumen; dirty editor navigation requests confirmation.

## Business profile

The Business is created with the owner at registration. The Business destination edits the owner's existing Business name and IANA timezone. A logo is optional, not a publication prerequisite. Keep Profile and logout available.

Timezone selection should:

- Allow a supported browser suggestion only on fresh registration; require confirmation through explicit submission. Display the saved timezone when editing, without browser detection.
- Use an editable native select with ICU names and cities in the active application locale and decorative current PHP offsets at one shared instant. Offer only native PHP default identifiers recognized by ICU as system zones; missing ICU support leaves no choices.
- Keep native options compact: localized country, representative city and decorative offset, such as `Bolivia, La Paz (UTC-04:00)`. Omit the city when it repeats the country. Shorter options reduce content width; native popup dimensions remain browser-controlled.
- Show the selected ICU time zone name in a subtle, wrapping badge below the field, updating it after manual selection and supported browser detection. Hide the badge when no valid selection exists. Preserve guidance and validation feedback. Capitalize only the time zone phrase's initial character; preserve proper names and the remaining runtime-localized text.
- Order by the displayed country, representative city and original IANA identity, not the offset. Place UTC last, using its localized name and offset without fabricated geography. Append original IANA detail only when geographic labels still collide. Keep every distinct choice.
- Keep recognized zones even when PHP and ICU offset rules differ: PHP supplies the offset and ICU supplies generic-location wording in that case. Use ICU's localized long name for UTC. Labels reflect installed runtime data, not a promised external-provider vocabulary. Submitted and stored values remain validated original IANA identifiers, never rewritten ICU canonical aliases.
- Remain editable in settings.
- Explain briefly that it controls Promoción days and deadlines.

Do not expose UTC offsets as the stored Business identity because offsets can change in many regions.

## Dashboard

**Resumen** is a read-only status view, not an editor or setup wizard. Its preparation cards are informational articles, not disabled clickable controls or checkboxes; the only setup link, **Ir a Pase**, goes to the Pase workspace for pass appearance and Promoción preparation. Never remember a setup origin to redirect there after saving. Distinguish incomplete Promoción preparation, scheduled, active, ended and cancelled Promociones; a scheduled Promoción completes preparation but cannot accept visits yet. Show truthful waiting states rather than fabricated activity.

### Top section

Keep one predictable content order in every Summary state: header and Pase link, primary Promotion card, four equal-weight metric cards, then the points-context and upcoming-Promotion cards. These cards remain present when context or activity is empty. Select the currently active published Promotion, even when it began before today. If none is active, select the earliest future scheduled publication by start instant, then identity. If neither exists, show an explicit placeholder that states there is no active or scheduled Promotion. Drafts and ended/cancelled publications remain in Pase, not in either Summary Promotion card. Show actual Reward terms and target **per pass**, using frozen published dates and timezone. Never invent a Promotion, activity or global-progress total to fill a card.

When preparation needs attention, show both preparation cards after the stable content, including any completed step. Appearance remains pending until saved. First-Promotion creation is complete when a draft or any scheduled, active, ended or cancelled publication exists; preserve the separate historical-publication fact. A draft still completes the first-Promotion preparation step without becoming Summary primary context. If its preparation card is visible because appearance is missing, describe the saved draft without claiming publication. Publication history prevents a later draft from reopening first-time onboarding. Hide the preparation section when appearance and draft/publication preparation are complete. Show an amber **Preparación pendiente** badge beside the header's existing **Ir a Pase** access only while the section is needed; keep this group centered and wrapping, without adding another action.

The primary card always retains its **Promoción** section heading, using the same global section role as the points and upcoming cards. Its Reward name uses the subordinate card-heading role; an empty-state message uses body scale with medium weight, not a competing heading. The upcoming card always retains its **Próxima promoción** heading. Select the earliest future scheduled publication after the primary Promotion, excluding its identity. With an active primary, this is the first future publication; with a scheduled primary, it is the second. If no primary exists, no upcoming Promotion exists. Whenever no upcoming publication exists, show the same **No hay una próxima promoción programada.** informational helper inside the card. The points card uses only the selected published primary Promotion's actual rules. When no Promotion exists, explain that this card will show point rules and extra-point configuration, not visitor accumulation, instead of inventing terms.

The next-scheduled Promotion panel uses a section heading for **Próxima promoción**, matching the points-context panel, with its status badge adjacent to the title. Its Reward name uses a subordinate card heading. Promotion cards reuse the publication detail labels **Meta** with a trophy icon and **Vigencia** with a calendar icon. The target remains per pass. Show the Reward description when present, using the shared support-text role. Center the gift icon against the Reward title and description only, with a `4px` text gap; metadata remains below that group. Arrange facts horizontally when space permits and stack them on mobile. Do not repeat the fixed **Visita habitual · 1 punto** on Promotion cards; keep it in the separate points-context panel when actual Promotion terms exist. Give configurable **Puntos extra** the full available card width. Reuse publication review labels, the sparkles icon and weekday/time rows with violet multiplier badges. Rule cards use equal responsive tracks and row heights; an odd last card never expands across the whole desktop row. A single mobile column may use full width. When no rules exist, show **Sin puntos extra** as an informational helper. Do not show timezone labels in Summary panels; keep frozen-timezone date conversion unchanged.

Use the shared informational note role, support text, help ink and decorative information icon for scope explanations, empty context, waiting guidance and preparation help. Place each message inside its corresponding card or section. Reward descriptions and metric labels remain ordinary text, not informational alerts.

Center every Summary badge vertically with its associated title or content, including preparation cards and wrapped rule rows. Summary Promotion phase badges reuse Pase and detail-modal Flux variants and labels: active green and scheduled blue. Draft and terminal badges remain in Pase. Preparation steps use native green **Completado** or neutral **Pendiente** variants. The completion count is neutral for `0 de 2` and amber for partial `1 de 2` progress; never mark an unsaved appearance complete. Neutral badges reuse canonical native Flux background and ink with shared support typography, matching Pase's ended-state badge without a parallel palette.

### Counters

Use four Promotion-scoped counters for the authorized Business's **currently active** Promotion: distinct Customer passes with at least one accepted Visit (**Pases con actividad**, not issued passes or people), sum of stored awarded Visit points, created Reward entitlements including redeemed ones, and definitively redeemed entitlements. Confirmed Visits and currently available Rewards may appear as supporting text; do not subtract historical points on redemption. Keep all four cards visible. Without an active Promotion, show `—` and not-applicable guidance inside each card, never invented zeroes. A successful empty active query means known zeroes; put the no-Visit helper inside **Pases con actividad**.

For a handled statistics outage, keep the cards and show `—`, with one safe generic recovery banner above the content, one native generic toast per failed read and one **Reintentar** action. Do not repeat errors inside individual cards or retain previous metrics. Retry re-resolves current ownership and context. A total context outage follows the existing exception boundary; do not fabricate a Business header or stale facts. A statistics failure alone does not block invitation or authorized operational validation. Avoid analytics, trends and cross-Promotion totals.

### Responsive summary layout

For the four equal-weight metrics use **four columns above `64rem`**, two at `64rem` down to above `36rem`, and one at `36rem` or less. Points and upcoming cards retain two columns above `48rem` and stack below that boundary, including empty states. The two preparation cards use two desktop columns and one at `48rem` or less whenever visible. Promotion facts stack on mobile. Wrap long names and terms rather than hiding numbers, status or reducing operational type. Informational cards do not gain pointer/hover affordances.

### Quick actions

- **Registrar visita** as a prominent global action, reachable on mobile. Keep complete-product menu destinations enabled during incremental implementation, even when routes or functionality are absent and links may fail. Do not disable them or add coming-soon/unavailable copy solely because implementation is pending.
- **Pase** for managing Promociones and appearance.
- **Invitar clientes** for the public acquisition QR.

Avoid advanced analytics, segmentation, trends, or customer lists in MVP.

## Pase and Promoción editor

**Pase** is a vertical sequence: compact illustrative Wallet preview, independently saved pass appearance, then Promoción list. Keep a `3:2` preview ratio while the sample fits; let its height grow naturally when content does not fit. Give the header and reward/footer zones the same `72px` baseline and `8px` vertical inset, with a content-sized middle row that takes the remaining space. Allow the card to grow naturally for wrapped names or content instead of clipping. Bound the preview rail to `320px`–`400px` in split layouts, with `400px` preferred. Preserve at least `192px` for adjacent content and a `24px` gap; shrink the rail only as available space requires, then give remaining width to adjacent content. In stacked layouts, center the preview and its caption together and cap the rail at `400px` so it does not stretch across a wide tablet row. Keep the split rail left-aligned and top-aligned. On mobile, use available width without forcing the `320px` minimum. Do not target a fraction of the row. The Business preview may show a clearly labeled illustrative sample Promotion, including invented terms and simulated progress such as `9 / 15 puntos`; an outer caption must identify it as a sample, not current Business or customer activity. Never represent it as live backend/Wallet state or show real customer/statistical data, credentials, QR codes, or issued Wallet content. Keep actual Wallet presentation database-authoritative. Avoid a giant phone mockup, a fixed card that leaves unused row space, or a blank full-height preview column. Appearance keeps all eight presets, the accessible native colour input and synchronized hexadecimal field on one line at desktop widths. At constrained tablet widths, use Flux’s public small preset size (32px) to keep the row together. On narrow mobile widths, allow the controls to wrap instead of adding horizontal scrolling. Preserve accessible names, selected state and the server error below the complete control group. The operating system or browser owns native-picker dismissal. Google Wallet supports background colour but does not guarantee a chosen native text colour. List drafts, scheduled, active, ended and cancelled Promociones. Only draft terms are editable; cancellation is a separate action.

Open a dedicated full-page Promotion editor within Pase, with **Información general** and optional **Puntos extra** sections. Use native date controls and accessible Flux Free/basic fields; do not use a modal, tabs, or Pro-only date picker for Promotion editing. Appearance colour selection uses the native browser/OS colour control rather than an in-app modal.

Keep publication review within Pase as a compact native confirmation modal over the mounted editor. Closing it preserves editor values and scroll position. Bound its content to the viewport, scroll only the summary body, and keep confirmation actions visible without scrolling the page. Compose its header from a 36px outline rocket in a 64px accent-tinted circle, an accent eyebrow, a clear heading and a concise subtitle. Group the Reward title/description and three fact rows—target, inclusive local validity, and extra-point count—in one neutral bordered summary card with fixed-width desktop labels and restrained dividers. Use the same body-scale semibold value typography for target, dates and extra-point count in review and published detail. Center each fact icon vertically in its row while labels and values start at the same edge. Keep the extra-point count aligned with its label in a 44px disclosure row; expanded cards remain below the row. Do not show a green readiness badge: the review confirms owner-entered terms, not publication availability. Use an accent callout for the whole-Promotion immutability warning and a secondary edit action beside the primary confirmation.

Keep configured timezone out of routine review and show a warning only when it changes. Keep extra-point count visible in a native disclosure that starts collapsed, with compact cards when expanded. Do not repeat the scheduled start date outside the validity range; do not imply immediate activation for a future Promotion. Reveal the accent scrollbar on hover or keyboard focus for fine pointers; keep native touch scrolling usable and respect reduced-motion preferences. Keep the review keyboard-accessible and validate focus/dismissal behavior in the browser. Do not add a separate review route or a second confirmation modal.

When publication fails, close the confirmation modal and stay in the editor with its submitted values, page position and focus preserved. Show specific localized domain feedback for expected rejections, field feedback for invalid submitted data, and a safe generic message for unexpected failures. A timezone change requires reopening the review and confirming again. Only successful publication redirects to Pase.

### Published Promotion detail and cancellation

Open **Ver detalle** from active, scheduled and historical entries in a separate native modal over Pase. Reuse the publication-summary presentation for the saved Reward, target, original inclusive local dates and all extra-point rules. Derive the displayed dates from the Promotion's frozen timezone, not the current Business timezone. Do not expose editable published terms or reuse unsaved editor values.

Use a 36px outline document icon in a 64px accent-tinted circle. Keep the detail badge consistent with the corresponding list entry. Pase status badges use muted native green for active, blue for scheduled, red for cancelled and neutral zinc for ended, with explicit status text. This scoped status mapping does not recolor other application surfaces.

Only active or scheduled detail offers **Cancelar promoción**. Its initial action uses the primary accent treatment and reveals an inline danger warning in the same modal. The final **Sí, cancelar promoción** action uses danger styling. Keep **Volver al pase** as the single secondary action; do not open another confirmation modal. Closing or dismissing before final confirmation does not cancel anything. Stale, repeated or ended requests show safe localized feedback without changing the original terms or first cancellation instant.

Bound the modal to the viewport. Scroll only its body and keep its footer actions reachable at narrow widths without page-level horizontal overflow. Support Escape and native close controls. Restore focus to the visible originating detail action; if cancellation removes or hides that action in collapsed history, focus the Promotions heading without opening history.

### Promotion history

Place ended and cancelled Promotions in a bottom history disclosure, collapsed by default. Preserve its open or closed state across detail dismissal, cancellation and pagination. Keep scheduled, draft and history pagination independent. Order history by the original start instant ascending, then stable Promotion ID ascending; cancellation time does not move a row to the beginning.

Use compact divided rows without an outer card or individual row cards. Each row shows only the Reward title, status, original date range and **Ver detalle**. Do not repeat the description or point target; those remain in detail. Use `14/20px` support typography for the title, date and action, and the native small status badge size.

Keep **Ver detalle** a semantic button with accent-text ink, intrinsic width, a pointer cursor and a minimum 44px touch target. Use underline and a visible keyboard-focus outline for interaction feedback. Its background stays transparent at rest, hover, focus and active states; do not add a ghost-button hover fill.

### Información general

Show local start/end dates, positive target points, Reward title and optional description, and the current Business IANA timezone as read-only context, not an independent selector. Publication review confirms the timezone; if it changed since review, refresh and ask for confirmation again. Published terms, including scheduled ones, are immutable.

### Puntos extra

State the fixed regular Visit value: 1 point. Keep an inline Add form stable while entries are added: weekday, whole day or start/end hours, and multiplier x2, x3 or x5. Below it show each added entry as a row with weekday, whole-day or hours, multiplier and **Quitar**. Multiple disjoint half-open `[start, end)` windows may touch endpoints but not overlap; whole-day and timed entries cannot coexist on one weekday. Split overnight intervals across days. Never stack rules or offer expressions/a generic builder. No rules are inherited from previous Promociones.

### Shared actions and preview

Use **Cancelar**, **Guardar borrador**, **Publicar** when applicable. Preserve unsaved values between sections and review, show errors within the relevant section, warn about incomplete unadded entries and confirm navigation away from a dirty editor. Save/publish the complete Promotion and its extra-points rules atomically; appearance saves separately. Server validation is authoritative. Show a deterministic compact preview with numeric points, regular or applicable extra-points Visit value and no dynamic stamp circles; it is not a native-device colour or issuance guarantee.

## Invitation and operational availability

After the first **saved** appearance, **Ver QR para invitar** is available from Pase, with or without an active Promotion; merely previewing a default colour does not qualify. The permanent public join page shows an active Promotion or an honest save-your-pass waiting state, not a new QR for each Promotion. A QR-generation error is distinct from invitation eligibility.

For an implemented validation feature, enable **Registrar visita** only when appearance is saved, the current Promotion is active, and server-authorized validation is operational. When a genuine runtime prerequisite prevents use, keep its disabled reason legible and its header access visible. Known zero Visits and failed statistics queries never disable an otherwise authorized operation. Unknown operational phase/authorization must not be inferred from an old badge; server rechecks on confirmation. No local mock capability flag proves production readiness.

## Acquisition QR page

Business view:

- Business identity.
- Permanent public QR with adequate print contrast and quiet zone; avoid promising an unverified physical size.
- QR-generation failures remain recoverable without changing the permanent public identity.
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

When implemented and operational, the global action opens one identification → confirmation → result dialog for fast counter use. Pending implementation alone does not disable its header entry.

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

> No se pudo acceder a la cámara. Revisa los permisos o ingresa el código del Pase.

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
- Associated labels and field errors.
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
