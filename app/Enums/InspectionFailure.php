<?php

namespace App\Enums;

/**
 * Single catalog of failures raised by the pass inspection flow.
 *
 * Keeps identity, adapter status and operational metadata together.
 * Extend the relevant mappings when adding a failure; callers consume
 * this catalog rather than maintaining parallel reason mappings.
 */
enum InspectionFailure: string
{
    case Forbidden = 'forbidden';
    case InvalidCredential = 'invalid_credential';
    case InvalidAttemptLimit = 'invalid_attempt_limit';
    case AuthoritativeReadFailed = 'authoritative_read_failed';
    case AmbiguousActivePromotion = 'ambiguous_active_promotion';

    /**
     * Returns the response status available to an adapter for this failure.
     *
     * @return int Status code, never 200 for a failure.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Forbidden => 403,
            self::InvalidCredential => 404,
            self::InvalidAttemptLimit => 429,
            self::AuthoritativeReadFailed => 503,
            self::AmbiguousActivePromotion => 503,
        };
    }

    /**
     * Returns the log level used when this failure emits an event.
     *
     * @return string PSR-3 level name.
     */
    public function logLevel(): string
    {
        return match ($this) {
            self::AuthoritativeReadFailed => 'error',
            self::Forbidden => 'warning',
            self::InvalidCredential,
            self::InvalidAttemptLimit => 'notice',
            self::AmbiguousActivePromotion => 'error',
        };
    }

    /**
     * Returns the operational event name, or null when the failure is not an operational event.
     *
     * @return string|null Event name, or null when nothing must be emitted.
     */
    public function eventName(): ?string
    {
        return match ($this) {
            self::Forbidden => null,
            self::InvalidCredential => 'customer_pass.lookup_rejected',
            self::InvalidAttemptLimit => 'customer_pass.lookup_throttled',
            self::AuthoritativeReadFailed => 'customer_pass.lookup_unavailable',
            self::AmbiguousActivePromotion => 'customer_pass.lookup_unavailable',
        };
    }

    /**
     * Returns the outcome label attached to this failure's operational event.
     *
     * @return string Stable outcome label used in operational context.
     */
    public function outcome(): string
    {
        return match ($this) {
            self::Forbidden => 'forbidden',
            self::InvalidCredential,
            self::InvalidAttemptLimit => 'rejected',
            self::AuthoritativeReadFailed => 'unavailable',
            self::AmbiguousActivePromotion => 'unavailable',
        };
    }
}
