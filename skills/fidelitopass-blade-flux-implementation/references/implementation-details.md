# Blade, Flux and Tailwind implementation

Apply [shared code quality](../../fidelitopass-laravel-implementation/references/code-quality.md) before these presentation-specific conventions. Cooperate with the [PHP owner](../../fidelitopass-laravel-implementation/SKILL.md) for mixed Livewire components; do not move server rules into markup or local client state. Keep standalone JavaScript algorithms and lifecycle implementation outside this reference.

## Composition and framework gates

- Evaluate an appropriate installed Flux UI Free component first. Check its supported props, attributes, events, rendered semantics and defaults with targeted framework guidance or installed source. Use it only when it preserves semantic HTML, accessibility, keyboard access and progressive/no-JavaScript behavior. Do not guess component or icon names, assume Pro availability or treat size variants as universal values.
- Keep native landmarks, headings, links, images, wrappers and disclosure elements when they are the correct semantic choice. Flux-first is not Flux-every-tag. Explain a non-obvious fallback briefly; do not add wrappers or convert native elements merely to increase Flux usage.
- Use supported native component composition and public theme tokens first. Use scoped Tailwind utilities for layout and spacing before custom CSS. Preserve computed typography, focus, ink and geometry. Add the smallest scoped CSS exception only for a demonstrated gap; use existing shared styling for a genuinely shared role. Do not override private Flux internals globally, edit vendor or replace native dropdown/menu behavior with a custom imitation.
- Prefer Livewire interactions for server-driven state. Use Alpine for small local transient interactions only, before bespoke JavaScript. Do not duplicate authoritative Promotion/Reward state or consequential operations in Alpine. Keep validation, authorization and mutation authoritative on the server; client hints and disabled controls are not security boundaries.
- Preserve stable starter component formats and layouts. Do not blanket-convert SFCs or restructure screens for consistency. Use the existing Flux/Tailwind/Vite asset pipeline; colocate required page-local scripts through native component facilities such as `@script`. Do not introduce a standalone bundle, global inline behavior, another Alpine runtime, duplicate theme/build infrastructure or a SPA framework.
- Use `wire:navigate` conservatively. Where shared navigation persistence is necessary, keep it outside Livewire components and preserve dynamic active-link state. Leave custom JavaScript initialization/disposal details to the client implementation owner; preserve existing lifecycle ownership when changing markup.

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
- Stay compatible with configured formatters without adding dependencies. Do not claim an unavailable Blade formatter passed, or add source-text tests for attribute order, wrapping or comment placement.

## Visible text, directives and comments

- Put visible translated/interpolated body text, labels and slot content on their own indented line inside tags. Keep real attribute-only native/component labels in their supported API; do not invent child slots to satisfy formatting.
- Preserve deliberate whitespace around inline links, emphasis and punctuation. Do not blindly wrap whitespace-sensitive content or change rendered layout while improving readability.
- Use Laravel translations for professional Spanish visible copy, including placeholders, alternative text, titles, accessibility labels and illustrative data. Preserve intentional decorative `alt=""`. Keep technical IDs, fragments, data hooks and their JavaScript/test references in English.
- Use native Blade conditional/loop directives rather than string-built markup. Keep escaping appropriate to the output context; do not bypass escaping for untrusted copy. Serialize server data into client contexts with supported secure framework facilities, not concatenated JavaScript or guessed quoting.
- Use lowercase English internal semantic Blade comments only for major related content/action regions when useful. Prefer non-rendered Blade comment syntax. Do not impose fixed labels, templates or comments above every element; apply shared comment-purpose gates instead of duplicating them.

## Outcome owners and verification

Select only the applicable owner sections before the affected edit. Stop for an unreadable source or unresolved conflict; do not invent frontend policy.

- Read [palette authority](../../../docs/ui-ux-guidelines.md#approved-dark-only-palette) for semantic roles, provenance and unmigrated-surface debt; read [typography](../../../docs/ui-ux-guidelines.md#typography) and [centralized role sizing](../../../docs/ui-ux-guidelines.md#centralized-role-sizing) for font/role mapping. Do not copy their values here or treat global tokens as rollout authorization.
- Read [Flux implementation mapping](../../../docs/ui-ux-guidelines.md#implementation-mapping-flux-free) and [visual fidelity checks](../../../docs/ui-ux-guidelines.md#integration-and-visual-fidelity-check), plus the relevant page/layout/menu section, for approved outcomes. Do not copy mockup adapters, demo state or active application source as reusable templates.
- Read [form behavior](../../../docs/ui-ux-guidelines.md#form-behaviour), [loading feedback](../../../docs/ui-ux-guidelines.md#loading-and-perceived-responsiveness) and [accessibility](../../../docs/ui-ux-guidelines.md#accessibility-baseline) for validation, disabled/loading, focus, keyboard and status expectations. Preserve those outcomes through native controls and Livewire rather than redefining them here.
- For scanner markup, read [required order](../../../docs/ui-ux-guidelines.md#required-order) and [customer-pass result](../../../docs/ui-ux-guidelines.md#customer-pass-result). Preserve the same-dialog stage outcome: scanner and manual identification controls disappear together at confirmation/result. Do not redefine lookup/mutation policy, routes or camera lifecycle here.
- Select evidence through the [quality strategy](../../../docs/quality-strategy.md). Verify observable rendering and interaction at the changed boundary; inspect source readability separately. Documentation/link checks do not prove browser focus, computed colors, fonts or visual fidelity. Do not use source-format assertions as behavioral evidence.
