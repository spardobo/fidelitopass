# PHP readability calibration
Read before the first code change in a task. Examples come from this repository's real patterns; reuse the calibration, don't copy the file wholesale.

## Canonical: enum with metadata
```php
<?php

namespace App\Enums;

/**
 * Single catalog of failures raised by the pass inspection flow.
 *
 * Adding a failure extends this case catalog and its relevant metadata.
 * Every consumer reads that metadata from the enum instead of
 * maintaining a parallel failure mapping.
 */
enum InspectionFailure: string
{
    case Forbidden               = 'forbidden';
    case InvalidCredential       = 'invalid_credential';
    case InvalidAttemptLimit     = 'invalid_attempt_limit';
    case AuthoritativeReadFailed = 'authoritative_read_failed';

    /**
     * Returns the severity used for an operational failure event.
     *
     * @return string PSR-3 level name.
     */
    public function logLevel(): string
    {
        return match ($this) {
            self::Forbidden               => 'warning',
            self::InvalidCredential,
            self::InvalidAttemptLimit     => 'notice',
            self::AuthoritativeReadFailed => 'error',
        };
    }

    /**
     * Returns the event name, or null when the failure must remain silent.
     *
     * @return string|null Operational event name, when applicable.
     */
    public function eventName(): ?string
    {
        return match ($this) {
            self::Forbidden               => null,
            self::InvalidCredential       => 'customer_pass.lookup_rejected',
            self::InvalidAttemptLimit     => 'customer_pass.lookup_throttled',
            self::AuthoritativeReadFailed => 'customer_pass.lookup_unavailable',
        };
    }
}
```

Note: illustrative cases, aligned =>, one case per line, exhaustive matches without a default. Only logLevel() and eventName() are required. Add metadata only for actual consumers, not for symmetry.

## Canonical: typed exception
```php
class CustomerPassInspectionException extends RuntimeException implements ShouldntReport
{
    /**
     * Builds the exception from its typed failure and an optional safe native cause.
     *
     * @param InspectionFailure $failure Typed reason for the failure.
     * @param Throwable|null $previous Non-secret-bearing native cause, when safe to retain.
     */
    public function __construct(
        public readonly InspectionFailure $failure,
        ?Throwable $previous = null,
    ) {
        parent::__construct($failure->value, 0, $previous);
    }
}
```

Note: constructor excerpt only. The non-final exception carries one readonly failure enum and owns its log(array $context = []): void emitter. It returns for a null event and emits reason plus explicit safe context otherwise. A retained safe cause contributes only previous_class, previous_file, previous_line and previous_frames, with each frame filtered through Arr::only to file/line/class/function. Never emit cause messages or arguments. Secret-bearing causes must be null; the boundary supplies selected safe metadata instead. Keep ShouldntReport and existing safe retry hints without adding constructor arguments, factories or translations.

## Canonical: inspector boundary vs operation flow
Boundary (validation + translation + logging + return):

```php
public function inspect(?User $actor, string $method, #[SensitiveParameter] string $credential): array
{
    // reject unauthenticated or unverified actors before any other work
    if ($actor === null || ! $actor->hasVerifiedEmail()) {
        $this->reject(InspectionFailure::Forbidden);
    }

    $businessId = null;

    try {
        $business = $actor->business()->first();
        if ($business === null) {
            $this->reject(InspectionFailure::Forbidden);
        }

        $businessId = $business->id;

        // enforce the shared invalid-attempt window before touching the credential
        $rateLimitKey = self::RATE_LIMIT_PREFIX.$actor->id;
        $this->assertNotThrottled($rateLimitKey);

        // reject malformed credentials before reading the clock or the snapshot
        $normalized = $this->normalizeCredential($method, $credential);
        if ($normalized === null) {
            $this->recordInvalidAttempt($rateLimitKey);
            $this->reject(InspectionFailure::InvalidCredential);
        }

        return $this->runInspection($business, $method, $normalized, $rateLimitKey);
    } catch (QueryException|PDOException $exception) {
        // discard the secret-bearing cause and pass only selected safe diagnostics
        $failure = new CustomerPassInspectionException(InspectionFailure::AuthoritativeReadFailed);
        $failure->log([
            'actor_id' => $actor->id,
            'business_id' => $businessId,
            'outcome' => 'unavailable',
            'previous_class' => $exception::class,
            'exception_class' => $exception::class,
            'source_file' => $exception->getFile(),
            'source_line' => $exception->getLine(),
            'exception_frames' => array_map(
                static fn (array $frame): array => Arr::only($frame, ['file', 'line', 'class', 'function']),
                $exception->getTrace(),
            ),
        ]);

        throw $failure;
    } catch (CustomerPassInspectionException $exception) {
        $exception->log([
            'actor_id' => $actor->id,
            'business_id' => $businessId,
            'outcome' => match ($exception->failure) {
                InspectionFailure::Forbidden => 'forbidden',
                InspectionFailure::InvalidCredential,
                InspectionFailure::InvalidAttemptLimit => 'rejected',
                InspectionFailure::AuthoritativeReadFailed => 'unavailable',
            },
        ]);

        throw $exception;
    }
}
```

