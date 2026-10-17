<?php

use App\Enums\PassFindingFailure;
use App\Exceptions\CustomerPassFindingException;
use App\Support\CustomerPassFinder;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

it('carries its typed reason and optional cause without logging during construction', function () {
    Log::spy();
    $previous = new RuntimeException('synthetic-private-message');

    $exception = new CustomerPassFindingException(PassFindingFailure::AuthoritativeReadFailed, $previous);

    expect($exception->failure)->toBe(PassFindingFailure::AuthoritativeReadFailed);
    expect($exception->getMessage())->toBe('authoritative_read_failed');
    expect($exception->getPrevious())->toBe($previous);
    expect($exception->retryAfterSeconds)->toBeNull();
    expect($exception)->toBeInstanceOf(ShouldntReport::class);
    Log::shouldNotHaveReceived('log');
});

it('keeps forbidden failures silent even with a previous cause', function () {
    Log::spy();
    $exception = new CustomerPassFindingException(PassFindingFailure::Forbidden, new RuntimeException('private'));

    $exception->log(['actor_id' => 7]);

    Log::shouldNotHaveReceived('log');
});

it('emits the catalog event with the supplied safe context and no invented diagnostics', function () {
    Log::spy();
    $exception = new CustomerPassFindingException(PassFindingFailure::InvalidCredential);

    $exception->log(['actor_id' => 7, 'business_id' => 11, 'outcome' => 'rejected']);

    Log::shouldHaveReceived('log')->once()->with('notice', 'customer_pass.lookup_rejected', [
        'reason' => 'invalid_credential',
        'actor_id' => 7,
        'business_id' => 11,
        'outcome' => 'rejected',
    ]);
});

it('emits only source metadata from a previous cause without messages arguments objects or chained causes', function () {
    Log::spy();
    $private = 'synthetic-private-argument';
    $previous = (static fn (string $value, object $object): RuntimeException => new RuntimeException(
        'synthetic-private-message',
        0,
        new RuntimeException('synthetic-private-cause'),
    ))($private, (object) ['private' => $private]);
    $exception = new CustomerPassFindingException(PassFindingFailure::AuthoritativeReadFailed, $previous);
    $payload = null;
    Log::shouldReceive('log')
        ->once()
        ->with('error', 'customer_pass.lookup_unavailable', Mockery::capture($payload));

    $exception->log(['actor_id' => 7]);

    expect($payload['reason'])->toBe('authoritative_read_failed');
    expect($payload['actor_id'])->toBe(7);
    expect($payload['previous_class'])->toBe(RuntimeException::class);
    expect($payload['previous_file'])->toBe($previous->getFile());
    expect($payload['previous_line'])->toBe($previous->getLine());
    expect($payload['previous_frames'])->toHaveCount(count($previous->getTrace()));
    expect($payload['previous_frames'])->not->toBeEmpty();
    foreach ($payload['previous_frames'] as $index => $frame) {
        expect(array_diff(array_keys($frame), ['file', 'line', 'class', 'function']))->toBeEmpty();
        foreach ($frame as $key => $value) {
            expect($value)->toBe($previous->getTrace()[$index][$key]);
        }
    }
    expect(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('synthetic-private');
});

it('constructs readonly retry and context facts and preserves them through native classification', function () {
    Log::spy();
    $exception = new CustomerPassFindingException(PassFindingFailure::InvalidAttemptLimit,
        context: ['actor_id' => 7], retryAfterSeconds: 42);

    expect($exception->retryAfterSeconds)->toBe(42);
    expect(fn () => $exception->retryAfterSeconds = 1)->toThrow(Error::class);
    expect(fn () => $exception->context['actor_id'] = 8)->toThrow(Error::class);
    expect(CustomerPassFindingException::fromException($exception))->toBe($exception);
    expect((new ReflectionClass(CustomerPassFinder::class))->hasMethod('reject'))->toBeFalse();
    Log::shouldNotHaveReceived('log');
});

it('propagates a native logging failure without substituting the classified rejection', function () {
    $loggingFailure = new RuntimeException('synthetic logging failure');
    Log::shouldReceive('log')->once()->andThrow($loggingFailure);
    $exception = new CustomerPassFindingException(PassFindingFailure::InvalidCredential);

    expect(fn () => $exception->log())->toThrow($loggingFailure);
});
