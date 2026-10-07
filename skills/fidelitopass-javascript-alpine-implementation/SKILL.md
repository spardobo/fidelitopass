---
name: fidelitopass-javascript-alpine-implementation
description: "Trigger: FidelitoPass JavaScript, Alpine, component-local browser behavior or JS tests/scripts. Apply native behavior, readable source and resource ownership."
license: Apache-2.0
metadata:
    author: FidelitoPass
    version: "1.0"
---

## Activation Contract

Use for project JavaScript/Alpine, browser behavior and JS tests/scripts; exclude PHP-only, markup-only and delivery work. Installed framework guidance owns APIs. Apply DOM lifecycle only to DOM code.

## Hard Rules

- Apply both references; assess readability separately from behavior.
- Implement the simplest complete current requirement; apply the architecture owner rather than generalizing for hypothetical reuse.
- Before writing behavior, identify its observable requirement and owner, then establish the actual file, language, module, runtime/context, lifetime, APIs, resources and cheapest meaningful proof. Stop if a material fact is unknown.
- Preserve the real file path/extension, language, module system, runtime/context and formatter/linter ownership. Do not introduce TypeScript-only syntax in `.js`/`.mjs` or migrate languages incidentally.
- Treat a refactor as behavior-preserving only when the relevant execution boundary supports that claim. Preserve applicable public, failure, observation-time, operation-order and test-scenario contracts; report unverified boundaries and residual risk.
- For browser behavior, prefer native HTML/browser behavior, then suitable Flux, Livewire, local Alpine and only then custom JavaScript for a concrete gap. This is a capability order, not a checklist for Node code.
- Preserve server authority, native assets and translated Spanish copy; add no competing runtime or pipeline.
- Expose actual responsibilities/resources; add no speculative lifecycle machinery.

## Decision Gates

| Situation                            | Action                                                                                                                                                                                                   |
| ------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Mixed component                      | Load the [PHP skill](../fidelitopass-laravel-livewire-implementation/SKILL.md) for affected server logic and [Blade/Flux skill](../fidelitopass-blade-flux-implementation/SKILL.md) for affected markup. |
| Custom behavior                      | Establish the native gap and actual DOM/lifetime contract.                                                                                                                                               |
| Missing source or conflicting intent | Stop before editing; report the exact gap.                                                                                                                                                               |

## Execution Steps

1. Read affected source/tests and owners through [project routing](../../AGENTS.md#documentation-routing); establish source and tool ownership before editing.
2. Apply references; verify uncertain APIs against installed versions/configuration and select evidence at the relevant runtime boundary.
3. Make the smallest authorized change; check behavior, readability and limits without claiming more than the executed evidence proves.

## Output Contract

Report paths, owners/API evidence, checks, resource/readability decisions and risks. Separate measurements from assumptions; flag registry/routing needs without unauthorized edits.

## References

- [Shared quality](../shared/code-quality.md).
- [JavaScript implementation](references/implementation-details.md).

- [Readable JavaScript examples](references/readability-examples.md) — optional names/paragraph/resource calibration; no mandatory controller or request framework.
