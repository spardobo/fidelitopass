<?php

namespace App\Exceptions;

use App\Enums\VisitRegistrationFailure;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Carries only classified client observations, without native browser error payloads. */
class VisitRegistrationException extends RuntimeException implements ShouldntReport
{
    /**
     * Stores a closed observation and explicitly selected safe metadata.
     *
     * @param  VisitRegistrationFailure  $failure  Presentation-owned identity.
     * @param  array<string, mixed>  $context  Validated observation metadata, never native errors.
     */
    public function __construct(
        public readonly VisitRegistrationFailure $failure,
        public readonly array $context = [],
    ) {
        parent::__construct($failure->value, 0);
    }

    /**
     * Emits one client observation without claiming an authoritative domain outcome.
     *
     * @param  array<string, mixed>  $context  Trusted actor and Business identities.
     */
    public function log(array $context = []): void
    {
        $event = $this->failure->eventName();
        if ($event === null) {
            return;
        }

        Log::log($this->failure->logLevel(), $event, [
            ...$this->context,
            ...$context,
            'reason' => $this->failure->value,
            'source' => 'client_observation',
        ]);

    }
}
