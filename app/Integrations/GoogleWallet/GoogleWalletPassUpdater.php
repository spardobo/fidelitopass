<?php

namespace App\Integrations\GoogleWallet;

use App\Enums\FailureOutcome;
use App\Enums\GoogleWalletStage;
use App\Exceptions\GoogleWalletSynchronizationException;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Support\WalletPresentationBuilder;
use Google\Service\Walletobjects\LoyaltyObject;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Throwable;

/** Confirms existing-pass content inside the domain caller's transaction without committing local facts. */
class GoogleWalletPassUpdater
{
    private const BUDGET_SECONDS = 10;

    /**
     * Receives the existing presentation and configuration with an optional native test transport.
     *
     * @param  WalletPresentationBuilder  $presentation  Reads tentative facts on the caller's connection.
     * @param  GoogleWalletConfiguration  $configuration  Validates current deployment credentials.
     * @param  HttpClient|null  $http  Optional native test handler, never an alternate provider implementation.
     */
    public function __construct(
        private WalletPresentationBuilder $presentation,
        private GoogleWalletConfiguration $configuration,
        #[SensitiveParameter] private ?HttpClient $http = null,
    ) {}

    /**
     * Replaces presentation on the same Object and confirms it before the caller may commit.
     *
     * The caller owns authorization, domain locks, one non-retrying transaction and its final commit.
     * A provider confirmation is not a local commit or a guarantee that the device rendered the update.
     *
     * @param  Business  $business  Persisted owner authorized by the domain caller.
     * @param  CustomerPass  $pass  Existing same-Business pass; credentials are never rotated.
     *
     * @throws GoogleWalletSynchronizationException When preparation, transport or confirmation fails.
     * @throws Throwable When native logging fails; the caller must still roll back its transaction.
     */
    public function update(Business $business, CustomerPass $pass): void
    {
        $startedAt = hrtime(true);
        $deadline = null;
        $stage = GoogleWalletStage::Preparation;

        try {
            if (
                $business->getConnection() !== $pass->getConnection()
                || $pass->getConnection()->transactionLevel() === 0
            ) {
                throw new GoogleWalletSynchronizationException($stage, FailureOutcome::Definitive, 'transaction_required');
            }

            if (
                ! $business->exists
                || ! $pass->exists
                || $pass->business_id !== $business->id
            ) {
                throw new GoogleWalletSynchronizationException($stage, FailureOutcome::Definitive, 'pass_invalid');
            }

            $pass = $business->customerPasses()->findOrFail($pass->id);
            $settings = $this->configuration->load();
            $objectId = $settings['issuer_id'].'.pass_'.$pass->public_id;
            $classId = $settings['issuer_id'].'.business_'.$business->public_id;

            if ($pass->wallet_object_id !== $objectId) {
                throw new GoogleWalletSynchronizationException($stage, FailureOutcome::Definitive, 'binding_conflict');
            }

            $content = $this->presentation->build($business, $pass);
            $modules = [];

            foreach ($content->sections as $section) {
                $modules[] = [
                    'id' => $section->id,
                    'header' => $section->header,
                    'body' => $section->body,
                ];
            }

            if ($content->progress !== null) {
                $modules[] = [
                    'id' => 'barcode_top',
                    'header' => __('wallet.labels.progress'),
                    'body' => $content->progress,
                ];
            }

            $modules[] = [
                'id' => 'details_code',
                'header' => __('wallet.labels.manual_code'),
                'body' => $pass->manual_code,
            ];

            // share one deadline across authentication, the update and any confirmation read
            $deadline = hrtime(true) + self::BUDGET_SECONDS * 1_000_000_000;
            $stage = GoogleWalletStage::OAuth;
            $wallet = (new GoogleWalletClient($this->http))->authenticate($settings, $deadline);
            $this->checkDeadline($deadline, $stage);

            $stage = GoogleWalletStage::ObjectRead;
            $remote = $wallet->loyaltyobject->get($objectId);
            $this->checkDeadline($deadline, $stage);

            if (! $this->matchesIdentity($remote, $pass, $classId)) {
                throw new GoogleWalletSynchronizationException($stage, FailureOutcome::Definitive, 'binding_conflict');
            }

            $stage = GoogleWalletStage::ObjectUpdate;
            $remote = $wallet->loyaltyobject->patch($objectId, new LoyaltyObject(['textModulesData' => $modules]));
            $this->checkDeadline($deadline, $stage);

            $stage = GoogleWalletStage::Confirmation;

            if (! $this->matchesIdentity($remote, $pass, $classId) || ! $this->matchesContent($remote, $modules)) {
                $remote = $wallet->loyaltyobject->get($objectId);
                $this->checkDeadline($deadline, $stage);
            }

            if (! $this->matchesIdentity($remote, $pass, $classId) || ! $this->matchesContent($remote, $modules)) {
                throw new GoogleWalletSynchronizationException($stage, FailureOutcome::Unknown, 'confirmation_mismatch');
            }
        } catch (Throwable $exception) {
            $failure = GoogleWalletSynchronizationException::fromFailure(
                $exception,
                $stage,
                $deadline !== null && hrtime(true) >= $deadline,
            );

            Log::error('wallet.synchronization.failed', [
                'business_id' => $business->id,
                'customer_pass_id' => $pass->id,
                'stage' => $failure->stage->value,
                'outcome' => $failure->outcome->value,
                'reason' => $failure->reason,
                'provider_status' => $failure->providerStatus,
                'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            ]);

            throw $failure;
        }

        Log::info('wallet.synchronization.confirmed', [
            'business_id' => $business->id,
            'customer_pass_id' => $pass->id,
            'outcome' => 'provider_confirmed',
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ]);
    }

    /**
     * Rejects late responses without claiming that an attempted remote write was rolled back.
     *
     * @param  int  $deadline  Monotonic operation deadline in nanoseconds.
     * @param  GoogleWalletStage  $stage  Last provider boundary entered.
     *
     * @throws GoogleWalletSynchronizationException When the total provider budget is exhausted.
     */
    private function checkDeadline(int $deadline, GoogleWalletStage $stage): void
    {
        if (hrtime(true) >= $deadline) {
            $outcome = in_array($stage, [GoogleWalletStage::ObjectUpdate, GoogleWalletStage::Confirmation], true)
                ? FailureOutcome::Unknown
                : FailureOutcome::Definitive;

            throw new GoogleWalletSynchronizationException($stage, $outcome, 'deadline_exceeded');
        }
    }

    /**
     * Confirms immutable identity and credentials without storing or logging the remote barcode.
     *
     * @param  LoyaltyObject  $remote  Provider response, potentially incomplete.
     * @param  CustomerPass  $pass  Persisted identity with a one-way token hash.
     * @param  string  $classId  Expected deterministic Business Class identifier.
     * @return bool Whether the response belongs to this exact issued pass.
     */
    private function matchesIdentity(#[SensitiveParameter] LoyaltyObject $remote, CustomerPass $pass, string $classId): bool
    {
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
     * Compares the complete owned module set while ignoring unrelated provider metadata and order.
     *
     * @param  LoyaltyObject  $remote  Provider response to confirm.
     * @param  list<array{id: string, header: string, body: string}>  $expected  Absolute module replacement.
     * @return bool Whether every requested module is present and no stale module remains.
     */
    private function matchesContent(#[SensitiveParameter] LoyaltyObject $remote, array $expected): bool
    {
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

        usort($actual, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));
        usort($expected, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));

        return $actual === $expected;
    }
}
