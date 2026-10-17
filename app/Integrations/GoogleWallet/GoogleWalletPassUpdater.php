<?php

namespace App\Integrations\GoogleWallet;

use App\Enums\FailureOutcome;
use App\Enums\GoogleWalletStage;
use App\Enums\GoogleWalletSynchronizationFailure;
use App\Exceptions\GoogleWalletSynchronizationException;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Support\WalletPresentationBuilder;
use Google\Service\Walletobjects\LoyaltyObject;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use SensitiveParameter;
use Throwable;

/** Confirms existing-pass content inside the caller's transaction without committing local facts. */
class GoogleWalletPassUpdater
{
    private const BUDGET_SECONDS = 10;

    /**
     * Receives presentation, configuration and the shared Google SDK client.
     *
     * @param  WalletPresentationBuilder  $presentation  Reads tentative facts on the caller's connection.
     * @param  GoogleWalletConfiguration  $configuration  Loads current deployment credentials.
     * @param  GoogleWalletClient  $client  Authenticates fresh SDK resources within the operation deadline.
     */
    public function __construct(
        private WalletPresentationBuilder $presentation,
        private GoogleWalletConfiguration $configuration,
        #[SensitiveParameter] private GoogleWalletClient $client,
    ) {}

    /**
     * Replaces and confirms content on the same Object before the caller may commit.
     *
     * The caller owns authorization, locks and the non-retrying transaction. Provider confirmation
     * does not confirm a local commit or device rendering; a local rollback cannot undo the PATCH.
     * The caller records the operation outcome; this integration only propagates classified failures.
     *
     * @param  Business  $business  Persisted owner authorized by the domain caller.
     * @param  CustomerPass  $pass  Existing same-Business pass whose credentials must remain unchanged.
     * @param  string|null  $operationAt  Trusted caller's PostgreSQL instant; null lets presentation capture its own.
     *
     * @throws GoogleWalletSynchronizationException If preparation, transport or confirmation fails.
     */
    public function update(Business $business, CustomerPass $pass, ?string $operationAt = null): void
    {
        // establish the provider deadline and failure classification before preparation
        $deadline = null;
        $stage = GoogleWalletStage::Preparation;

        try {
            // reload the scoped pass and prepare content before starting the provider budget
            $pass = $this->loadPass($business, $pass);
            $settings = $this->configuration->load(synchronizing: true);
            $objectId = $settings['issuer_id'].'.pass_'.$pass->public_id;
            $classId = $settings['issuer_id'].'.business_'.$business->public_id;

            if ($pass->wallet_object_id !== $objectId) {
                throw new GoogleWalletSynchronizationException(GoogleWalletSynchronizationFailure::BindingConflict, stage: $stage);
            }

            $modules = $this->presentation->build($business, $pass, $operationAt);

            // share one deadline across OAuth, the PATCH and all confirmation reads
            $deadline = hrtime(true) + self::BUDGET_SECONDS * 1_000_000_000;
            $stage = GoogleWalletStage::OAuth;
            $wallet = $this->client->authenticate($settings, $deadline, synchronizing: true);
            $this->checkDeadline($deadline, $stage);

            // verify the existing identity before sending any presentation change
            $stage = GoogleWalletStage::ObjectRead;
            $remote = $wallet->loyaltyobject->get($objectId);
            $this->checkDeadline($deadline, $stage);

            if (! $this->matchesIdentity($remote, $pass, $classId)) {
                throw new GoogleWalletSynchronizationException(GoogleWalletSynchronizationFailure::BindingConflict, stage: $stage);
            }

            // replace text modules without rotating credentials or creating another Object
            $stage = GoogleWalletStage::ObjectUpdate;
            $remote = $wallet->loyaltyobject->patch($objectId, new LoyaltyObject([
                'textModulesData' => $modules,
            ]));
            $this->checkDeadline($deadline, $stage);

            // use one additional read only when the PATCH response does not confirm the content
            $stage = GoogleWalletStage::Confirmation;

            if (
                ! $this->matchesIdentity($remote, $pass, $classId)
                || ! $this->matchesContent($remote, $modules)
            ) {
                $remote = $wallet->loyaltyobject->get($objectId);
                $this->checkDeadline($deadline, $stage);
            }

            if (
                ! $this->matchesIdentity($remote, $pass, $classId)
                || ! $this->matchesContent($remote, $modules)
            ) {
                throw new GoogleWalletSynchronizationException(
                    GoogleWalletSynchronizationFailure::ConfirmationMismatch,
                    stage: $stage,
                    outcome: FailureOutcome::Unknown,
                );
            }
        } catch (Throwable $exception) {
            throw GoogleWalletSynchronizationException::fromException(
                $exception, $stage, $deadline !== null && hrtime(true) >= $deadline,
            );
        }
    }

