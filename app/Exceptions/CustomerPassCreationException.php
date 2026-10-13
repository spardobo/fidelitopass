<?php

namespace App\Exceptions;

use App\Enums\FailureOutcome;
use App\Enums\GoogleWalletStage;
use App\Enums\PassCreationStage;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use PDOException;
use Random\RandomException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Describes an issuance failure without retaining provider bodies, SQL bindings or its cause. */
class CustomerPassCreationException extends RuntimeException
{
    /**
     * Stores the safe facts used by the HTTP response and application log.
     *
     * @param  PassCreationStage  $stage  Application boundary that failed.
     * @param  FailureOutcome  $outcome  Whether the issuance result remains unknown.
     * @param  string  $reason  Application-owned reason, never an external error message.
     * @param  GoogleWalletStage|null  $providerStage  Google step, when applicable.
     * @param  int|null  $providerStatus  Received HTTP status, when available.
     * @param  array<string, mixed>  $diagnostics  Selected safe metadata, without secrets or exceptions.
     */
    public function __construct(
        public readonly PassCreationStage $stage,
        public readonly FailureOutcome $outcome,
        public readonly string $reason,
        public readonly ?GoogleWalletStage $providerStage = null,
        public readonly ?int $providerStatus = null,
        private readonly array $diagnostics = [],
    ) {
        parent::__construct('Customer pass creation failed.');
    }

    /**
     * Describes a failure before provider work starts.
     *
     * @param  string  $reason  Local diagnostic code.
     * @param  array<string, mixed>  $diagnostics  Safe source metadata.
     * @return self Definitive preparation failure.
     */
    public static function preparationFailed(string $reason, array $diagnostics = []): self
    {
        return new self(
            PassCreationStage::Preparation,
            FailureOutcome::Definitive,
            $reason,
            diagnostics: $diagnostics,
        );
    }

    /**
     * Describes a local failure after Google has accepted the object.
     *
     * @param  string  $reason  Local diagnostic code.
     * @return self Unresolved issuance; a local rollback cannot undo Google creation.
     */
    public static function persistenceFailed(string $reason): self
    {
        return new self(PassCreationStage::Local, FailureOutcome::Unknown, $reason);
    }

    /**
     * Describes an explicit rejection at a Google boundary.
     *
     * @param  GoogleWalletStage  $stage  Configuration, OAuth, class or object check.
     * @param  string  $reason  Locally selected diagnostic code.
     * @param  array<string, mixed>  $diagnostics  Safe method or binding names, never binding values.
     * @return self Definitive provider-boundary rejection.
     */
    public static function providerRejected(GoogleWalletStage $stage, string $reason, array $diagnostics = []): self
    {
        return new self(
            PassCreationStage::Provider,
            FailureOutcome::Definitive,
            $reason,
            $stage,
            diagnostics: $diagnostics,
        );
    }

    /**
     * Classifies a failed SDK request without retaining its body or exception chain.
     *
     * @param  Throwable  $exception  SDK or transport failure that may contain secrets.
     * @param  GoogleWalletStage  $stage  Provider boundary being executed.
     * @param  string  $method  Locally selected HTTP method.
     * @param  array<string, mixed>  $context  Safe evidence from an earlier object insertion.
     * @return self Classified failure; an uncertain write never permits automatic reissuance.
     */
    public static function fromProvider(
        #[SensitiveParameter] Throwable $exception,
        GoogleWalletStage $stage,
        string $method,
        array $context = [],
    ): self {
        // classify the received response, not an assumed rollback
        $status = match (true) {
            $exception instanceof GoogleServiceException => $exception->getCode(),
            $exception instanceof RequestException => $exception->getResponse()?->getStatusCode(),
            default => null,
        };
        $status = is_int($status) && $status >= 100 && $status <= 599 ? $status : null;
        $unknown = $stage === GoogleWalletStage::ObjectCreation
            && (
                $method === 'GET'
                || $status === null
                || $status < 400
                || in_array($status, [408, 409, 429], true)
                || $status >= 500
            );

        // keep diagnostic codes while dropping provider messages and payloads
        $reason = match (true) {
            $stage === GoogleWalletStage::ObjectCreation && $method === 'GET' => 'confirmation_unavailable',
            $status !== null => 'provider_rejected',
            $exception instanceof GuzzleException => 'transport_failed',
            default => 'request_failed',
        };

        return new self(
            PassCreationStage::Provider,
            $unknown ? FailureOutcome::Unknown : FailureOutcome::Definitive,
            $reason,
            $stage,
            $status,
            [
                'http_method' => $method,
                'exception_class' => $exception::class,
                ...$context,
            ],
        );
    }

