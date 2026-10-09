<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
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
            ->assertSee('Borrador guardado')
            ->assertSee('Todavía falta publicarlo')
            ->assertDontSee('Borrador privado');
    }

    public function test_saved_appearance_without_a_draft_keeps_promotion_preparation_pending(): void
    {
        $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);

        $this->actingAs($business->user)->get(route('dashboard'))
            ->assertSee('1 de 2 completados')->assertSee('La apariencia está guardada')
            ->assertSee('Define una recompensa')->assertDontSee('Borrador guardado')
            ->assertDontSee('Actividad de esta promoción');
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

    public function test_active_empty_statistics_show_four_known_zeroes(): void
    {
        $business = Business::factory()->create();
        $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00');

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $this->assertSame(['0', '0', '0', '0'], $this->metricValues($response->getContent()));
        $response->assertSee('Todavía no tiene visitas confirmadas')->assertDontSee('Reintentar');
        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="activity-title" and @aria-describedby="activity-scope"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="activity-title"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="activity-scope"]')->length);
        $this->assertSame(4, $xpath->query('//section[@aria-labelledby="activity-title"]//dl/div[dt and count(dd)=2]')->length);
    }

    public function test_active_statistics_present_persisted_facts_before_upcoming_and_preparation(): void
    {
        $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
        $promotion = $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00');
        $pass = CustomerPass::factory()->for($business)->create();
        DB::table('visits')->insert([
            'business_id' => $business->id, 'promotion_id' => $promotion->id, 'customer_pass_id' => $pass->id,
            'confirmed_by_user_id' => $business->user_id, 'operation_id' => (string) Str::uuid(),
            'awarded_points' => 5, 'confirmed_at' => '2026-01-02 12:00:00+00',
        ]);
        DB::table('reward_entitlements')->insert([
            'business_id' => $business->id, 'promotion_id' => $promotion->id, 'customer_pass_id' => $pass->id,
            'unlocked_at' => '2026-01-02 12:00:00+00', 'redeemed_at' => '2026-01-02 13:00:00+00',
            'redeemed_by_user_id' => $business->user_id,
        ]);
        $this->promotion($business, 'published', '2098-02-01 04:00:00+00', '2098-03-01 04:00:00+00');

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $this->assertSame(['1', '5', '1', '1'], $this->metricValues($response->getContent()));
        $response->assertSeeTextInOrder(['Promoción activa', 'Actividad de esta promoción', 'Próxima promoción', 'Preparación del negocio'])
            ->assertSee('Pases distintos con al menos una visita confirmada')
            ->assertSee('incluidas las ya canjeadas')->assertDontSee('Todavía no tiene visitas confirmadas');
    }

    public function test_statistics_outage_does_not_gate_global_actions_and_refresh_reselects_promotion(): void
    {
        $business = Business::factory()->create();
        $first = $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00');
        $pass = CustomerPass::factory()->for($business)->create();
        DB::table('visits')->insert([
            'business_id' => $business->id, 'promotion_id' => $first->id, 'customer_pass_id' => $pass->id,
            'confirmed_by_user_id' => $business->user_id, 'operation_id' => (string) Str::uuid(),
            'awarded_points' => 5, 'confirmed_at' => '2026-01-02 12:00:00+00',
        ]);
        $fail = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$fail): void {
            if ($fail && str_contains($sql, 'COUNT(DISTINCT')) {
                throw new QueryException('pgsql', $sql, [], new \PDOException('Statistics cancelled', 57014));
            }
        });
        $component = Livewire::actingAs($business->user)->test('pages::business.summary');
        $this->assertSame(['1', '5', '0', '0'], $this->metricValues($component->html()));
        $fail = true;

        $this->get(route('dashboard'))
            ->assertSee(url('/visits/create'))->assertSee(url('/invite'));
        $component->call('$refresh')
            ->assertSee('No pudimos cargar las estadísticas')->assertSee('Reintentar');
        $this->assertSame(['—', '—', '—', '—'], $this->metricValues($component->html()));

        $fail = false;
        $first->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        $replacement = $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00');
        $replacement->update(['reward_title' => 'Recompensa nueva']);

        $component->call('$refresh')->assertSee('Recompensa nueva')->assertDontSee('Un café de cortesía')
            ->assertDontSee('No pudimos cargar las estadísticas');
        $this->assertSame(['0', '0', '0', '0'], $this->metricValues($component->html()));
    }

    public function test_refresh_removes_metrics_when_the_database_clock_reaches_the_exclusive_end(): void
    {
        $business = Business::factory()->create();
        $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00');
        $component = Livewire::actingAs($business->user)->test('pages::business.summary');
        $this->assertSame(['0', '0', '0', '0'], $this->metricValues($component->html()));
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        DB::unprepared("CREATE OR REPLACE FUNCTION public.clock_timestamp() RETURNS timestamptz LANGUAGE SQL AS $$ SELECT '2098-01-01 04:00:00+00'::timestamptz $$");
        DB::statement('SET LOCAL search_path TO public, pg_catalog');

        $component->call('$refresh')->assertSee('Finalizada')->assertDontSee('Actividad de esta promoción');
        $this->assertSame([], $this->metricValues($component->html()));
    }

    public function test_refresh_rechecks_persisted_ownership(): void
    {
        $business = Business::factory()->create();
        $component = Livewire::actingAs($business->user)->test('pages::business.summary');
        $business->forceFill(['user_id' => User::factory()->create()->id])->save();

        $this->expectException(ModelNotFoundException::class);
        $component->call('$refresh');
    }

    public function test_refresh_rejects_an_unverified_actor(): void
    {
        $business = Business::factory()->create();
        $component = Livewire::actingAs($business->user)->test('pages::business.summary');
        $business->user->forceFill(['email_verified_at' => null])->save();
        Auth::setUser($business->user->fresh());

        $component->call('$refresh')->assertForbidden();
    }

    public function test_refresh_requires_a_current_authenticated_actor(): void
    {
        $business = Business::factory()->create();
        $component = Livewire::actingAs($business->user)->test('pages::business.summary');
        Auth::logout();

        $component->call('$refresh')->assertUnauthorized();
    }

    /**
     * Reads the four semantic activity definitions independently of their styling.
     *
     * @param  string  $html  Rendered Summary document or component.
     * @return list<string> Displayed metric values in definition-list order.
     */
    private function metricValues(string $html): array
    {
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $values = (new \DOMXPath($document))->query('//section[@aria-labelledby="activity-title"]//dl/div/dd[1]');

        return array_map(fn (\DOMNode $node): string => trim($node->textContent), iterator_to_array($values));
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
