<?php

namespace App\Enums;

/** Identifies synchronization causes without multiplying them by stage or remote certainty. */
enum GoogleWalletSynchronizationFailure: string
{
    case TransactionRequired = 'transaction_required';
    case PassInvalid = 'pass_invalid';
    case BindingConflict = 'binding_conflict';
    case ConfirmationMismatch = 'confirmation_mismatch';
    case UnsafeSdkLogging = 'unsafe_sdk_logging';
    case ConfigurationInvalid = 'configuration_invalid';
    case LogoUrlInvalid = 'logo_url_invalid';
    case CredentialsInvalid = 'credentials_invalid';
    case SigningKeyInvalid = 'signing_key_invalid';
    case OriginInvalid = 'origin_invalid';
    case MalformedResponse = 'malformed_response';
    case DeadlineExceeded = 'deadline_exceeded';
    case TransportFailed = 'transport_failed';
    case RequestFailed = 'request_failed';
    case ProviderRejected = 'provider_rejected';

    /**
     * Returns the infrastructure severity without creating an integration-owned event.
     *
     * @return string PSR-3 severity.
     */
    public function logLevel(): string
    {
        return 'error';
    }

    /**
     * Names the failure event emitted by the domain outcome owner, never by the integration.
     *
     * @return string|null Stable caller-owned synchronization event.
     */
    public function eventName(): ?string // @phpstan-ignore return.unusedType (uniform failure catalog contract permits silent causes)
    {
        return 'google_wallet.synchronization_failed';
    }
}
