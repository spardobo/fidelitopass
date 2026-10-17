<?php

namespace App\Exceptions;

use App\Enums\FailureOutcome;
use App\Enums\VisitAcceptanceFailure;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use PDOException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Carries safe immutable Visit failure evidence without promising a local or remote rollback. */
class VisitAcceptanceException extends RuntimeException implements ShouldntReport
{
    /**
     * Stores the cause, selected diagnostics and independently known operation facts.
     *
     * @param  VisitAcceptanceFailure  $failure  Application-owned cause.
     * @param  Throwable|null  $previous  Optional safe cause, never a native SQL or provider failure.
     * @param  array<string, mixed>  $context  Selected nonsecret context.
     * @param  bool  $providerConfirmed  Whether this invocation confirmed Google before local completion failed.
     * @param  FailureOutcome  $localOutcome  Whether local nonacceptance is definite or commit is unresolved.
     * @param  int|null  $retryAfterSeconds  Existing invalid-lookup window delay, otherwise null.
     */
    public function __construct(
        public readonly VisitAcceptanceFailure $failure,
        ?Throwable $previous = null,
        public readonly array $context = [],
        public readonly bool $providerConfirmed = false,
        public readonly FailureOutcome $localOutcome = FailureOutcome::Definitive,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($failure->value, 0, $previous);
    }

    /**
     * Discards secret-bearing native causes while retaining safe source and commit evidence.
     *
     * @param  Throwable  $exception  Native or already classified Visit failure.
     * @param  bool  $providerConfirmed  Whether Google returned confirmation before the local failure.
     * @return self Complete classification; same-domain exceptions remain unchanged.
     */
    public static function fromException(#[SensitiveParameter] Throwable $exception, bool $providerConfirmed = false): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        $databaseFailure = $exception instanceof QueryException || $exception instanceof PDOException;

        return new self(
            match (true) {
                $exception instanceof UniqueConstraintViolationException => VisitAcceptanceFailure::OperationConflict,
                $databaseFailure => VisitAcceptanceFailure::DatabaseFailed,
                default => VisitAcceptanceFailure::UnexpectedFailure,
            },
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
     * Emits the outcome owner's safe event without inferring retry permission from remote certainty.
     *
     * @param  array<string, mixed>  $context  Explicit nonsecret operation context overriding stored context.
     *
     * @throws Throwable If native logging fails.
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
            'outcome' => $this->localOutcome === FailureOutcome::Unknown ? 'unresolved' : 'not_accepted',
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
