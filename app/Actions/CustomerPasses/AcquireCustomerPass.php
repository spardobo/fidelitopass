<?php

namespace App\Actions\CustomerPasses;

use App\Integrations\GoogleWallet\GoogleWallet;
use App\Integrations\GoogleWallet\WalletIssuanceException;
use App\Models\Business;
use App\Support\CustomerPassCredentials;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Acquires one anonymous provider/local pair before releasing its private handoff.
 */
final class AcquireCustomerPass
{
    /**
     * Receives the provider boundary and the owner of the outer local issuance transaction.
     *
     * @param  GoogleWallet  $wallet  Provider confirmation without local writes or automatic retries.
     * @param  IssueCustomerPass  $issue  Local persistence requiring confirmed provider identity.
     */
    public function __construct(
        private GoogleWallet $wallet,
        private IssueCustomerPass $issue,
    ) {}

    /**
     * Prepares one identity, confirms Google, commits locally, then releases private saving material.
     *
     * Refuses ambient transactions before preparation or provider contact. A normal issuance
     * return confirms its outer commit, not a customer Wallet save. Any local exception is
     * uncertain: even a lost acknowledgement after commit must withhold the handoff. Failures
     * retain no exception, credentials or provider body and never allocate another identity.
     *
     * @param  Business  $business  Persisted Business selected exclusively from the public route.
     * @return array{outcome: 'confirmed', save_url: string, manual_code: string}|array{
     *     outcome: 'definitive'|'unknown',
     *     stage: 'preparation'|'provider'|'local',
     *     retry_allowed: bool
     * } Private committed handoff or sanitized failure, without a rollback assertion.
     */
    public function handle(Business $business): array
    {
        if (DB::transactionLevel() !== 0) {
            return [
                'outcome' => 'definitive',
                'stage' => 'preparation',
                'retry_allowed' => false,
            ];
        }

        try {
            $credentials = CustomerPassCredentials::prepare($business);
        } catch (Throwable) {
            return [
                'outcome' => 'definitive',
                'stage' => 'preparation',
                'retry_allowed' => true,
            ];
        }

        try {
            $provider = $this->wallet->provision($business, $credentials);
        } catch (WalletIssuanceException $exception) {
            return [
                'outcome' => $exception->outcome,
                'stage' => 'provider',
                'retry_allowed' => $exception->outcome === 'definitive',
            ];
        } catch (Throwable) {
            return [
                'outcome' => 'unknown',
                'stage' => 'provider',
                'retry_allowed' => false,
            ];
        }

        try {
            $this->issue->handle($business, $credentials, $provider['object_id']);
        } catch (Throwable) {
            return [
                'outcome' => 'unknown',
                'stage' => 'local',
                'retry_allowed' => false,
            ];
        }

        return [
            'outcome' => 'confirmed',
            'save_url' => $provider['save_url'],
            'manual_code' => $credentials->manualCode(),
        ];
    }
}
