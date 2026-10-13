<?php

use App\Enums\FailureOutcome;
use App\Enums\GoogleWalletStage;
use App\Enums\PassCreationStage;
use App\Exceptions\CustomerPassCreationException;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

it('classifies native provider failures without retaining secret bodies or causes', function (
    GoogleWalletStage $stage,
    string $method,
    int $status,
    FailureOutcome $outcome,
) {
    $native = new GoogleServiceException('synthetic-secret-provider-body', $status);

    $failure = CustomerPassCreationException::fromProvider($native, $stage, $method);

    expect($failure->stage)->toBe(PassCreationStage::Provider);
    expect($failure->providerStage)->toBe($stage);
    expect($failure->providerStatus)->toBe($status);
    expect($failure->outcome)->toBe($outcome);
    expect($failure->canRetry())->toBe($outcome === FailureOutcome::Definitive);
    expect($failure->getPrevious())->toBeNull();
    expect(json_encode($failure->context(), JSON_THROW_ON_ERROR))->not->toContain('synthetic-secret');
})->with(function (): iterable {
    foreach ([302, 400, 408, 409, 429, 500, 503] as $status) {
        yield 'class-'.$status => [GoogleWalletStage::ClassDefinition, 'POST', $status, FailureOutcome::Definitive];
    }

    foreach ([400, 401, 403, 404, 422] as $status) {
        yield 'object-rejection-'.$status => [GoogleWalletStage::ObjectCreation, 'POST', $status, FailureOutcome::Definitive];
    }

    foreach ([302, 408, 409, 429, 500, 503] as $status) {
        yield 'object-unknown-'.$status => [GoogleWalletStage::ObjectCreation, 'POST', $status, FailureOutcome::Unknown];
    }

    foreach ([400, 401, 403, 404, 503] as $status) {
        yield 'confirmation-'.$status => [GoogleWalletStage::ObjectCreation, 'GET', $status, FailureOutcome::Unknown];
    }
});

it('never permits retry for unknown local outcomes or a caller-owned transaction', function () {
    $unknown = CustomerPassCreationException::persistenceFailed('database_failed');
    $transaction = CustomerPassCreationException::preparationFailed('transaction_active');

    expect($unknown->canRetry())->toBeFalse();
    expect($unknown->isExpectedRejection())->toBeFalse();
    expect($unknown->getPrevious())->toBeNull();
    expect($transaction->canRetry())->toBeFalse();
    expect($transaction->isExpectedRejection())->toBeTrue();
});

it('keeps transport status and source class without carrying a response or private cause', function (bool $receivedResponse) {
    $request = new Request('POST', 'https://walletobjects.googleapis.com/test-only');
    $native = $receivedResponse
        ? new RequestException('synthetic-secret-message', $request, new Response(503, [], 'synthetic-secret-body'))
        : new ConnectException('synthetic-secret-message', $request);

    $failure = CustomerPassCreationException::fromProvider($native, GoogleWalletStage::ObjectCreation, 'POST');

    expect($failure->outcome)->toBe(FailureOutcome::Unknown);
    expect($failure->canRetry())->toBeFalse();
    expect($failure->providerStatus)->toBe($receivedResponse ? 503 : null);
    expect($failure->context()['exception_class'])->toBe($native::class);
    expect($failure->getPrevious())->toBeNull();
    expect(json_encode($failure->context(), JSON_THROW_ON_ERROR))->not->toContain('synthetic-secret');
})->with(['lost response' => false, 'received response' => true]);

it('retains a classified boundary decision without translating it again', function () {
    $original = CustomerPassCreationException::providerRejected(GoogleWalletStage::Configuration, 'configuration_invalid');

    $failure = CustomerPassCreationException::fromFailure($original, PassCreationStage::Provider);

    expect($failure)->toBe($original);
    expect($failure->stage)->toBe(PassCreationStage::Provider);
    expect($failure->canRetry())->toBeTrue();
});
