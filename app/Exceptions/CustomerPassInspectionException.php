<?php

namespace App\Exceptions;

use App\Enums\InspectionFailure;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Carries a typed inspection failure and emits its safe operational event on request.
 *
 * The inspection boundary owns logging. Secret-bearing native causes must be
 * omitted; that boundary supplies only their selected safe source metadata.
 */
class CustomerPassInspectionException extends RuntimeException implements ShouldntReport
{
    /**
     * Seconds the caller should wait before retrying, or null when not throttled.
     */
    public ?int $retryAfterSeconds = null;

    /**
     * Builds the failure with an optional cause known to be safe to retain.
     *
     * @param  InspectionFailure  $failure  Typed reason propagated to the caller.
     * @param  Throwable|null  $previous  Optional non-secret-bearing native cause.
     */
    public function __construct(
        public readonly InspectionFailure $failure,
        ?Throwable $previous = null,
    ) {
        parent::__construct($failure->value, 0, $previous);
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
            'reason' => $this->failure->value,
            ...$context,
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
