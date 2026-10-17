<?php

namespace App\Actions\CustomerPasses;

use App\Enums\CustomerPassCreationFailure;
use App\Enums\FailureOutcome;
use App\Enums\PassCreationStage;
use App\Exceptions\CustomerPassCreationException;
use App\Integrations\GoogleWallet\GoogleWalletPassCreator;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Support\CustomerPassCredentials;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Psr\Log\LogLevel;
use SensitiveParameter;
use Throwable;

/** Owns the complete issuance: prepare credentials, confirm Google, commit locally, then deliver. */
final class CreateCustomerPass
{
    /**
     * Receives the concrete Google integration.
     *
     * @param  GoogleWalletPassCreator  $googleWallet  Creates and confirms one provider object.
     */
    public function __construct(private GoogleWalletPassCreator $googleWallet) {}

    /**
     * Creates the pass and releases its private handoff only after the local commit.
     *
     * @param  Business  $business  Persisted business selected by the trusted caller.
     * @return array{save_url: string, manual_code: string} Private handoff; not proof of a customer save.
     *
     * @throws CustomerPassCreationException If preparation, Google confirmation or persistence fails.
     * @throws Throwable If operational logging fails, including after a successful commit.
     */
    public function handle(Business $business): array
    {
        // prepare one issuance attempt
        $stage = PassCreationStage::Preparation;
        $operationId = null;
        $startedAtNs = hrtime(true);

        try {
            if (DB::transactionLevel() !== 0) {
                throw new CustomerPassCreationException(CustomerPassCreationFailure::TransactionActive);
            }

            $credentials = CustomerPassCredentials::prepare($business);
            $operationId = $credentials->publicPassId;

            // confirm Google outside the database transaction
            $stage = PassCreationStage::Provider;
            $walletResult = $this->googleWallet->create($business, $credentials);

            // commit the corresponding local identity
            $stage = PassCreationStage::Local;
            $pass = DB::transaction(fn (): CustomerPass => $this->persistPass(
                $business,
                $credentials,
                $walletResult['object_id'],
            ));
        } catch (Throwable $exception) {
            $failure = CustomerPassCreationException::fromException($exception, $stage);
            $failure->log([
                'operation_id' => $operationId,
                'business_id' => $business->id,
                'duration_ms' => (int) ((hrtime(true) - $startedAtNs) / 1_000_000),
            ]);

            throw $failure;
        }

        // record completion and release the private handoff
        Log::log(
            $walletResult['confirmed_by_readback'] ? LogLevel::WARNING : LogLevel::INFO,
            'customer_pass.creation.completed',
            [
                'operation_id' => $operationId,
                'business_id' => $business->id,
                'customer_pass_id' => $pass->id,
                'stage' => 'local',
                'outcome' => $pass->wasRecentlyCreated ? 'issued' : 'replayed',
                'confirmed_by_readback' => $walletResult['confirmed_by_readback'],
                'duration_ms' => (int) ((hrtime(true) - $startedAtNs) / 1_000_000),
            ],
        );

        return [
            'save_url' => $walletResult['save_url'],
            'manual_code' => $credentials->manualCode,
        ];
    }

    /**
     * Saves the confirmed identity inside handle()'s transaction, without provider calls.
     *
     * @param  Business  $business  Owner whose row is locked before checking identities.
     * @param  CustomerPassCredentials  $credentials  The same credentials supplied to Google.
     * @param  string  $walletObjectId  Object ID confirmed by this action's integration.
     * @return CustomerPass Matching existing record or a new record awaiting commit.
     *
     * @throws CustomerPassCreationException If a binding conflicts or a model event rejects the save.
     * @throws ModelNotFoundException If the owning business no longer exists.
     * @throws QueryException If locking, identity lookup or persistence fails.
     * @throws PDOException If the database driver fails.
     */
    private function persistPass(
        Business $business,
        #[SensitiveParameter] CustomerPassCredentials $credentials,
        string $walletObjectId,
    ): CustomerPass {
        // serialize issuance for this business and compute the stored verifier once
        Business::query()
            ->whereKey($business->id)
            ->lockForUpdate()
            ->firstOrFail();
        $tokenHash = $credentials->hashValidationToken();
        $existing = CustomerPass::query()
            ->where('public_id', $credentials->publicPassId)
            ->first();

        // reuse only an identical local identity, never a different pass
        if ($existing !== null) {
            if (
                $existing->business_id !== $business->id
                || $existing->wallet_object_id !== $walletObjectId
                || $existing->manual_code !== $credentials->manualCode
                || ! hash_equals((string) $existing->validation_token_hash, $tokenHash)
            ) {
                throw new CustomerPassCreationException(
                    CustomerPassCreationFailure::IdentityConflict,
                    stage: PassCreationStage::Local,
                    outcome: FailureOutcome::Unknown,
                );
            }

            return $existing;
        }

        // reject credentials already attached to another pass; constraints cover concurrent races
        $hasConflict = CustomerPass::query()
            ->where('wallet_object_id', $walletObjectId)
            ->orWhere('validation_token_hash', $tokenHash)
            ->orWhere(fn ($query) => $query
                ->where('business_id', $business->id)
                ->where('manual_code', $credentials->manualCode))
            ->exists();

        if ($hasConflict) {
            throw new CustomerPassCreationException(
                CustomerPassCreationFailure::IdentityConflict,
                stage: PassCreationStage::Local,
                outcome: FailureOutcome::Unknown,
            );
        }

        // preserve Eloquent events and fail when an event cancels the write
        $pass = new CustomerPass;
        $pass->business()->associate($business);
        $pass->public_id = $credentials->publicPassId;
        $pass->wallet_object_id = $walletObjectId;
        $pass->validation_token_hash = $tokenHash;
        $pass->manual_code = $credentials->manualCode;

        if (! $pass->save()) {
            throw new CustomerPassCreationException(
                CustomerPassCreationFailure::SaveRejected,
                stage: PassCreationStage::Local,
                outcome: FailureOutcome::Unknown,
            );
        }

        return $pass;
    }
}
