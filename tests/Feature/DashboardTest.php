<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Promotion;
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
            ->assertSee('Resumen')
            ->assertSee('Ir a Pase')
            ->assertSee(route('business.pass'));
    }

    public function test_unverified_owners_cannot_read_the_summary(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_owner_without_a_business_cannot_read_another_business(): void
    {
        $user = User::factory()->create();
        $foreign = Business::factory()->create();

        $this->actingAs($user)->get(route('dashboard', ['business_id' => $foreign->id]))->assertNotFound();
    }

    public function test_first_setup_waits_without_presenting_zero_activity(): void
    {
        $business = Business::factory()->create();

        $this->actingAs($business->user)->get(route('dashboard'))
            ->assertSee('0 de 2 completados')
            ->assertSee('Elige y guarda la apariencia')
            ->assertSee('Todavía no hay una promoción activa')
            ->assertDontSee('Pases con actividad')
            ->assertDontSee('Recompensas canjeadas');
    }

    public function test_saved_appearance_and_draft_do_not_complete_publication(): void
    {
        $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
        $business->promotions()->create([
            'local_start_date' => '2098-01-01',
            'local_end_date' => '2098-01-31',
            'target_points' => 8,
            'reward_title' => 'Borrador privado',
        ]);

        $this->actingAs($business->user)->get(route('dashboard'))
            ->assertSee('1 de 2 completados')
            ->assertSee('La apariencia está guardada')
            ->assertSee('La preparación se completa al publicar')
            ->assertDontSee('Borrador privado');
    }

    public function test_scheduled_promotion_completes_preparation_but_waits_for_activity(): void
    {
        $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
        $this->promotion($business, 'published', '2098-10-01 04:00:00+00', '2098-11-01 04:00:00+00');

        $this->actingAs($business->user)->get(route('dashboard'))
            ->assertSee('2 de 2 completados')
            ->assertSee('Próxima promoción')
            ->assertSee('Un café de cortesía')
            ->assertSee('01/10/2098 – 31/10/2098')
            ->assertSee('Aún no admite visitas ni canjes')
            ->assertDontSee('Recompensas desbloqueadas');
    }

    public function test_active_promotion_displays_frozen_terms_and_extra_points_before_preparation(): void
    {
        $business = Business::factory()->create(['timezone' => 'Asia/Tokyo', 'pass_background_color' => '#A77BFF']);
        $promotion = $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00');
        $promotion->extraPoints()->create(['weekday' => 2, 'multiplier' => 3, 'start_time' => '14:00', 'end_time' => '17:00']);

        $this->actingAs($business->user)->get(route('dashboard'))
            ->assertSeeTextInOrder(['Promoción activa', 'Un café de cortesía', 'Meta por pase', '8 puntos', '01/01/2020 – 31/12/2097', 'America/La_Paz', 'Martes', '3 puntos por visita', '14:00–17:00', 'Preparación del negocio'])
            ->assertDontSee('Asia/Tokyo');
    }

    public function test_ended_and_cancelled_publications_preserve_preparation_and_only_brief_history(): void
    {
        $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
        $promotion = $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2020-02-01 04:00:00+00');

        $this->actingAs($business->user)->get(route('dashboard'))
            ->assertSee('2 de 2 completados')->assertSee('Última promoción')->assertSee('Finalizada')
            ->assertDontSee('Meta por pase')->assertDontSee('Recompensas canjeadas');

        $promotion->forceFill(['status' => 'cancelled', 'cancelled_at' => '2020-01-10 12:00:00+00'])->save();

        $this->get(route('dashboard'))
            ->assertSee('2 de 2 completados')->assertSee('Cancelada')->assertSee('Un café de cortesía')
            ->assertDontSee('Finalizada')->assertDontSee('Recompensas canjeadas');
    }

    public function test_business_authored_summary_copy_is_escaped(): void
    {
        $business = Business::factory()->create(['name' => '<script>owner()</script>']);
        $promotion = $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00');
        $promotion->update(['reward_title' => '<script>reward()</script>', 'reward_description' => '<img src=x onerror=alert(1)>']);

        $this->actingAs($business->user)->get(route('dashboard'))
            ->assertSee('<script>owner()</script>')->assertDontSee('<script>owner()</script>', false)
            ->assertSee('<script>reward()</script>')->assertDontSee('<script>reward()</script>', false)
            ->assertSee('<img src=x onerror=alert(1)>')->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    /**
     * Persists synthetic frozen terms without publication or operational effects.
     *
     * @param  Business  $business  Fixture owner.
     * @param  string  $status  Stored lifecycle status.
     * @param  string  $start  Inclusive UTC start.
     * @param  string  $end  Exclusive UTC end.
     * @return Promotion Owned persisted Promotion.
     */
    private function promotion(Business $business, string $status, string $start, string $end): Promotion
    {
        $promotion = $business->promotions()->make();
        $promotion->forceFill([
            'status' => $status,
            'target_points' => 8,
            'reward_title' => 'Un café de cortesía',
            'timezone_snapshot' => 'America/La_Paz',
            'starts_at' => $start,
            'ends_at' => $end,
        ])->save();

        return $promotion;
    }
}
