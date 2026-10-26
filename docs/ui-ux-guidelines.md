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
| Shell; canvas | `#1C1C1C`; `#1C1C1C` | Page-colored shell/header/footer and canvas |
| Neutral surface; large neutral; raised surface | `#2E2E2E`; `#2E2E2E`; `#363636` | All neutral cards/FAQ/auth panels and hero/large neutral panels share one target; retain the distinct raised role |
| Border; line; panel border | `#525252`; `#484848`; `#525252` | Neutral boundary; panel line; panel-specific boundary (retain separate tokens) |
| Priority surface; border; ink | `#3D2E55`; `#8465AC`; `#E4D6FA` | Active Promotion and general-information card |
| Control surface; border | `#222222`; `#7D7D7D` | Inputs inside cards; recognizable field outline |
| Secondary button; border; hover / pressed | `#2E2E2E`; `#7A7A7A`; `#363636` | Same neutral surface at rest; subtle neutral hover / pressed states |
| Primary; secondary; help ink | `#F6F5F2`; `#C7C4CE`; `#B0ACB8` | Reading hierarchy |
| Accent; hover; pressed | `#A77BFF`; `#B893FF`; `#9566EB` | Primary action state |
| Ink on accent; accent text / general focus | `#17131F`; `#CDB0FF` | Primary button text; readable link / general focus-visible outline |
| Success ink; surface; border | `#BCE7C9`; `#263D2E`; `#507F5E` | Confirmed operation and completed preparation, with text/icon |
| Warning ink; surface; border | `#E6D4AB`; `#3A3326`; `#736248` | Draft / pending badge, with label |
| Danger ink; surface; border | `#FFB4BE`; `#382426`; `#91525A` | Error / cancelled status, with explanation |
| Scheduled surface | `#403152` | Scheduled context outside the scoped pass status-badge mapping below |
| Danger hover; ready operational hover | `#482B32`; `#46355E` | Danger button hover; enabled header registration action hover, not accent hover |

Neutral secondary actions use a visible solid boundary with the secondary-button border role, including filled controls. Apply the same treatment to equivalent neutral actions across pages and account dialogs. Preserve native disabled/loading feedback and keep primary, danger, ghost/icon and navigation actions distinct.

For a secondary action on an emphasized surface, use the reusable `app-button-secondary-on-emphasis` treatment with Flux's outline variant. Keep its background transparent at rest and use the priority-ink role for the visible border and text. Hover and active states add a light priority-ink tint only while enabled. Reuse the shared button geometry and keyboard-focus treatment, and retain native disabled and loading semantics. Ordinary neutral cards keep the existing gray secondary treatment; contextual availability does not authorize a global rollout.

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

Summary, the Business pass overview, the appearance editor and the Promoción editor share the existing Business workspace roles. Choose a role by meaning, not by whether the text sits inside a card. Reuse the existing classes; do not create a parallel page-specific scale.

| Purpose | Existing role / composition | Size / line / weight | Ink | Spacing | Example |
| --- | --- | --- | --- | --- | --- |
| Page title | `app-heading` / `app-role-title` | `32/40px`, 700 | Primary | Existing header group | Resumen, Tarjeta Jaqaku |
| Page introduction | `app-role-intro` | `18/28px`, 400 | Secondary | Existing header group | Page description |
| Named section, including a standalone card section | `app-role-section` | `20/28px`, 600 | Primary | `16px` section content gap | Promoción, Puntos extra, Próxima promoción |
| Subordinate entity or item heading | `app-role-card` | `18/28px`, 600 | Primary | `4px` Reward-description gap; `8px` in pass article bodies | Reward name, preparation item |
| Reading text | `app-role-body` | `16/24px`, 400 | Primary or contextual secondary | Existing body group | Business context or description |
| Action, label or compact empty-state message | `app-role-action`, or body with medium weight | `16/24px`, 500 | Contextual | Keep associated content together | Metric label, empty Promotion message |
| Supporting description or field help | `app-role-support` | `14/20px`, 400 | Secondary; help ink for field help | Adjacent to the content it explains | Reward description, metric description |
| Explanatory informational note | `app-note-with-icon`, support text, help ink | `14/20px`, 400 | Help | `8px` icon/text gap; `16px` outline glyph centered in a `16×20px` line box | Scope, waiting guidance, Wallet note |
| Metric value | `app-role-metric` | `36/40px`, 600, tabular | Primary | Existing metric content gap | Current Promotion activity |
| Status or count badge | Native Flux variant with shared support typography | Native `14/20px`, 500 | Native semantic variant | Center against associated text | Activa, Pendiente, completion count |

Place a group heading outside its grouped cards. Put a standalone named section heading inside its own card. Do not repeat the same heading outside and inside. A named section remains a section even when contained in a card; a Reward or item name remains subordinate. Normal descriptions and field help are not informational alerts and do not gain information icons.

Summary cards reuse the pass page's neutral-container padding: `p-4 sm:p-6`. Keep `16px` between card content groups and the existing page/grid gaps. Do not flatten contextual rhythms: Reward title/description uses `4px`, informational icon/text uses `8px`, and pass article body content retains its existing `8px` rhythm. Informational notes use a decorative `inline-flex h-5 w-4` icon wrapper with a `size-4` outline information glyph. Warning banners and metadata icons retain their distinct roles and sizes. Native Flux neutral badges own their background and ink; do not replace them with the card surface. Preserve existing colored variants and compact pass list badges.

Business identification uses body scale and accent ink; intros use canonical secondary ink. These values supersede the generic title scale only in the existing Business workspace context. Reuse shared semantic roles instead of page-specific title and intro overrides.

Keep generic authentication/modal titles and public marketing roles unchanged. This shared mapping does not authorize unrelated screen rollout.

### Business pass page normalization

The following composition and preview rules remain scoped to the Business pass overview and appearance editor; shared workspace typography does not expand their scope.

Keep the authenticated workspace max width, `16px` horizontal padding (`24px` from `sm`), and `32px` workspace gap. The overview/editor breadcrumb uses body scale and sits `8px` before the title and intro. The overview panel uses `16px` padding (`24px` from `sm`) and a `24px` gap, without a larger `lg` padding tier. Keep existing touch targets and Flux control geometry. The right overview column uses 16px body/action scale with `8px` between heading and description and `16px` between content groups. Status badges retain Flux’s native `14/20px` sizing and muted public color variants; informational helper notes use `14/20px` support text and help ink. The Promotions section has `16px` outer spacing and a `4px` heading/helper gap. Promotion cards use `16px` padding (`24px` from `sm`), `16px` outer gap, a `48px` icon, and `8px` content rhythm.

