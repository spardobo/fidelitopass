<?php

namespace App\Exceptions;

use App\Enums\CustomerPassCreationFailure;
use App\Enums\FailureOutcome;
use App\Enums\GoogleWalletStage;
use App\Enums\PassCreationStage;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use PDOException;
use Random\RandomException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Carries immutable issuance facts and emits them only when its outcome owner requests logging. */
class CustomerPassCreationException extends RuntimeException
{
    /**
     * Stores the complete classification without retaining secret-bearing native causes.
     *
     * @param  CustomerPassCreationFailure  $failure  Application-owned cause.
     * @param  Throwable|null  $previous  Optional cause known to be safe to retain.
     * @param  array<string, mixed>  $context  Selected nonsecret evidence, never raw errors or operation objects.
     * @param  PassCreationStage  $stage  Last application boundary entered.
     * @param  FailureOutcome  $outcome  Certainty of issuance, independent of retry permission.
     * @param  GoogleWalletStage|null  $providerStage  Provider boundary when applicable.
     * @param  int|null  $providerStatus  Received HTTP status, not an assumed provider result.
     */
    public function __construct(
        public readonly CustomerPassCreationFailure $failure,
        ?Throwable $previous = null,
        public readonly array $context = [],
        public readonly PassCreationStage $stage = PassCreationStage::Preparation,
        public readonly FailureOutcome $outcome = FailureOutcome::Definitive,
        public readonly ?GoogleWalletStage $providerStage = null,
        public readonly ?int $providerStatus = null,
    ) {
        parent::__construct($failure->value, 0, $previous);
    }

    /**
     * Classifies a native failure using explicit attempt facts and discards its private payloads.
     *
     * @param  Throwable  $exception  Native or already classified failure.
     * @param  PassCreationStage  $stage  Last entered application boundary.
     * @param  GoogleWalletStage|null  $providerStage  Provider boundary, or null for local failures.
     * @param  string  $method  Actual provider HTTP operation, when applicable.
     * @param  array<string, mixed>  $context  Safe preceding write or confirmation evidence.
     * @return self Complete immutable classification; existing classifications are preserved unchanged.
     */
    public static function fromException(
        #[SensitiveParameter] Throwable $exception,
        PassCreationStage $stage,
        ?GoogleWalletStage $providerStage = null,
        string $method = '',
        array $context = [],
    ): self {
        if ($exception instanceof self) {
            return $exception;
        }

        $source = ['previous_class' => $exception::class, 'exception_class' => $exception::class];
        if ($providerStage !== null) {
            $status = match (true) {
                $exception instanceof GoogleServiceException => $exception->getCode(),
                $exception instanceof RequestException => $exception->getResponse()?->getStatusCode(),
                default => null,
            };
            $status = is_int($status) && $status >= 100 && $status <= 599 ? $status : null;
            $unknown = $providerStage === GoogleWalletStage::ObjectCreation && (
                $method === 'GET' || $status === null || $status < 400
                || in_array($status, [408, 409, 429], true) || $status >= 500
            );
            $failure = match (true) {
                $providerStage === GoogleWalletStage::ObjectCreation && $method === 'GET' => CustomerPassCreationFailure::ConfirmationUnavailable,
                $status !== null => CustomerPassCreationFailure::ProviderRejected,
                $exception instanceof GuzzleException => CustomerPassCreationFailure::TransportFailed,
                default => CustomerPassCreationFailure::RequestFailed,
            };

            return new self(
                $failure,
                context: ['http_method' => $method, ...$source, ...$context],
                stage: $stage,
                outcome: $unknown ? FailureOutcome::Unknown : FailureOutcome::Definitive,
                providerStage: $providerStage,
                providerStatus: $status,
            );
        }

        $failure = match (true) {
            $exception instanceof UniqueConstraintViolationException => CustomerPassCreationFailure::IdentityConflict,
            $exception instanceof ModelNotFoundException => CustomerPassCreationFailure::BusinessNotFound,
            $exception instanceof QueryException, $exception instanceof PDOException => CustomerPassCreationFailure::DatabaseFailed,
            $exception instanceof RandomException => CustomerPassCreationFailure::RandomnessUnavailable,
            default => CustomerPassCreationFailure::UnexpectedFailure,
        };
        if ($exception instanceof QueryException || $exception instanceof PDOException) {
            $source['sqlstate'] = $exception->errorInfo[0] ?? null;
        } elseif ($failure === CustomerPassCreationFailure::UnexpectedFailure) {
            $source += [
                'source_file' => $exception->getFile(),
                'source_line' => $exception->getLine(),
                'exception_frames' => array_map(
                    static fn (array $frame): array => Arr::only($frame, ['file', 'line', 'class', 'function']),
                    $exception->getTrace(),
                ),
            ];
        }

        return new self(
            $failure,
            context: [...$source, ...$context],
            stage: $stage,
            outcome: $stage === PassCreationStage::Preparation ? FailureOutcome::Definitive : FailureOutcome::Unknown,
        );
    }

    /**
     * Permits reacquisition only after a definitive failure outside a caller-owned transaction.
     *
     * @return bool Whether another issuance attempt is safe.
     */
    public function isRetryable(): bool
    {
        return $this->outcome === FailureOutcome::Definitive && $this->failure !== CustomerPassCreationFailure::TransactionActive;
    }

    /**
     * Emits stored evidence and explicit caller context without leaking native messages or arguments.
     *
     * @param  array<string, mixed>  $context  Safe outcome context; explicit values override stored context.
     *
     * @throws Throwable If native logging fails.
     */
    public function log(array $context = []): void
    {
        $eventName = $this->failure->eventName();
        if ($eventName === null) {
            return;
        }

        $payload = [
            'exception_class' => self::class,
            ...$this->context,
            ...$context,
            'reason' => $this->failure->value,
            'stage' => $this->stage->value,
            'outcome' => $this->outcome->value,
            'retry_allowed' => $this->isRetryable(),
            'provider_stage' => $this->providerStage?->value,
            'provider_status' => $this->providerStatus,
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
        $level = $this->outcome === FailureOutcome::Unknown ? 'error' : $this->failure->logLevel();
        if (
            $this->failure === CustomerPassCreationFailure::ProviderRejected
            && $this->outcome === FailureOutcome::Definitive
            && $this->providerStatus >= 400
            && $this->providerStatus < 500
        ) {
            $level = 'warning';
        }

        Log::log($level, $eventName, $payload);
    }
}
