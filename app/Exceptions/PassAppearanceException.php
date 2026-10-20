<?php

namespace App\Exceptions;

use App\Enums\PassAppearanceFailure;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Carries classified local save failures already handled by the appearance outcome owner. */
class PassAppearanceException extends RuntimeException implements ShouldntReport
{
    /**
     * Stores only classified, nonsecret persistence evidence.
     *
     * @param  PassAppearanceFailure  $failure  Owned local failure cause.
     * @param  array<string, mixed>  $context  Selected safe evidence, never SQL or native exceptions.
     */
    public function __construct(
        public readonly PassAppearanceFailure $failure,
        public readonly array $context = [],
    ) {
        parent::__construct($failure->value);
    }

    /**
     * Emits the Action-owned failure without raw database evidence.
     *
     * @param  array<string, mixed>  $context  Explicit outcome context overriding stored facts.
     */
    public function log(array $context = []): void
    {
        $eventName = $this->failure->eventName();
        if ($eventName === null) {
            return;
        }

        Log::log($this->failure->logLevel(), $eventName, [
            ...$this->context,
            ...$context,
            'reason' => $this->failure->value,
        ]);
    }
}
