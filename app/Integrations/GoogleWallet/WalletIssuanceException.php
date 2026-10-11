<?php

namespace App\Integrations\GoogleWallet;

use RuntimeException;

/**
 * Safe provider-boundary failure without credentials, payloads or a retained provider exception.
 * Unknown Object outcomes must block blind issuance retries; definitive means this attempt
 * did not confirm the expected binding, not that Google can never contain an orphan.
 */
class WalletIssuanceException extends RuntimeException
{
    /**
     * Records only application-owned classifications suitable for reporting and private UX.
     *
     * @param  'configuration'|'oauth'|'class'|'object'  $stage  Boundary at which issuance stopped.
     * @param  'definitive'|'unknown'  $outcome  Whether Object creation remains unresolved.
     * @param  string  $reason  Fixed application reason code, never provider content.
     */
    public function __construct(public readonly string $stage, public readonly string $outcome, public readonly string $reason)
    {
        parent::__construct('Google Wallet issuance failed.');
    }

    /**
     * Exposes sanitized classifications to Laravel's exception reporter.
     *
     * @return array{stage: string, outcome: string, reason: string} Safe diagnostic context only.
     */
    public function context(): array
    {
        return ['stage' => $this->stage, 'outcome' => $this->outcome, 'reason' => $this->reason];
    }
}
