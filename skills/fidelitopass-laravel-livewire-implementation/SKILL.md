---
name: fidelitopass-laravel-livewire-implementation
description: "Trigger: FidelitoPass PHP, Laravel, Livewire, Eloquent, Actions, Enums, Support, Jobs, Integrations, Pest. Apply shared standards plus Laravel-specific structure, Action shape, enum-driven failures, transaction ownership and PHPDoc format."
license: Apache-2.0
metadata:
  author: FidelitoPass
  version: "4.0"
---

## Activation Contract
Use for PHP generation, modification, refactoring, review, and mixed components in this repository. Read ../shared/coding-standards.md first — this skill refines it, never relaxes it.

## Hard Rules
Apply shared standards: failure taxonomy, single-enum codes, log-once-at-boundary, fixed return shapes, naming, extraction gate, separation of concerns, comment and docblock rules.

Prefer Laravel roots (Actions, Enums, Models, Policies, Support, Data) and native Eloquent/scopes/casts/transactions/locks. NEVER wrap Eloquent access in repositories or generic CRUD services.

Use a <Verb><Subject> Action with a handle() entry point ONLY for consequential commands (transaction, authorization, multiple writes, external effect). Routine edits stay direct.

One Action owns exactly one transaction boundary. Callers do not open a competing DB::transaction.

Locks acquired in a stable order. Business before dependent rows. lockForUpdate() on the selected row.

The authoritative clock is captured once per operation from DatabaseClock; mutations capture it after acquiring their required locks. NEVER use now(), time(), or the PHP system timezone for domain validity.

Livewire 4 components: PHP, Blade and Pest colocated. Keep server authority; NEVER duplicate domain rules in hydrated state.

NEVER add TypeScript-style annotations, PHP 8.4 property hooks on non-final classes, or attributes that change caller coercion. Preserve existing parameter types and defaults.

### Failure pattern (canonical)
Three files, one flow:

app/Enums/<Flow>Failure.php — compact string-backed cause catalog with logLevel() and eventName(): ?string. No Illuminate imports; only Psr\Log\LogLevel is allowed from Psr\Log. Never constructs exceptions. Keep only intrinsic metadata here; do not multiply causes by runtime stage or certainty.

app/Exceptions/<Flow>Exception.php — non-final RuntimeException carrying public readonly <Flow>Failure $failure. Constructor passes enum value, code zero and optional safe previous to the parent. It also accepts readonly safe context and actually consumed typed operational facts, such as stage, certainty, provider status or retry delay. No mutable diagnostics or later metadata assignments. Its own log(array $context = []): void merges stored then caller context, preserves the authoritative reason and safe cause frames, or returns for a null event.

An optional fromException() may classify native causes and select safe evidence using explicit operation facts. It preserves existing same-domain exceptions and never logs, queries, retries or decides business outcomes. Prefer it over duplicated client classification/status/source helpers; do not add a factory zoo or domain-to-domain translations. HTTP/retry mappings use the facts they actually need, never certainty inferred from retryability.

app/Support/<Flow>Inspector.php (or <Verb><Subject> Action) — public entry validates and owns the outcome. Use ordinary throw new; no mandatory reject/helper/trait/base class. Collaborators only propagate; the outcome owner calls log() once and may then propagate.

Never retain secret-bearing SQL, HTTP, SDK or signing causes. Pass previous=null and select safe source metadata, including previous_class, at construction. Safe retained causes expose only class/file/line and Arr::only-filtered frames, never messages or arguments. Do not mark $previous with SensitiveParameter. Keep the flow's existing ShouldntReport policy. Preserve nullable eventName signatures without fake branches/events; when necessary, only an exact-declaration return.unusedType annotation explaining this uniform contract is appropriate.

See references/implementation-details.md for the complete shape.

### Transaction pattern (canonical)
```php
private function publish(...): Promotion
{
    return DB::transaction(function () use (...): Promotion {
        $business = $actor->business()->lockForUpdate()->firstOrFail();
        // authorization, status checks, timezone reconfirmation
        $operation = $this->databaseClock->captureForBusinessTimezone($business->timezone);
        // window conversion, overlap check, writes
        return $published->load('extraPoints');
    });
}
```

Rules: one owner, no competing transactions. NEVER put external effects inside an automatically retryable callback. Existing-pass Wallet updates follow accepted ADR-007: synchronous confirmation inside the caller-owned, non-retrying transaction, one shared ten-second budget and no automatic retries. Initial issuance remains Google-first before local persistence.

### PHPDoc format (strict)
```php
/**
 * Short responsibility sentence in third person.
 *
 * Optional paragraph for order, invariants, or trust boundaries.
 *
 * @param Type $name Description ending with a period.
 * @param Type|null $name Description.
 *
 * @return Type Description.
 *
 * @throws DomainException When specific condition.
 */
```

Periods at the end of every tag description.

Blank line between the summary and the first @param.

Blank line before @return and before @throws.

@return never for non-returning callables; no @return on constructors.

Preserve #[SensitiveParameter] on every secret parameter; add it to new helpers that receive secrets.

### Formatting (Pint)
4 spaces, no tabs.

Class and single-line-signature braces on the next line; multiline-signature braces beside the return type.

One array entry per line for multi-entry arrays; tiny leaves may inline.

Fluent query: one call per line. Preserve filter order, eager loads, selected columns, locks.

Expand conditions with multiple &&/||: opening if ( on its own line, clauses aligned, subgroups parenthesized.

120 columns guide, not a wall.

### Livewire 4
Multi-file components for new project-owned full pages when PHP/Blade/Pest share responsibility.

Route::livewire with the starter layout (layouts::public or layouts::app).

Order: properties, lifecycle hooks, actions, private helpers, listeners last.

Validation and authorization server-side. Never in hydrated state or Alpine.

Resolve non-public collaborators per request via native injection; mount() assignments do not survive hydration.

### Verification
Pest for new tests; keep untouched PHPUnit starter tests as-is.

Database integration for locks/persistence. Real fixtures for filesystem.

Run through Sail. Report unavailable runners; never substitute host tools.

## Decision Gates

| Situation | Required action |
| --- | --- |
| A supplied pattern conflicts with an accepted owner | Preserve the owner's contract; correct only the conflicting rule. |
| A required runtime or API is unknown | Stop before dependent edits. NEVER invent the missing contract. |
| Work includes markup or client behavior | Load the [Blade skill](../fidelitopass-blade-flux-implementation/SKILL.md) and/or [JavaScript skill](../fidelitopass-javascript-alpine-implementation/SKILL.md) for the affected responsibilities. |
| A consequential operation owns a transaction or external effect | Follow [Laravel boundaries](references/implementation-details.md#laravel-boundaries) and preserve operation and transaction ownership. |

## Execution Steps

1. Read shared standards and the applicable references before the first edit.
2. Establish the real boundary and preserve the approved behavior.
3. Apply the canonical shape and report only evidence actually observed.

## Output Contract

Report changed paths, applied owners, observed checks, unavailable evidence and unresolved limits. NEVER claim unrun verification.

## References

- [Shared coding standards](../shared/coding-standards.md) — cross-language gates.
- [Implementation details](references/implementation-details.md) — Laravel/PHP specifics and patterns.
- [Readability examples](references/readability-examples.md) — PHP calibration with real shapes.
- [Project recipes](references/project-recipes.md) — schema and SQL recipes for this project.