    /**
     * Reloads the pass through its owner on the caller's existing transaction.
     *
     * @param  Business  $business  Authorized owner using the same connection as the supplied pass.
     * @param  CustomerPass  $pass  Persisted identity to reload without acquiring additional locks.
     * @return CustomerPass Current same-Business record from the owning relation.
     *
     * @throws GoogleWalletSynchronizationException If the transaction or ownership preconditions fail.
     * @throws ModelNotFoundException If the scoped record no longer exists.
     */
    private function loadPass(Business $business, #[SensitiveParameter] CustomerPass $pass): CustomerPass
    {
        // require the same connection and an already-open transaction
        if (
            $business->getConnection() !== $pass->getConnection()
            || $pass->getConnection()->transactionLevel() === 0
        ) {
            throw new GoogleWalletSynchronizationException(GoogleWalletSynchronizationFailure::TransactionRequired);
        }

        // validate the supplied identity before reloading it through its owner
        if (
            ! $business->exists
            || ! $pass->exists
            || $pass->business_id !== $business->id
        ) {
            throw new GoogleWalletSynchronizationException(GoogleWalletSynchronizationFailure::PassInvalid);
        }

        return $business->customerPasses()->findOrFail($pass->id);
    }

    /**
     * Rejects late responses without claiming rollback of a remote write.
     *
     * @param  int  $deadline  Monotonic operation deadline in nanoseconds.
     * @param  GoogleWalletStage  $stage  Last provider boundary entered.
     *
     * @throws GoogleWalletSynchronizationException If the total provider budget is exhausted.
     */
    private function checkDeadline(int $deadline, GoogleWalletStage $stage): void
    {
        if (hrtime(true) >= $deadline) {
            throw new GoogleWalletSynchronizationException(
                GoogleWalletSynchronizationFailure::DeadlineExceeded,
                stage: $stage,
                outcome: in_array($stage, [GoogleWalletStage::ObjectUpdate, GoogleWalletStage::Confirmation], true)
                    ? FailureOutcome::Unknown : FailureOutcome::Definitive,
            );
        }
    }

    /**
     * Matches immutable bindings without retaining or logging the remote barcode.
     *
     * @param  LoyaltyObject  $remote  Provider response, potentially incomplete.
     * @param  CustomerPass  $pass  Persisted identity with a one-way validation-token hash.
     * @param  string  $classId  Expected Business Class identifier.
     * @return bool Whether the response belongs to this exact issued pass.
     */
    private function matchesIdentity(
        #[SensitiveParameter] LoyaltyObject $remote,
        CustomerPass $pass,
        string $classId,
    ): bool {
        $data = $remote->toSimpleObject();
        $token = data_get($data, 'barcode.value');

        return ($data->id ?? null) === $pass->wallet_object_id
            && ($data->classId ?? null) === $classId
            && ($data->accountId ?? null) === $pass->manual_code
            && in_array(data_get($data, 'barcode.type'), ['QR_CODE', 'qrCode'], true)
            && is_string($token)
            && hash_equals($pass->validation_token_hash, hash('sha256', $token));
    }

    /**
     * Compares the complete module set without provider metadata or ordering differences.
     *
     * @param  LoyaltyObject  $remote  Provider response to confirm.
     * @param  list<array{id: string, header: string, body: string}>  $expected  Complete requested module set.
     * @return bool Whether every requested module is present and no stale module remains.
     */
    private function matchesContent(#[SensitiveParameter] LoyaltyObject $remote, array $expected): bool
    {
        // reject incomplete module data before comparing owned fields
        $actual = [];
        $data = $remote->toSimpleObject();

        if (! is_array($data->textModulesData ?? null)) {
            return false;
        }

        foreach ($data->textModulesData as $module) {
            if (
                ! is_string($module->id ?? null)
                || ! is_string($module->header ?? '')
                || ! is_string($module->body ?? '')
            ) {
                return false;
            }

            $actual[] = [
                'id' => $module->id,
                'header' => $module->header ?? '',
                'body' => $module->body ?? '',
            ];
        }

        // ignore ordering without hiding duplicates or stale entries
        usort($actual, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));
        usort($expected, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));

        return $actual === $expected;
    }
}
