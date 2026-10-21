<?php

namespace App\Enums;

/** Identifies redemption causes independently of provider and commit certainty. */
enum RewardRedemptionFailure: string
{
    case Forbidden = 'forbidden';
    case InvalidCredential = 'invalid_credential';
    case InvalidAttemptLimit = 'invalid_attempt_limit';
    case TransactionActive = 'transaction_active';
    case TargetUnavailable = 'target_unavailable';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case SaveRejected = 'save_rejected';
    case DatabaseFailed = 'database_failed';
    case UnexpectedFailure = 'unexpected_failure';

    /**
     * Selects the intrinsic operational severity.
     *
     * @return string PSR log level.
     */
    public function logLevel(): string
    {
        return match ($this) {
            self::DatabaseFailed, self::UnexpectedFailure, self::SaveRejected, self::TransactionActive => 'error',
            self::Forbidden => 'warning',
            default => 'notice',
        };
    }

    /**
     * Selects the event owned by the redemption boundary.
     *
     * @return string|null Safe event name.
     */
    public function eventName(): ?string // @phpstan-ignore return.unusedType (uniform failure catalog contract permits silent causes)
    {
        return 'reward.redemption_failed';
    }
}