The boundary owns the outcome and calls the exception's emitter once before propagating. Collaborators do not log. Outcome labels stay here, not in the enum. Native logging failures propagate without a protective wrapper.

Operation flow (no log, no catch, no translation; database/cache I/O is not pure):

```php
private function runInspection(Business $business, string $method, #[SensitiveParameter] string $normalized, string $rateLimitKey): array
{
    // capture one authoritative instant for the whole snapshot
    $capturedAt = $this->clock->capture($business->timezone)->instant;
    $row = $this->readSnapshot($business, $method, $normalized, $capturedAt);

    // a well-formed credential resolving to no pass is a business rejection
    if ($row === null) {
        $this->recordInvalidAttempt($rateLimitKey);
        $this->reject(InspectionFailure::InvalidCredential);
    }

    return $this->buildResult($row, $capturedAt);
}
```

Note: runInspection receives only validated inputs. It decides on business outcomes (no match), never on contract or format.

## Canonical: fixed return shape
```php
private function buildResult(stdClass $row, string $capturedAt): array
{
    // build the promotion projection once; eligibility flags derive from it
    $promotion = $row->promotion_id === null ? null : [
        'id' => (int) $row->promotion_id,
        'target_points' => (int) $row->target_points,
        'earned_points' => (int) $row->earned_points,
        'reward_title' => (string) $row->reward_title,
        'reward_status' => $row->reward_id === null
            ? 'none'
            : ($row->redeemed_at === null ? 'available' : 'redeemed'),
    ];

    return [
        'status' => 'found',
        'customer_pass_id' => (int) $row->customer_pass_id,
        'checked_at' => $capturedAt,
        'promotion' => $promotion,
        'can_accept_visit' => $promotion !== null
            && $promotion['earned_points'] < $promotion['target_points']
            && $promotion['reward_status'] === 'none',
        'can_redeem_reward' => $promotion !== null
            && $promotion['reward_status'] === 'available',
    ];
}
```

Note: promotion is null or a full shape. No optional keys. Eligibility flags derive from the projection.

## Anti-patterns to avoid
| Anti-pattern | Why it's wrong |
| --- | --- |
| collaborator catch (Throwable $e) { Log::error(...); throw $e; } | Logs twice (here + boundary). |
| if (count($rows) > 1) throw Ambiguous after the checked upstream flow guarantees uniqueness | Guards an impossible state. |
| private function maxAttempts(): int { return max(1, (int) config(...)); } for one caller | One-line helper used once. |
| $promotion['id'] ?? null inside a fixed shape | Optional keys violate the fixed-shape rule. |
| @return array<string, mixed> on a bounded shape | Loses static analysis value. Use the shape. |
| prepareCredential that validates | Name lies about responsibility. |
| ->whereKey(...)->lockForUpdate()->firstOrFail() before locking the Business | Unstable lock order. |
## Final calibration
Every file: read top-to-bottom as a narrative.

Every application-owned failure: classified, enum-coded, with at most one applicable boundary event. Native unexpected-error reporting remains intact.

Every method: contract documented with @param / @return / @throws (only when real).

Every extraction: justified by a rule in shared §6.

Every guard: against a real, reachable state.