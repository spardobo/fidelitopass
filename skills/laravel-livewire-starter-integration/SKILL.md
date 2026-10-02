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
- For FidelitoPass, load the linked implementation skills for affected responsibilities: PHP server logic, Blade/Flux markup and/or JavaScript client behavior. Apply their required references, including shared code quality; presentation-only integration does not automatically load PHP. Document owners retain outcome/risk policy; do not copy it here or guess installed APIs.

## Decision Gates

| Situation | Action |
| --- | --- |
| New page has cohesive PHP, Blade, and colocated tests | Own a full-page multi-file component (MFC); use `Route::livewire` and the appropriate starter layout with its `$slot`. |
| Existing starter component already fits | Extend it without format conversion. |
| Navigation requires DOM continuity | Use `wire:navigate` conservatively; place `@persist` outside Livewire components only when needed, and keep active-link state dynamic after navigation. |
| Theme or language differs from another project | Follow this project's choices and translation conventions, not copied defaults. |

## Execution Steps

1. Trace route, page, layout slots, navigation, assets and auth. Verify uncertain APIs against installed source/configuration or targeted official guidance. Read affected skill references and owners selected through [Documentation routing](../../AGENTS.md#documentation-routing). Stop before editing for a missing source or unresolved intent; skill loading alone is insufficient.
2. Implement the smallest native extension, using Laravel translations for user-facing copy and Flux/Tailwind for presentation.
3. Exercise authorized and unauthorized behavior, navigation state, and relevant responsive flows with the project's native test runner.

## Output Contract

Report paths, consulted sections/API evidence, actual checks, material presentation/lifecycle choices, risks and registry-refresh needs. Keep the handoff scoped, not per helper.

## References

- [PHP/Livewire skill](../fidelitopass-laravel-livewire-implementation/SKILL.md) / [component integration](../fidelitopass-laravel-livewire-implementation/references/implementation-details.md#livewire-structure-and-authority).
- [Blade/Flux skill](../fidelitopass-blade-flux-implementation/SKILL.md) / [composition](../fidelitopass-blade-flux-implementation/references/implementation-details.md#composition-and-framework-gates).
- [JavaScript skill](../fidelitopass-javascript-alpine-implementation/SKILL.md) / [lifecycle](../fidelitopass-javascript-alpine-implementation/references/implementation-details.md#resource-ownership-and-disposal).
- [Shared code quality](../shared/code-quality.md) — cross-language conventions.
- [UI outcomes](../../docs/ui-ux-guidelines.md) and [application contracts](../../docs/development/laravel-application-standard.md) — select affected sections only.
