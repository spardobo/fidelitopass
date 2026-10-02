---
name: fidelitopass-laravel-implementation
description: "Trigger: FidelitoPass Laravel, PHP, Livewire, PostgreSQL, Google Wallet or project UI implementation. Route domain and presentation decisions to targeted project references."
license: Apache-2.0
metadata:
  author: FidelitoPass
  version: "3.0"
---

## Activation Contract

Use for project-owned application or UI changes. Framework skills and installed source own APIs; this skill routes project-specific constraints. Do not use for delivery-board planning alone.

## Hard Rules

- Prefer conventional Laravel/Eloquent; use focused `<Verb><Subject>Action::handle()` for consequential commands and own the entire transaction. Authorize by explicit Business ownership; synchronize Wallet only after commit.
- One points-target Promotion: regular Visit = 1 point; Promotion-owned optional recurring x2/x3/x5 Puntos extra, never inherited or stacked. Freeze the **entire** published aggregate including Reward and schedule. Use `docs/promotion-model.md` for exact windows and lifecycle.
- Store immutable Visit `points_awarded`; allow legitimate same-day repeats but make retries idempotent. PostgreSQL owns domain truth; no customer User account.
- After relevant locks capture one PostgreSQL `clock_timestamp()` operation instant for validity, local point evaluation and timestamps; use published timezone, not host/browser time.
- Keep Livewire component order: properties, lifecycle, actions, optional private helpers, **listeners last**. Apply canonical [Source style](../../docs/development/laravel-application-standard.md#source-style) and [PHPDoc and comments](../../docs/development/laravel-application-standard.md#phpdoc-and-comments) to affected source; use `references/implementation-details.md` for targeted constraints. Translate all visible UI strings (Pase, Promoción, Puntos extra).
- For presentation, use the screen/state-specific sections of `docs/ui-ux-guidelines.md`; do not copy mockup assets or replace native Flux behaviour with guessed props. Owner-approved requirements govern; raise irreconcilable domain conflicts.

## Decision Gates

| Situation | Action |
| --- | --- |
| Routine CRUD vs consequential transition | Keep routine changes direct; use one Action for transaction/concurrency/side effects. |
| Abstraction or dependency contract | Apply [Pragmatic design principles](../../docs/development/laravel-application-standard.md#pragmatic-design-principles); retain concrete injection unless the [interface gate](../../docs/development/laravel-application-standard.md#concrete-dependencies-and-interface-gate) is met. |
| Domain/time ambiguity | Read relevant section of `docs/promotion-model.md`, `docs/development/database-standard.md` and acceptance requirement. |
| New vs existing Livewire UI | Prefer cohesive native multi-file component; preserve stable starter format. |
| Provider failure | Preserve committed facts and retry after-commit synchronization. |
| UI screen or component | Read only the relevant UI guide headings and acceptance clause; inspect installed Flux version/API, then compose native Flux, scoped Tailwind and only necessary shared CSS. |
| Navigation or scanner | Use targeted UI guide navigation/mapping or scanner/availability sections; preserve focus, dirty drafts and server authority. |

## Execution Steps

1. Read work item, affected code, nearby tests and relevant installed versions. Use [Documentation routing](../../AGENTS.md#documentation-routing) to select applicable owners and actually read the necessary sections before writing code; identify governing constraints and unresolved conflicts. Reading this skill alone is insufficient. If a required source is unavailable or material intent is unresolved, stop before the affected edit and report it. Use `references/implementation-details.md` for implementation constraints, not a second global map.
2. Apply the design principles and interface gate above before adding layers or contracts. Implement smallest server-authoritative change, respecting persistence, presentation and localization. For changed Blade, PHP and JavaScript (including scripts/tests), read and apply [Source readability acceptance](../../docs/development/laravel-application-standard.md#source-readability-acceptance) and its efficient-design criteria; assess independently of tests and organize by actual responsibilities, not a universal template.
3. Verify at natural test layer; cover ownership, time boundaries, idempotency and provider failure when affected.

## Output Contract

Report changed files, selected owner sections and API evidence, relevant domain/security/time boundaries, observed checks and remaining risks. Report material design, readability and efficiency decisions against canonical criteria, including demonstrated interface need, comment purpose, non-obvious Flux/native choices and lifecycle ownership when affected. Identify unmet criteria and limits of performance evidence separately from observed tests, not per helper.

## References

- `references/implementation-details.md` — detailed coding, logging, localization and testing conventions.
- `docs/promotion-model.md` — Promotion and Puntos extra authority (repository-root path; open the affected section only).
- `docs/ui-ux-guidelines.md` — canonical derived visual and interaction guidance (repository-root path; affected section only).
- `docs/development/laravel-application-standard.md` — project Laravel conventions (repository-root path; affected section only).
