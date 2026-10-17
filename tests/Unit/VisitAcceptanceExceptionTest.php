<?php

use App\Enums\FailureOutcome;
use App\Enums\VisitAcceptanceFailure;
use App\Exceptions\VisitAcceptanceException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the cause and consumed commit facts immutable and emits nothing during construction', function () {
    Log::spy();
    $failure = new VisitAcceptanceException(
        VisitAcceptanceFailure::InvalidAttemptLimit,
        context: ['business_id' => 7],
        retryAfterSeconds: 15,
    );

    expect($failure->getMessage())->toBe('invalid_attempt_limit');
    expect($failure->getCode())->toBe(0);
    expect($failure->getPrevious())->toBeNull();
    expect($failure->retryAfterSeconds)->toBe(15);
    expect(fn () => $failure->context['business_id'] = 9)->toThrow(Error::class);
    expect(fn () => $failure->providerConfirmed = true)->toThrow(Error::class);
    expect(VisitAcceptanceException::fromException($failure, true))->toBe($failure);
    Log::shouldNotHaveReceived('log');
});

it('drops native SQL messages bindings and causes while preserving full filtered source diagnostics', function (bool $providerConfirmed) {
    Log::spy();
    $native = new QueryException('pgsql', 'SELECT private_token FROM private_table WHERE token = ?', ['private-binding'], new PDOException('private-native'));
    $failure = VisitAcceptanceException::fromException($native, $providerConfirmed);

    expect($failure->failure)->toBe(VisitAcceptanceFailure::DatabaseFailed);
    expect($failure->providerConfirmed)->toBe($providerConfirmed);
    expect($failure->localOutcome)->toBe($providerConfirmed ? FailureOutcome::Unknown : FailureOutcome::Definitive);
    expect($failure->getPrevious())->toBeNull();
    expect($failure->context['previous_class'])->toBe(QueryException::class);
    expect($failure->context['source_file'])->toBe($native->getFile());
    expect($failure->context['source_line'])->toBe($native->getLine());
    expect($failure->context['source_frames'])->toHaveCount(count($native->getTrace()));
    foreach ($failure->context['source_frames'] as $frame) {
        expect(array_diff(array_keys($frame), ['file', 'line', 'class', 'function']))->toBeEmpty();
    }
    expect(json_encode($failure->context))->not->toContain('private_token', 'private_table', 'private-binding', 'private-native');
    Log::shouldNotHaveReceived('log');
})->with([false, true]);

it('classifies a native global UUID collision without retaining SQL or exposing the other identity', function () {
    $native = new UniqueConstraintViolationException(
        'pgsql', 'insert into visits (operation_id) values (?)', ['private-operation'], new PDOException('private collision'),
    );

    $failure = VisitAcceptanceException::fromException($native);

    expect($failure->failure)->toBe(VisitAcceptanceFailure::OperationConflict);
    expect($failure->localOutcome)->toBe(FailureOutcome::Definitive);
    expect($failure->getPrevious())->toBeNull();
    expect(json_encode($failure->context))->not->toContain('private-operation', 'private collision', 'insert into visits');
});

it('emits one selected event with authoritative reason and safe source evidence', function () {
    Log::spy();
    $previous = new RuntimeException('private cause message');
    $failure = new VisitAcceptanceException(
        VisitAcceptanceFailure::SaveRejected,
        previous: $previous,
        context: ['business_id' => 1, 'promotion_id' => 5],
    );

    $failure->log(['business_id' => 2, 'reason' => 'forged']);

    Log::shouldHaveReceived('log')->once()->with('error', 'visit.acceptance_failed', Mockery::on(
        fn ($context) => $context['business_id'] === 2 && $context['promotion_id'] === 5
            && $context['reason'] === 'save_rejected'
            && $context['previous_class'] === RuntimeException::class
            && $context['previous_file'] === $previous->getFile()
            && ! str_contains(json_encode($context), 'private cause message'),
    ));
});

it('propagates native logging failures without wrapping them', function () {
    $native = new RuntimeException('logging failed');
    Log::shouldReceive('log')->once()->andThrow($native);

    try {
        (new VisitAcceptanceException(VisitAcceptanceFailure::Forbidden))->log();
        test()->fail('Expected native logging failure.');
    } catch (RuntimeException $failure) {
        expect($failure)->toBe($native);
    }
});
