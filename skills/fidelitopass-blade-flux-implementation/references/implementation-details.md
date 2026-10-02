# Blade, Flux and Tailwind implementation

Apply [shared code quality](../../shared/code-quality.md) before these presentation-specific conventions. Cooperate with the [PHP owner](../../fidelitopass-laravel-livewire-implementation/SKILL.md) for mixed Livewire components; do not move server rules into markup or local client state. Load the [JavaScript skill](../../fidelitopass-javascript-alpine-implementation/SKILL.md) when client behavior is affected; keep its algorithms and lifecycle implementation outside this reference.

## Composition and framework gates

- Before extracting a project-owned wrapper, define required props/defaults, supported slots, the actual rendered control and the destination of each forwarded attribute.
- Forward bindings, names and accessibility attributes to their intended control, not merely the outer wrapper. Preserve native/Flux behavior and attribute-bag merge precedence; verify the rendered contract rather than only the wrapper markup.
- Evaluate an appropriate installed Flux UI Free component first. Use `composer.lock` for the resolved version, then inspect installed source/configuration for the actual version and API behavior; the lockfile alone does not prove the runtime. Check supported props, attributes, events, rendered semantics and defaults with targeted framework guidance or installed source. Use it only when it preserves semantic HTML, accessibility, keyboard access and progressive/no-JavaScript behavior. Do not guess component or icon names, assume Pro availability or treat size variants as universal values.
- Keep native landmarks, headings, links, images, wrappers and disclosure elements when they are the correct semantic choice. Flux-first is not Flux-every-tag. Explain a non-obvious fallback briefly; do not add wrappers or convert native elements merely to increase Flux usage.
- Use supported native component composition and public theme tokens first. Use scoped Tailwind utilities for layout and spacing before custom CSS. Preserve computed typography, focus, ink and geometry. Add the smallest scoped CSS exception only for a demonstrated gap; use existing shared styling for a genuinely shared role. Do not override private Flux internals globally, edit vendor or replace native dropdown/menu behavior with a custom imitation.
- Prefer Livewire interactions for server-driven state. Use Alpine for small local transient interactions only, before bespoke JavaScript. Do not duplicate authoritative Promotion/Reward state or consequential operations in Alpine. Keep validation, authorization and mutation authoritative on the server; client hints and disabled controls are not security boundaries.
- Preserve stable starter component formats and layouts. Do not blanket-convert SFCs or restructure screens for consistency. Use the existing Flux/Tailwind/Vite asset pipeline; colocate required page-local scripts through native facilities for the actual component format: Livewire 4 class-based views use `@script`, while single/multi-file components use their documented script structure. Do not introduce a standalone bundle, global inline behavior, another Alpine runtime, duplicate theme/build infrastructure or a SPA framework.
- Initialize the approved dark appearance through the shared head before Flux loads, reusing the shared theme tokens rather than adding a second theme system. The UI owner below retains palette, font and no-light-toggle outcomes.
- Use `wire:navigate` conservatively. Where shared navigation persistence is necessary, keep it outside Livewire components and preserve dynamic active-link state. Leave custom JavaScript initialization/disposal details to the client implementation owner; preserve existing lifecycle ownership when changing markup.

### Component selection gates

Use this mapping as a starting point, not an API guarantee. Check installed Free availability and the applicable UI outcome
before choosing a component; retain correct native semantics when no suitable component exists.

| Responsibility | Starting composition |
| --- | --- |
| Header navigation | `flux:navbar` / `flux:navbar.item` or existing starter navigation. |
| Summary facts and preparation | Native `article` / `section`; Flux heading/text where suitable. |
| Actions and status | `flux:button` with supported variant/size; `flux:badge` with visible status text/icon. |
| Field labels, entry and errors | `flux:field`, `flux:label`, `flux:input`, `flux:error`; native date/colour controls where appropriate. |
| Weekday and multiplier selection | `flux:select` or native semantic select; do not assume Pro controls. |
| Account menu | Existing starter `flux:dropdown` / `flux:menu`; retain authentication destinations. |
| Operational scanner | `flux:modal`, semantic stage content and Livewire actions; leave camera lifecycle to the JavaScript owner. |
| Invitation QR and pass preview | Project-owned semantic content; Flux text/button where useful. |

For centralized typography roles, inspect component defaults and supported native size variants first; size names and
rendered values are not universal. Use a variant only when it preserves the approved hierarchy, readability and targets.
When none fits, define the smallest shared role adjustment through existing composition; introduce a thin Blade wrapper
or centralized styling only when necessary. Keep size, line height, weight and responsive behavior together. Do not
scatter fixed values or repeated size props, override every component or resize the root to force a match.

## Attribute order and wrapping

Use this reversible project convention for affected markup, not as a claim about historical source ordering. Order present attributes by these semantic groups:

