<?php

namespace App\Enums;

/** Identifies Visit rejection and infrastructure causes independently of remote or commit certainty. */
enum VisitAcceptanceFailure: string
{
    case Forbidden = 'forbidden';
    case TransactionActive = 'transaction_active';
    case InvalidCredential = 'invalid_credential';
    case InvalidAttemptLimit = 'invalid_attempt_limit';
    case InvalidOperation = 'invalid_operation';
    case OperationConflict = 'operation_conflict';
    case PromotionUnavailable = 'promotion_unavailable';
    case PromotionChanged = 'promotion_changed';
    case PromotionCompleted = 'promotion_completed';
    case SaveRejected = 'save_rejected';
    case DatabaseFailed = 'database_failed';
    case UnexpectedFailure = 'unexpected_failure';

    /**
     * Returns the intrinsic severity of the classified cause.
     *
     * @return string PSR-3 severity.
     */
    public function logLevel(): string
    {
        return match ($this) {
            self::Forbidden, self::OperationConflict => 'warning',
            self::TransactionActive, self::SaveRejected, self::DatabaseFailed, self::UnexpectedFailure => 'error',
            default => 'notice',
        };
    }

    /**
     * Returns the stable event emitted only by the outcome owner.
     *
     * @return string|null Visit failure event.
     */
    public function eventName(): ?string // @phpstan-ignore return.unusedType (uniform failure catalog contract permits silent causes)
    {
        return match ($this) {
            self::SaveRejected, self::DatabaseFailed, self::UnexpectedFailure => 'visit.acceptance_failed',
            self::InvalidAttemptLimit => 'visit.acceptance_throttled',
            default => 'visit.rejected',
        };
    }
}
