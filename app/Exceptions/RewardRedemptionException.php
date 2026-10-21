<?php

namespace App\Exceptions;

use App\Enums\FailureOutcome;
use App\Enums\RewardRedemptionFailure;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use PDOException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Carries sanitized redemption failure evidence without promising remote rollback. */
class RewardRedemptionException extends RuntimeException implements ShouldntReport
{
    /**
     * Stores the classified cause and independently known completion evidence.
     *
     * @param  RewardRedemptionFailure  $failure  Closed cause.
     * @param  Throwable|null  $previous  Optional safe cause.
     * @param  array<string, mixed>  $context  Selected nonsecret evidence.
     * @param  bool  $providerConfirmed  Whether Google confirmed before completion failed.
     * @param  FailureOutcome  $localOutcome  Whether the local outcome is unresolved.
     * @param  int|null  $retryAfterSeconds  Existing invalid-attempt window delay.
     */
    public function __construct(
        public readonly RewardRedemptionFailure $failure,
        ?Throwable $previous = null,
        public readonly array $context = [],
        public readonly bool $providerConfirmed = false,
        public readonly FailureOutcome $localOutcome = FailureOutcome::Definitive,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($failure->value, 0, $previous);
    }

    /**
     * Discards private native payloads while retaining safe source and commit evidence.
     *
     * @param  Throwable  $exception  Native or classified failure.
     * @param  bool  $providerConfirmed  Whether the provider confirmed this invocation.
     * @return self Complete safe classification.
     */
    public static function fromException(#[SensitiveParameter] Throwable $exception, bool $providerConfirmed = false): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        $databaseFailure = $exception instanceof QueryException || $exception instanceof PDOException;

        return new self(
            $databaseFailure ? RewardRedemptionFailure::DatabaseFailed : RewardRedemptionFailure::UnexpectedFailure,
            context: [
                'previous_class' => $exception::class,
                'source_file' => $exception->getFile(),
                'source_line' => $exception->getLine(),
                'source_frames' => array_map(
                    static fn (array $frame): array => Arr::only($frame, ['file', 'line', 'class', 'function']),
                    $exception->getTrace(),
                ),
                'sqlstate' => $databaseFailure ? ($exception->errorInfo[0] ?? null) : null,
            ],
            providerConfirmed: $providerConfirmed,
            localOutcome: $providerConfirmed ? FailureOutcome::Unknown : FailureOutcome::Definitive,
        );
    }

    /**
     * Emits one safe outcome at its owning boundary.
     *
     * @param  array<string, mixed>  $context  Trusted operation identities.
     */
    public function log(array $context = []): void
    {
        $event = $this->failure->eventName();
        if ($event === null) {
            return;
        }

        $payload = [
            ...$this->context,
            ...$context,
            'reason' => $this->failure->value,
            'provider_confirmed' => $this->providerConfirmed,
            'local_outcome' => $this->localOutcome->value,
            'outcome' => $this->localOutcome === FailureOutcome::Unknown ? 'unresolved' : 'not_redeemed',
            'retry_after_seconds' => $this->retryAfterSeconds,
        ];
        $previous = $this->getPrevious();
        if ($previous !== null) {
            $payload['previous_class'] = $previous::class;
            $payload['previous_file'] = $previous->getFile();
            $payload['previous_line'] = $previous->getLine();
            $payload['previous_frames'] = array_map(
                static fn (array $frame): array => Arr::only($frame, ['file', 'line', 'class', 'function']),
                $previous->getTrace(),
            );
        }

        Log::log($this->failure->logLevel(), $event, $payload);

    }
}
