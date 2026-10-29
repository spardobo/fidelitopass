<?php

use App\Actions\Rewards\RedeemReward;
use App\Actions\Visits\AcceptVisit;
use App\Enums\FailureOutcome;
use App\Enums\PassFindingFailure;
use App\Enums\RewardRedemptionFailure;
use App\Enums\VisitAcceptanceFailure;
use App\Enums\VisitRegistrationFailure;
use App\Enums\VisitRegistrationStage;
use App\Exceptions\CustomerPassFindingException;
use App\Exceptions\RewardRedemptionException;
use App\Exceptions\VisitAcceptanceException;
use App\Exceptions\VisitRegistrationException;
use App\Models\User;
use App\Support\CustomerPassFinder;
use App\Support\RewardRedemptionReceipt;
use App\Support\VisitReceipt;
use Flux\Flux;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Coordinates identification, acceptance and receipt presentation without hydrating credentials. */
new class extends Component
{
    #[Locked]
    public string $stage = VisitRegistrationStage::Identify->value;

    /** @var array<string, mixed>|null Read-only hint, never mutation authority. */
    #[Locked]
    public ?array $preview = null;

    /** @var array<string, mixed>|null Immutable facts returned by the accepted operation. */
    #[Locked]
    public ?array $facts = null;

    /** @var array<string, mixed>|null Original promotion details read after acceptance. */
    #[Locked]
    public ?array $details = null;

    /** @var array<string, mixed>|null Original entitlement presentation, never mutation authority. */
    #[Locked]
    public ?array $reward = null;

    /** @var array<string, mixed>|null Confirmed redemption facts, separate from Visit facts. */
    #[Locked]
    public ?array $redemption = null;

    /** Retains the compound Action's established postcondition even if optional detail reads fail. */
    #[Locked]
    public bool $combinedConfirmed = false;

    #[Locked]
    public string $errorCode = '';

    /**
     * Supplies the same translated failure catalog to Blade and the browser.
     *
     * @return array{
     *     errorMessage: string,
     *     projectsReward: bool,
     *     redemptionKind: string,
     *     confirmationTitle: string,
     *     resultTitle: string,
     *     unavailableMessage: string,
     *     cardPromotion: array<string, mixed>|null,
     *     cardPoints: int|null,
     *     clientFailures: array{
     *         errors: array<string, array{message: string, notice: string}>,
     *         camera: array<string, string>,
     *         codes: array<string, string>
     *     }
     * } Safe presentation data without private operation values.
     */
    public function with(): array
    {
        $clientFailures = $this->buildClientFailures();

        return [
            ...$this->buildPresentation(),
            'errorMessage' => $clientFailures['errors'][$this->errorCode]['message'] ?? '',
            'clientFailures' => $clientFailures,
        ];
    }

    // -----------------------------------------------------------------------------
    // visit actions
    // -----------------------------------------------------------------------------

    /** Restores identification and clears all server-owned presentation state. */
    public function resetIdentification(): void
    {
        $this->reset('stage', 'preview', 'facts', 'details', 'reward', 'redemption', 'combinedConfirmed', 'errorCode');
    }

    /**
     * Resolves a credential and selects a read-only preview for confirmation.
     *
     * @param  CustomerPassFinder  $finder  Injected owner of credential lookup and its outcome logging.
     * @param  RewardRedemptionReceipt  $rewards  Reader of original entitlement presentation.
     * @param  string  $method  Untrusted token or manual method.
     * @param  string  $credential  Private action argument, never component state.
     * @return string|null Browser-owned operation UUID, or null when identification fails.
     */
    public function findPass(
        CustomerPassFinder $finder,
        RewardRedemptionReceipt $rewards,
        string $method,
        #[SensitiveParameter] string $credential,
    ): ?string {
        $this->resetIdentification();

        try {
            $actor = Auth::user();
            $pass = $finder->find($actor instanceof User ? $actor : null, $method, $credential);
            $reward = null;

            if ($pass['promotion'] !== null && $pass['promotion']['reward_status'] !== 'none') {
                $reward = $rewards->find(
                    $actor instanceof User ? $actor : null,
                    $method,
                    $credential,
                    $pass['promotion']['id'],
                );
            }

            // publish confirmation only after all required reads succeed
            $this->showConfirmation($pass);
            $this->reward = $reward;

            return (string) Str::uuid();
        } catch (CustomerPassFindingException $exception) {
            $failure = $this->feedbackForIdentification($exception->failure, $method, $credential);
        } catch (Throwable $exception) {
            report($exception);
            $failure = VisitRegistrationFailure::ReadUnavailable;
        }

        $this->presentFailure($failure);

        return null;
    }

    /**
     * Submits one intentional visit through the existing transaction owner.
     *
     * @param  AcceptVisit  $acceptVisit  Injected owner of acceptance and its outcome logging.
     * @param  string  $method  Private credential method revalidated by the action.
     * @param  string  $credential  Private credential, never component state.
     * @param  string  $operationId  Same UUID throughout this intentional operation.
     * @param  int  $expectedPromotionId  Selected offer comparison, never mutation authority.
     * @param  bool  $redeemReward  Explicit compound intent revalidated by the transaction owner.
     * @return 'confirmed'|'rejected'|'unknown' Acceptance outcome for the browser's submission guard.
     */
    public function acceptVisit(
        AcceptVisit $acceptVisit,
        string $method,
        #[SensitiveParameter] string $credential,
        string $operationId,
        int $expectedPromotionId,
        bool $redeemReward = false,
    ): string {
        $this->errorCode = '';
        $outcome = 'rejected';

        try {
            $actor = Auth::user();
            $this->facts = $acceptVisit->handle(
                $actor instanceof User ? $actor : null,
                $method,
                $credential,
                $operationId,
                $expectedPromotionId,
                $redeemReward,
            );

            // the successful action establishes persisted redemption, not merely requested intent

            $this->combinedConfirmed = $redeemReward;
            $this->preview = null;
            $this->details = null;
            $this->stage = VisitRegistrationStage::Result->value;

            return 'confirmed';
        } catch (VisitAcceptanceException $exception) {
            if ($exception->localOutcome === FailureOutcome::Unknown) {
                $outcome = 'unknown';
                $failure = VisitRegistrationFailure::Unknown;
            } else {
                $failure = $this->feedbackForAcceptance($exception->failure);
            }
        } catch (Throwable $exception) {
            report($exception);
            $outcome = 'unknown';
            $failure = VisitRegistrationFailure::Unknown;
        }

        $this->presentFailure($failure);

        return $outcome;
    }

    /**
     * Reads the original operation without discarding known committed facts on failure.
     *
     * @param  VisitReceipt  $receipts  Injected reader of authorized original operations.
     * @param  CustomerPassFinder  $finder  Credential validator used for malformed operation identifiers.
     * @param  RewardRedemptionReceipt  $rewards  Reader of the original Visit's entitlement, never a replacement offer.
     * @param  string  $method  Private credential method revalidated on this request.
     * @param  string  $credential  Private action argument, never component state.
     * @param  string  $operationId  Same unresolved or committed operation UUID.
     * @param  bool  $showResult  Whether a found receipt replaces the rendered step.
     * @param  bool  $requireRedemption  Requires actual original redemption before resolving an unknown compound request.
     * @return bool Whether an authorized receipt was found; false never proves rollback.
     */
    public function findVisitReceipt(
        VisitReceipt $receipts,
        CustomerPassFinder $finder,
        RewardRedemptionReceipt $rewards,
        string $method,
        #[SensitiveParameter] string $credential,
        string $operationId,
        bool $showResult = true,
        bool $requireRedemption = false,
    ): bool {
        try {
            $actor = Auth::user();
            $actor = $actor instanceof User ? $actor : null;

            if (! Str::isUuid($operationId)) {
                // preserves credential validation even when the operation id cannot identify a receipt
                $finder->find($actor, $method, $credential);
                $receipt = null;
            } else {
                $receipt = $receipts->find($actor, $method, $credential, $operationId);
            }

            if ($receipt !== null) {
                $reward = null;
                if ($requireRedemption) {
                    $reward = $rewards->find($actor, $method, $credential, $receipt['promotion_id']);
                    if ($reward === null || $reward['redeemed_at'] === null) {
                        $this->presentFailure(VisitRegistrationFailure::Unknown);

                        return false;
                    }
                }
                if ($showResult) {
                    $this->showReceipt($receipt);
                    $this->combinedConfirmed = $requireRedemption;
                    $this->reward = $reward ?? $rewards->find($actor, $method, $credential, $receipt['promotion_id']);
                    if ($this->reward !== null && $this->reward['redeemed_at'] !== null) {
                        $this->redemption = Arr::only($this->reward, ['reward_entitlement_id', 'customer_pass_id', 'promotion_id', 'redeemed_at', 'redeemed_by_user_id']);
                    }
                }

                $this->errorCode = '';

                return true;
            }

            $failure = VisitRegistrationFailure::Unknown;
        } catch (CustomerPassFindingException $exception) {
            $failure = $exception->failure === PassFindingFailure::Forbidden
                ? VisitRegistrationFailure::Forbidden
                : VisitRegistrationFailure::Unknown;
        } catch (Throwable $exception) {
            report($exception);
            $failure = VisitRegistrationFailure::Unknown;
        }

        // receipt availability cannot overturn already confirmed acceptance
        if ($this->facts !== null) {
            $failure = $this->combinedConfirmed ? VisitRegistrationFailure::RewardDetailsUnavailable : VisitRegistrationFailure::DetailsUnavailable;
        }

        $this->presentFailure($failure);

        return false;
    }

    /**
     * Submits an explicitly confirmed original entitlement without replacing Visit facts on failure.
     *
     * @param  RedeemReward  $redeemReward  Transaction and outcome owner.
     * @param  string  $method  Current private credential method.
     * @param  string  $credential  Private action argument, never hydrated.
     * @param  int  $entitlementId  Original selected entitlement.
     * @param  int  $promotionId  Original selected Promotion.
     * @return string Confirmed, rejected or unresolved submission outcome.
     */
    public function redeemReward(
        RedeemReward $redeemReward,
        string $method,
        #[SensitiveParameter] string $credential,
        int $entitlementId,
        int $promotionId,
    ): string {
        $this->errorCode = '';
        $outcome = 'rejected';

        try {
            $actor = Auth::user();
            $this->redemption = $redeemReward->handle(
                $actor instanceof User ? $actor : null,
                $method,
                $credential,
                $entitlementId,
                $promotionId,
            );
            // retain the original reward presentation until its committed receipt is available
            $this->stage = VisitRegistrationStage::Result->value;

            return 'confirmed';
        } catch (RewardRedemptionException $exception) {
            if ($exception->localOutcome === FailureOutcome::Unknown) {
                $outcome = 'unknown';
                $failure = VisitRegistrationFailure::Unknown;
            } else {
                $failure = match ($exception->failure) {
                    RewardRedemptionFailure::Forbidden => VisitRegistrationFailure::Forbidden,
                    RewardRedemptionFailure::InvalidCredential => VisitRegistrationFailure::InvalidCredential,
                    RewardRedemptionFailure::InvalidAttemptLimit => VisitRegistrationFailure::Throttled,
                    RewardRedemptionFailure::Expired => VisitRegistrationFailure::RewardExpired,
                    RewardRedemptionFailure::Cancelled => VisitRegistrationFailure::RewardCancelled,
                    default => VisitRegistrationFailure::RewardUnavailable,
                };
            }
        } catch (Throwable $exception) {
            report($exception);
            $outcome = 'unknown';
            $failure = VisitRegistrationFailure::Unknown;
        }

        $this->presentFailure($failure);

        return $outcome;
    }

    /**
     * Reconciles only the original entitlement and retains known final facts when details fail.
     *
     * @param  RewardRedemptionReceipt  $receipts  Current-credential original-target reader.
     * @param  string  $method  Private credential method.
     * @param  string  $credential  Private action argument.
     * @param  int  $entitlementId  Original unresolved or committed entitlement.
     * @param  int  $promotionId  Original Promotion.
     * @param  bool  $showResult  Whether to present found committed redemption.
     * @return bool Whether the original final redemption is confirmed, never rollback evidence.
     */
    public function findRewardReceipt(
        RewardRedemptionReceipt $receipts,
        string $method,
        #[SensitiveParameter] string $credential,
        int $entitlementId,
        int $promotionId,
        bool $showResult = true,
    ): bool {
        try {
            $actor = Auth::user();
            $receipt = $receipts->find($actor instanceof User ? $actor : null, $method, $credential, $promotionId, $entitlementId);
            if ($receipt !== null && $receipt['redeemed_at'] !== null) {
                if ($showResult) {
                    $this->reward = $receipt;
                    $this->redemption = Arr::only($receipt, ['reward_entitlement_id', 'customer_pass_id', 'promotion_id', 'redeemed_at', 'redeemed_by_user_id']);
                    $this->stage = VisitRegistrationStage::Result->value;
                }
                $this->errorCode = '';

                return true;
            }
        } catch (CustomerPassFindingException) {
            // the reader owns its safe failure event
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->presentFailure($this->redemption !== null ? VisitRegistrationFailure::RewardDetailsUnavailable : VisitRegistrationFailure::Unknown);

        return false;
    }

    // -----------------------------------------------------------------------------
    // client observations
    // -----------------------------------------------------------------------------

    /**
     * Records a classified camera observation without raw hardware or SDK diagnostics.
     *
     * @param  mixed  $code  Untrusted camera code validated against the feedback enum.
     * @param  mixed  $type  Untrusted capability, startup or decoding classification.
     *
     * @throws HttpException When unauthorized or throttled.
     * @throws ValidationException When observation fields are invalid.
     */
    #[Renderless]
    public function logCameraFatalError(mixed $code, mixed $type): void
    {
        $businessId = $this->authorizeClientObservation();
        $observation = Validator::make(compact('code', 'type'), [
            'code' => [
                'required',
                'string',
                'max:40',
                Rule::in([
                    VisitRegistrationFailure::CameraDenied->value,
                    VisitRegistrationFailure::CameraBusy->value,
                    VisitRegistrationFailure::CameraUnavailable->value,
                    VisitRegistrationFailure::CameraInitialization->value,
                ]),
            ],
            'type' => [
                'required',
                'string',
                'max:16',
                Rule::in(['capability', 'startup', 'decode']),
            ],
        ])->validate();

        $exception = new VisitRegistrationException(
            VisitRegistrationFailure::from($observation['code']),
            ['type' => $observation['type']],
        );
        $exception->log([
            'actor_id' => Auth::id(),
            'business_id' => $businessId,
        ]);
    }

    /**
     * Records bounded transport evidence independently of the authoritative visit outcome.
     *
     * @param  mixed  $code  Untrusted transport code validated against the feedback enum.
     * @param  mixed  $action  Untrusted action name without its private arguments.
     * @param  mixed  $status  Untrusted HTTP status, or null when no response was observed.
     * @param  mixed  $type  Untrusted network, HTTP or runtime classification.
     *
     * @throws HttpException When unauthorized or throttled.
     * @throws ValidationException When observation fields are invalid.
     */
    #[Renderless]
    public function logTransportError(mixed $code, mixed $action, mixed $status, mixed $type): void
    {
        $businessId = $this->authorizeClientObservation();
        $observation = Validator::make(compact('code', 'action', 'status', 'type'), [
            'code' => [
                'required',
                'string',
                'max:40',
                Rule::in([
                    VisitRegistrationFailure::NetworkError->value,
                    VisitRegistrationFailure::TransportFailed->value,
                ]),
            ],
            'action' => [
                'required',
                'string',
                'max:32',
                Rule::in([
                    'findPass',
                    'resetIdentification',
                    'acceptVisit',
                    'findVisitReceipt',
                    'redeemReward',
                    'findRewardReceipt',
                ]),
            ],
            'status' => ['nullable', 'integer', 'between:100,599'],
            'type' => [
                'required',
                'string',
                'max:16',
                Rule::in(['network', 'http', 'runtime']),
            ],
        ])->validate();

        $exception = new VisitRegistrationException(
            VisitRegistrationFailure::from($observation['code']),
            [
                'action' => $observation['action'],
                'status' => $observation['status'] === null ? null : (int) $observation['status'],
                'type' => $observation['type'],
            ],
        );
        $exception->log([
            'actor_id' => Auth::id(),
            'business_id' => $businessId,
        ]);
    }

    // -----------------------------------------------------------------------------
    // rendered states
    // -----------------------------------------------------------------------------

    /**
     * Derives the dialog's copy, card facts and eligible inline choice from server-owned presentation.
     *
     * The completion preview is a hint only; the existing Actions retain mutation authority.
     *
     * @return array{projectsReward: bool, redemptionKind: string, confirmationTitle: string, resultTitle: string, unavailableMessage: string, cardPromotion: array<string, mixed>|null, cardPoints: int|null} Render-only values, never cached authority.
     */
    private function buildPresentation(): array
    {
        $promotion = $this->preview['promotion'] ?? null;
        $projectsReward = ($this->preview['can_accept_visit'] ?? false)
            && $promotion['target_points'] <= $promotion['earned_points'] + $promotion['visit_points'];
        $redemptionKind = '';

        if ($this->stage === VisitRegistrationStage::Confirm->value) {
            $redemptionKind = match (true) {
                $projectsReward => 'combined',
                $this->reward['can_redeem_reward'] ?? false => 'reward',
                default => '',
            };
        }

        $resultTitle = match (true) {
            $this->combinedConfirmed || $this->redemption !== null => 'redeemed',
            $this->details['reward_unlocked'] ?? false => 'reward_unlocked',
            default => 'success_title',
        };
        $unavailableMessage = match (true) {
            $promotion === null => 'no_promotion',
            $promotion['reward_status'] === 'redeemed' => 'already_redeemed',
            default => 'completed',
        };
        $cardPoints = ($this->preview['can_accept_visit'] ?? false) ? $promotion['visit_points'] : null;

        if ($this->stage === VisitRegistrationStage::Result->value) {
            $promotion = $this->facts !== null ? $this->details : ($this->reward['promotion'] ?? null);
            $cardPoints = $this->facts['points_awarded'] ?? null;
        }

        return [
            'projectsReward' => $projectsReward,
            'redemptionKind' => $redemptionKind,
            'confirmationTitle' => __('visit-registration.'.($projectsReward ? 'reward_on_confirmation' : 'confirmation_title')),
            'resultTitle' => __('visit-registration.'.$resultTitle),
            'unavailableMessage' => __('visit-registration.'.$unavailableMessage),
            'cardPromotion' => $promotion,
            'cardPoints' => $cardPoints,
        ];
    }

    /**
     * Selects only the lookup fields required by the confirmation step.
     *
     * @param  array<string, mixed>  $pass  Authoritative lookup result including non-renderable fields.
     */
    private function showConfirmation(array $pass): void
    {
        $this->preview = Arr::only($pass, [
            'checked_at',
            'promotion',
            'can_accept_visit',
            'can_redeem_reward',
        ]);
        $this->stage = VisitRegistrationStage::Confirm->value;
    }

    /**
     * Selects immutable visit facts and the original promotion for the result step.
     *
     * @param  array<string, mixed>  $receipt  Authorized original-operation receipt.
     */
    private function showReceipt(array $receipt): void
    {
        $this->facts = Arr::only($receipt, [
            'visit_id',
            'operation_id',
            'customer_pass_id',
            'promotion_id',
            'points_awarded',
            'confirmed_at',
        ]);
        $this->details = $receipt['promotion'];
        $this->preview = null;
        $this->stage = VisitRegistrationStage::Result->value;
    }

    // -----------------------------------------------------------------------------
    // failure presentation
    // -----------------------------------------------------------------------------

    /**
     * Sets persistent feedback and emits its attention notice without re-logging domain failures.
     *
     * @param  VisitRegistrationFailure  $failure  Feedback selected by this presentation boundary.
     */
    private function presentFailure(VisitRegistrationFailure $failure): void
    {
        $this->errorCode = $failure->value;

        Flux::toast(variant: 'danger', text: __($failure->noticeKey()));
    }

    /**
     * Selects identification feedback while preserving the empty manual-input special case.
     *
     * @param  PassFindingFailure  $failure  Cause already classified by the lookup owner.
     * @param  string  $method  Untrusted lookup method used for the manual-input message.
     * @param  string  $credential  Private input inspected only for the existing empty-input rule.
     * @return VisitRegistrationFailure Safe presentation feedback, without altering the domain cause.
     */
    private function feedbackForIdentification(
        PassFindingFailure $failure,
        string $method,
        #[SensitiveParameter] string $credential,
    ): VisitRegistrationFailure {
        if (
            $method === 'manual'
            && trim($credential) === ''
            && $failure === PassFindingFailure::InvalidCredential
        ) {
            return VisitRegistrationFailure::ManualRequired;
        }

        return match ($failure) {
            PassFindingFailure::Forbidden => VisitRegistrationFailure::Forbidden,
            PassFindingFailure::InvalidCredential => VisitRegistrationFailure::InvalidCredential,
            PassFindingFailure::InvalidAttemptLimit => VisitRegistrationFailure::Throttled,
            PassFindingFailure::AuthoritativeReadFailed,
            PassFindingFailure::AmbiguousActivePromotion => VisitRegistrationFailure::ReadUnavailable,
        };
    }

    /**
     * Maps definitive acceptance rejections to their existing presentation feedback.
     *
     * @param  VisitAcceptanceFailure  $failure  Cause already classified by the acceptance owner.
     * @return VisitRegistrationFailure Safe feedback for a known rejection.
     */
    private function feedbackForAcceptance(VisitAcceptanceFailure $failure): VisitRegistrationFailure
    {
        return match ($failure) {
            VisitAcceptanceFailure::Forbidden => VisitRegistrationFailure::Forbidden,
            VisitAcceptanceFailure::InvalidCredential => VisitRegistrationFailure::InvalidCredential,
            VisitAcceptanceFailure::InvalidAttemptLimit => VisitRegistrationFailure::Throttled,
            VisitAcceptanceFailure::PromotionChanged => VisitRegistrationFailure::PromotionChanged,
            VisitAcceptanceFailure::PromotionUnavailable => VisitRegistrationFailure::NoPromotion,
            VisitAcceptanceFailure::PromotionCompleted => VisitRegistrationFailure::Completed,
            VisitAcceptanceFailure::RewardNotEarned => VisitRegistrationFailure::RewardNotEarned,
            default => VisitRegistrationFailure::NotAccepted,
        };
    }

    /**
     * Builds translated messages and the existing client aliases from the single failure enum.
     *
     * @return array{
     *     errors: array<string, array{message: string, notice: string}>,
     *     camera: array<string, string>,
     *     codes: array<string, string>
     * } Feedback catalog shared by Blade, Alpine and the camera scanner.
     */
    private function buildClientFailures(): array
    {
        $errors = [];

        foreach (VisitRegistrationFailure::cases() as $failure) {
            $errors[$failure->value] = [
                'message' => __($failure->translationKey()),
                'notice' => __($failure->noticeKey()),
            ];
        }

        return [
            'errors' => $errors,
            'camera' => [
                'DENIED' => VisitRegistrationFailure::CameraDenied->value,
                'BUSY' => VisitRegistrationFailure::CameraBusy->value,
                'UNAVAILABLE' => VisitRegistrationFailure::CameraUnavailable->value,
                'INITIALIZATION' => VisitRegistrationFailure::CameraInitialization->value,
            ],
            'codes' => [
                'network' => VisitRegistrationFailure::NetworkError->value,
                'transport' => VisitRegistrationFailure::TransportFailed->value,
                'read' => VisitRegistrationFailure::ReadUnavailable->value,
                'unknown' => VisitRegistrationFailure::Unknown->value,
                'details' => VisitRegistrationFailure::DetailsUnavailable->value,
                'rewardDetails' => VisitRegistrationFailure::RewardDetailsUnavailable->value,
            ],
        ];
    }

    // -----------------------------------------------------------------------------
    // observation authorization
    // -----------------------------------------------------------------------------

    /**
     * Rechecks the verified business owner before consuming the existing telemetry allowance.
     *
     * @return int Authorized business identifier.
     *
     * @throws HttpException When unauthorized or throttled.
     */
    private function authorizeClientObservation(): int
    {
        $actor = Auth::user();

        abort_unless(
            $actor instanceof User
            && $actor->exists
            && $actor->hasVerifiedEmail(),
            403,
        );

        $businessId = $actor->business()
            ->value('id');

        abort_if($businessId === null, 403);

        $rateLimitKey = 'visit-client-observation:'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($rateLimitKey, 20), 429);
        RateLimiter::hit($rateLimitKey, 60);

        return (int) $businessId;
    }
};
