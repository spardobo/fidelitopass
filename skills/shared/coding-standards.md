# Shared coding standards
Applies to every language in this repository. Language-specific syntax lives in each skill. These rules are non-negotiable; language skills may tighten but never relax them. NEVER use a coding example to override accepted product, security or transaction contracts.

<a id="design-authority"></a>

<a id="application-by-surface"></a>

## 1. Failure taxonomy — pick exactly one per situation
Every failure MUST be classified before writing the throw/return:

| Category | What it is | Mechanism | Log? |
| --- | --- | --- | --- |
| Contract / bug | null where forbidden, wrong type, broken invariant. Programmer error. | Native exception (InvalidArgumentException, LogicException, TypeError). | NEVER add a duplicate local event; retain the configured unexpected-error reporter |
| Expected business rejection | Invalid credential, no match, throttle hit, state not eligible. Normal flow. | Typed domain exception carrying a closed-set reason. | YES, once, at the boundary |
| Infrastructure | DB down, driver failure, timeout, disk. | Typed domain exception after translation. | YES, once, at the boundary, with trace |
NEVER invent an exception for an expected predicate result. NEVER let an infrastructure exception escape untranslated past a domain boundary.

## 2. Failure codes live in one enum
If a PHP application flow owns more than one classified failure reason, that flow MUST have a string-backed enum. In other languages, use the equivalent native closed catalog; NEVER introduce PHP or TypeScript syntax into JavaScript. The enum owns ALL metadata:

```php
enum InspectionFailure: string
{
    case Forbidden               = 'forbidden';
    case InvalidCredential       = 'invalid_credential';
    case InvalidAttemptLimit     = 'invalid_attempt_limit';
    case AuthoritativeReadFailed = 'authoritative_read_failed';

    public function httpStatus(): int { /* match */ }
    public function logLevel(): string { /* match */ }
    public function eventName(): ?string { /* match */ }
    public function outcome(): string { /* match */ }
}
```

The domain exception carries the enum, never a string:

```php
final class CustomerPassInspectionException extends RuntimeException
{
    public function __construct(
        public readonly InspectionFailure $failure,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($failure->value);
    }
}
```

Consumers (controller, tests, dashboards) read from the enum. Adding a failure = adding its case and relevant metadata mappings. NEVER duplicate production code strings in parallel catalogs. Independent test expectations may assert literal public values.

<a id="failures-and-logging"></a>

## 3. Logging: once, at the boundary that knows the outcome
Log exactly once per failure, in the method that owns the semantic outcome.

NEVER log-and-throw from a collaborator when another boundary owns that same outcome. The owning boundary MAY log once and then translate or propagate. If that boundary logs, the caller MUST NOT re-log.

The boundary logger receives the typed failure and reads level/event/outcome from the enum.

Known infrastructure failures handled at the boundary log with Arr::only($frame, ['file', 'line', 'class', 'function']) on the trace. NEVER the SQL, bindings, or credential.

One blank line before and after each Log:: call.

Anti-pattern:

```php
// WRONG — logs twice (here and in caller), keeps raw context
catch (Throwable $e) {
    Log::error('failed', ['exception' => $e]);
    throw $e;
}
```

Pattern:

```php
// RIGHT — translate and let the boundary log once
catch (QueryException $e) {
    $this->logReadFailure($actor->id, $businessId, $e);
    $this->reject(InspectionFailure::AuthoritativeReadFailed);
}
```

<a id="types-and-value-organization"></a>

## 4. Return shape is fixed
A method returning a structured application result MUST always return the same keys. This does not replace native model, scalar, collection or void contracts. Optional fields are null, never absent. NEVER return a different shape for a failure branch.

```php
return [
    'status'            => 'found',
    'customer_pass_id'  => (int) $row->customer_pass_id,
    'checked_at'        => $capturedAt,
    'promotion'         => $promotion,          // null or full shape
    'can_accept_visit'  => $promotion !== null && ...,
    'can_redeem_reward' => $promotion !== null && ...,
];
```

NEVER mix ['ok' => true, 'data' => ...] with ['ok' => false, 'error' => ...]. If you must express failure as a value, the failure variant still carries the full key set with nulls.

<a id="readability-and-cohesion"></a>

## 5. Naming
Choose names that describe what the code actually does:

| Wrong | Right | Why |
| --- | --- | --- |
| prepareCredential | normalizeCredential | It validates and canonicalizes, not "prepares" |
| inspectionResult | buildResult | Verb describing action, not type |
| readPassSnapshot | readSnapshot | "pass" is already implicit in the class |
| $instant | $capturedAt | Meaning, not type |
| $rows | $matches | Semantic: at most one result |
| Helper, Manager, Util | Concrete responsibility | Vague abstraction |
| handle() on random methods | Reserved for real Action entry points | Not decoration |
Rules:

NEVER repeat information the class or type already provides.

Predicates name what they establish: hasOverlap, isPublished, canRedeem — not checkOverlap, validateStatus.

