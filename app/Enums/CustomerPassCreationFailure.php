<?php

namespace App\Enums;

/** Identifies issuance causes independently of the operation's stage and certainty. */
enum CustomerPassCreationFailure: string
{
    case TransactionActive = 'transaction_active';
    case BusinessNotPersisted = 'business_not_persisted';
    case BusinessLookupFailed = 'business_lookup_failed';
    case CredentialQueryFailed = 'credential_query_failed';
    case ManualCodeExhausted = 'manual_code_exhausted';
    case IdentityConflict = 'identity_conflict';
    case BusinessNotFound = 'business_not_found';
    case DatabaseFailed = 'database_failed';
    case RandomnessUnavailable = 'randomness_unavailable';
    case UnexpectedFailure = 'unexpected_failure';
    case SaveRejected = 'save_rejected';
    case UnsafeSdkLogging = 'unsafe_sdk_logging';
    case ConfigurationInvalid = 'configuration_invalid';
    case LogoUrlInvalid = 'logo_url_invalid';
    case CredentialsInvalid = 'credentials_invalid';
    case SigningKeyInvalid = 'signing_key_invalid';
    case OriginInvalid = 'origin_invalid';
    case BusinessInvalid = 'business_invalid';
    case SigningFailed = 'signing_failed';
    case MalformedResponse = 'malformed_response';
    case ClassNotUsable = 'class_not_usable';
    case BindingConflict = 'binding_conflict';
    case ProviderRejected = 'provider_rejected';
    case TransportFailed = 'transport_failed';
    case RequestFailed = 'request_failed';
    case ConfirmationUnavailable = 'confirmation_unavailable';

    /**
     * Returns the intrinsic severity; provider response severity also needs runtime evidence.
     *
     * @return string PSR-3 severity.
     */
    public function logLevel(): string
    {
        return match ($this) {
            self::TransactionActive, self::BindingConflict => 'warning',
            default => 'error',
        };
    }

    /**
     * Selects the existing issuance or route-lookup event.
     *
     * @return string|null Outcome-owner event.
     */
    public function eventName(): ?string // @phpstan-ignore return.unusedType (uniform failure catalog contract permits nullable events)
    {
        return $this === self::BusinessLookupFailed
            ? 'customer_pass.business_lookup.failed'
            : 'customer_pass.creation.failed';
    }
}