In the saved-appearance promotion card, leave `24px` between the explanatory text and the create-promotion action.

### Public marketing and sample-pass roles

The landing's intentionally large display heading is a marketing exception to the general authenticated H1 scale. Preserve its `36/40px` mobile size, `40/44px` at `lg`, and `44/48px` from `70rem`. Standard marketing section headings use `28/36px` by default and `32/40px` from `md`. The distinct “Una meta con fecha. Una visita que suma.” marketing statement shares that section-heading scale and uses app accent for every animated word. The editorial tagline “Cada visita acerca a tu cliente a una recompensa. El vínculo con tu negocio permanece.” retains its three animated semantic color groups: primary ink, canonical app accent for the reward-word group, and help ink for the final group. Landing benefit and step card headings use separate semantic roles at `20/28px` by default and `24/32px` from `md`, all semibold. Keep these scoped roles separate; do not resize general card or other marketing section roles. Marketing H1/H2s use canonical primary app ink on neutral surfaces. Small landing section-intro/eyebrow text and the login link use canonical app accent. The Mechanics eyebrow is a scoped exception: it uses the semantic marketing-eyebrow role mapped to app accent-text for contrast on the emphasized panel. The accent CTA retains its contrast-safe on-accent ink. Body copy keeps its contextual secondary roles and help notes keep help ink.

Landing, Business pass overview and appearance editor use one semantic example-card layout. Preserve Onest, semantic colour, rounded shape, the 8px spacing rhythm and sparse elevation. The approved reference scale below is an explicit card-local exception, not a workspace-wide typography change. Sizes are rendered pixels, without uniform transform scaling; the wide tier follows the **card container** at `448px`, not the viewport.

| Card role | Narrow / wide font size | Weight | Relative line height |
| --- | --- | --- | --- |
| Business | 24 / 28px | 700 | 1.15 |
| PROMOCIÓN | 14px | 500 | 1.35 |
| Reward | 22 / 24px | 600 | 1.25 |
| Description | 16px | 400 | 1.45 |
| Progress | 26 / 28px | 700 | 1.15 |
| Today message and validity | 14px | 400 | 1.35 |
| Manual-code label | 12px | 500 | 1.35 |
| Manual-code value | 20 / 22px | 700 | 1.15 |

Order Business and brand, divider, literal **PROMOCIÓN**, Reward without an icon, optional Reward description, obtained/required points, optional **Ahora tu visita vale 2 puntos.**-style message, divider, and **Válido hasta el 30 de septiembre de 2026**-style validity. Do not show the generated “Consigue X puntos hasta Y” sentence. Keep progress prominent above validity without adding a Points heading. The landing shows mock QR and sample manual code only when the card container is at least `448px` wide; below `448px` hide both regions and their divider. Both owner previews always omit them and use the full content width. These responsive mocks are not a security boundary. Preserve the brand icon everywhere. Use two distinct fictional data sets for landing and owner, with overview/editor sharing owner data. Landing may use a café example; owner examples must remain industry-neutral rather than assuming any product category. Hide the extra-points row entirely when absent; examples do not evaluate real rules or scoring. Keep the promotion `tag` and extra-points `bolt`, and add the outline calendar beside validity; do not show a Reward `gift` icon. Decorative icons are hidden from assistive technology and centered in the associated first-line box, including wrapped text. Use subtle 1px horizontal header/footer dividers. Separate visible public validity and manual-code regions with a 1px vertical divider; hide the credential region and divider together on small cards. Owner cards have no credential divider. Do not add background circles or decorative shapes. Public QR/code remain nonoperational examples. Actual Wallet/customer state remains database-authoritative.

Use intrinsic header, middle and footer rows with matching vertical insets. Keep Business name and brand on opposite sides and copy left-aligned. Public cards cap at `576px` with a preferred `384px` minimum desktop height, not a fixed mobile aspect ratio. Use `16px` inner padding on compact cards and `24px` when at least `448px` wide. Below that width, omit the public credential regions from the rendered layout; the visible wide-card QR remains square and undistorted. Narrow text wraps and grows the card instead of clipping, shrinking type or multiplying blank rows. Owner cards use the same responsive text hierarchy without credential columns and retain their bounded rail; retain at least `16px` horizontal inset inside the overview panel on narrow screens, without changing editor spacing. Public hover/sheens remain bounded and static for touch/reduced motion. Landing benefit icons remain unchanged.

Use sentence case for headings, buttons and labels; preserve proper names, acronyms and Business-authored copy. Landing registration CTAs use the shared `16/24px`, `500` action role and existing `44px` control minimum-height token; preserve their current variants, palette and shapes without forcing new padding. Do not fade essential pending-state explanations. On initial setup, preparation cards use body scale, not tiny secondary text. Short Wallet-specific uppercase labels may remain where the Wallet presentation contract requires them.

## Public landing page

The landing page introduces the product, not the dashboard. When landing content is published before an illustrated capability is available, label that capability as future in product-facing copy; do not present the target journey as currently operational.

### Header

- FidelitoPass wordmark/logo on the left, with the page-colored shell; preserve the existing header height and composition.
- Navigation order: **Beneficios → Cómo funciona → Promociones → Tu Tarjeta → Preguntas frecuentes → Empezar**.
- No header registration button or theme toggle. The header links to page sections; the hero and final section carry the same registration action.

On small screens, keep navigation accessible without adding a competing registration CTA.

The authenticated Business header uses the same FidelitoPass wordmark size as the public landing header. At desktop widths (`900px` and above), match the public header's `91px` outer height and center the wordmark and `44px`-minimum navigation targets in the row. At narrower widths, preserve the existing two-row authenticated header and its touch targets; do not compress it to the shorter public mobile header. Preserve routes and actions.

### Hero

In descriptive landing paragraphs and FAQ answers, capitalize and bold the complete domain terms **Negocio**, **Cliente**, **Promoción**, **Tarjeta** and **Recompensa**, including their plurals. Keep the surrounding text, punctuation, paragraph size and ink unchanged. FAQ questions also capitalize these domain terms, without adding the paragraph-only bold treatment. Other headings, navigation, metadata, the animated editorial statement and illustrative card content remain outside this treatment. The violet Wallet eyebrow is an explicit exception: **Tu tarjeta para volver**. Copy remains translated plain text, with escaped output rather than executable markup.

