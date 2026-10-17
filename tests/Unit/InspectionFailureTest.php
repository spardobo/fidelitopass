<?php

use App\Enums\InspectionFailure;

it('provides the closed inspection reason catalog and its operational metadata', function (
    string $code,
    string $level,
    ?string $event,
) {
    $failure = InspectionFailure::from($code);

    expect($failure->value)->toBe($code);
    expect($failure->logLevel())->toBe($level);
    expect($failure->eventName())->toBe($event);
})->with([
    'forbidden' => ['forbidden', 'warning', null],
    'invalid credential' => ['invalid_credential', 'notice', 'customer_pass.lookup_rejected'],
    'attempt limit' => ['invalid_attempt_limit', 'notice', 'customer_pass.lookup_throttled'],
    'read failure' => ['authoritative_read_failed', 'error', 'customer_pass.lookup_unavailable'],
    'ambiguous active Promotion' => ['ambiguous_active_promotion', 'error', 'customer_pass.lookup_unavailable'],
]);
