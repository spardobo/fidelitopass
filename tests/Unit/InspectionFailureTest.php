<?php

use App\Enums\InspectionFailure;
use App\Exceptions\CustomerPassInspectionException;

it('provides one typed inspection failure catalog for caller and operational metadata', function (
    string $code,
    int $status,
    string $level,
    ?string $event,
    string $outcome,
) {
    $failure = InspectionFailure::from($code);
    $exception = new CustomerPassInspectionException($failure);

    expect($failure->httpStatus())->toBe($status);
    expect($failure->logLevel())->toBe($level);
    expect($failure->eventName())->toBe($event);
    expect($failure->outcome())->toBe($outcome);
    expect($exception->failure)->toBe($failure);
    expect($exception->retryAfterSeconds)->toBeNull();
    expect($exception->getMessage())->toBe('Customer pass inspection failed.');
    expect($exception->getPrevious())->toBeNull();
})->with([
    'forbidden' => ['forbidden', 403, 'warning', null, 'forbidden'],
    'invalid credential' => ['invalid_credential', 404, 'notice', 'customer_pass.lookup_rejected', 'rejected'],
    'attempt limit' => ['invalid_attempt_limit', 429, 'notice', 'customer_pass.lookup_throttled', 'rejected'],
    'read failure' => ['authoritative_read_failed', 503, 'error', 'customer_pass.lookup_unavailable', 'unavailable'],
    'ambiguous active Promotion' => ['ambiguous_active_promotion', 503, 'error', 'customer_pass.lookup_unavailable', 'unavailable'],
]);
