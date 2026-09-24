<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_appearance_settings_require_authentication(): void
    {
        $this->get(route('appearance.edit'))->assertRedirect(route('login'));
    }

    public function test_appearance_settings_show_translated_native_choices_and_navigation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('appearance.edit'))
            ->assertOk()
            ->assertSee('Apariencia')
            ->assertSee('Actualiza la apariencia de tu cuenta')
            ->assertSee('Claro')
            ->assertSee('Oscuro')
            ->assertSee('Sistema')
            ->assertSee('value="light"', false)
            ->assertSee('value="dark"', false)
            ->assertSee('value="system"', false)
            ->assertSee(route('appearance.edit'))
            ->assertDontSee('Esta aplicación utiliza un tema oscuro')
            ->assertDontSee('El tema oscuro está activo para todas las cuentas');
    }

    public function test_other_settings_pages_link_to_appearance(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee(route('appearance.edit'))
            ->assertSee('Apariencia');
    }
}
