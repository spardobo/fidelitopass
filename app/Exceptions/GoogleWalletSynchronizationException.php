<?php

namespace App\Exceptions;

use App\Enums\FailureOutcome;
use App\Enums\GoogleWalletStage;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Lang;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Describes an existing-pass synchronization failure without retaining a secret-bearing cause. */
class GoogleWalletSynchronizationException extends RuntimeException
{
    /**
     * Stores safe diagnostic facts separately from the translated cashier message.
     *
     * @param  GoogleWalletStage  $stage  Locally selected synchronization boundary.
     * @param  FailureOutcome  $outcome  Certainty of the remote write, not of the caller's transaction.
     * @param  string  $reason  Application-owned failure code.
     * @param  int|null  $providerStatus  Received HTTP status when available.
     */
    public function __construct(
        public readonly GoogleWalletStage $stage,
        public readonly FailureOutcome $outcome,
        public readonly string $reason,
        public readonly ?int $providerStatus = null,
    ) {
        parent::__construct('Google Wallet synchronization failed.');
    }

    /**
     * Classifies one failure without copying provider text, credentials or exception arguments.
     *
     * @param  Throwable  $exception  Native or already classified failure.
     * @param  GoogleWalletStage  $stage  Last boundary entered by the updater.
     * @param  bool  $deadlineExceeded  Whether the operation's monotonic budget expired.
     * @return self Safe failure that must escape the caller-owned transaction.
     */
    public static function fromFailure(#[SensitiveParameter] Throwable $exception, GoogleWalletStage $stage, bool $deadlineExceeded): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        if ($exception instanceof CustomerPassCreationException) {
            return new self(
                $exception->providerStage ?? $stage,
                FailureOutcome::Definitive,
                $deadlineExceeded ? 'deadline_exceeded' : $exception->reason,
                $exception->providerStatus,
            );
        }

        $status = match (true) {
            $exception instanceof GoogleServiceException => $exception->getCode(),
            $exception instanceof RequestException => $exception->getResponse()?->getStatusCode(),
            default => null,
        };
        $status = is_int($status) && $status >= 100 && $status <= 599 ? $status : null;
        $unknown = $stage === GoogleWalletStage::Confirmation || ($stage === GoogleWalletStage::ObjectUpdate && (
            $status === null || $status < 400 || in_array($status, [408, 409, 429], true) || $status >= 500
        ));

        return new self(
            $stage,
            $unknown ? FailureOutcome::Unknown : FailureOutcome::Definitive,
            $deadlineExceeded ? 'deadline_exceeded' : ($status === null ? 'request_failed' : 'provider_rejected'),
            $status,
        );
    }

    /**
     * Returns domain-friendly copy without claiming a rollback or exposing provider diagnostics.
     *
     * @return string Application-locale message for the future cashier operation boundary.
     */
    public function userMessage(): string
    {
        return Lang::string('wallet-synchronization.failed');
    }
}
