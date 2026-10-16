<?php

use App\Enums\CustomerPassCreationFailure;
use App\Enums\FailureOutcome;
use App\Enums\GoogleWalletStage;
use App\Enums\PassCreationStage;
use App\Exceptions\CustomerPassCreationException;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

it('constructs complete immutable facts without logging and keeps retry separate from certainty', function () {
    Log::spy();
    $previous = new RuntimeException('private cause');
    $exception = new CustomerPassCreationException(
        CustomerPassCreationFailure::TransactionActive,
        context: ['business_id' => 7],
        previous: $previous,
    );

    expect($exception->failure)->toBe(CustomerPassCreationFailure::TransactionActive);
    expect($exception->getMessage())->toBe('transaction_active');
    expect($exception->getCode())->toBe(0);
    expect($exception->getPrevious())->toBe($previous);
    expect($exception->stage)->toBe(PassCreationStage::Preparation);
    expect($exception->outcome)->toBe(FailureOutcome::Definitive);
    expect($exception->isRetryable())->toBeFalse();
    expect($exception->context)->toBe(['business_id' => 7]);
    expect(fn () => $exception->failure = CustomerPassCreationFailure::ManualCodeExhausted)->toThrow(Error::class);
    expect(fn () => $exception->context['business_id'] = 8)->toThrow(Error::class);
    expect(fn () => $exception->outcome = FailureOutcome::Unknown)->toThrow(Error::class);
    Log::shouldNotHaveReceived('log');
});

it('logs stored context then caller context with an authoritative reason', function () {
    $exception = new CustomerPassCreationException(CustomerPassCreationFailure::TransactionActive, context: [
        'business_id' => 7, 'operation_id' => 'operation', 'reason' => 'incorrect',
    ]);
    $payload = null;
    Log::shouldReceive('log')->once()->with('warning', 'customer_pass.creation.failed', Mockery::capture($payload));

    $exception->log(['business_id' => 8, 'reason' => 'also incorrect']);

    expect($payload)->toMatchArray([
        'business_id' => 8, 'operation_id' => 'operation', 'reason' => 'transaction_active',
        'stage' => 'preparation', 'outcome' => 'definitive', 'retry_allowed' => false,
        'provider_stage' => null, 'provider_status' => null,
    ]);
});

it('filters every retained previous frame without copying messages arguments objects or causes', function () {
    $previous = (static fn (string $secret, object $object): RuntimeException => new RuntimeException(
        'private message', 0, new RuntimeException('private nested cause'),
    ))('private argument', (object) ['secret' => 'private object']);
    $exception = new CustomerPassCreationException(CustomerPassCreationFailure::UnexpectedFailure, previous: $previous);
    $payload = null;
    Log::shouldReceive('log')->once()->with('error', 'customer_pass.creation.failed', Mockery::capture($payload));

    $exception->log(['business_id' => 7]);

    expect($payload['previous_class'])->toBe(RuntimeException::class);
    expect($payload['previous_file'])->toBe($previous->getFile());
    expect($payload['previous_line'])->toBe($previous->getLine());
    expect($payload['previous_frames'])->toHaveCount(count($previous->getTrace()));
    foreach ($payload['previous_frames'] as $index => $frame) {
        expect(array_diff(array_keys($frame), ['file', 'line', 'class', 'function']))->toBeEmpty();
        foreach ($frame as $key => $value) {
            expect($value)->toBe($previous->getTrace()[$index][$key]);
        }
    }
    expect(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('private ');
});

it('classifies provider evidence once without logging or retaining secret-bearing causes', function (GoogleWalletStage $stage, string $method, int $status, FailureOutcome $outcome, string $level) {
    Log::spy();
    $native = new GoogleServiceException('private provider body', $status);

    $exception = CustomerPassCreationException::fromException(
        $native, PassCreationStage::Provider, $stage, $method, ['provider_attempt' => ['http_method' => 'POST']],
    );

    expect($exception->failure)->toBe($method === 'GET' && $stage === GoogleWalletStage::ObjectCreation
        ? CustomerPassCreationFailure::ConfirmationUnavailable : CustomerPassCreationFailure::ProviderRejected);
    expect($exception->stage)->toBe(PassCreationStage::Provider);
    expect($exception->outcome)->toBe($outcome);
    expect($exception->providerStage)->toBe($stage);
    expect($exception->providerStatus)->toBe($status);
    expect($exception->getPrevious())->toBeNull();
    expect($exception->context['previous_class'])->toBe($native::class);
    expect($exception->context['provider_attempt'])->toBe(['http_method' => 'POST']);
    expect(json_encode($exception->context))->not->toContain('private provider body');
    expect(CustomerPassCreationException::fromException($exception, PassCreationStage::Local))->toBe($exception);
    Log::shouldNotHaveReceived('log');

    $exception->log();

    Log::shouldHaveReceived('log')->once()->with($level, 'customer_pass.creation.failed', Mockery::any());
})->with([
    [GoogleWalletStage::ClassDefinition, 'POST', 503, FailureOutcome::Definitive, 'error'],
    [GoogleWalletStage::ClassDefinition, 'GET', 400, FailureOutcome::Definitive, 'warning'],
    [GoogleWalletStage::ObjectCreation, 'POST', 400, FailureOutcome::Definitive, 'warning'],
    [GoogleWalletStage::ObjectCreation, 'POST', 503, FailureOutcome::Unknown, 'error'],
    [GoogleWalletStage::ObjectCreation, 'GET', 400, FailureOutcome::Unknown, 'error'],
]);

it('keeps SQLSTATE but discards native SQL and bindings at construction', function () {
    $cause = new PDOException('private driver detail');
    $cause->errorInfo = ['23505', 7, 'private diagnostic'];
    $native = new QueryException('pgsql', 'select private SQL', ['private binding'], $cause);

    $exception = CustomerPassCreationException::fromException($native, PassCreationStage::Local);

    expect($exception->failure)->toBe(CustomerPassCreationFailure::DatabaseFailed);
    expect($exception->outcome)->toBe(FailureOutcome::Unknown);
    expect($exception->context)->toMatchArray(['previous_class' => QueryException::class, 'sqlstate' => '23505']);
    expect($exception->getPrevious())->toBeNull();
    expect(json_encode($exception->context))->not->toContain('private');
});

it('propagates logging failures natively', function () {
    $native = new RuntimeException('sink unavailable');
    Log::shouldReceive('log')->once()->andThrow($native);
    $exception = new CustomerPassCreationException(CustomerPassCreationFailure::TransactionActive);

    expect(fn () => $exception->log())->toThrow($native);
});