Use the approved localized marketing copy; do not replace it with a speculative draft. The hero and final CTA both link to registration; sign-in remains subordinate. Show the illustrative pass and Promoción as conceptual product visuals, not proof of issued Wallet credentials or operational Visit validation. The mock QR is not independently decoded or functional. Do not present conceptual visuals as usable credentials.

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

Describe a persistent **Tarjeta para Google Wallet**, not a new pass per Promoción. Show the Wallet information hierarchy without implying an illustrative preview is an issued credential.

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

The desktop header offers **Resumen**, **Tu Tarjeta**, **Invitar clientes**, a global **Registrar visita** action and account access; account menu retains Business, Profile, Security and logout destinations. Mobile rearranges visible navigation without shrinking operational text or hiding the action without an accessible alternative. Keep exactly one active link with `aria-current="page"`; appearance editing, Promotion editing/review/detail keep the pass navigation item selected. Anchor the active lavender line to the intrinsic icon + label group with `0.25rem` gap and `0.125rem` thickness, not to the bottom of the whole header link; preserve the link rectangle and independent keyboard focus. If the icon hides on mobile, the line tracks the label width. Saving/cancelling appearance or Promotion returns to the pass page, not Resumen; dirty editor navigation requests confirmation.

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

### Calendar-date presentation

Use one shared regional numeric presentation for calendar dates in Summary, the pass page, Promotion draft preview, publication review, saved detail and history. Derive the order, separators and padding from the active Laravel locale selected through `APP_LOCALE`; do not maintain fixed date patterns by language or let browser preferences override it. Centralize persisted date labels in one server-side formatter that accepts an interpreted date, uses the active Laravel locale and preserves the supplied calendar day and timezone. Do not override those labels in JavaScript. Translation fallback does not change the active presentation locale. Promotion date and time pickers use the same application locale. Use Flatpickr with selection-only main fields and a calendar icon for dates. Preserve a saved draft date in the visible field even when it is earlier than the current minimum; displaying that value does not permit selecting blocked days or bypass server validation. A shared initializer derives the visible date from the application locale through Intl at initialization and after selection; it does not maintain language-specific patterns. Time uses the locale's default hour cycle and minute precision; retain the picker's native hour/minute bounds and blur correction rather than a second input validator. The live Promotion form summary only mirrors already-visible field values on input or selection; it does not reformat dates or request server updates. Fictional pass-example validity labels are a scoped exception: use Spanish day and month names with an explicit year, not operational date ranges.

Keep date-input values and server payloads in `YYYY-MM-DD` form, and time payloads in `HH:mm` form. Display localization does not change these wire formats. Published calendar dates come from the frozen Promotion timezone, with the exclusive end converted to its inclusive local end date on the server. Display formatting never changes the date, timezone, validation or domain clock. Keep readable server-rendered date text and semantic date values available before JavaScript runs. Relative security timestamps and timezone names retain their separate presentation.

## Dashboard

**Resumen** is a read-only status view, not an editor or setup wizard. Its preparation cards are informational articles, not disabled clickable controls or checkboxes; the only setup link, **Ir a tu tarjeta**, uses the primary button role and goes to the pass workspace for pass appearance and Promoción preparation. Never remember a setup origin to redirect there after saving. Distinguish incomplete Promoción preparation, scheduled, active, ended and cancelled Promociones; a scheduled Promoción completes preparation but cannot accept visits yet. Show truthful waiting states rather than fabricated activity.

### Top section

Keep one predictable content order in every Summary state: header and pass link, primary Promotion card, four equal-weight metric cards, then the points-context and upcoming-Promotion cards. These cards remain present when context or activity is empty. Select the currently active published Promotion, even when it began before today. If none is active, select the earliest future scheduled publication by start instant, then identity. If neither exists, show an explicit placeholder that states there is no active or scheduled Promotion. Its helper describes the Reward, target and validity shown in this card, not activity metrics. Drafts remain on the pass page; ended and cancelled publications appear only in Summary history, not in either Summary Promotion card. Show actual Reward terms and target **per pass**, using frozen published dates and timezone. Never invent a Promotion, activity or global-progress total to fill a card.

When preparation needs attention, show both preparation cards after the stable content, including any completed step. Appearance remains pending until saved. First-Promotion creation is complete when a draft or any scheduled, active, ended or cancelled publication exists; preserve the separate historical-publication fact. A draft still completes the first-Promotion preparation step without becoming Summary primary context. If its preparation card is visible because appearance is missing, describe the saved draft without claiming publication. Publication history prevents a later draft from reopening first-time onboarding. Hide the preparation section when appearance and draft/publication preparation are complete. Show an amber **Preparación pendiente** badge beside the header's existing **Ir a tu tarjeta** access only while the section is needed; keep this group centered and wrapping, without adding another action.

The primary card always retains its **Promoción** section heading, using the same global section role as the points and upcoming cards. Its Reward name uses the subordinate card-heading role; an empty-state message uses body scale with medium weight, not a competing heading. The upcoming card always retains its **Próxima promoción** heading. Select the earliest future scheduled publication after the primary Promotion, excluding its identity. With an active primary, this is the first future publication; with a scheduled primary, it is the second. If no primary exists, no upcoming Promotion exists. Whenever no upcoming publication exists, show the same **No hay una próxima promoción programada.** informational helper inside the card. The points card uses only the selected published primary Promotion's actual rules. When no Promotion exists, explain that this card will show point rules and extra-point configuration, not visitor accumulation, instead of inventing terms.

The next-scheduled Promotion panel uses a section heading for **Próxima promoción**, matching the points-context panel, with its status badge adjacent to the title. Its Reward name uses a subordinate card heading. Promotion cards reuse the publication detail labels **Meta** with a trophy icon and **Vigencia** with a calendar icon. The target remains per pass. Show the Reward description when present, using the shared support-text role. Use the same heading/badge, Reward title/description, facts and detail-action composition in both populated cards. Center the primary card’s gift hero icon and the upcoming card’s calendar hero icon against the Reward title and description only, with a `4px` text gap; metadata remains below that group. Keep both hero icons at the same size. Align the detail action with the text column on desktop and retain a responsive full-width action within that column on mobile. The active primary card uses the emphasis surface and contextual outlined secondary action. Scheduled primary and upcoming cards use the neutral surface and ordinary gray secondary action. Arrange facts horizontally when space permits and stack them on mobile. Do not repeat the fixed **Visita habitual · 1 punto** on Promotion cards; keep it in the separate points-context panel when actual Promotion terms exist. Keep configurable **Puntos extra** in the dedicated primary points-context card, using its full available width. The upcoming card omits extra-point lists, configuration counts and no-extra-points helpers; **Ver detalle** reveals its full frozen configuration. Reuse publication review labels, the sparkles icon and weekday/time rows with violet multiplier badges. The extra-point label and configuration count use the shared detail-row layout and typography: support-scale medium secondary label and body-scale semibold primary value; neither is a section heading. Center the icon, label and value within the desktop row. On mobile, keep the icon centered beside the label, with the value below the label. Rule cards use equal responsive tracks and row heights; an odd last card never expands across the whole desktop row. A single mobile column may use full width. When no rules exist, show **Sin puntos extra** as an informational helper. Do not show timezone labels in Summary panels; keep frozen-timezone date conversion unchanged.

