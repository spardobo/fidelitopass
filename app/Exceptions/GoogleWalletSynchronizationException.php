<?php

namespace App\Exceptions;

use App\Enums\FailureOutcome;
use App\Enums\GoogleWalletStage;
use App\Enums\GoogleWalletSynchronizationFailure;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Carries immutable synchronization evidence without deciding or logging the caller's transaction. */
class GoogleWalletSynchronizationException extends RuntimeException
{
    /**
     * Stores the classified cause and actual remote-operation facts.
     *
     * @param  GoogleWalletSynchronizationFailure  $failure  Application-owned cause.
     * @param  Throwable|null  $previous  Optional safe native cause.
     * @param  array<string, mixed>  $context  Selected nonsecret evidence, never raw provider errors.
     * @param  GoogleWalletStage  $stage  Last integration boundary entered.
     * @param  FailureOutcome  $outcome  Certainty of the remote write, not local commit.
     * @param  int|null  $providerStatus  Received HTTP status when available.
     */
    public function __construct(
        public readonly GoogleWalletSynchronizationFailure $failure,
        ?Throwable $previous = null,
        public readonly array $context = [],
        public readonly GoogleWalletStage $stage = GoogleWalletStage::Preparation,
        public readonly FailureOutcome $outcome = FailureOutcome::Definitive,
        public readonly ?int $providerStatus = null,
    ) {
        parent::__construct($failure->value, 0, $previous);
    }

    /**
     * Classifies native request evidence without retaining bodies, credentials or native causes.
     *
     * @param  Throwable  $exception  Native or already classified synchronization failure.
     * @param  GoogleWalletStage  $stage  Last operation boundary entered.
     * @param  bool  $deadlineExceeded  Whether the shared monotonic budget has expired.
     * @param  array<string, mixed>  $context  Explicit safe request evidence.
     * @return self Complete failure; an existing classification is returned unchanged.
     */
    public static function fromException(
        #[SensitiveParameter] Throwable $exception,
        GoogleWalletStage $stage,
        bool $deadlineExceeded,
        array $context = [],
    ): self {
        if ($exception instanceof self) {
            return $exception;
        }

        $status = match (true) {
            $exception instanceof GoogleServiceException => $exception->getCode(),
            $exception instanceof RequestException => $exception->getResponse()?->getStatusCode(),
            default => null,
        };
        $status = is_int($status) && $status >= 100 && $status <= 599 ? $status : null;
        $unknown = $stage === GoogleWalletStage::Confirmation || (in_array($stage, [GoogleWalletStage::ObjectUpdate, GoogleWalletStage::ClassUpdate], true) && (
            $status === null || $status < 400 || in_array($status, [408, 409, 429], true) || $status >= 500
        ));

        $failure = match (true) {
            $deadlineExceeded => GoogleWalletSynchronizationFailure::DeadlineExceeded,
            $stage === GoogleWalletStage::OAuth && $status === null && $exception instanceof GuzzleException => GoogleWalletSynchronizationFailure::TransportFailed,
            $status === null => GoogleWalletSynchronizationFailure::RequestFailed,
            default => GoogleWalletSynchronizationFailure::ProviderRejected,
        };

        return new self(
            $failure,
            context: ['previous_class' => $exception::class, ...$context],
            stage: $stage,
            outcome: $unknown ? FailureOutcome::Unknown : FailureOutcome::Definitive,
            providerStatus: $status,
        );
    }

    /**
     * Emits only an owned event with stored context and safe retained-cause source frames.
     *
     * @param  array<string, mixed>  $context  Explicit safe outcome context overriding stored context.
     *
     * @throws Throwable If native logging fails.
     */
    public function log(array $context = []): void
    {
        $eventName = $this->failure->eventName();
        if ($eventName === null) {
            return;
        }

        $payload = [...$this->context, ...$context, 'reason' => $this->failure->value];
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

        Log::log($this->failure->logLevel(), $eventName, $payload);
    }
}
