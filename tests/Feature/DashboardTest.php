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
            ->assertSee('Tu negocio, de un vistazo')
            ->assertSee('Consulta tu pase, tus promociones y las recompensas de tus clientes.')
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
            ->assertSeeTextInOrder(['Preparación del negocio', '1. Prepara tu pase', '2. Crea tu primera promoción', 'Todavía no hay una promoción activa'])
            ->assertSee('0 de 2 completados')
            ->assertDontSee('Prepara tu pase y tu primera promoción desde Pase.')
            ->assertSee('Dale a tu pase el estilo de tu negocio.')
            ->assertSee('Elige una recompensa, los puntos necesarios y las fechas de tu promoción.')
            ->assertSee('Todavía no hay una promoción activa')
            ->assertSee('Cuando tu promoción esté activa, aquí verás sus resultados.')
            ->assertDontSee('Los resultados se muestran solo para una promoción activa.')
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
            ->assertSeeTextInOrder(['Preparación del negocio', 'Borrador', 'Todavía no hay una promoción activa'])
            ->assertSee('1 de 2 completados')
            ->assertSee('Tu pase ya tiene el estilo de tu negocio.')
            ->assertSee('Borrador')
            ->assertSee('Tu promoción está guardada como borrador. Publícala cuando esté lista.')
            ->assertDontSee('Borrador privado');
    }

    public function test_saved_appearance_without_a_draft_keeps_promotion_preparation_pending(): void
    {
        $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);

        $this->actingAs($business->user)->get(route('dashboard'))
            ->assertSeeTextInOrder(['Preparación del negocio', '2. Crea tu primera promoción', 'Todavía no hay una promoción activa'])
            ->assertSee('1 de 2 completados')->assertSee('Tu pase ya tiene el estilo de tu negocio.')
            ->assertSee('Elige una recompensa, los puntos necesarios y las fechas de tu promoción.')
            ->assertDontSee('Borrador')
            ->assertDontSee('Actividad de esta promoción');
    }

    public function test_scheduled_promotion_completes_preparation_but_waits_for_activity(): void
    {
        $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
        $this->promotion($business, 'published', '2098-10-01 04:00:00+00', '2098-11-01 04:00:00+00');

        $this->actingAs($business->user)->get(route('dashboard'))
            ->assertSeeTextInOrder(['Preparación del negocio', '2. Crea tu primera promoción', 'Próxima promoción'])
            ->assertSee('2 de 2 completados')
            ->assertSee('Tu primera promoción ya está publicada.')
            ->assertSee('Próxima promoción')
            ->assertSee('Un café de cortesía')
            ->assertSee('01/10/2098 – 31/10/2098')
            ->assertSee('Aún no admite visitas ni canjes')
            ->assertDontSee('Recompensas desbloqueadas');
    }

    public function test_waiting_summary_explains_its_state_after_two_informational_preparation_cards(): void
    {
        $business = Business::factory()->create();

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//section[@aria-labelledby="preparation-title"]//article[.//h3]')->length);
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="preparation-title"]//*[self::a or self::button or self::input]')->length);
        $this->assertSame(1, $xpath->query('//main//a[normalize-space(.)="Ir a Pase"]')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="waiting-title" and @aria-describedby="waiting-description"]')->length);
        $this->assertSame('Cuando tu promoción esté activa, aquí verás sus resultados.', trim($xpath->query('//*[@id="waiting-description"]')->item(0)?->textContent ?? ''));
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="waiting-title"]//svg[not(@aria-hidden="true")]')->length);
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="points-title"]')->length);
        $this->assertSame([], $this->metricValues($response->getContent()));
    }

    public function test_scheduled_summary_groups_frozen_metadata_without_inventing_saved_appearance(): void
    {
        $business = Business::factory()->create(['timezone' => 'Asia/Tokyo']);
        $this->promotion($business, 'published', '2098-10-01 04:00:00+00', '2098-11-01 04:00:00+00');

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $response->assertSee('1 de 2 completados')
            ->assertSee('Dale a tu pase el estilo de tu negocio.')
            ->assertSee('Tu primera promoción ya está publicada.')
            ->assertSeeTextInOrder(['Preparación del negocio', 'Próxima promoción', 'Programada', 'Un café de cortesía'])
            ->assertDontSee('Asia/Tokyo')
            ->assertDontSee('Puntos de esta promoción')
            ->assertDontSee('Todavía no hay una promoción activa');
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame('Próxima promoción', trim($xpath->query('//h2[@id="next-promotion-title"]')->item(0)?->textContent ?? ''));
        $this->assertSame('Un café de cortesía', trim($xpath->query('//section[@aria-labelledby="next-promotion-title"]//h3')->item(0)?->textContent ?? ''));
        $facts = $xpath->query('//section[@aria-labelledby="next-promotion-title"]//dl/div');
        $this->assertSame(2, $facts->length);
        $this->assertSame(['Meta', 'Vigencia'], array_map(
            fn (\DOMNode $node): string => trim($node->textContent),
            iterator_to_array($xpath->query('//section[@aria-labelledby="next-promotion-title"]//dt')),
        ));
        $this->assertSame(['8 puntos', '01/10/2098 – 31/10/2098'], array_map(
            fn (\DOMNode $node): string => trim($node->textContent),
            iterator_to_array($xpath->query('//section[@aria-labelledby="next-promotion-title"]//dd')),
        ));
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="next-promotion-title" and @aria-describedby="scheduled-waiting"]')->length);
        $this->assertSame('Comienza el 01/10/2098. Aún no admite visitas ni canjes.', trim($xpath->query('//*[@id="scheduled-waiting"]')->item(0)?->textContent ?? ''));
        $this->assertStringNotContainsString('Zona horaria', $xpath->query('//main')->item(0)->textContent);
        $this->assertStringNotContainsString('America/La_Paz', $xpath->query('//main')->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//*[@id="scheduled-waiting"]//svg[not(@aria-hidden="true")]')->length);
        $this->assertSame(0, $xpath->query('//p[@id="next-promotion-description"]')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="next-promotion-title"]/p[normalize-space(.)="Sin puntos extra"]')->length);
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="next-promotion-title"]/section[@aria-label="Puntos extra"]')->length);
        $this->assertSame([], $this->metricValues($response->getContent()));
    }

    /**
     * Checks upcoming point schedules with and without an active Promotion.
     */
    public function test_upcoming_promotion_displays_its_own_frozen_extra_points_in_both_states(): void
    {
        $business = Business::factory()->create();
        $next = $this->promotion($business, 'published', '2098-10-01 04:00:00+00', '2098-11-01 04:00:00+00');
        $next->update(['reward_description' => 'Café de especialidad recién preparado.']);
        $next->extraPoints()->create(['weekday' => 3, 'multiplier' => 5, 'start_time' => '09:00', 'end_time' => '12:00']);

        foreach ([false, true] as $hasActive) {
            if ($hasActive) {
                $active = $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00');
                $active->extraPoints()->create(['weekday' => 1, 'multiplier' => 2]);
            }

            $response = $this->actingAs($business->user)->get(route('dashboard'));
            $document = new \DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($document);
            $panel = $xpath->query('//section[@aria-labelledby="next-promotion-title"]')->item(0);

            $this->assertStringContainsString('Miércoles · 09:00–12:00', $panel->textContent);
            $this->assertStringContainsString('×5', $panel->textContent);
            $this->assertSame('Café de especialidad recién preparado.', trim($xpath->query('//p[@id="next-promotion-description"]')->item(0)?->textContent ?? ''));
            $this->assertSame(1, $xpath->query('//section[@aria-labelledby="next-promotion-title" and @aria-describedby="next-promotion-description"] | //section[@aria-labelledby="next-promotion-title" and @aria-describedby="next-promotion-description scheduled-waiting"]')->length);
            $this->assertStringNotContainsString('Visita habitual', $panel->textContent);
            $this->assertStringNotContainsString('1 punto', $panel->textContent);
            $this->assertSame(1, $xpath->query('//section[@aria-labelledby="next-promotion-title"]/section[@aria-label="Puntos extra"]//ul/li')->length);
            $this->assertStringNotContainsString('Lunes', $panel->textContent);
            $this->assertStringNotContainsString('Cada visita confirmada suma 1 punto.', $panel->textContent);
            $this->assertStringNotContainsString('Zona horaria', $panel->textContent);
        }
    }

    public function test_active_promotion_displays_frozen_terms_and_extra_points_before_preparation(): void
    {
        $business = Business::factory()->create(['timezone' => 'Asia/Tokyo', 'pass_background_color' => '#A77BFF']);
        $promotion = $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00');
        $promotion->update(['reward_description' => 'Preparado al momento con café de especialidad.']);
        $promotion->extraPoints()->create(['weekday' => 2, 'multiplier' => 3, 'start_time' => '14:00', 'end_time' => '17:00']);
        $promotion->extraPoints()->create(['weekday' => 5, 'multiplier' => 5, 'start_time' => null, 'end_time' => null]);

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $response->assertSeeTextInOrder(['Promoción activa', 'Un café de cortesía', 'Meta', '8 puntos', '01/01/2020 – 31/12/2097', 'Actividad de esta promoción', 'Puntos de esta promoción', 'Visita habitual', '1 punto', 'Puntos extra', 'Martes · 14:00–17:00', '×3', 'Preparación del negocio'])
            ->assertSee('Viernes · Todo el día')
            ->assertSee('×5')
            ->assertDontSee('Cada visita confirmada suma 1 punto.')
            ->assertDontSee('Asia/Tokyo');
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="active-promotion-title" and @aria-describedby="active-promotion-description"]')->length);
        $this->assertSame('Preparado al momento con café de especialidad.', trim($xpath->query('//*[@id="active-promotion-description"]')->item(0)?->textContent ?? ''));
        $this->assertSame(['Meta', 'Vigencia'], array_map(
            fn (\DOMNode $node): string => trim($node->textContent),
            iterator_to_array($xpath->query('//section[@aria-labelledby="active-promotion-title"]//dt')),
        ));
        $this->assertSame(['8 puntos', '01/01/2020 – 31/12/2097'], array_map(
            fn (\DOMNode $node): string => trim($node->textContent),
            iterator_to_array($xpath->query('//section[@aria-labelledby="active-promotion-title"]//dd')),
        ));
        $this->assertSame(1, $xpath->query('//main//a[normalize-space(.)="Ir a Pase"]')->length);
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="active-promotion-title"]//svg[not(@aria-hidden="true")]')->length);
        $this->assertStringNotContainsString('14:00', $xpath->query('//section[@aria-labelledby="active-promotion-title"]')->item(0)->textContent);
        $this->assertSame(2, $xpath->query('//section[@aria-labelledby="points-title"]//ul/li')->length);
        $this->assertStringNotContainsString('Zona horaria', $xpath->query('//main')->item(0)->textContent);
        $this->assertStringNotContainsString('America/La_Paz', $xpath->query('//main')->item(0)->textContent);
    }

    public function test_active_summary_without_extra_points_or_upcoming_promotion_keeps_preparation_truthful(): void
    {
        $business = Business::factory()->create(['timezone' => 'Asia/Tokyo']);
        $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00');

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $response->assertSeeTextInOrder(['Promoción activa', 'Actividad de esta promoción', 'Puntos de esta promoción', 'Visita habitual', '1 punto', 'Sin puntos extra', 'Preparación del negocio'])
            ->assertSee('1 de 2 completados')
            ->assertSee('Dale a tu pase el estilo de tu negocio.')
            ->assertSee('Tu primera promoción ya está publicada.')
            ->assertDontSee('Próxima promoción')
            ->assertDontSee('Cada visita confirmada suma 1 punto.')
            ->assertDontSee('Prepara tu pase y tu primera promoción desde Pase.')
            ->assertDontSee('Asia/Tokyo');
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="points-title" and not(@aria-describedby)]')->length);
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="points-title"]//ul/li')->length);
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="next-promotion-title"]')->length);
        $this->assertSame(2, $xpath->query('//section[@aria-labelledby="preparation-title"]//article')->length);
        $this->assertStringContainsString('Pendiente', $xpath->query('//article[@aria-labelledby="preparation-appearance"]')->item(0)->textContent);
        $this->assertStringContainsString('Completado', $xpath->query('//article[@aria-labelledby="preparation-promotion"]')->item(0)->textContent);
    }

    public function test_ended_and_cancelled_publications_preserve_preparation_and_only_brief_history(): void
    {
        $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
        $promotion = $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2020-02-01 04:00:00+00');

        $this->actingAs($business->user)->get(route('dashboard'))
            ->assertSeeTextInOrder(['Preparación del negocio', 'Tu pase está listo para la próxima promoción', 'Última promoción', 'Un café de cortesía', 'Finalizada'])
            ->assertSee('2 de 2 completados')->assertSee('Última promoción')->assertSee('Finalizada')
            ->assertSee('Consulta tus promociones anteriores o prepara una nueva desde Pase.')
            ->assertDontSee('Meta')->assertDontSee('Recompensas canjeadas');

        $promotion->forceFill(['status' => 'cancelled', 'cancelled_at' => '2020-01-10 12:00:00+00'])->save();

        $this->get(route('dashboard'))
            ->assertSeeTextInOrder(['Preparación del negocio', 'Tu pase está listo para la próxima promoción', 'Última promoción', 'Un café de cortesía', 'Cancelada'])
            ->assertSee('2 de 2 completados')->assertSee('Cancelada')->assertSee('Un café de cortesía')
            ->assertSee('Tu primera promoción ya está publicada.')
            ->assertSee('Consulta tus promociones anteriores o prepara una nueva desde Pase.')
            ->assertDontSee('Finalizada')->assertDontSee('Recompensas canjeadas');
    }

    /**
     * Uses the same public Flux phase variants as Pase and promotion details.
     */
    public function test_summary_reuses_promotion_phase_badge_labels_and_colors(): void
    {
        foreach ([
            ['draft', '2098-10-01 04:00:00+00', '2098-11-01 04:00:00+00', 'Borrador', 'bg-amber-400/25'],
            ['published', '2098-10-01 04:00:00+00', '2098-11-01 04:00:00+00', 'Programada', 'bg-blue-400/20'],
            ['published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00', 'Activa', 'bg-green-400/20'],
            ['published', '2020-01-01 04:00:00+00', '2020-02-01 04:00:00+00', 'Finalizada', 'bg-zinc-400/15'],
            ['cancelled', '2020-01-01 04:00:00+00', '2020-02-01 04:00:00+00', 'Cancelada', 'bg-red-400/20'],
        ] as [$status, $startsAt, $endsAt, $label, $colorClass]) {
            $business = Business::factory()->create();
            if ($status === 'draft') {
                $business->promotions()->create(['local_start_date' => '2098-10-01', 'local_end_date' => '2098-10-31', 'target_points' => 8, 'reward_title' => 'Borrador privado']);
            } else {
                $promotion = $this->promotion($business, 'published', $startsAt, $endsAt);
                if ($status === 'cancelled') {
                    $promotion->forceFill(['status' => 'cancelled', 'cancelled_at' => '2020-01-10 12:00:00+00'])->save();
                }
            }
            $response = $this->actingAs($business->user)->get(route('dashboard'));
            $document = new \DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($document);
            $badges = $xpath->query('//*[@data-flux-badge and normalize-space(.)="'.$label.'"]');

            $this->assertSame(1, $badges->length, $label);
            $this->assertContains($colorClass, explode(' ', $badges->item(0)->getAttribute('class')), $label);
        }
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
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="activity-title" and @aria-describedby="activity-scope"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="activity-title"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="activity-scope"]')->length);
        $this->assertSame(4, $xpath->query('//section[@aria-labelledby="activity-title"]//dl/div[dt and count(dd)=2]')->length);
        $this->assertSame(['Pases con actividad', 'Puntos acumulados', 'Recompensas desbloqueadas', 'Recompensas canjeadas'], array_map(
            fn (\DOMNode $node): string => trim($node->textContent),
            iterator_to_array($xpath->query('//section[@aria-labelledby="activity-title"]//dt')),
        ));
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="active-promotion-title" and @aria-describedby]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="active-promotion-description"]')->length);
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="activity-title"]//dl[@aria-describedby]')->length);
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
        $next = $this->promotion($business, 'published', '2098-01-31 15:00:00+00', '2098-02-28 15:00:00+00');
        $next->forceFill(['reward_title' => 'Recompensa de la próxima', 'timezone_snapshot' => 'Asia/Tokyo', 'target_points' => 12])->save();

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $this->assertSame(['1', '5', '1', '1'], $this->metricValues($response->getContent()));
        $response->assertSeeTextInOrder(['Promoción activa', 'Actividad de esta promoción', 'Puntos de esta promoción', 'Próxima promoción', 'Recompensa de la próxima', 'Preparación del negocio'])
            ->assertSee('Desde el inicio de esta promoción.')
            ->assertSee('Pases que ya registraron una visita en esta promoción.')
            ->assertSee('Puntos sumados con las visitas y los puntos extra.')
            ->assertSee('Recompensas obtenidas, incluidas las ya canjeadas.')
            ->assertSee('Recompensas que tus clientes ya canjearon.')
            ->assertDontSee('Un pase no equivale a una persona única ni a una instalación de Wallet.')
            ->assertDontSee('Todavía no tiene visitas confirmadas');
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(['12 puntos', '01/02/2098 – 28/02/2098'], array_map(
            fn (\DOMNode $node): string => trim($node->textContent),
            iterator_to_array($xpath->query('//section[@aria-labelledby="next-promotion-title"]//dd')),
        ));
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="next-promotion-title"]//a')->length);
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
            ->assertSee('No pudimos cargar las estadísticas')->assertSee('Reintentar')
            ->assertSee('Inténtalo de nuevo en unos momentos.')
            ->assertSee('Actualizando…')
            ->assertDontSee('Este fallo no desactiva la invitación ni el registro de visitas.');
        $this->assertSame(['—', '—', '—', '—'], $this->metricValues($component->html()));
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$component->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@role="status" and @aria-labelledby="statistics-error" and @aria-describedby="statistics-recovery"]')->length);
        $this->assertSame('No pudimos cargar las estadísticas de esta promoción.', trim($xpath->query('//*[@id="statistics-error"]')->item(0)?->textContent ?? ''));
        $this->assertSame('Inténtalo de nuevo en unos momentos.', trim($xpath->query('//*[@id="statistics-recovery"]')->item(0)?->textContent ?? ''));
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="activity-title"]//dl[@aria-describedby="statistics-error statistics-recovery"]')->length);
        $this->assertSame(1, $xpath->query('//button[@aria-describedby="statistics-recovery"]')->length);

        $fail = false;
        $first->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        $replacement = $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00');
        $replacement->update(['reward_title' => 'Recompensa nueva']);

        $component->call('$refresh')->assertSee('Recompensa nueva')->assertDontSee('Un café de cortesía')
            ->assertDontSee('No pudimos cargar las estadísticas');
        $this->assertSame(['0', '0', '0', '0'], $this->metricValues($component->html()));
        $component->assertDontSee('statistics-error', false)->assertDontSee('statistics-recovery', false);
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