Use the shared informational note role, support text, help ink and decorative information icon for scope explanations, empty context, waiting guidance and preparation help. Place each message inside its corresponding card or section. Reward descriptions and metric labels remain ordinary text, not informational alerts.

Center every Summary badge vertically with its associated title or content, including preparation cards and wrapped rule rows. Summary Promotion phase badges reuse pass-page and detail-modal Flux variants and labels: active green and scheduled blue. Draft badges remain on the pass page; terminal badges appear in Summary history. Preparation steps use native green **Completado** or neutral **Pendiente** variants. The completion count is neutral for `0 de 2` and amber for partial `1 de 2` progress; never mark an unsaved appearance complete. Neutral badges reuse canonical native Flux background and ink with shared support typography, matching the shared ended-state badge without a parallel palette.

Both populated Summary Promotion cards and every history row offer **Ver detalle**. Reuse the owned published-detail modal and saved terms from the pass page. Summary detail is read-only: it has no cancellation action and closes with **Volver al resumen**. Placeholder cards offer no detail action. Native dismissal restores focus to the visible originating button, or the primary Promotion heading when that button is no longer visible.

### Counters

Use four Promotion-scoped counters for the authorized Business's **currently active** Promotion: distinct Customer passes with at least one accepted Visit (**Tarjetas con actividad**, not issued passes or people), distinct Customer passes with at least two accepted Visits (**Tarjetas que regresaron**), created Reward entitlements including redeemed ones, and definitively redeemed entitlements. Count each returning pass once, including legitimate same-day repeat Visits. Count accepted Visits, not awarded points or visits from other Promotions. Explain the threshold with **Tarjetas con dos o más visitas en esta promoción.** Use an accent outline `arrow-path-rounded-square` icon for this counter. Confirmed Visits and currently available Rewards may appear as supporting text. Keep all four cards visible. Without an active Promotion, show `—` and not-applicable guidance inside each card, never invented zeroes. A successful empty active query means known zeroes; put the no-Visit helper inside **Tarjetas con actividad**.

For a handled statistics outage, keep the cards and show `—`, with one safe generic recovery banner above the content, one native generic toast per failed read and one **Reintentar** action. Do not repeat errors inside individual cards or retain previous metrics. Retry re-resolves current ownership and context. A total context outage follows the existing exception boundary; do not fabricate a Business header or stale facts. A statistics failure alone does not block invitation or authorized operational validation. Avoid analytics, trends and cross-Promotion totals.

### Responsive summary layout

For the four equal-weight metrics use **four columns above `64rem`**, two at `64rem` down to above `36rem`, and one at `36rem` or less. Points and upcoming cards retain two columns above `48rem` and stack below that boundary, including empty states. The two preparation cards use two desktop columns and one at `48rem` or less whenever visible. Promotion facts stack on mobile. Wrap long names and terms rather than hiding numbers, status or reducing operational type. Informational cards do not gain pointer/hover affordances.

### Quick actions

- **Registrar visita** as a prominent global action, reachable on mobile. Keep complete-product menu destinations enabled during incremental implementation, even when routes or functionality are absent and links may fail. Do not disable them or add coming-soon/unavailable copy solely because implementation is pending.
- **Tu Tarjeta** for managing Promociones and appearance.
- **Invitar clientes** for the public acquisition QR.

Avoid advanced analytics, segmentation, trends, or customer lists in MVP.

## Pass and Promoción editor

Use **Tu Tarjeta** for the navigation destination and browser title. The overview heading uses **Tarjeta {business_name}**, for example **Tarjeta Jaqaku**, without the preposition **de**. Owner navigation actions use **Ir a tu tarjeta** and **Volver a tu tarjeta**. The private application and landing address the Business owner with **tu tarjeta** for shared appearance and offering; identify **la tarjeta del cliente** when an operation concerns an individual holder. Customer-directed copy belongs inside the individual card and on the public acquisition page, including its controls, errors and manual-code help. Illustrative cards in owner previews retain the same customer viewpoint as the card they represent; surrounding configuration copy addresses the owner. Customer-facing copy uses natural sentence capitalization, not Business-oriented domain capitalization; preserve proper names and deliberate visual labels such as **PROMOCIÓN**.

**Tu Tarjeta** is a vertical sequence: compact illustrative Wallet preview, independently saved pass appearance, then Promoción list. Use the shared readable responsive hierarchy and intrinsic height; do not enforce a narrow aspect ratio by shrinking text. Use the shared intrinsic header and validity/footer zones with matching `8px` vertical insets and a content-sized middle row. Allow the card to grow naturally for wrapped names or content instead of clipping. Bound the preview rail to `320px`–`400px` in split layouts, with `400px` preferred. Preserve at least `192px` for adjacent content and a `24px` gap; shrink the rail only as available space requires, then give remaining width to adjacent content. In stacked layouts, center the preview and its caption together and cap the rail at `400px` so it does not stretch across a wide tablet row. Keep the split rail left-aligned and top-aligned. On mobile, use available width without forcing the `320px` minimum. Do not target a fraction of the row. The Business preview may show a clearly labeled illustrative sample Promotion, including invented terms and simulated progress such as `9 / 15 puntos`; an outer caption must identify it as a sample, not current Business or customer activity. Never represent it as live backend/Wallet state or show real customer/statistical data, private credentials, operational QR codes or issued Wallet content. Owner previews omit both the mock QR and sample manual code; only the public landing example shows them. Keep actual Wallet presentation database-authoritative. Avoid a giant phone mockup, a fixed card that leaves unused row space, or a blank full-height preview column. Appearance keeps all eight presets, the accessible native colour input and synchronized hexadecimal field on one line at desktop widths. At constrained tablet widths, use Flux’s public small preset size (32px) to keep the row together. On narrow mobile widths, allow the controls to wrap instead of adding horizontal scrolling. Preserve accessible names, selected state and the server error below the complete control group. The operating system or browser owns native-picker dismissal. Google Wallet supports background colour but does not guarantee a chosen native text colour. List drafts, scheduled and active Promociones. Keep ended and cancelled publications in Summary history only. When only historical publications exist, state that no active, scheduled or draft Promotion is available and direct the owner to Resumen for previous publications; do not present first-creation guidance. Only draft terms are editable; cancellation is a separate action.

