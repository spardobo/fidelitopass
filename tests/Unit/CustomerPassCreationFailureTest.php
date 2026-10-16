<?php

use App\Enums\CustomerPassCreationFailure;

it('keeps the original creation causes separate from runtime stage and certainty', function () {
    expect(array_column(CustomerPassCreationFailure::cases(), 'value'))->toEqualCanonicalizing([
        'transaction_active', 'business_not_persisted', 'business_lookup_failed', 'credential_query_failed',
        'manual_code_exhausted', 'identity_conflict', 'business_not_found', 'database_failed',
        'randomness_unavailable', 'unexpected_failure', 'save_rejected', 'unsafe_sdk_logging',
        'configuration_invalid', 'logo_url_invalid', 'credentials_invalid', 'signing_key_invalid',
        'origin_invalid', 'business_invalid', 'signing_failed', 'malformed_response', 'class_not_usable',
        'binding_conflict', 'provider_rejected', 'transport_failed', 'request_failed', 'confirmation_unavailable',
    ]);
    expect(get_class_methods(CustomerPassCreationFailure::class))->toEqualCanonicalizing([
        'logLevel', 'eventName', 'cases', 'from', 'tryFrom',
    ]);
});

it('retains intrinsic operational mappings without predicting a provider response', function () {
    expect(CustomerPassCreationFailure::TransactionActive->logLevel())->toBe('warning');
    expect(CustomerPassCreationFailure::BindingConflict->logLevel())->toBe('warning');
    expect(CustomerPassCreationFailure::BusinessLookupFailed->eventName())->toBe('customer_pass.business_lookup.failed');
    expect(CustomerPassCreationFailure::ProviderRejected->eventName())->toBe('customer_pass.creation.failed');
    expect(CustomerPassCreationFailure::ProviderRejected->logLevel())->toBe('error');
});
