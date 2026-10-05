<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_appearance_settings_require_authentication(): void
    {
        $this->get(route('appearance.edit'))->assertRedirect(route('login'));
    }

    public function test_bookmarked_appearance_page_is_static_and_dark(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('appearance.edit'))
            ->assertOk()
            ->assertSee('class="dark"', false)
            ->assertSee('Apariencia')
            ->assertSee('El modo oscuro está siempre activo')
            ->assertSee('El tema oscuro está activo para todas las cuentas.')
            ->assertDontSee('Dark mode is always on')
            ->assertDontSee('value="light"', false)
            ->assertDontSee('value="system"', false)
            ->assertDontSee('<flux:radio', false);
    }

    public function test_appearance_page_renders_its_grouped_catalog_copy(): void
    {
        Lang::addLines([
            'appearance.dark_mode_heading' => 'Appearance heading from its PHP catalog',
            'appearance.dark_mode_description' => 'Appearance description from its PHP catalog',
        ], 'es');

        $this->actingAs(User::factory()->create())
            ->get(route('appearance.edit'))
            ->assertSeeText('Appearance heading from its PHP catalog')
            ->assertSeeText('Appearance description from its PHP catalog')
            ->assertDontSeeText('El modo oscuro está siempre activo')
            ->assertDontSeeText('El tema oscuro está activo para todas las cuentas.');
    }

    public function test_other_settings_pages_do_not_offer_appearance_navigation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee(route('appearance.edit'));
    }
}
