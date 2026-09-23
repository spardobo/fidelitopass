---
name: landing-page-design
description: "Trigger: landing page, marketing page, landing copy, hero, conversion CTA, landing redesign. Build truthful, accessible FidelitoPass landing pages with the native Laravel/Livewire stack."
license: MIT
metadata:
  author: FidelitoPass contributors; adapted from Elaya
  version: "1.0"
---

## Activation Contract

Use for FidelitoPass landing-page structure, copy, or visual changes; not for unrelated dashboards or general UI.

## Hard Rules

- Follow precedence: explicit user choices and product docs, then native framework conventions and accessibility, then this adapted upstream guidance.
- Ground every claim, price, metric, testimonial, and proof signal in implemented behavior or verified evidence. Do not promise permanent free pricing or features not shipped.
- Use Laravel 13 / Livewire 4 full-page MFCs, shared layouts and `Route::livewire`; reuse Flux 2, Tailwind 4 `@theme` in `resources/css/app.css`, and Vite. No parallel theme or navigation stack.
- Use neutral Spanish copy via grouped `lang/es/{landing,common}.php` keys. Keep warm light as default, optional dark consistent with project UI guidelines; preserve contrast, visible focus, semantic metadata, and reduced-motion preferences.

## Decision Gates

| Situation | Action |
| --- | --- |
| Offer, audience, or primary action unclear | Check product docs and ask only for a genuine product decision; do not invent promises. |
| No verified social proof | Omit proof instead of fabricating statistics or testimonials. |
| Visual rule conflicts with native UI or accessibility | Prefer Flux/Tailwind and accessible behavior; choose one typeface, deliberate spacing and radius without rigid font/icon bans or compulsory animation. |

## Execution Steps

1. Confirm one offer, audience, and primary action; draft benefit-first headline and precise next-step CTA.
2. Shape a responsive hero → benefits → how it works → objections → final CTA argument, omitting unsupported sections.
3. Implement in the existing page/layout and translation pipeline; check mobile and desktop, keyboard focus, contrast, motion preference, metadata, and truthful CTA destinations.
4. Run focused checks and the existing Playwright Docker wrapper when relevant.

## Output Contract

Report changed files, verified claims and CTA behavior, actual checks, and unresolved product or accessibility risks.

## References

- `LICENSE` — MIT license and attribution for Elaya's [landing-page-design source](https://github.com/elayadesign/ai-design-skills/blob/main/skills/landing-page-design/SKILL.md); adapted strategy, not its blanket UI/style mandates.