Saving appearance updates the shared background used by already issued Google Wallet cards, not their Promotion content or credentials. Show success only after confirmed provider and local persistence; Google controls when devices display it. If saving cannot be confirmed, keep the chosen draft and show safe inline operation feedback beside the save controls plus one generic attention toast. Do not redirect or claim success. Before any pass has been issued, appearance saving stays local.

Open a dedicated full-page Promotion editor within the pass workspace, with **Información general** and optional **Puntos extra** sections. Use native date controls and accessible Flux Free/basic fields; do not use a modal, tabs, or Pro-only date picker for Promotion editing. Appearance colour selection uses the native browser/OS colour control rather than an in-app modal.

Keep publication review within the pass workspace as a compact native confirmation modal over the mounted editor. Closing it preserves editor values and scroll position. Bound its content to the viewport, scroll only the summary body, and keep confirmation actions visible without scrolling the page. Compose its header from a 36px outline rocket in a 64px accent-tinted circle, an accent eyebrow, a clear heading and a concise subtitle. Group the Reward title/description and three fact rows—target, inclusive local validity, and extra-point count—in one neutral bordered summary card with fixed-width desktop labels and restrained dividers. Use the same body-scale semibold value typography for target, dates and extra-point count in review and published detail. Center each fact icon and label with its main value row. Keep the validity helper below that row so it does not shift the date alignment. On mobile, place each icon beside its label and the value below the label. Keep the extra-point count aligned with its label in a 44px disclosure row; expanded cards remain below the row. Do not show a green readiness badge: the review confirms owner-entered terms, not publication availability. Use an accent callout for the whole-Promotion immutability warning and a secondary edit action beside the primary confirmation.

Keep configured timezone out of routine review and show a warning only when it changes. Keep extra-point count visible in a native disclosure that starts collapsed, with compact cards when expanded. Do not repeat the scheduled start date outside the validity range; do not imply immediate activation for a future Promotion. Keep the review keyboard-accessible and validate focus/dismissal behavior in the browser. Do not add a separate review route or a second confirmation modal.

When publication fails, close the confirmation modal and stay in the editor with its submitted values, page position and focus preserved. Show specific localized domain feedback for expected rejections, field feedback for invalid submitted data, and a safe generic message for unexpected failures. A timezone change requires reopening the review and confirming again. Only successful publication redirects to the pass page.

### Published Promotion detail and cancellation

Open **Ver detalle** from active and scheduled entries in a separate native modal over the pass page; Summary cards and history use the same saved-detail presentation in their own read-only modal. Reuse the publication-summary presentation for the saved Reward, target, original inclusive local dates and all extra-point rules. Derive the displayed dates from the Promotion's frozen timezone, not the current Business timezone. Do not expose editable published terms or reuse unsaved editor values.

Use a 36px outline document icon in a 64px accent-tinted circle. Keep the detail badge consistent with the corresponding list entry. Pass-page status badges use muted native green for active, blue for scheduled, red for cancelled and neutral zinc for ended, with explicit status text. This scoped status mapping does not recolor other application surfaces.

Only active or scheduled detail opened from the pass page offers **Cancelar promoción**. Its initial action uses the primary accent treatment and reveals an inline danger warning in the same modal. The final **Sí, cancelar promoción** action uses danger styling. Keep **Volver a tu tarjeta** as the single secondary action; do not open another confirmation modal. Closing or dismissing before final confirmation does not cancel anything. Stale, repeated or ended requests show safe localized feedback without changing the original terms or first cancellation instant.

Bound the modal to the viewport. Scroll only its body and keep its footer actions reachable at narrow widths without page-level horizontal overflow. Support Escape and native close controls. Restore focus to the visible originating detail action; if cancellation removes or hides that action, focus the Promotions heading. Summary detail restores its visible originating button or the primary Promotion heading if the origin is no longer visible.

### Promotion history

Place ended and cancelled Promotions in a visible bottom Summary section after any preparation cards. Do not use a disclosure or duplicate history on the pass page. Keep the section present when empty, explaining that ended and cancelled Promotions will appear here. Show three rows per page with native pagination independent of other page selectors. Order history by the original start instant descending, then stable Promotion ID descending; cancellation time does not determine row order. Historical detail remains read-only and returns with **Volver al resumen**.

Use compact divided rows without an outer card or individual row cards. Each row shows only the Reward title, status, original date range and **Ver detalle**. Do not repeat the description or point target; those remain in detail. Use `14/20px` support typography for the title, date and action, and the native small status badge size.

Keep **Ver detalle** a semantic button with accent-text ink, intrinsic width, a pointer cursor and a minimum 44px touch target. Use underline and a visible keyboard-focus outline for interaction feedback. Its background stays transparent at rest, hover, focus and active states; do not add a ghost-button hover fill.

### Información general

Show local start/end dates, positive target points, Reward title and optional description, and the current Business IANA timezone as read-only context, not an independent selector. Publication review confirms the timezone; if it changed since review, refresh and ask for confirmation again. Published terms, including scheduled ones, are immutable.

### Puntos extra

State the fixed regular Visit value: 1 point. Keep an inline Add form stable while entries are added: weekday, whole day or start/end hours, and multiplier x2, x3 or x5. Below it show each added entry as a row with weekday, whole-day or hours, multiplier and **Quitar**. Multiple disjoint half-open `[start, end)` windows may touch endpoints but not overlap; whole-day and timed entries cannot coexist on one weekday. Split overnight intervals across days. Never stack rules or offer expressions/a generic builder. No rules are inherited from previous Promociones.

### Shared actions and preview

Use **Cancelar**, **Guardar borrador**, **Publicar** when applicable. Preserve unsaved values between sections and review, show errors within the relevant section, warn about incomplete unadded entries and confirm navigation away from a dirty editor. Save/publish the complete Promotion and its extra-points rules atomically; appearance saves separately. Server validation is authoritative. Show a deterministic compact preview with numeric points, regular or applicable extra-points Visit value and no dynamic stamp circles; it is not a native-device colour or issuance guarantee.

## Invitation and operational availability

