<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows an unsaved pass appearance without persisting its preview fallback', function () {
    $business = Business::factory()->create();

    $this->actingAs($business->user)
        ->get(route('business.pass'))
        ->assertOk()
        ->assertSee(__('business.pass.unsaved_status'))
        ->assertSee(__('business.pass.preview_fallback'));

    expect($business->fresh()->pass_background_color)->toBeNull();
});

it('keeps the pass overview focused on appearance status and promotions', function () {
    $business = Business::factory()->create();

    $this->actingAs($business->user)
        ->get(route('business.pass'))
        ->assertOk()
        ->assertSee(__('business.pass.prepare'))
        ->assertSee(__('business.pass.promotions_prerequisite'))
        ->assertDontSee('pass-background-color')
        ->assertDontSee('pass-background-hex');
});

it('opens a separate appearance editor with a savable fallback draft', function () {
    $business = Business::factory()->create();

    $this->actingAs($business->user)
        ->get(route('business.pass.appearance'))
        ->assertOk()
        ->assertSee(__('business.pass.editor_title'))
        ->assertSee('pass-background-color')
        ->assertSee('pass-background-hex');

    Livewire::actingAs($business->user)
        ->test('pages::business.pass')
        ->assertSet('backgroundColor', '#A77BFF')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($business->user)
        ->test('pages::business.pass')
        ->set('backgroundColor', '#E53935')
        ->assertSet('isDirty', true);

    expect($business->fresh()->pass_background_color)->toBe('#A77BFF');
});

it('keeps the untouched fallback draft clean and compares hex values case-insensitively', function () {
    $business = Business::factory()->create();

    Livewire::actingAs($business->user)
        ->test('pages::business.pass')
        ->assertSet('backgroundColor', '#A77BFF')
        ->assertSet('isDirty', false)
        ->set('backgroundColor', '#a77bff')
        ->assertSet('isDirty', false)
        ->set('backgroundColor', '#E53935')
        ->assertSet('isDirty', true);

    $savedBusiness = Business::factory()->create(['pass_background_color' => '#A77BFF']);

    Livewire::actingAs($savedBusiness->user)
        ->test('pages::business.pass')
        ->set('backgroundColor', '#a77bff')
        ->assertSet('isDirty', false);
});

it('uses the application theme and primary action roles on both pass screens', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);

    $this->get(route('business.pass.appearance'))
        ->assertSee('class="app-theme mx-auto', false)
        ->assertSee('app-button-primary min-h-11', false);

    $this->get(route('business.pass'))
        ->assertSee('class="app-theme mx-auto', false)
        ->assertSee('app-button-primary min-h-11', false);
});

it('uses a static landing-inspired pass shape without sample promotion or customer data', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);

    foreach ([route('business.pass'), route('business.pass.appearance')] as $route) {
        $this->get($route)
            ->assertSee('aspect-[3/2]', false)
            ->assertSee('border-current/25', false)
            ->assertSee('shadow-2xl', false)
            ->assertDontSee('data-pass', false)
            ->assertDontSee('preview-pass-qr.svg', false)
            ->assertDontSee('landing-pass', false);
    }
});

it('leaves an unset appearance unset when the editor is cancelled', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);

    Livewire::test('pages::business.pass')
        ->set('backgroundColor', '#E53935')
        ->call('cancel')
        ->assertSet('backgroundColor', '#A77BFF');

    expect($business->fresh()->pass_background_color)->toBeNull();
});

it('saves a valid appearance and restores the persisted value when cancelled', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);

    Livewire::test('pages::business.pass')
        ->set('backgroundColor', '#a77bff')
        ->call('save')
        ->assertHasNoErrors();

    expect($business->fresh()->pass_background_color)->toBe('#A77BFF');

    Livewire::test('pages::business.pass')
        ->assertSet('backgroundColor', '#A77BFF')
        ->set('backgroundColor', '#E53935')
        ->call('cancel')
        ->assertSet('backgroundColor', '#A77BFF');

    $this->get(route('business.pass'))->assertSee('#A77BFF');
});

it('rejects malformed appearance colors without changing the saved value', function () {
    $business = Business::factory()->create(['pass_background_color' => '#2C3E50']);
    $this->actingAs($business->user);

    Livewire::test('pages::business.pass')
        ->set('backgroundColor', '#12')
        ->call('save')
        ->assertHasErrors(['backgroundColor']);

    expect($business->fresh()->pass_background_color)->toBe('#2C3E50');
});

it('keeps pass preview text at accessible contrast across presets and boundary colors', function () {
    $business = Business::factory()->create();
    $relativeLuminance = static function (string $color): float {
        $channels = [
            hexdec(substr($color, 1, 2)) / 255,
            hexdec(substr($color, 3, 2)) / 255,
            hexdec(substr($color, 5, 2)) / 255,
        ];
        $linear = array_map(
            static fn (float $channel): float => $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            $channels,
        );

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    };
    $contrast = static function (string $first, string $second) use ($relativeLuminance): float {
        $firstLuminance = $relativeLuminance($first);
        $secondLuminance = $relativeLuminance($second);
        $lighter = max($firstLuminance, $secondLuminance);
        $darker = min($firstLuminance, $secondLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    };
    $colors = [
        '#A77BFF', '#E53935', '#2C3E50', '#1E88E5', '#43A047', '#8E24AA', '#FB8C00', '#000000',
        '#FFFFFF', '#777777', '#FFFF00', '#00FFFF', '#FF00FF', '#808080', '#101010',
    ];

    $component = Livewire::actingAs($business->user)->test('pages::business.pass');

    foreach ($colors as $color) {
        $darkInk = '#17131F';
        $expectedInk = $contrast($darkInk, $color) >= 4.5
            ? $darkInk
            : ($contrast('#FFFFFF', $color) >= 4.5 ? '#FFFFFF' : '#000000');

        expect($contrast($expectedInk, $color))->toBeGreaterThanOrEqual(4.5);
        $component->set('backgroundColor', $color)->assertSee("color: {$expectedInk}");
    }
});

it('rechecks business ownership before saving an appearance', function () {
    $business = Business::factory()->create();
    $component = Livewire::actingAs($business->user)
        ->test('pages::business.pass')
        ->set('backgroundColor', '#E53935');
    $newOwner = User::factory()->create();

    $business->newQuery()->whereKey($business->getKey())->update(['user_id' => $newOwner->id]);

    expect(fn () => $component->call('save'))->toThrow(ModelNotFoundException::class);
    expect($business->fresh()->pass_background_color)->toBeNull();
});

it('sends anonymous and unverified visitors through the protected page boundary', function () {
    foreach ([route('business.pass'), route('business.pass.appearance')] as $route) {
        $this->get($route)->assertRedirect(route('login'));
    }

    $unverifiedOwner = User::factory()->unverified()->create();
    Business::factory()->for($unverifiedOwner)->create();

    foreach ([route('business.pass'), route('business.pass.appearance')] as $route) {
        $this->actingAs($unverifiedOwner)
            ->get($route)
            ->assertRedirect(route('verification.notice'));
    }
});
