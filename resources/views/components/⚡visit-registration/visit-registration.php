<?php

use App\Actions\Visits\AcceptVisit;
use App\Enums\FailureOutcome;
use App\Enums\PassFindingFailure;
use App\Enums\VisitAcceptanceFailure;
use App\Exceptions\CustomerPassFindingException;
use App\Exceptions\GoogleWalletSynchronizationException;
use App\Exceptions\VisitAcceptanceException;
use App\Models\User;
use App\Support\CustomerPassFinder;
use App\Support\VisitReceipt;
use Flux\Flux;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Owns rendered operational stages without hydrating private credentials. */
new class extends Component
{
    #[Locked]
    public string $stage = 'identify';

    /** @var array<string, mixed>|null Selected read-only hint, never mutation authority. */
    #[Locked]
    public ?array $preview = null;

    /** @var array<string, mixed>|null Six immutable accepted Visit facts. */
    #[Locked]
    public ?array $facts = null;

    /** @var array<string, mixed>|null Original Promotion receipt details. */
    #[Locked]
    public ?array $details = null;

    #[Locked]
    public string $message = '';

    /** Restores clean identification after an explicitly resolved operation. */
    public function resetIdentification(): void
    {
        $this->reset('stage', 'preview', 'facts', 'details', 'message');
    }

    /**
     * Resolves a current credential and renders only safe operational hints.
     *
     * @param  CustomerPassFinder  $finder  Injected read-only credential resolver.
     * @param  string  $method  Untrusted token or manual method.
     * @param  string  $credential  Private action argument, never component state.
     * @return string|null New caller-owned operation UUID for private page memory, or no selection.
     */
    public function findPass(CustomerPassFinder $finder, string $method, #[SensitiveParameter] string $credential): ?string
    {
        $this->resetIdentification();

        try {
            $actor = Auth::user();
            $found = $finder->find($actor instanceof User ? $actor : null, $method, $credential);
            $this->preview = Arr::only($found, ['checked_at', 'promotion', 'can_accept_visit', 'can_redeem_reward']);
            $this->stage = 'confirm';

            return (string) Str::uuid();
        } catch (CustomerPassFindingException $exception) {
            $this->message = $method === 'manual'
                && trim($credential) === ''
                && $exception->failure === PassFindingFailure::InvalidCredential
                    ? __('visit-registration.manual_required')
                    : $this->passFindingMessage($exception);
        } catch (Throwable $exception) {
            report($exception);
            $this->message = __('visit-registration.read_unavailable');
        }

        Flux::toast(variant: 'danger', text: __('visit-registration.error_notice'));

        return null;
    }

    /**
     * Accepts one explicit operation, leaving ancillary receipt reads separate from known facts.
     *
     * @param  AcceptVisit  $acceptVisit  Injected owner of the acceptance transaction.
     * @param  string  $method  Current private credential method.
     * @param  string  $credential  Private credential revalidated by the owning Action.
     * @param  string  $operationId  Same UUID retained throughout this intentional operation.
     * @param  int  $expectedPromotionId  Selected offer comparison, never target authority.
     * @return string Confirmed, rejected or unresolved outcome for the private submission guard.
     */
    public function acceptVisit(
        AcceptVisit $acceptVisit,
        string $method,
        #[SensitiveParameter] string $credential,
        string $operationId,
        int $expectedPromotionId,
    ): string {
        $this->message = '';
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
            $this->stage = 'result';

            return 'confirmed';
        } catch (VisitAcceptanceException $exception) {
            $outcome = $exception->localOutcome === FailureOutcome::Unknown ? 'unknown' : 'rejected';
            $this->message = $outcome === 'unknown' ? __('visit-registration.unknown') : match ($exception->failure) {
                VisitAcceptanceFailure::Forbidden => __('visit-registration.forbidden'),
                VisitAcceptanceFailure::InvalidCredential => __('visit-registration.invalid_credential'),
                VisitAcceptanceFailure::InvalidAttemptLimit => __('visit-registration.throttled'),
                VisitAcceptanceFailure::PromotionChanged => __('visit-registration.promotion_changed'),
                VisitAcceptanceFailure::PromotionUnavailable => __('visit-registration.no_promotion'),
                VisitAcceptanceFailure::PromotionCompleted => __('visit-registration.completed'),
                default => __('visit-registration.not_accepted'),
            };
        } catch (GoogleWalletSynchronizationException) {
            // the Action already owns the provider failure event
            $this->message = __('visit-registration.wallet_unavailable');
        } catch (Throwable $exception) {
            report($exception);
            $outcome = 'unknown';
            $this->message = __('visit-registration.unknown');
        }

        Flux::toast(variant: 'danger', text: __('visit-registration.error_notice'));

        return $outcome;
    }

    /**
     * Reads the original operation without erasing already known committed facts on failure.
     *
     * @param  VisitReceipt  $receipts  Injected original-operation reader.
     * @param  CustomerPassFinder  $finder  Injected credential validator for malformed operation identifiers.
     * @param  string  $method  Current private credential method.
     * @param  string  $credential  Private action argument revalidated on this request.
     * @param  string  $operationId  Same unresolved or committed operation UUID.
     * @return bool Whether this read found an authorized receipt; false never proves rollback.
     */
    public function findVisitReceipt(
        VisitReceipt $receipts,
        CustomerPassFinder $finder,
        string $method,
        #[SensitiveParameter] string $credential,
        string $operationId,
    ): bool {
        try {
            $actor = Auth::user();
            $actor = $actor instanceof User ? $actor : null;
            if (! Str::isUuid($operationId)) {
                $finder->find($actor, $method, $credential);
                $receipt = null;
            } else {
                $receipt = $receipts->find($actor, $method, $credential, $operationId);
            }

            if ($receipt !== null) {
                $this->facts = Arr::only($receipt, ['visit_id', 'operation_id', 'customer_pass_id', 'promotion_id', 'points_awarded', 'confirmed_at']);
                $this->details = $receipt['promotion'];
                $this->preview = null;
                $this->message = '';
                $this->stage = 'result';

                return true;
            }

            $this->message = __('visit-registration.unknown');
        } catch (CustomerPassFindingException $exception) {
            $this->message = $exception->failure === PassFindingFailure::Forbidden
                ? __('visit-registration.forbidden')
                : __('visit-registration.unknown');
        } catch (Throwable $exception) {
            report($exception);
            $this->message = __('visit-registration.unknown');
        }

        if ($this->facts !== null) {
            $this->message = __('visit-registration.details_unavailable');
        }
        Flux::toast(variant: 'danger', text: __($this->facts !== null ? 'visit-registration.details_notice' : 'visit-registration.error_notice'));

        return false;
    }

    /**
     * Maps handled pass-finding failures without duplicating the owner's event.
     *
     * @param  CustomerPassFindingException  $exception  Already handled domain failure.
     * @return string Safe translated feedback without credential-enumeration details.
     */
    private function passFindingMessage(CustomerPassFindingException $exception): string
    {
        return match ($exception->failure) {
            PassFindingFailure::Forbidden => __('visit-registration.forbidden'),
            PassFindingFailure::InvalidCredential => __('visit-registration.invalid_credential'),
            PassFindingFailure::InvalidAttemptLimit => __('visit-registration.throttled'),
            PassFindingFailure::AuthoritativeReadFailed,
            PassFindingFailure::AmbiguousActivePromotion => __('visit-registration.read_unavailable'),
        };
    }
};
