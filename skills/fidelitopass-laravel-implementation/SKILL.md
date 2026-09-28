---
name: fidelitopass-laravel-implementation
description: "Trigger: FidelitoPass Laravel, PHP, Livewire, PostgreSQL, Google Wallet application-code changes. Apply project-specific implementation and domain constraints."
license: Apache-2.0
metadata:
  author: FidelitoPass
  version: "3.0"
---

## Activation Contract

Use for project-owned application changes. Framework skills/docs own APIs; this skill owns FidelitoPass constraints. Do not use for delivery-board planning alone.

## Hard Rules

- Prefer conventional Laravel/Eloquent; use focused `<Verb><Subject>Action::handle()` for consequential commands and own the entire transaction. Authorize by explicit Business ownership; synchronize Wallet only after commit.
- One points-target Promotion: regular Visit = 1 point; Promotion-owned optional recurring x2/x3/x5 Puntos extra, never inherited or stacked. Freeze the **entire** published aggregate including Reward and schedule. Use `docs/promotion-model.md` for exact windows and lifecycle.
- Store immutable Visit `points_awarded`; allow legitimate same-day repeats but make retries idempotent. PostgreSQL owns domain truth; no customer User account.
- After relevant locks capture one PostgreSQL `clock_timestamp()` operation instant for validity, local point evaluation and timestamps; use published timezone, not host/browser time.
- Keep Livewire component order: properties, lifecycle, actions, optional private helpers, **listeners last**. Use meaningful lowercase English Blade region comments; translate all visible UI strings (Pase, Promoción, Puntos extra).

## Decision Gates

| Situation | Action |
| --- | --- |
| Routine CRUD vs consequential transition | Keep routine changes direct; use one Action for transaction/concurrency/side effects. |
| Domain/time ambiguity | Read relevant section of `docs/promotion-model.md`, `docs/development/database-standard.md` and acceptance requirement. |
| New vs existing Livewire UI | Prefer cohesive native multi-file component; preserve stable starter format. |
| Provider failure | Preserve committed facts and retry after-commit synchronization. |

## Execution Steps

1. Read work item, affected code, nearby tests and relevant installed versions; load only needed sections via `references/implementation-details.md`.
2. Implement smallest server-authoritative change, respecting persistence, presentation, localization and source-style constraints there.
3. Verify at natural test layer; cover ownership, time boundaries, idempotency and provider failure when affected.

## Output Contract

Report changed files, domain/security/time boundaries, observed checks, remaining risks or trade-offs.

## References

- `references/implementation-details.md` — detailed coding, logging, localization and testing conventions.
- `docs/promotion-model.md` — Promotion and Puntos extra authority.
- `docs/development/laravel-application-standard.md` — project Laravel conventions.
