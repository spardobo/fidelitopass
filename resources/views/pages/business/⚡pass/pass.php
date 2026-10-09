<?php

use App\Actions\Promotions\CancelPromotion;
use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\PromotionMultiplierWindow;
use App\Support\DatabaseClock;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

new #[Layout('layouts::app'), Title('business.pass.title')] class extends Component
{
    use WithPagination;

    private const PREVIEW_FALLBACK = '#A77BFF';

    private const MINIMUM_PREVIEW_CONTRAST = 4.5;

    private const COLOR_PRESETS = [
        '#A77BFF',
        '#E53935',
        '#2C3E50',
        '#1E88E5',
        '#43A047',
        '#8E24AA',
        '#FB8C00',
        '#000000',
    ];

    private const COLOR_PRESET_NAMES = [
        'business.pass.preset_lavender',
        'business.pass.preset_red',
        'business.pass.preset_slate',
        'business.pass.preset_blue',
        'business.pass.preset_green',
        'business.pass.preset_violet',
        'business.pass.preset_orange',
        'business.pass.preset_black',
    ];

    public ?string $backgroundColor = null;

    #[Locked]
    public bool $editing = false;

    #[Locked]
    public bool $draftSavedNoticePending = false;

    #[Locked]
    public string $publicationNotice = '';

    #[Locked]
    public ?string $selectedPromotionId = null;

    #[Locked]
    public bool $confirmingPromotionCancellation = false;

    /**
     * Initializes the appearance editor after authorizing the Business and loading its current color.
     * Consume one-time Promotion draft-save or publication feedback for the Pase page.
     *
     * @throws AuthorizationException When the actor cannot update the Business.
     * @throws ModelNotFoundException When the actor has no Business.
     */
    public function mount(): void
    {
        $business = $this->authorizedBusiness();

        $this->editing = request()->routeIs('business.pass.appearance');
        $this->backgroundColor = $business->pass_background_color ?? self::PREVIEW_FALLBACK;

        $this->draftSavedNoticePending = (bool) Session::pull('business.promotion.draft_saved');
        $publicationNotice = Session::pull('business.promotion.publication_notice');
        $this->publicationNotice = $publicationNotice === 'published'
            ? 'published'
            : '';
    }

    /**
     * Sets a supported appearance color for the local preview without persisting it.
     *
     * @param  string  $color  Hex value selected from the configured preset palette.
     *
     * @throws AuthorizationException When the actor cannot update the Business.
     * @throws ModelNotFoundException When the actor has no Business.
     * @throws ValidationException When the supplied color is not an allowed preset.
     */
    public function selectColor(string $color): void
    {
        $this->authorizedBusiness();

        if (! in_array($color, self::COLOR_PRESETS, true)) {
            throw ValidationException::withMessages([
                'backgroundColor' => __('business.pass.invalid_color'),
            ]);
        }

        $this->backgroundColor = $color;
    }

    /**
     * Validates and persist the selected appearance, then show a success toast and return to Pase.
     *
     * @throws AuthorizationException When the actor cannot update the Business.
     * @throws ModelNotFoundException When the actor has no Business.
     * @throws ValidationException When the selected color is invalid.
     */
    public function save(): void
    {
        $business = $this->authorizedBusiness();

        $validated = $this->validate([
            'backgroundColor' => ['required', 'string', 'regex:/\A#[0-9A-Fa-f]{6}\z/'],
        ]);

        $business->update([
            'pass_background_color' => strtoupper($validated['backgroundColor']),
        ]);

        $this->resetValidation();
        Flux::toast(__('business.pass.saved_status'), null, 5000, 'success');
        $this->redirectRoute('business.pass', navigate: true);
    }

    /**
     * Restores the persisted appearance, clear validation state, and leave without saving pending changes.
     *
     * @throws AuthorizationException When the actor cannot update the Business.
     * @throws ModelNotFoundException When the actor has no Business.
     */
    public function cancel(): void
    {
        $business = $this->authorizedBusiness();

        $this->backgroundColor = $business->pass_background_color ?? self::PREVIEW_FALLBACK;
        $this->resetValidation();
        $this->redirectRoute('business.pass', navigate: true);
    }

    /**
     * Opens persisted Promotion terms after resolving the selected identity within the owned Business.
     *
     * @param  mixed  $publicId  Untrusted public identifier requested by a Pase detail trigger.
     *
     * @throws AuthorizationException When the actor cannot update the Business.
     * @throws ModelNotFoundException When the actor has no Business.
     * @throws HttpException When the Promotion identifier is invalid or unavailable.
     */
    public function showPromotionDetail(mixed $publicId): void
    {
        abort_unless(is_string($publicId) && Str::isUuid($publicId), 404);
        $this->confirmingPromotionCancellation = false;
        $this->resetValidation('promotionCancellation');
        $this->selectedPromotionId = $publicId;
        unset($this->promotionDetail);

        $this->promotionDetail;
        Flux::modal('promotion-detail')->show();
    }

    /**
     * Clears only the selected detail without changing appearance or listing pagination.
     */
    public function dismissPromotionDetail(): void
    {
        $this->selectedPromotionId = null;
        $this->confirmingPromotionCancellation = false;
        $this->resetValidation('promotionCancellation');
        unset($this->promotionDetail);
    }

    /**
     * Arms inline cancellation only after freshly resolving an owned eligible Promotion.
     *
     * @throws AuthorizationException When the actor cannot update the Business.
     * @throws ModelNotFoundException When the actor has no Business.
     * @throws HttpException When the selected Promotion is invalid or unavailable.
     */
    public function requestPromotionCancellation(): void
    {
        $this->confirmingPromotionCancellation = false;
        $this->resetValidation('promotionCancellation');
        unset($this->promotionDetail);
        $detail = $this->promotionDetail;

        if ($detail === null || ! in_array($detail['phase'], ['active', 'scheduled'], true)) {
            $message = match ($detail['phase'] ?? null) {
                'cancelled' => __('business.promotion.already_cancelled'),
                'ended' => __('business.promotion.cancel_ended'),
                default => __('business.pass.cancel_confirmation_required'),
            };
            $this->showCancellationError($message);

            return;
        }

        $this->confirmingPromotionCancellation = true;
        $this->dispatch('promotion-cancellation-focus', target: 'promotion-cancellation-heading');
    }

    /**
     * Consumes server confirmation and delegates the locked transition to its existing Action.
     *
     * @param  CancelPromotion  $cancelPromotion  Authoritative cancellation boundary.
     *
     * @throws AuthorizationException When the actor cannot update the selected Promotion.
     * @throws ModelNotFoundException When the actor or owned Promotion is unavailable.
     * @throws HttpException When the selected Promotion is invalid or unavailable.
     */
    public function confirmPromotionCancellation(CancelPromotion $cancelPromotion): void
    {
        unset($this->promotionDetail);
        $detail = $this->promotionDetail;
        $this->resetValidation('promotionCancellation');

        if (! $this->confirmingPromotionCancellation || $detail === null) {
            $this->showCancellationError(__('business.pass.cancel_confirmation_required'));

            return;
        }

        $this->confirmingPromotionCancellation = false;

        try {
            $cancelPromotion->handle(Auth::user(), $detail['promotion']);
        } catch (ValidationException $exception) {
            $message = $exception->errors()['promotion'][0] ?? '';
            $safeMessages = [
                __('business.promotion.already_cancelled'),
                __('business.promotion.cancel_only_published'),
                __('business.promotion.cancel_ended'),
            ];
            $this->showCancellationError(in_array($message, $safeMessages, true)
                ? $message : __('business.pass.cancel_error_unexpected'));

            return;
        } catch (AuthorizationException|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->showCancellationError(__('business.pass.cancel_error_unexpected'));

            return;
        }

        unset($this->promotionDetail, $this->currentPromotionListings);
        $this->resetPage('historyPage');
        Flux::toast(__('business.pass.promotion_cancelled_notice'), null, 5000, 'success');
        $this->dispatch('promotion-cancellation-focus', target: 'promotion-detail-heading');
    }

    /**
     * Presents safe cancellation feedback and refreshes the selected phase and current listings.
     *
     * @param  string  $message  Localized allowlisted domain feedback or generic failure text.
     */
    private function showCancellationError(string $message): void
    {
        unset($this->promotionDetail, $this->currentPromotionListings);
        $this->addError('promotionCancellation', $message);
        $this->dispatch('promotion-cancellation-focus', target: 'promotion-cancellation-error');
    }

    /**
     * Reloads owned frozen terms and derives the current phase from a fresh PostgreSQL instant.
     *
     * @return array{
     *     promotion: Promotion, phase: string, start_date: string, end_date: string,
     *     extra_points: list<array{weekday: int, start_time: string|null, end_time: string|null, multiplier: int}>
     * }|null Persisted detail for this request, or no selection.
     *
     * @throws AuthorizationException When the actor cannot update the Business.
     * @throws ModelNotFoundException When the actor has no Business.
     * @throws HttpException When the selected Promotion is invalid or unavailable.
     */
    #[Computed]
    public function promotionDetail(): ?array
    {
        if ($this->selectedPromotionId === null) {
            return null;
        }

        abort_unless(Str::isUuid($this->selectedPromotionId), 404);
        $business = $this->authorizedBusiness();
        $promotion = $business->promotions()
            ->where('public_id', $this->selectedPromotionId)
            ->whereIn('status', [PromotionStatus::Published->value, PromotionStatus::Cancelled->value])
            ->with(['extraPoints' => fn ($query) => $query->orderBy('weekday')->orderBy('start_time')->orderBy('id')])
            ->first();
        abort_if($promotion === null, 404);

        $instant = CarbonImmutable::parse(
            app(DatabaseClock::class)->captureForBusinessTimezone($business->timezone)['instant'],
        );
        $phase = match (true) {
            $promotion->status === PromotionStatus::Cancelled => 'cancelled',
            $instant->lessThan($promotion->starts_at) => 'scheduled',
            $instant->greaterThanOrEqualTo($promotion->ends_at) => 'ended',
            default => 'active',
        };

        return [
            'promotion' => $promotion,
            'phase' => $phase,
            'start_date' => $promotion->starts_at->setTimezone($promotion->timezone_snapshot)->format('d/m/Y'),
            'end_date' => $promotion->ends_at->setTimezone($promotion->timezone_snapshot)->subDay()->format('d/m/Y'),
            'extra_points' => $promotion->extraPoints->map(fn (PromotionMultiplierWindow $rule): array => [
                'weekday' => $rule->weekday,
                'start_time' => $rule->start_time === null ? null : substr($rule->start_time, 0, 5),
                'end_time' => $rule->end_time === null ? null : substr($rule->end_time, 0, 5),
                'multiplier' => $rule->multiplier,
            ])->all(),
        ];
    }

    #[Computed]
    public function previewColor(): string
    {
        return $this->isValidColor($this->backgroundColor)
            ? strtoupper($this->backgroundColor)
            : self::PREVIEW_FALLBACK;
    }

    #[Computed]
    public function previewTextColor(): string
    {
        $backgroundLuminance = $this->relativeLuminance($this->previewColor);
        $darkInkLuminance = $this->relativeLuminance('#17131F');
        $whiteLuminance = 1.0;

        $darkInkContrast = $this->contrastRatio($backgroundLuminance, $darkInkLuminance);
        $whiteContrast = $this->contrastRatio($backgroundLuminance, $whiteLuminance);

        if ($darkInkContrast >= self::MINIMUM_PREVIEW_CONTRAST) {
            return '#17131F';
        }

        if ($whiteContrast >= self::MINIMUM_PREVIEW_CONTRAST) {
            return '#FFFFFF';
        }

        return '#000000';
    }

    #[Computed]
    public function colorPresets(): array
    {
        return self::COLOR_PRESETS;
    }

    #[Computed]
    public function colorPresetNames(): array
    {
        return array_map(
            static fn (string $translationKey): string => __($translationKey),
            self::COLOR_PRESET_NAMES,
        );
    }

    #[Computed]
    public function business(): Business
    {
        return $this->authorizedBusiness();
    }

    /**
     * Returns only the authenticated Business's draft promotions in stable, paginated order.
     *
     * @return LengthAwarePaginator<int, Promotion> Owned drafts ordered by local start date, update time, and ID.
     *
     * @throws AuthorizationException When the actor cannot update the Business.
     * @throws ModelNotFoundException When the actor has no Business.
     */
    #[Computed]
    public function draftPromotions(): LengthAwarePaginator
    {
        return $this->authorizedBusiness()->promotions()
            ->where('status', PromotionStatus::Draft->value)
            ->orderByRaw('local_start_date ASC NULLS LAST')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(3);
    }

    /**
     * Returns owned active, scheduled and historical Promotions with independent pagination.
     * Captures one database instant so every lifecycle group shares the same boundary.
     *
     * @return array{
     *     active: array{promotion: Promotion, period: string}|null,
     *     scheduled: LengthAwarePaginator<int, array{promotion: Promotion, period: string}>,
     *     history: LengthAwarePaginator<int, array{promotion: Promotion, period: string, phase: string}>
     * } Published and cancelled rows with inclusive dates in their frozen publication timezone.
     *
     * @throws AuthorizationException When the actor cannot update the Business.
     * @throws ModelNotFoundException When the actor has no Business.
     */
    #[Computed]
    public function currentPromotionListings(): array
    {
        $business = $this->authorizedBusiness();
        $instant = CarbonImmutable::parse(
            app(DatabaseClock::class)->captureForBusinessTimezone($business->timezone)['instant'],
        );

        $activePromotion = $business->promotions()
            ->where('status', PromotionStatus::Published->value)
            ->where('starts_at', '<=', $instant)
            ->where('ends_at', '>', $instant)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->first();

        $formatPeriod = static function (Promotion $promotion): string {
            $timezone = $promotion->timezone_snapshot;
            $start = $promotion->starts_at->setTimezone($timezone)->format('d/m/Y');
            $end = $promotion->ends_at->setTimezone($timezone)->subDay()->format('d/m/Y');

            return __('business.pass.promotion_published_period', ['start' => $start, 'end' => $end]);
        };
        $scheduled = $business->promotions()
            ->where('status', PromotionStatus::Published->value)
            ->where('starts_at', '>', $instant)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate(3, ['*'], 'scheduledPage')
            ->through(fn (Promotion $promotion): array => [
                'promotion' => $promotion,
                'period' => $formatPeriod($promotion),
            ]);
        $history = $business->promotions()
            ->where(function ($query) use ($instant): void {
                $query->where('status', PromotionStatus::Cancelled->value)
                    ->orWhere(fn ($published) => $published
                        ->where('status', PromotionStatus::Published->value)
                        ->where('ends_at', '<=', $instant));
            })
            ->orderByRaw('COALESCE(cancelled_at, ends_at) DESC')
            ->orderByDesc('id')
            ->paginate(3, ['*'], 'historyPage')
            ->through(fn (Promotion $promotion): array => [
                'promotion' => $promotion,
                'period' => $formatPeriod($promotion),
                'phase' => $promotion->status === PromotionStatus::Cancelled ? 'cancelled' : 'ended',
            ]);

        return [
            'active' => $activePromotion === null ? null : [
                'promotion' => $activePromotion,
                'period' => $formatPeriod($activePromotion),
            ],
            'scheduled' => $scheduled,
            'history' => $history,
        ];
    }

    #[Computed]
    public function isDirty(): bool
    {
        $savedColor = $this->authorizedBusiness()->pass_background_color ?? self::PREVIEW_FALLBACK;

        return strtoupper($this->backgroundColor ?? '') !== strtoupper($savedColor);
    }

    private function authorizedBusiness(): Business
    {
        $business = Auth::user()->business()->firstOrFail();
        Gate::authorize('update', $business);

        return $business;
    }

    private function isValidColor(?string $color): bool
    {
        return is_string($color) && preg_match('/\A#[0-9A-Fa-f]{6}\z/', $color) === 1;
    }

    private function relativeLuminance(string $color): float
    {
        $red = hexdec(substr($color, 1, 2)) / 255;
        $green = hexdec(substr($color, 3, 2)) / 255;
        $blue = hexdec(substr($color, 5, 2)) / 255;

        return 0.2126 * $this->linearize($red)
            + 0.7152 * $this->linearize($green)
            + 0.0722 * $this->linearize($blue);
    }

    private function contrastRatio(float $firstLuminance, float $secondLuminance): float
    {
        $lighter = max($firstLuminance, $secondLuminance);
        $darker = min($firstLuminance, $secondLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function linearize(float $channel): float
    {
        return $channel <= 0.04045
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;
    }
};
