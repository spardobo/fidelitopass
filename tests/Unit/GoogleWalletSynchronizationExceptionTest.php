<?php

use App\Enums\FailureOutcome;
use App\Enums\GoogleWalletStage;
use App\Enums\GoogleWalletSynchronizationFailure;
use App\Exceptions\GoogleWalletSynchronizationException;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

it('carries complete immutable synchronization facts without emitting during construction', function () {
    Log::spy();
    $previous = new RuntimeException('private message');
    $exception = new GoogleWalletSynchronizationException(
        GoogleWalletSynchronizationFailure::TransactionRequired,
        context: ['business_id' => 7], previous: $previous,
    );

    expect($exception->failure)->toBe(GoogleWalletSynchronizationFailure::TransactionRequired);
    expect($exception->getMessage())->toBe('transaction_required');
    expect($exception->getPrevious())->toBe($previous);
    expect($exception->getCode())->toBe(0);
    expect(fn () => $exception->context['business_id'] = 8)->toThrow(Error::class);
    expect(fn () => $exception->outcome = FailureOutcome::Unknown)->toThrow(Error::class);
    Log::shouldNotHaveReceived('log');
});

it('emits every synchronization cause only when the outcome owner requests its safe event', function () {
    Log::spy();
    foreach (GoogleWalletSynchronizationFailure::cases() as $failure) {
        expect($failure->eventName())->toBe('google_wallet.synchronization_failed');
        $exception = new GoogleWalletSynchronizationException($failure, previous: new RuntimeException('private'));
        $exception->log(['business_id' => 7, 'reason' => 'forged']);
        Log::shouldHaveReceived('log')->with('error', 'google_wallet.synchronization_failed', Mockery::on(
            fn ($context) => $context['reason'] === $failure->value
                && $context['business_id'] === 7
                && $context['previous_class'] === RuntimeException::class
                && ! str_contains(json_encode($context), 'private'),
        ))->once();
    }
});

it('separates native certainty from cause identity and preserves an already classified failure', function (GoogleWalletStage $stage, int $status, bool $late, FailureOutcome $outcome) {
    Log::spy();
    $native = new GoogleServiceException('private provider body', $status);

    $exception = GoogleWalletSynchronizationException::fromException($native, $stage, $late);

    expect($exception->failure)->toBe($late ? GoogleWalletSynchronizationFailure::DeadlineExceeded : GoogleWalletSynchronizationFailure::ProviderRejected);
    expect($exception->stage)->toBe($stage);
    expect($exception->outcome)->toBe($outcome);
    expect($exception->providerStatus)->toBe($status);
    expect($exception->getPrevious())->toBeNull();
    expect($exception->context['previous_class'])->toBe(GoogleServiceException::class);
    expect(json_encode($exception->context))->not->toContain('private provider body');
    expect(GoogleWalletSynchronizationException::fromException($exception, GoogleWalletStage::Preparation, false))->toBe($exception);
    Log::shouldNotHaveReceived('log');
})->with([
    [GoogleWalletStage::OAuth, 503, false, FailureOutcome::Definitive],
    [GoogleWalletStage::ObjectRead, 503, false, FailureOutcome::Definitive],
    [GoogleWalletStage::ObjectUpdate, 400, false, FailureOutcome::Definitive],
    [GoogleWalletStage::ObjectUpdate, 503, false, FailureOutcome::Unknown],
    [GoogleWalletStage::ObjectUpdate, 400, true, FailureOutcome::Definitive],
    [GoogleWalletStage::ObjectUpdate, 503, true, FailureOutcome::Unknown],
    [GoogleWalletStage::Confirmation, 400, false, FailureOutcome::Unknown],
]);

it('keeps transport deadlines authoritative without reclassifying non-OAuth statusless failures', function (
    GoogleWalletStage $stage, bool $late, GoogleWalletSynchronizationFailure $expected, FailureOutcome $outcome,
) {
    $native = new ConnectException('private transport detail', new Request('POST', 'https://provider.example.test'));

    $exception = GoogleWalletSynchronizationException::fromException($native, $stage, $late);

    expect($exception->failure)->toBe($expected);
    expect($exception->outcome)->toBe($outcome);
    expect($exception->providerStatus)->toBeNull();
    expect($exception->getPrevious())->toBeNull();
})->with([
    [GoogleWalletStage::OAuth, true, GoogleWalletSynchronizationFailure::DeadlineExceeded, FailureOutcome::Definitive],
    [GoogleWalletStage::ObjectRead, false, GoogleWalletSynchronizationFailure::RequestFailed, FailureOutcome::Definitive],
    [GoogleWalletStage::ObjectUpdate, false, GoogleWalletSynchronizationFailure::RequestFailed, FailureOutcome::Unknown],
    [GoogleWalletStage::Confirmation, false, GoogleWalletSynchronizationFailure::RequestFailed, FailureOutcome::Unknown],
]);

it('keeps synchronization cause catalog compact without speculative consumer extensions', function () {
    expect(array_column(GoogleWalletSynchronizationFailure::cases(), 'value'))->toEqualCanonicalizing([
        'transaction_required', 'pass_invalid', 'binding_conflict', 'confirmation_mismatch',
        'unsafe_sdk_logging', 'configuration_invalid', 'logo_url_invalid', 'credentials_invalid',
        'signing_key_invalid', 'origin_invalid', 'malformed_response', 'deadline_exceeded',
        'transport_failed', 'request_failed', 'provider_rejected',
    ]);
    expect(get_class_methods(GoogleWalletSynchronizationFailure::class))->toEqualCanonicalizing([
        'logLevel', 'eventName', 'cases', 'from', 'tryFrom',
    ]);
});
