<?php

use App\Models\Business;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app'), Title('business.pass.title')] class extends Component
{
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

    public ?string $backgroundColor = null;

    #[Locked]
    public bool $editing = false;

    public function mount(): void
    {
        $business = $this->authorizedBusiness();

        $this->editing = request()->routeIs('business.pass.appearance');
        $this->backgroundColor = $business->pass_background_color ?? self::PREVIEW_FALLBACK;
    }

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
        $this->redirectRoute('business.pass', navigate: true);
    }

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
    public function business(): Business
    {
        return $this->authorizedBusiness();
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