Expensive work MUST be visible in the name. NEVER disguise a query as getFoo().

Keep technical identifiers English. Visible UI copy stays professional Spanish via Laravel translations.

<a id="efficiency-gate"></a>

## 6. Function extraction — KISS / YAGNI / DRY, in that order
Extract a function when AT LEAST ONE holds:

It has a non-trivial body (loops, branching, SQL, regex, mapping).

It has more than one caller.

It has a semantic name that reduces the caller's reading effort.

It isolates a side effect (log, external call, transaction).

Do NOT extract:

A one-liner used once (inline it).

A max(1, (int) config(...)) guard used once (inline with the default).

A "for readability" wrapper around count() or firstOrFail().

A helper that only forwards arguments.

Anti-pattern:

```php
// WRONG — one caller, one line, adds a name that repeats the body
private function maxAttempts(): int
{
    return max(1, (int) config('pass_lookup.max_invalid_attempts', 10));
}
```

Pattern:

```php
// RIGHT — inline at the single call site
$maxAttempts = max(1, (int) config('pass_lookup.max_invalid_attempts', 10));
```

<a id="framework-first-gate"></a>

## 7. Separation of concerns
Validation lives before business logic. All guards (auth, throttle, format) run before the business method is entered. The business method receives already-validated inputs.

Business methods do not log, do not catch, do not translate. They throw typed exceptions and return fixed shapes.

The boundary method (the public entry) owns: translation of infra exceptions, logging, and the try/catch that keeps the flow linear.

```php
public function inspect(...): array
{
    // validations first
    if (...) { $this->reject(Forbidden); }
    // ... all guards ...

    try {
        return $this->runInspection($business, $method, $normalized, $rateLimitKey);
    } catch (QueryException|PDOException $e) {
        $this->logReadFailure(...);
        $this->reject(AuthoritativeReadFailed);
    } catch (CustomerPassInspectionException $e) {
        $this->logOperationalFailure(...);
        throw $e;
    }
}

private function runInspection(...): array
{
    // operation flow, no log, no catch
}
```

## 8. No defensive guards against impossible states
If an upstream invariant (a checked publication flow under its transactional lock, a unique constraint or a published state machine) makes a state impossible, DO NOT guard against it. A lock alone does not establish uniqueness.

Anti-pattern:

```php
// WRONG — PublishPromotion guarantees no overlapping published windows
$rows = DB::select("... LIMIT 2", [...]);
if (count($rows) > 1) {
    throw new CustomerPassInspectionException(AmbiguousActivePromotion);
}
```

Pattern:

```php
// RIGHT — trust the invariant, one row max
$row = DB::selectOne($query, $bindings);
```

Adding a guard against the impossible costs: more failure codes, more tests, more branches, more reading. It is overengineering.

<a id="comments-and-documentation"></a>

## 9. Comments
Internal comments are ONE line, lowercase English (except proper nouns / identifiers), no trailing period.

They mark a phase, an order, or a non-obvious rationale. NEVER narrate each call.

```php
// reject malformed credentials before reading the clock
$normalized = $this->normalizeCredential($method, $credential);
```

One blank line around the commented block. No decorative boxes inside methods.

Boxes are ONLY allowed to group sibling methods, in the same lowercase English form:

```php
// ------------------------------------------------------------------------
// validations
// ------------------------------------------------------------------------

private function reject(...) { ... }
```

<a id="callable-documentation"></a>

## 10. Docblocks
Every named callable MUST have a docblock: classes, methods, functions, constructors.

Include @param for each parameter and @return for every non-void return.

Include @throws ONLY when the callable actually throws.

Describe responsibility, units, trust, preconditions. Do not restate the signature.

```php
/**
 * Aborts the inspection with the given typed failure.
 *
 * @param InspectionFailure $failure Typed reason propagated to the caller.
 * @param int|null $retryAfterSeconds Optional Retry-After hint for throttled callers.
 *
 * @return never
 *
 * @throws CustomerPassInspectionException Always.
 */
private function reject(InspectionFailure $failure, ?int $retryAfterSeconds = null): never
```

Document at the declaration, above attributes.

Anonymous closures need docblocks only for non-obvious contracts.

## 11. Reading flow
Every method MUST be readable top-to-bottom as: inputs → guards → decisions → effects → result.

Early returns over nested conditionals.

One idea per paragraph, one blank line between paragraphs.

Keep related steps adjacent. Do not interleave validation with I/O.

Signatures and calls split by meaningful argument, one per line if multiline.

<a id="scoped-review"></a>

## 12. Delivery gate — verify before returning
For every changed file:

□ Every failure classified (contract / business / infra).
□ Failure codes live in one enum, no duplicated strings.
□ Logging occurs once, at the boundary, with enum metadata.
□ Return shape is identical across all branches.
□ No helper exists for a single one-line call.
□ No defensive guard exists against an upstream invariant.
□ Internal comments are one line, lowercase, purpose-only.
□ Every named callable has @param + @return; @throws only when real.
□ The method reads top-to-bottom without jumping.
