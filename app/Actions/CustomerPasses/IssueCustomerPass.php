<?php

namespace App\Actions\CustomerPasses;

use App\Models\Business;
use App\Models\CustomerPass;
use App\Support\CustomerPassCredentials;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use PDOException;
use RuntimeException;
use SensitiveParameter;

/**
 * Provides local persistence for a trusted provider-confirmed caller.
 *
 * This Action never confirms Google success.
 */
final class IssueCustomerPass
{
    /**
     * Commits a provider-confirmed binding or returns an exact current-operation replay.
     *
     * The trusted caller must confirm Google REST creation before invoking this Action.
     * An object ID is not evidence of provider success. No provider calls or Wallet-save
     * claims occur here. Only a normal return confirms this Action's outer local transaction.
     * Failures do not prove provider rollback or authorize allocating another operation.
     *
     * @param  Business  $business  Business selected by trusted acquisition orchestration.
     * @param  CustomerPassCredentials  $credentials  Private prepared material reused for this operation.
     * @param  string  $confirmedWalletObjectId  Object identity already confirmed by the trusted provider caller.
     * @return CustomerPass Locally committed pass with precisely matching operation bindings.
     *
     * @throws InvalidArgumentException When the object identity is malformed.
     * @throws LogicException When an ambient transaction prevents this Action from owning commit.
     * @throws DomainException When any existing identity or credential binding conflicts.
     * @throws ModelNotFoundException When the selected Business no longer exists.
     * @throws RuntimeException On database failure, without asserting the commit outcome.
     */
    public function handle(
        Business $business,
        #[SensitiveParameter] CustomerPassCredentials $credentials,
        string $confirmedWalletObjectId,
    ): CustomerPass {
        if (
            strlen($confirmedWalletObjectId) > 255
            || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9._-]+$/D', $confirmedWalletObjectId) !== 1
        ) {
            throw new InvalidArgumentException('Customer pass provider identity is invalid.');
        }

        if (! $business->exists || $credentials->businessId() !== $business->id) {
            throw new DomainException('Customer pass issuance bindings conflict.');
        }

        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Customer pass issuance must own its outer transaction.');
        }

        try {
            $pass = DB::transaction(
                fn (): CustomerPass => $this->persistPass(
                    $business,
                    $credentials,
                    $confirmedWalletObjectId,
                ),
            );
        } catch (UniqueConstraintViolationException) {
            // handles global uniqueness conflicts across Businesses.
            throw new DomainException('Customer pass issuance bindings conflict.');
        } catch (QueryException|PDOException) {
            // omits the previous exception because SQL bindings may contain private manual codes.
            throw new RuntimeException(
                'Customer pass persistence failed; local commit outcome is not confirmed.',
            );
        }

        Log::info('customer_pass.issued', [
            'business_id' => $business->id,
            'customer_pass_id' => $pass->id,
            'operation_id' => $credentials->publicId(),
            'outcome' => $pass->wasRecentlyCreated ? 'issued' : 'replayed',
        ]);

        return $pass;
    }

    // -----------------------------------------------------------------------------
    // issuance persistence and binding validation
    // -----------------------------------------------------------------------------

    /**
     * Locks the Business, returns a matching replay, or persists a new pass.
     *
     * Runs inside the transaction owned by handle(). The Business lock serializes
     * operations even when their pass row does not exist yet.
     *
     * @param  Business  $business  Business whose row is locked before checking bindings.
     * @param  CustomerPassCredentials  $credentials  Private bindings for the current operation.
     * @param  string  $confirmedWalletObjectId  Validated provider-confirmed object identity.
     * @return CustomerPass Existing matching pass or newly saved pass awaiting outer commit.
     *
     * @throws ModelNotFoundException When the selected Business no longer exists.
     * @throws DomainException When an existing identity or credential binding conflicts.
     * @throws QueryException When a database query or write fails.
     * @throws PDOException When the database driver fails.
     */
    private function persistPass(
        Business $business,
        #[SensitiveParameter] CustomerPassCredentials $credentials,
        string $confirmedWalletObjectId,
    ): CustomerPass {
        Business::query()
            ->whereKey($business->id)
            ->lockForUpdate()
            ->firstOrFail();

        $existing = CustomerPass::query()
            ->where('public_id', $credentials->publicId())
            ->first();

        if ($existing !== null) {
            $this->assertReplayBindings(
                $existing,
                $business,
                $credentials,
                $confirmedWalletObjectId,
            );

            return $existing;
        }

        $conflict = CustomerPass::query()
            ->where('wallet_object_id', $confirmedWalletObjectId)
            ->orWhere('validation_token_hash', $credentials->validationTokenHash())
            ->orWhere(
                fn ($query) => $query
                    ->where('business_id', $business->id)
                    ->where('manual_code', $credentials->manualCode()),
            )
            ->exists();

        if ($conflict) {
            throw new DomainException('Customer pass issuance bindings conflict.');
        }

        $pass = new CustomerPass;
        $pass->business()->associate($business);
        $pass->public_id = $credentials->publicId();
        $pass->wallet_object_id = $confirmedWalletObjectId;
        $pass->validation_token_hash = $credentials->validationTokenHash();
        $pass->manual_code = $credentials->manualCode();
        $pass->save();

        return $pass;
    }

    /**
     * Rejects an existing pass unless every current-operation binding matches.
     *
     * @param  CustomerPass  $existing  Pass found by the operation's public identity.
     * @param  Business  $business  Business expected to own the pass.
     * @param  CustomerPassCredentials  $credentials  Private bindings expected for this operation.
     * @param  string  $confirmedWalletObjectId  Provider-confirmed identity expected on the pass.
     *
     * @throws DomainException When any binding differs.
     */
    private function assertReplayBindings(
        CustomerPass $existing,
        Business $business,
        #[SensitiveParameter] CustomerPassCredentials $credentials,
        string $confirmedWalletObjectId,
    ): void {
        if (
            $existing->business_id !== $business->id
            || $existing->wallet_object_id !== $confirmedWalletObjectId
            || $existing->manual_code !== $credentials->manualCode()
            || ! hash_equals(
                (string) $existing->validation_token_hash,
                $credentials->validationTokenHash(),
            )
        ) {
            throw new DomainException('Customer pass issuance bindings conflict.');
        }
    }
}
