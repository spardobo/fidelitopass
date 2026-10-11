---
name: fidelitopass-laravel-livewire-implementation
description: "Trigger: FidelitoPass PHP, Laravel, Livewire, Eloquent, Actions, Jobs, Support, integrations or Pest implementation, refactoring and review. Apply readable PHP, third-person PHPDoc and semantic groups; route domain decisions to owners."
license: Apache-2.0
metadata:
  author: FidelitoPass
  version: "3.0"
---

## Activation Contract

Use for PHP generation, modification, refactoring and review, including mixed components. Exclude presentation-only, JavaScript-only and delivery planning work. Framework skills and installed source own APIs.

Apply these PHP refinements over generic style suggestions. Honor explicit user instructions; do not change shared standards or global formatter configuration for local style.

For snippets, do not invent files, dependencies or policy. Missing owners block only dependent decisions.

## Hard Rules

- Apply [PHP implementation](references/implementation-details.md) and available [shared quality](../shared/code-quality.md) before writing. Generate readable code immediately; tests/formatters do not certify readability.
- Prefer conventional Laravel/Eloquent, concrete injection and cohesive native Livewire; preserve starter formats and server authority. Keep domain, data, security, time and presentation policy with document owners.
- Document classes and every named callable, including private methods/constructors, with an English third-person singular opening verb and useful parameter/return/exception contracts. Do not rename methods into third-person verbs; apply the reference's PHPDoc syntax.
- Use three-line separators only for at least two meaningful semantic groups in the same class/procedural/test file, each containing one to N related callables. Otherwise use none. Reject isolated banners, per-method boxes and artificial groups; keep a blank line above/below, before PHPDoc, never inside bodies.
- Keep constructors, framework/Livewire lifecycle hooks and Pest/PHPUnit configuration/setup/teardown unboxed at the top after state. Preserve documentation; exclude them from labeled groups/counts.
- Expand conditions, queries, payloads and assertions by meaning; separate ideas with blank lines. Extract complete responsibilities, not helpers per step or layers merely to shorten methods.
- Preserve observable semantics during refactoring, including replay, credentials and failure classification. Never silently fix behavior, add retries or expose secret-bearing failures through exception chaining; apply both references' preservation rules.

## Decision Gates

| Situation | Action |
| --- | --- |
| Consequential command | Use one focused Action for its complete boundary; keep routine edits direct. |
| Abstraction | Apply architecture's pragmatic design principles and shared efficiency gate. |
| Mixed component | Load the [Blade/Flux skill](../fidelitopass-blade-flux-implementation/SKILL.md) and/or [JavaScript skill](../fidelitopass-javascript-alpine-implementation/SKILL.md) for affected responsibilities. |
| Refactor | Establish observable behavior first; report suspected defects separately. |
| Missing source or unresolved intent | Report the exact gap; pause dependent work only. |

## Execution Steps

1. Identify inputs, outputs, effects, trust boundaries and order. Inspect instructions, callers/tests, installed APIs and tooling; select necessary owners through [Documentation routing](../../AGENTS.md#documentation-routing).
2. Read relevant [readability examples](references/readability-examples.md) before the task's first code change; reuse calibration later. Read only needed [recipes](references/project-recipes.md).
3. Write cohesive code with authoritative order visible; preserve behavior unless functional change is requested.
4. Apply the delivery gate below to every changed class/test; revise violations before returning.
5. Run configured formatting and applicable checks/tests. Read the final diff for lost grouping, changed contracts and readability.

## Delivery Gate

Apply [Final calibration](references/readability-examples.md#final-calibration) and the implementation reference's PHPDoc, semantic groups, formatting, behavior preservation and Pest readability sections. Tests/formatters do not waive this gate.

## Output Contract

Deliver requested code/files, meaningful changes and observed checks. State verification limits and material owner/API evidence. Never claim equivalence from formatting or performance from cleaner code.

## References

- [Shared code quality](../shared/code-quality.md) — reusable cross-language gates.
- [PHP implementation](references/implementation-details.md) — mandatory PHP/Livewire rules and targeted owners.
- [Project recipes](references/project-recipes.md) — source-sensitive examples, not scaffolding or domain authority.
- [Readability examples](references/readability-examples.md) — mandatory relevant calibration, not compulsory templates.
