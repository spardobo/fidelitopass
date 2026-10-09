<?php

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Support\DatabaseClock;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

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

    /**
     * Initialize the appearance editor after authorizing the Business and loading its current color.
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
     * Set a supported appearance color for the local preview without persisting it.
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
     * Validate and persist the selected appearance, then show a success toast and return to Pase.
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
     * Restore the persisted appearance, clear validation state, and leave without saving pending changes.
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
     * Return only the authenticated Business's draft promotions in stable, paginated order.
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
     * Return the owned currently active Promotion and every future scheduled Promotion.
     * Capture one database instant so both lifecycle groups share the same boundary.
     *
     * @return array{
     *     active: array{promotion: Promotion, period: string}|null,
     *     scheduled: LengthAwarePaginator<int, array{promotion: Promotion, period: string}>
     * } Current published rows with inclusive dates formatted in their frozen publication timezone.
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

        return [
            'active' => $activePromotion === null ? null : [
                'promotion' => $activePromotion,
                'period' => $formatPeriod($activePromotion),
            ],
            'scheduled' => $scheduled,
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
