<?php

namespace App\Exceptions;

use App\Enums\PassFindingFailure;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Carries a typed pass-finding failure and emits its safe operational event on request.
 *
 * The finder boundary owns logging. Secret-bearing native causes must be
 * omitted; that boundary supplies only their selected safe source metadata.
 */
class CustomerPassFindingException extends RuntimeException implements ShouldntReport
{
    /**
     * Builds the failure with an optional cause known to be safe to retain.
     *
     * @param  PassFindingFailure  $failure  Typed reason propagated to the caller.
     * @param  Throwable|null  $previous  Optional non-secret-bearing native cause.
     * @param  array<string, mixed>  $context  Selected safe source or operation evidence.
     * @param  int|null  $retryAfterSeconds  Seconds to wait when throttled, otherwise null.
     */
    public function __construct(
        public readonly PassFindingFailure $failure,
        ?Throwable $previous = null,
        public readonly array $context = [],
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($failure->value, 0, $previous);
    }

    /**
     * Classifies an authoritative-read failure without retaining SQL, bindings or native causes.
     *
     * @param  Throwable  $exception  Native read failure or already classified pass-finding failure.
     * @return self Immutable safe evidence; existing classifications are preserved.
     */
    public static function fromException(#[SensitiveParameter] Throwable $exception): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        return new self(PassFindingFailure::AuthoritativeReadFailed, context: [
            'previous_class' => $exception::class,
            'exception_class' => $exception::class,
            'source_file' => $exception->getFile(),
            'source_line' => $exception->getLine(),
            'exception_frames' => array_map(
                static fn (array $frame): array => Arr::only($frame, ['file', 'line', 'class', 'function']),
                $exception->getTrace(),
            ),
        ]);
    }

    /**
     * Emits the operational event with explicit safe context and source-only diagnostics.
     *
     * @param  array<string, mixed>  $context  Nonsecret context selected by the outcome owner.
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
            ...$this->context,
            ...$context,
            'reason' => $this->failure->value,
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

        Log::log($this->failure->logLevel(), $eventName, $payload);
    }
}
