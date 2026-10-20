<?php

use App\Actions\Visits\AcceptVisit;
use App\Enums\FailureOutcome;
use App\Enums\PassFindingFailure;
use App\Enums\VisitAcceptanceFailure;
use App\Enums\VisitRegistrationFailure;
use App\Enums\VisitRegistrationStage;
use App\Exceptions\CustomerPassFindingException;
use App\Exceptions\GoogleWalletSynchronizationException;
use App\Exceptions\VisitAcceptanceException;
use App\Exceptions\VisitRegistrationException;
use App\Models\User;
use App\Support\CustomerPassFinder;
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

    #[Locked]
    public string $errorCode = '';

    /**
     * Supplies the same translated failure catalog to Blade and the browser.
     *
     * @return array{
     *     errorMessage: string,
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
        $this->reset('stage', 'preview', 'facts', 'details', 'errorCode');
    }

    /**
     * Resolves a credential and selects a read-only preview for confirmation.
     *
     * @param  CustomerPassFinder  $finder  Injected owner of credential lookup and its outcome logging.
     * @param  string  $method  Untrusted token or manual method.
     * @param  string  $credential  Private action argument, never component state.
     * @return string|null Browser-owned operation UUID, or null when identification fails.
     */
    public function findPass(
        CustomerPassFinder $finder,
        string $method,
        #[SensitiveParameter] string $credential,
    ): ?string {
        $this->resetIdentification();

        try {
            $actor = Auth::user();
            $pass = $finder->find($actor instanceof User ? $actor : null, $method, $credential);
            $this->showConfirmation($pass);

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
     * @return 'confirmed'|'rejected'|'unknown' Acceptance outcome for the browser's submission guard.
     */
    public function acceptVisit(
        AcceptVisit $acceptVisit,
        string $method,
        #[SensitiveParameter] string $credential,
        string $operationId,
        int $expectedPromotionId,
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
            );
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
        } catch (GoogleWalletSynchronizationException) {
            // the action already owns the provider failure event
            $failure = VisitRegistrationFailure::WalletUnavailable;
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
     * @param  string  $method  Private credential method revalidated on this request.
     * @param  string  $credential  Private action argument, never component state.
     * @param  string  $operationId  Same unresolved or committed operation UUID.
     * @param  bool  $showResult  Whether a found receipt replaces the rendered step.
     * @return bool Whether an authorized receipt was found; false never proves rollback.
     */
    public function findVisitReceipt(
        VisitReceipt $receipts,
        CustomerPassFinder $finder,
        string $method,
        #[SensitiveParameter] string $credential,
        string $operationId,
        bool $showResult = true,
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
                if ($showResult) {
                    $this->showReceipt($receipt);
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
            $failure = VisitRegistrationFailure::DetailsUnavailable;
        }

        $this->presentFailure($failure);

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
