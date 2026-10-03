<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_dashboard_resolves_the_session_owners_business_not_a_request_identifier(): void
    {
        $business = Business::factory()->create(['name' => 'Negocio propio']);
        $foreignBusiness = Business::factory()->create(['name' => 'Negocio ajeno']);

        $this->actingAs($business->user)->get(route('dashboard', ['business_id' => $foreignBusiness->id]))
            ->assertOk()
            ->assertViewHas('business', fn (Business $resolved) => $resolved->is($business))
            ->assertSee('Negocio propio')
            ->assertSee(route('profile.edit'))
            ->assertSee(route('logout'))
            ->assertDontSee('Negocio ajeno');
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        Business::factory()->for($user)->create(['name' => 'Café Sur', 'timezone' => 'America/Argentina/Buenos_Aires']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Café Sur')
            ->assertSee('America/Argentina/Buenos_Aires')
            ->assertSee(route('business.edit'));
    }
}