1. Component configuration and semantic identity: native or component props, `id`, `name`, `type`, `href`, `for` and equivalents.
2. Livewire bindings and actions, including identity and loading directives.
3. Alpine and other local interaction bindings.
4. Accessibility and native state: `aria-*`, roles, `disabled`, `required`, `readonly` and equivalent state attributes.
5. Data hooks used by component behavior or tests.
6. Styling last: `class`, then `style` only when justified.

Keep dependent bindings together when separating them would obscure or alter their meaning. Classify bindings by their actual responsibility, not only their prefix. Preserve attribute-bag merge precedence, duplicate-attribute behavior and expression evaluation semantics; do not mechanically reorder an operation-sensitive bag or binding. Do not alphabetize away meaningful relationships.

- Keep simple readable opening or self-closing tags on one line. Wrap selectively when length, complex expressions or several semantic groups impede scanning; do not require one attribute per line.
- Indent wrapped attributes and nested content consistently with surrounding composition. Separate semantic content/action blocks with whitespace. Keep normal markup in Blade, not PHP string builders.
- Stay compatible with configured formatters without adding dependencies. Inspect the actual configured Blade/frontend formatter and its supported options. This documentation package does not establish installed formatting dependencies; use manual conventions when no compatible formatter is available and do not claim an unrun check passed. Do not add source-text tests for attribute order, wrapping or comment placement.

## Visible text, directives and comments

- Put visible translated/interpolated body text, labels and slot content on their own indented line inside tags. Keep real attribute-only native/component labels in their supported API; do not invent child slots to satisfy formatting.
- Preserve deliberate whitespace around inline links, emphasis and punctuation. Do not blindly wrap whitespace-sensitive content or change rendered layout while improving readability.
- Use Laravel translations for professional Spanish visible copy, including placeholders, alternative text, titles, accessibility labels and illustrative data. Preserve intentional decorative `alt=""`. Keep technical IDs, fragments, data hooks and their JavaScript/test references in English.
- Use native Blade conditional/loop directives rather than string-built markup. Keep escaping appropriate to the output context; do not bypass escaping for untrusted copy. Serialize server data into client contexts with supported secure framework facilities, not concatenated JavaScript or guessed quoting.
- Use lowercase English internal semantic Blade comments only for major related content/action regions when useful. Prefer non-rendered Blade comment syntax. Do not impose fixed labels, templates or comments above every element; apply shared comment-purpose gates instead of duplicating them.

## Outcome owners and verification

Select only the applicable owner sections before the affected edit. Stop for an unreadable source or unresolved conflict; do not invent frontend policy.

- Read [palette authority](../../../docs/ui-ux-guidelines.md#approved-dark-only-palette) for semantic roles, provenance and unmigrated-surface debt; read [typography](../../../docs/ui-ux-guidelines.md#typography) and [centralized role sizing](../../../docs/ui-ux-guidelines.md#centralized-role-sizing) for font/role mapping. Do not copy their values here or treat global tokens as rollout authorization.
- Read [visual fidelity checks](../../../docs/ui-ux-guidelines.md#integration-and-visual-fidelity-check) and the relevant page/layout/menu section for approved outcomes; apply the component selection gates above for coding choices. Do not copy mockup HTML/CSS/JS, adapters, demo state or active application source as reusable templates.
- Read [form behavior](../../../docs/ui-ux-guidelines.md#form-behaviour), [loading feedback](../../../docs/ui-ux-guidelines.md#loading-and-perceived-responsiveness) and [accessibility](../../../docs/ui-ux-guidelines.md#accessibility-baseline) for validation, disabled/loading, focus, keyboard and status expectations. Preserve those outcomes through native controls and Livewire rather than redefining them here.
- For scanner markup, read [required order](../../../docs/ui-ux-guidelines.md#required-order) and [customer-pass result](../../../docs/ui-ux-guidelines.md#customer-pass-result). Preserve the same-dialog stage outcome: scanner and manual identification controls disappear together at confirmation/result. Do not redefine lookup/mutation policy, routes or camera lifecycle here.
- Select evidence through the [quality strategy](../../../docs/quality-strategy.md). Verify observable rendering and interaction at the changed boundary; inspect source readability separately. Documentation/link checks do not prove browser focus, computed colors, fonts or visual fidelity. Do not use source-format assertions as behavioral evidence.

## Readable composition review

Keep label, control, help and error together as one field; separate complete fields or independent regions with one blank line. Do not insert blanks after every opening tag or before every closing tag.

Prepare query results at the server boundary; markup does not query, call providers or lazily load relations per row. Serialize prepared values into JavaScript with the installed framework's secure facilities. Avoid phrase-fragment concatenation, context-unsafe escaping and bindings that accidentally expose private server data.

Preserve whitespace-sensitive pre/code/textarea content. Use stable per-instance IDs and stable domain keys for changing lists; do not substitute indexes or random keys.

Use the [optional examples](readability-examples.md) for calibration, and [existing integration](project-integration.md) only when changing palette, assets or landing fit. Neither fixes layout, business terms, helper count or API availability for new work.