After the first **saved** appearance, **Ver QR para invitar** is available from the pass page, with or without an active Promotion; merely previewing a default colour does not qualify. The permanent public join page shows Business identity and a labelled fictional pass example, independent of persisted Promotion state. It does not require a new QR for each Promotion. A QR-generation error is distinct from invitation eligibility.

For an implemented validation feature, enable **Registrar visita** only when appearance is saved, the current Promotion is active, and server-authorized validation is operational. When a genuine runtime prerequisite prevents use, keep its disabled reason legible and its header access visible. Known zero Visits and failed statistics queries never disable an otherwise authorized operation. Unknown operational phase/authorization must not be inferred from an old badge; server rechecks on confirmation. No local mock capability flag proves production readiness.

## Acquisition QR page

Business view:

- Business identity.
- Permanent public QR with adequate print contrast and quiet zone; avoid promising an unverified physical size.
- QR-generation failures remain recoverable without changing the permanent public identity.
- Short copy explaining what customers do.
- Download/print action.
- In the same QR card, show the exact public QR URL as a labelled read-only field with a copy action after appearance is saved and QR generation succeeds.

Customer join view:

- Do not show a FidelitoPass logo, wordmark, branding header or home link on Join. Keep global favicons and other pages unchanged.
- Center the download Heroicon above the actual Business name alone and **¡Tu tarjeta de puntos te está esperando!**. Do not hardcode the example Business.
- Keep customers focused on their mobile inside the Business. Do not show a fictional card, mock QR/code, sample Promotion, example caption or phone frame.
- Do not advertise current or future offers, query Promotions or evaluate extra-points rules. No customer account-creation form.

