# PHP readability calibration
Read before the first code change in a task. Examples calibrate naming, paragraphs and boundaries; do not copy them as scaffolding.

## Canonical: compact cause enum
```php
<?php

namespace App\Enums;

/** Identifies inspection causes independently of operation facts. */
enum InspectionFailure: string
{
    case Forbidden = 'forbidden';
    case InvalidCredential = 'invalid_credential';
    case InvalidAttemptLimit = 'invalid_attempt_limit';
    case AuthoritativeReadFailed = 'authoritative_read_failed';

    /**
     * Returns the intrinsic severity for this cause.
     *
     * @return string PSR-3 level name.
     */
    public function logLevel(): string
    {
        return match ($this) {
            self::Forbidden => 'warning',
            self::InvalidCredential, self::InvalidAttemptLimit => 'notice',
            self::AuthoritativeReadFailed => 'error',
        };
    }

    /**
     * Returns the event name, or null when the failure must remain silent.
     *
     * @return string|null Operational event name.
     */
    public function eventName(): ?string
    {
        return match ($this) {
            self::Forbidden => null,
            self::InvalidCredential => 'customer_pass.lookup_rejected',
            self::InvalidAttemptLimit => 'customer_pass.lookup_throttled',
            self::AuthoritativeReadFailed => 'customer_pass.lookup_unavailable',
        };
    }
}
```

Only intrinsic metadata belongs here. If one native database failure can happen before or after a provider write, keep one cause and store the actual stage and certainty on the exception. Do not create a case for every combination.

## Canonical: complete immutable container
```php
class CustomerPassInspectionException extends RuntimeException implements ShouldntReport
{
    /**
     * Stores a typed cause and complete safe evidence without logging.
     *
     * @param InspectionFailure $failure Application-owned cause.
     * @param Throwable|null $previous Optional safe native cause.
     * @param array<string, mixed> $context Selected nonsecret evidence.
     * @param int|null $retryAfterSeconds Seconds to wait when throttled.
     */
    public function __construct(
        public readonly InspectionFailure $failure,
        ?Throwable $previous = null,
        public readonly array $context = [],
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($failure->value, 0, $previous);
    }
}
```

Constructor excerpt only. Each non-final exception owns its `log(array $context = []): void` emitter. It merges stored context then caller context and keeps the typed reason authoritative. Null events return early. Safe retained causes contribute class/file/line and frames filtered through `Arr::only` to file/line/class/function. Never emit messages, arguments, frame objects or raw causes.

Select safe diagnostics for secret-bearing SQL, HTTP, SDK and signing failures at construction and pass previous=null. An optional `fromException()` can centralize classification and evidence extraction, using explicit operation facts. It returns an existing same-domain failure unchanged and never logs, queries, retries or decides a business operation. No factory zoo, shared base or mandatory client helper is needed.

## Canonical: ordinary throw and outcome-owner catch
```php
public function inspect(?User $actor, string $method, #[SensitiveParameter] string $credential): array
{
    if ($actor === null || ! $actor->hasVerifiedEmail()) {
        throw new CustomerPassInspectionException(InspectionFailure::Forbidden);
    }

    $businessId = null;

    try {
        $business = $actor->business()->first();
        if ($business === null) {
            throw new CustomerPassInspectionException(InspectionFailure::Forbidden);
        }

        $businessId = $business->id;
        $rateLimitKey = self::RATE_LIMIT_PREFIX.$actor->id;
        $this->assertNotThrottled($rateLimitKey);
        $normalized = $this->normalizeCredential($method, $credential);
        if ($normalized === null) {
            $this->recordInvalidAttempt($rateLimitKey);
            throw new CustomerPassInspectionException(InspectionFailure::InvalidCredential);
        }

        return $this->runInspection($business, $method, $normalized);
    } catch (QueryException|PDOException $exception) {
        $failure = CustomerPassInspectionException::fromException($exception);
        $failure->log(['actor_id' => $actor->id, 'business_id' => $businessId, 'outcome' => 'unavailable']);

        throw $failure;
    } catch (CustomerPassInspectionException $exception) {
        $exception->log([
            'actor_id' => $actor->id,
            'business_id' => $businessId,
            'outcome' => match ($exception->failure) {
                InspectionFailure::Forbidden => 'forbidden',
                InspectionFailure::InvalidCredential, InspectionFailure::InvalidAttemptLimit => 'rejected',
                InspectionFailure::AuthoritativeReadFailed => 'unavailable',
            },
        ]);

        throw $exception;
    }
}
```

This boundary owns the outcome and logs once before propagation. Collaborators never log the same outcome. Native logging failures propagate; do not wrap them or imply they undo a commit. A retry delay is supplied with `new CustomerPassInspectionException(InspectionFailure::InvalidAttemptLimit, retryAfterSeconds: $seconds)`, not assigned later. HTTP adapters use actual stage/certainty; retry permission is a separate decision.

## Canonical: fixed return shape
```php
private function buildResult(stdClass $row, string $capturedAt): array
{
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

Promotion is null or a full shape. Optional fields remain present. These excerpts omit unrelated declarations; named callables still need complete responsibility-first PHPDoc in production.

## Anti-patterns to avoid
| Anti-pattern | Why it is wrong |
| --- | --- |
| Collaborator logs then throws to an outcome-owning caller. | Duplicates the outcome event. |
| Mutable diagnostics or retry metadata assigned after construction. | Leaves a partially initialized failure. |
| Stage/certainty variants of every native cause. | Multiplies independent dimensions into a confusing catalog. |
| `reject()` required in every client. | Adds a convention without reducing the real operation. |
| A helper only forwards arguments or wraps one trivial expression. | Hides the reading flow. |
| Optional keys disappear from a structured result. | Breaks the fixed public shape. |
| Persistence is wrapped in generic CRUD or repository infrastructure. | Hides Laravel's existing ownership and transaction boundary. |

## Final calibration
Read every method top-to-bottom: inputs, guards, decisions, effects, result. Preserve queries, locks, authoritative clock, provider timing and public response contracts. Keep failure identity, operation facts, native evidence and outcome ownership distinct. Follow Pint output rather than restoring manual spacing.