    /**
     * Sanitizes local or unexpected failures at the action's single catch boundary.
     *
     * @param  Throwable  $exception  Failure whose message, arguments and cause must not be retained.
     * @param  PassCreationStage  $stage  Last application step entered.
     * @return self Existing classified failure or a safe description of an unexpected failure.
     */
    public static function fromFailure(#[SensitiveParameter] Throwable $exception, PassCreationStage $stage): self
    {
        // retain a boundary decision that has already been made
        if ($exception instanceof self) {
            return $exception;
        }

        // classify local errors without copying SQL bindings or exception arguments
        $reason = match (true) {
            $exception instanceof UniqueConstraintViolationException => 'identity_conflict',
            $exception instanceof ModelNotFoundException => 'business_not_found',
            $exception instanceof QueryException, $exception instanceof PDOException => 'database_failed',
            $exception instanceof RandomException => 'randomness_unavailable',
            default => 'unexpected_failure',
        };
        $diagnostics = ['exception_class' => $exception::class];

        if ($exception instanceof QueryException || $exception instanceof PDOException) {
            $diagnostics['sqlstate'] = $exception->errorInfo[0] ?? null;
        } elseif ($reason === 'unexpected_failure') {
            $diagnostics['source_file'] = $exception->getFile();
            $diagnostics['source_line'] = $exception->getLine();
            $diagnostics['exception_frames'] = array_map(
                static fn (array $frame): array => array_intersect_key(
                    $frame,
                    array_flip(['file', 'line', 'class', 'function']),
                ),
                $exception->getTrace(),
            );
        }

        return new self(
            $stage,
            $stage === PassCreationStage::Preparation ? FailureOutcome::Definitive : FailureOutcome::Unknown,
            $reason,
            diagnostics: $diagnostics,
        );
    }

    /**
     * Reports whether another issuance attempt is permitted.
     *
     * @return bool False for an unresolved issuance or a caller-owned transaction.
     */
    public function canRetry(): bool
    {
        return $this->outcome === FailureOutcome::Definitive && $this->reason !== 'transaction_active';
    }

    /**
     * Distinguishes known rejections from infrastructure failures.
     *
     * @return bool Whether warning-level logging is appropriate.
     */
    public function isExpectedRejection(): bool
    {
        return $this->outcome === FailureOutcome::Definitive
            && (
                in_array($this->reason, ['transaction_active', 'binding_conflict'], true)
                || ($this->reason === 'provider_rejected'
                    && $this->providerStatus >= 400
                    && $this->providerStatus < 500)
            );
    }

    /**
     * Returns diagnostic metadata without credentials or exception arguments.
     *
     * @return array<string, mixed> Safe facts for the operation log.
     */
    public function context(): array
    {
        return [
            'stage' => $this->stage->value,
            'outcome' => $this->outcome->value,
            'reason' => $this->reason,
            'retry_allowed' => $this->canRetry(),
            'provider_stage' => $this->providerStage?->value,
            'provider_status' => $this->providerStatus,
            'exception_class' => self::class,
            ...$this->diagnostics,
        ];
    }
}