Use the existing Invitation instruction-card hierarchy: numbered circles, decorative icons beside the step headings, body-scale descriptions and shared spacing and typography. Both instruction panels use the existing violet emphasis container, priority foreground and priority border roles to attract attention to the instructions. Keep the numbered markers distinct with the accent/on-accent roles. Do not add global styles, a new palette, gradients or glows. Use outline Heroicons except for the genuine Google Wallet product icon in the initial save step and ready Google step. Serve a transparent-background derivative locally, preserving the colored mark, geometry and dimensions; its source is the [official Google Play listing](https://play.google.com/store/apps/details?id=com.google.android.apps.walletnfcrel&hl=es). Do not alter the official save button.

Show one three-step instruction group at a time, not a wizard. Initially show **¿Cómo funciona tu tarjeta?**: **Obtén tu tarjeta** / **Toca el botón de abajo para comenzar.**; **Guárdala en Google Wallet** / **Sigue los pasos de Google para tenerla siempre a mano.**; **Suma puntos y gana recompensas** / **Muéstrala en el mostrador cada vez que nos visites.** The explicit action is **Obtener mi tarjeta**. Opening the page never creates a pass. Preserve this exact wording and punctuation.

Only after valid creation, replace the initial presentation with a green check and the state heading **¡Tu tarjeta de {business_name} está lista!**, followed by **¡Ya casi terminamos! Guárdala para tenerla siempre contigo.** Both state icons use the same 64px wrapper, 36px glyph, alignment and 16px header gap, so the change feels like an icon swap rather than a layout jump. This confirms creation, not Google saving. Start the new state at the viewport top and focus this heading once; Tab continues to the native Google link. Do not scroll toward the instructions or add smooth animation, repeated scrolling or an extra status banner. Google may open its app or the web and owns final saving.

Unsaved appearance remains publicly accessible. Show **Este negocio todavía no ha preparado su tarjeta. Consulta al negocio para saber cuándo estará disponible.** and omit the issuance action. Recheck saved appearance on POST so a reset after page load produces the same message without issuing a pass. Without JavaScript, retain the initial instructions and explain that JavaScript is required; keep issuance disabled.

While issuance is pending, show only a spinner in place of the button text. Preserve its accessible label, busy state and duplicate-submission guard. Do not add loading paragraphs or loading toasts. A failure uses a generic toast and a different persistent, actionable Flux error. Permit another request only after a confirmed retry-safe failure. An uncertain outcome explains the duplicate risk and directs the customer to the Business. Hidden error wrappers occupy no layout space.

Keep the ready order strict: state heading → official Google Wallet save link → real manual-code card → Google instructions. Use the unchanged official Spanish Latin America Add to Google Wallet asset and existing-Object native link first. Preserve its colors, shape, proportions, at least 48px height and 8px clear space, keyboard access, translated new-tab notice and native new-tab behavior. Do not restyle it as a purple button or add a loading spinner or disabled transition. Keep this exact note near the action: **Disfruta de tu tarjeta desde la web o en la aplicación de Google Wallet para Android. Recuerda usar la misma cuenta de Google con la que la guardaste.** The separate manual-code card follows with **Código manual de tu tarjeta**, its real five digits at 48px or larger, and **¿No puedes escanear tu tarjeta? Muestra este código en el negocio. ¡Recuerda guardarlo antes de salir de esta página!** Preserve leading zeros; never render the reference's example code.

The public acquisition page uses `24px` horizontal main padding, including mobile, to keep all content clear of the viewport edges. Retain `32px` vertical padding (`48px` from `sm`); do not increase card padding to create this page-level margin. Keep `24px` between the initial instruction panel and **Obtener mi tarjeta**, and between the ready heading and the Google save button, including its `8px` clear-space padding. Keep related content groups `16px` apart and informational icon/text spacing at `8px`; do not enlarge button-internal padding or restyle the official Google asset.

Use the same section-heading role for **Código manual de tu tarjeta** and **¡Solo faltan 3 pasos!**; the actual code remains the dominant 48px value. Match card padding at `16px` (`24px` from `sm`) and keep `16px` between content groups. The Wallet platform/account note reuses `app-note-with-icon`, support text and help ink, with the shared decorative information icon and `8px` icon/text gap. The manual-code usage/reminder paragraph remains ordinary support text, not an informational alert; do not add an information icon to it or to step descriptions. Preserve the exact customer copy.

Last, show **¡Solo faltan 3 pasos!**: **Toca el botón de Google** / **Google te ayudará a guardar tu tarjeta.**; **Inicia sesión si te lo pide** / **Usa tu cuenta de Google para continuar.**; **Confirma y ¡listo!** / **Tu tarjeta te estará esperando en Google Wallet cuando la necesites.** Preserve this exact wording and punctuation. No no-app reassurance/install boxes or extra app-download button.

Success uses only a short creation toast, not a large success banner or a claim that the customer saved the pass. Success and failure toasts retain the default bottom-end position; other public pages retain their grouped defaults. Keep private handoff data in page memory only and clear it on disposal. Never show issued credentials before valid creation. This layout does not change native Wallet states, provider objects or scoring.

## Registrar visita dialog

When implemented and operational, the global action opens one identification → confirmation → result dialog for fast counter use. Pending implementation alone does not disable its header entry.

### Required order

1. Dialog title: **Registrar visita**.
2. Manual-code entry and **Buscar tarjeta**.
3. Secondary **Activar cámara** action, with no camera region or media request before activation.
4. Customer-pass identification, explicit confirmation and result card in the same dialog.

Manual entry:

```text
Código de la Tarjeta
[ Ej: 583 ] [ Buscar tarjeta ]
```

Use equal-width input and search-button columns with equal control heights on desktop. Stack both at full width on mobile and center the label. Reuse the current Business-form treatment and shared help-with-icon style. The placeholder is a fixed illustrative three-digit example, not field data. Use the help **Ingresa el código de 5 dígitos que aparece en la Tarjeta del Cliente.** Preserve leading zeros in the text input and submitted credential. Keep enough width for supported historical codes; do not force a numeric keyboard or truncate them to the current issuance length.

Manual and camera identification are exclusive modes. **Activar cámara** hides the complete manual form and its large identification heading, then reveals capture only on explicit request. While capture is selected, visually hide the large dialog title without removing the native dialog's accessible name or close controls. Keep the stage indicator and avoid unnecessary desktop scrolling without reducing the approved preview width or minimum height. Restore the headings when capture closes. **Desactivar cámara** immediately releases owned capture and invalidates late permission results, then restores an empty manual form with its prior error cleared. Apply the device-entry focus policy below. Stopping decoding to identify a scanned token keeps manual entry hidden while that camera lookup is pending. A failed camera lookup closes capture and restores usable manual entry without a server reset. When manual editing is available, changing its value invalidates obsolete identification reads. During an active application request, disable editing instead of accepting a conflicting intent.

Scanning and manual identification are read-only and lead to the same confirmation. Stop capture before pass identification. Once identified, hide camera and manual controls together; display only the relevant confirmation, then only the result after a confirmed commit. **Volver a leer** restores clean manual-first identification without restarting the camera. Release camera and listeners on close/navigation. Fill the available preview width with an undistorted, centered cover crop. Keep a minimum frame height of 192px on small screens and resize. Decode only the visible crop, recalculated from the current video and preview dimensions; a QR outside that area must not trigger identification. Reserve the same frame during startup with an outline camera icon and **Activando cámara…**. Keep the video mounted and reveal it without a layout jump; do not force screen orientation or change capture resolution. Short viewports, zoom and long errors can require scrolling within the existing modal body; deactivation and footer controls must remain reachable.

### Camera error

Camera-origin failures close the camera region and release its owned capture. This includes capability, permission, device, reader and scanned-token lookup failures, including connection and prerequisite-read failures. Show exactly one existing translated native Flux error below **Activar cámara**, with safe feedback and a practical next step; do not expose native error messages. Focus that recovery control and keep empty manual entry immediately usable. Do not reset server state just to close failed capture or duplicate the detail beside the manual input. A new manual attempt uses ordinary field feedback and focus; a new camera activation clears the previous camera feedback. Preserve confirmation, registration, redemption and receipt feedback in their own stages. Use one camera control: **Activar cámara** before capture is selected, then only **Desactivar cámara**. During permission or reader initialization, its own label is replaced by the native spinner; do not add another loading button. The same control must remain clickable to cancel pending permission despite spinner pointer locking. Disable it only for actual conflicting action work, not merely because camera startup is pending.

### Customer-pass result

Show only operationally relevant data in the focused stage: current Promoción, current pass progress, applicable Visit point value or Reward deadline, stage result and one primary action. Failures keep the identification, confirmation or positive-result context where the user acted. Show the clear, nontechnical detail persistently in a native Flux error below the relevant input or beside the action when there is no field. A concise generic Flux danger toast draws attention to that detail; it does not repeat it. A missing required manual code receives a field-specific instruction without skipping the shared attempt guard. Unavailable optional receipt details retain the positive Visit result and use an attention toast that also preserves that fact. Do not replace context with a technical error or pending-status page or add a public status-check button. On initial pointer opening, automatically focus the input only for a fine primary pointer with hover capability. Coarse/touch and unknown capabilities use the non-editable **Cerrar** control as the native dialog focus fallback, avoiding automatic keyboard opening. Keyboard activation may focus the input regardless of pointer capability. Do not infer device type only from viewport width or user-agent brands. At confirmation, focus the relevant direct primary action. For keyboard interaction, Result first focuses **Volver a leer**, and activating that action returns focus to the manual input. If initial preparation is unavailable, focus **Cerrar**. Noninteractive headings do not receive programmatic focus. A failed manual lookup returns focus to its input; a failed camera lookup targets its camera recovery control. Ancillary receipt completion must not steal focus again when the step has not changed. Keep contextual focus after pointer activation, but suppress pointer-origin indicators only within this dialog, including on the manual input. Keyboard interaction immediately restores visible focus. Do not focus disabled controls. On close restore focus to the trigger and preserve the originating page's unsaved inputs.

Each new visible failure receives one inline error and one generic attention toast. Hidden placeholders, ordinary rerenders and obsolete or cancelled work do not announce failures. Before a scanned token starts identification, check the browser's connection hint. When it reports offline, show a minimal connection error and the generic toast without contacting the server, including for an unresolved prior receipt. Do not queue telemetry or retry automatically. An online hint does not prove server reachability; a current transport failure still needs safe feedback.

Use the same Reward-first card for confirmation and result facts. Keep its gift icon, title and full optional description together, followed by compact two-column numeric point and progress facts. Do not show a progress bar in this dialog. Confirmation uses the existing soft-emphasis violet surface; confirmed success uses the existing success surface. Only content and semantic surface change, not the structural hierarchy. Missing optional receipt details retain the known award without fabricated progress or Reward details. Point gains use the shared metric role; labels and complementary descriptions use secondary support text. Progress uses the authoritative pass snapshot before confirmation and the original receipt after commit. Retain actual numeric progress and award values, including an overshoot. Do not invent progress when optional receipt details are unavailable. Reward headings use the same larger generic gift icon in both steps, never a product-specific coffee icon. Center it vertically against the combined title and optional description, not just the title. Preserve the actual authorized Promotion description; omit absent descriptions rather than inventing them. Available-Reward copy may be warm and concise without implying another Visit. Offer redemption only for the authoritatively available original entitlement; an already-redeemed Reward has no mutation action. A success-colored check marks confirmed mutation success only. Use **Puntos sumados** and **Progreso actualizado** for every result containing a Visit, including a combined redemption. Redemption alone does not invent a Visit award. Use **Visita registrada**, **Recompensa desbloqueada** or **Recompensa canjeada** as the final title according to established server facts, without an unlock badge or pulse. Missing optional details never infer an unlock or fabricate progress. Keep status, primary point metric and secondary Reward detail visually distinct without changing the shared type scale or modal shell.

Each operational step has the same icon-heading composition, using the Promotion Details outline-icon size, emphasis circle and existing ink roles. Step 2 uses **Tarjeta identificada** with a violet check: identification is not a committed mutation. If the preview predicts completion, use **Recompensa al confirmar** instead. The preview is not authority; the mutation rechecks current terms, points and time. Only confirmed Visit or redemption success uses success coloring. Keep the identification heading associated with its manual-code input. Below the dialog title, show an informative, noninteractive 1–2–3 stage indicator instead of introductory help. The current step uses accent fill, bold ink-on-accent number and an accent-bold label. Completed steps stay unfilled and outlined, with matching accent marker and label, a checkmark and accessible completion text. Future steps retain their secondary treatment; identify the current step semantically. Use a wider, viewport-bounded dialog with natural height; do not shrink text or truncate descriptions to force content to fit.

Keep the native top X and ghost **Cerrar** at the left of every footer, including identification and result. Business actions stay right, with the outlined secondary immediately before the primary. Only the right action group wraps on narrow screens; do not shrink or cut copy. Place **Volver a leer** above stage content, below the steps and outside the footer only in confirmation and result. Use a native local-reset button styled as the existing violet arrow return link. Result has only footer **Cerrar**, with no mutation CTA. Start another operation through **Volver a leer**, preserving unresolved original-operation reconciliation.

Do not expose customer identity because none exists.

### Primary action mapping

| State | Primary action |
|---|---|
| Active and can progress below the target | **Confirmar visita** |
| Preview predicts completion | Secondary **Solo registrar**, primary **Registrar y canjear** |
| Reward available | **Canjear recompensa** |
| Promoción ended/cancelled | none |
| Waiting for Promoción | none |

Selecting the explicit operation submits it directly, without another confirmation step. Revalidate ownership, eligibility, published terms and database time on the server with an idempotency key; show resulting progress after commit. Provider sync failures must not invite a duplicate Visit or redemption. Show immediate scoped loading feedback within the current context. Every modal button with real asynchronous work replaces its visual text with the native centered spinner, preserving geometry and accessible name; unrelated actions do not spin. Never dismiss this dialog on backdrop clicks. While an application request is active, disable every modal control, including footer actions, inputs and both native X and footer Cerrar, and prevent Escape dismissal. Idle Escape, X and Cerrar remain available. Do not clear the selection or cancel its request merely because a prevented cancel event fired; close cleanup belongs to actual dismissal or root disposal. During native permission startup, disable all competing controls and dismissal while keeping that same camera **Desactivar cámara** control cancellable. Do not disable its enclosing fieldset merely because startup is pending. Restore contextual action focus only after controls re-enable; initial acceptance/reopen focus must not turn later ancillary receipt completion into another focus change. Immediate native dismissal has no artificial delay or fabricated loading state. Every reopening starts at clean identification: empty credential, no prior field error, confirmation or result. While preparing that initial view, hide stale stage content and show native progress. If preparation fails, keep old content hidden and show a clear error with its generic attention toast. Keep footer Cerrar usable; closing and reopening retries preparation. Do not add a preparation retry button or redisplay previous success. This preparation gate must not hide the active camera deactivation control or its spinner during an ordinary reset. Current status requires reading the card again. If a registration response is uncertain, retain its exact identity only in private page memory. A new lookup first reconciles that original Visit read-only without presenting its old result, then reads the current card only when the previous operation is resolved. A missing or failed receipt does not permit another registration. Within an open uncertain confirmation, retrying the primary action still reconciles that same Visit read-only, never submits a new Visit. A missing receipt does not prove rollback or allow a new registration. Keep returning to a new identification unavailable until the outcome is resolved. Once acceptance is known, unavailable optional details keep the positive result and produce a concise toast; closing clears that result presentation; reopening never refreshes or redisplays it automatically. Do not poll or expose a separate recovery workflow.

## Reward redemption

Redemption is final and available once per Customer pass and Promotion. It does not reset progress or permit another earning cycle.

Keep the originating confirmation visible while its selected command runs. **Canjear recompensa** or **Registrar y canjear** submits that explicit operation directly, without an extra warning, arming request, Cancel step, modal or radio choice. Identification and an ordinary Visit never redeem automatically. The combined operation accepts the Visit, unlocks and redeems atomically or commits none of those new facts, with one Wallet update against the final tentative state. Do not chain two transaction owners.

Before redemption show:

- Reward title.
- Promoción.
- Validity deadline.
- The explicit operation command.

Primary CTA:

> Canjear recompensa

After completion:

> Recompensa canjeada

All modal copy addresses the Business attendant in neutral professional Spanish. **Canjear** names the system-recorded fact; **entregar** may instruct the attendant but never asserts independently verified physical delivery. Do not use optimistic UI for redemption. Only established local postconditions produce **Recompensa canjeada**; redemption alone shows no point gain. A combined operation retains its actual Visit points and progress. Failures retain their originating context with one inline error and one generic attention toast. Keep known committed redemption positive if optional details fail.

An uncertain response retains the original private entitlement, Promotion and credential only in page memory. Reconcile that original entitlement read-only; never retry the provider mutation automatically or select a replacement Promotion. A missing receipt does not prove rollback or permit a new mutation. Fresh reopening clears old result presentation and reconciles unresolved identity before a new lookup. Offline attempts send no requests or telemetry and show one connection error with one generic toast.

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

All native application modals dim the mounted page with one black 55% native backdrop. Keep modal panels, content and native opening/dismissal transitions unchanged; do not add a second overlay or blur.

All native application modals share thin accent scrollbars on transparent tracks, including account modals. For fine pointers with hover support, reveal the scrollbar on hover, keyboard focus or focus within the scroll surface. For touch and coarse pointers, keep it available without hover. Respect reduced motion. Apply this appearance automatically without changing each modal's viewport bounds, scroll container, footer layout or native controls. Reserve stable scrollbar space only where the existing scroll-body layout needs it, not on every descendant. Keep an 8px inline-end content gap before the scrollbar in publication review and published-detail bodies, without adding horizontal overflow or padding to nested fields and cards.

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
