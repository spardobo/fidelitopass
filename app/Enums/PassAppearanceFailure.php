<?php

namespace App\Enums;

/** Identifies local appearance-save failures without retaining database diagnostics. */
enum PassAppearanceFailure: string
{
    case PersistenceFailed = 'persistence_failed';
    case SaveRejected = 'save_rejected';

    /**
     * Returns the operational severity of a failed save.
     *
     * @return string PSR-3 severity.
     */
    public function logLevel(): string
    {
        return 'error';
    }

    /**
     * Names the event emitted once by the appearance Action.
     *
     * @return string|null Owned save-failure event.
     */
    public function eventName(): ?string // @phpstan-ignore return.unusedType (uniform failure catalog contract permits silent causes)
    {
        return 'pass_appearance.save_failed';
    }
}
