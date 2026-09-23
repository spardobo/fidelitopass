---
name: laravel-starter-first-livewire
description: "Trigger: integrating UI features into a Laravel + Livewire starter, full-page Livewire routes, starter layouts, Flux navigation. Reuse installed starter conventions before adding presentation infrastructure."
license: Apache-2.0
metadata:
  author: gentleman-programming
  version: "1.0"
---

## Activation Contract

Use for UI integration in a Laravel + Livewire starter. Do not use for domain-only backend changes; leave product language and theme to the project.

## Hard Rules

- Inspect installed routes, layouts, components, translations, tests, and asset entry points before changing the shell.
- Preserve stock authentication/settings screens and their component format; never convert stable SFCs just for uniformity.
- Keep authorization and validation server-side, including policy checks on Livewire actions. Never treat hidden fields or UI visibility as access control.
- Reuse the shared Flux, Tailwind, and Vite pipeline; avoid duplicate stylesheets, theme systems, or JavaScript navigation frameworks.

## Decision Gates

| Situation | Action |
| --- | --- |
| New page has cohesive PHP, Blade, and colocated tests | Own a full-page multi-file component (MFC); use `Route::livewire` and the appropriate starter layout with its `$slot`. |
| Existing starter component already fits | Extend it without format conversion. |
| Navigation requires DOM continuity | Use `wire:navigate` conservatively; place `@persist` outside Livewire components only when needed, and keep active-link state dynamic after navigation. |
| Theme or language differs from another project | Follow this project's choices and translation conventions, not copied defaults. |

## Execution Steps

1. Trace the route through the page, layout slots, navigation, assets, and authentication boundary.
2. Implement the smallest native extension, using Laravel translations for user-facing copy and Flux/Tailwind for presentation.
3. Exercise authorized and unauthorized behavior, navigation state, and relevant responsive flows with the project's native test runner.

## Output Contract

Report changed files, actual checks, unresolved risks, and whether a skill-registry refresh is needed.

## References

None.
