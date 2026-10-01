---
name: laravel-livewire-starter-integration
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
- For FidelitoPass, load `skills/fidelitopass-laravel-implementation/SKILL.md` from the repository root for owner constraints. Apply the relevant Source style, PHPDoc and comments, and Component-local JavaScript sections of `docs/development/laravel-application-standard.md`; do not copy domain rules into this skill or guess installed APIs.

## Decision Gates

| Situation | Action |
| --- | --- |
| New page has cohesive PHP, Blade, and colocated tests | Own a full-page multi-file component (MFC); use `Route::livewire` and the appropriate starter layout with its `$slot`. |
| Existing starter component already fits | Extend it without format conversion. |
| Navigation requires DOM continuity | Use `wire:navigate` conservatively; place `@persist` outside Livewire components only when needed, and keep active-link state dynamic after navigation. |
| Theme or language differs from another project | Follow this project's choices and translation conventions, not copied defaults. |

## Execution Steps

1. Trace the route through the page, layout slots, navigation, assets, and authentication boundary; verify version-sensitive APIs against installed source/configuration and targeted official documentation.
2. Implement the smallest native extension, using Laravel translations for user-facing copy and Flux/Tailwind for presentation.
3. Exercise authorized and unauthorized behavior, navigation state, and relevant responsive flows with the project's native test runner.

## Output Contract

Report changed files, selected owner/style sections and API evidence, actual checks, material presentation/lifecycle choices, unresolved risks, and whether a skill-registry refresh is needed. Keep the handoff scoped, not a per-helper checklist.

## References

- [Project implementation skill](../fidelitopass-laravel-implementation/SKILL.md) — owner constraints; the inline path above resolves from the repository root.
- [Laravel application standard](../../docs/development/laravel-application-standard.md#source-style) — source-style owner; load only relevant sections.
