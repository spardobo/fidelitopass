<?php

namespace App\Integrations\GoogleWallet;

use App\Enums\FailureOutcome;
use App\Enums\GoogleWalletStage;
use App\Enums\GoogleWalletSynchronizationFailure;
use App\Exceptions\GoogleWalletSynchronizationException;
use App\Models\Business;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Walletobjects\LoyaltyClass;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Confirms shared Class appearance inside the caller's transaction without changing individual Objects. */
class GoogleWalletPassUpdater
{
    private const BUDGET_SECONDS = 10;

    /**
     * Receives configuration and the shared Google SDK client.
     *
     * @param  GoogleWalletConfiguration  $configuration  Loads current deployment credentials.
     * @param  GoogleWalletClient  $client  Authenticates fresh SDK resources within the operation deadline.
     */
    public function __construct(
        private GoogleWalletConfiguration $configuration,
        #[SensitiveParameter] private GoogleWalletClient $client,
    ) {}

    /**
     * Confirms the shared background on an existing Class without touching individual Objects.
     *
     * The caller owns fresh authorization, the Business lock and a non-retrying transaction.
     * A local rollback cannot undo a remotely accepted color change or guarantee device timing.
     *
     * @param  Business  $business  Locked owner with its tentatively saved, validated color.
     *
     * @throws GoogleWalletSynchronizationException When preparation or provider confirmation fails.
     */
    public function updateAppearance(Business $business): void
    {
        // require the caller's transaction before attempting a remote change
        if ($business->getConnection()->transactionLevel() === 0) {
            throw new GoogleWalletSynchronizationException(
                GoogleWalletSynchronizationFailure::TransactionRequired,
            );
        }

        $deadline = null;
        $stage = GoogleWalletStage::Preparation;

        try {
            // prepare the expected Class identity and new appearance
            $settings = $this->configuration->load(synchronizing: true);
            $classId = $settings['issuer_id'].'.business_'.$business->public_id;
            $color = (string) $business->pass_background_color;

            // share one deadline across authentication, Class update and confirmation
            $deadline = hrtime(true) + self::BUDGET_SECONDS * 1_000_000_000;
            $stage = GoogleWalletStage::OAuth;
            $wallet = $this->client->authenticate($settings, $deadline, synchronizing: true);
            $this->checkDeadline($deadline, $stage);

            // verify the existing Class before changing its appearance
            $stage = GoogleWalletStage::ClassRead;
            $remote = $wallet->loyaltyclass->get($classId);
            $this->checkDeadline($deadline, $stage);

            if (! $this->isApprovedClass($remote, $classId)) {
                throw new GoogleWalletSynchronizationException(
                    GoogleWalletSynchronizationFailure::ClassNotUsable,
                    stage: $stage,
                );
            }

            // patch only the shared color and request provider review
            $stage = GoogleWalletStage::ClassUpdate;
            $remote = $wallet->loyaltyclass->patch($classId, new LoyaltyClass([
                'hexBackgroundColor' => $color,
                'reviewStatus' => 'UNDER_REVIEW',
            ]));
            $this->checkDeadline($deadline, $stage);

            // read once when the PATCH response lacks the fields needed for confirmation
            $stage = GoogleWalletStage::Confirmation;

            if (
                $remote->getId() === null
                || $remote->getReviewStatus() === null
                || $remote->getHexBackgroundColor() === null
            ) {
                $remote = $wallet->loyaltyclass->get($classId);
                $this->checkDeadline($deadline, $stage);
            }

            if (! $this->confirmsAppearance($remote, $classId, $color)) {
                throw new GoogleWalletSynchronizationException(
                    GoogleWalletSynchronizationFailure::ConfirmationMismatch,
                    stage: $stage,
                    outcome: FailureOutcome::Unknown,
                );
            }
        } catch (Throwable $exception) {
            // preserve unrelated failures, classifying only provider and deadline failures
            $expired = $deadline !== null && hrtime(true) >= $deadline;

            if (
                ! $exception instanceof GoogleWalletSynchronizationException
                && ! $exception instanceof GoogleServiceException
                && ! $exception instanceof GuzzleException
                && ! ($expired && $exception::class === RuntimeException::class)
            ) {
                throw $exception;
            }

            throw GoogleWalletSynchronizationException::fromException(
                $exception,
                $stage,
                $expired,
            );
        }
    }

    /**
     * Matches the deterministic Class and documented native approval aliases.
     *
     * @param  LoyaltyClass  $remote  Potentially incomplete provider response.
     * @param  string  $classId  Expected shared Business Class.
     * @return bool Whether this exact Class is approved by Google.
     */
    private function isApprovedClass(#[SensitiveParameter] LoyaltyClass $remote, string $classId): bool
    {
        return $remote->getId() === $classId
            && in_array($remote->getReviewStatus(), ['APPROVED', 'approved'], true);
    }

    /**
     * Confirms only the owned color, identity and approval, not unrelated visual echoes.
     *
     * @param  LoyaltyClass  $remote  Potentially incomplete provider response.
     * @param  string  $classId  Expected shared Business Class.
     * @param  string  $color  Validated, normalized tentative color.
     * @return bool Whether the provider confirms the requested appearance.
     */
    private function confirmsAppearance(#[SensitiveParameter] LoyaltyClass $remote, string $classId, string $color): bool
    {
        // normalize the provider's equivalent three-digit hex notation
        $remoteColor = strtoupper((string) $remote->getHexBackgroundColor());

        if (preg_match('/^#[A-F0-9]{3}$/D', $remoteColor)) {
            $remoteColor = '#'
                .$remoteColor[1].$remoteColor[1]
                .$remoteColor[2].$remoteColor[2]
                .$remoteColor[3].$remoteColor[3];
        }

        return $this->isApprovedClass($remote, $classId)
            && $remoteColor === $color;
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
                outcome: in_array($stage, [GoogleWalletStage::ObjectUpdate, GoogleWalletStage::ClassUpdate, GoogleWalletStage::Confirmation], true)
                    ? FailureOutcome::Unknown : FailureOutcome::Definitive,
            );
        }
    }
}
