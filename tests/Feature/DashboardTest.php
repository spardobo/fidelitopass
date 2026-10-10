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
            ->assertSeeTextInOrder(['No hay una promoción activa ni programada', 'Preparación del negocio', '1. Prepara tu pase', '2. Crea tu primera promoción'])
            ->assertSee('0 de 2 completados')
            ->assertSee('Preparación pendiente')->assertDontSee('Completado')
            ->assertDontSee('Prepara tu pase y tu primera promoción desde Pase.')
            ->assertSee('Dale a tu pase el estilo de tu negocio.')
            ->assertSee('Elige una recompensa, los puntos necesarios y las fechas de tu promoción.')
            ->assertSee('No hay una promoción activa ni programada')
            ->assertSee('Aquí verás la recompensa, la meta y la vigencia de tu promoción activa o programada.')
            ->assertDontSee('Los resultados se muestran solo para una promoción activa.')
            ->assertSee('Pases con actividad')
            ->assertSee('Recompensas canjeadas');
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
            ->assertSee('No hay una promoción activa ni programada')
            ->assertDontSee('Preparación del negocio')
            ->assertDontSee('1. Prepara tu pase')
            ->assertDontSee('2. Crea tu primera promoción')
            ->assertDontSee('Borrador privado')
            ->assertDontSee('01/01/2098 – 01/31/2098');
    }

    public function test_saved_appearance_without_a_draft_keeps_promotion_preparation_pending(): void
    {
        $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);

        $this->actingAs($business->user)->get(route('dashboard'))
            ->assertSeeTextInOrder(['No hay una promoción activa ni programada', 'Preparación del negocio', '2. Crea tu primera promoción'])
            ->assertSee('1 de 2 completados')->assertSee('1. Prepara tu pase')
            ->assertSee('Tu pase ya tiene el estilo de tu negocio.')
            ->assertSee('Elige una recompensa, los puntos necesarios y las fechas de tu promoción.')
            ->assertDontSee('Borrador')
            ->assertSee('Actividad de esta promoción');
    }

    public function test_first_draft_keeps_published_placeholder_and_truthful_two_step_preparation(): void
    {
        $business = Business::factory()->create();
        $business->promotions()->create([
            'local_start_date' => '2098-01-01', 'local_end_date' => '2098-01-31',
            'target_points' => 8, 'reward_title' => 'Borrador privado',
        ]);

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $response->assertSee('1. Prepara tu pase')->assertSee('Dale a tu pase el estilo de tu negocio.')
            ->assertSee('Tu promoción está guardada como borrador. Publícala cuando esté lista.')
            ->assertSee('2. Crea tu primera promoción')->assertDontSee('Borrador privado')
            ->assertSee('No hay una promoción activa ni programada');
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//section[@aria-labelledby="preparation-title"]//article')->length);
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="promotion-title"]//*[@data-flux-badge]')->length);
    }

    /**
     * Keeps the same four content sections across the supported preparation and lifecycle states.
     */
    public function test_summary_keeps_primary_metrics_points_and_upcoming_cards_in_every_state(): void
    {
        foreach ([
            ['none', false], ['none', true], ['draft', false], ['draft', true],
            ['scheduled', true], ['active', false], ['active', true], ['active-next', true],
            ['ended', true], ['cancelled', true],
        ] as [$phase, $appearancePrepared]) {
            $business = Business::factory()->create(['pass_background_color' => $appearancePrepared ? '#A77BFF' : null]);
            if ($phase === 'draft') {
                $business->promotions()->create(['local_start_date' => '2098-01-01', 'local_end_date' => '2098-01-31', 'target_points' => 8, 'reward_title' => 'Draft reward']);
            } elseif ($phase !== 'none') {
                $start = $phase === 'scheduled' ? '2098-01-01 04:00:00+00' : '2020-01-01 04:00:00+00';
                $end = in_array($phase, ['ended', 'cancelled'], true) ? '2020-02-01 04:00:00+00' : '2099-01-01 04:00:00+00';
                $this->promotion($business, $phase === 'cancelled' ? 'cancelled' : 'published', $start, $end);
                if ($phase === 'active-next') {
                    $this->promotion($business, 'published', '2099-01-01 04:00:00+00', '2100-01-01 04:00:00+00');
                }
            }

            $response = $this->actingAs($business->user)->get(route('dashboard'));

            $document = new \DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($document);
            foreach (['promotion-title', 'activity-title', 'points-title', 'next-promotion-title'] as $heading) {
                $this->assertSame(1, $xpath->query('//section[@aria-labelledby="'.$heading.'"]')->length, $phase.' '.$heading);
            }
            $this->assertSame(1, $xpath->query('//h2[@id="promotion-title" and contains(@class,"app-role-section") and normalize-space(.)="Promoción"]')->length, $phase);
            $rewardRole = in_array($phase, ['none', 'draft', 'ended', 'cancelled'], true) ? 'p[contains(@class,"app-role-body") and contains(@class,"font-medium")]' : 'h3[contains(@class,"app-role-card")]';
            $this->assertSame(1, $xpath->query('//section[@aria-labelledby="promotion-title"]//'.$rewardRole)->length, $phase);
            if (in_array($phase, ['none', 'draft', 'ended', 'cancelled'], true)) {
                $this->assertSame(1, $xpath->query('//section[@aria-labelledby="points-title"]//div[contains(@class,"app-note-with-icon")]/p[normalize-space(.)="Aquí verás los puntos extra que configures para tu promoción."]')->length);
            }
            $this->assertSame(4, $xpath->query('//section[@aria-labelledby="activity-title"]//dl/div')->length, $phase);
            $this->assertSame(1, $xpath->query('//section[@aria-labelledby="points-title"]/../section[@aria-labelledby="next-promotion-title"]')->length, $phase);
            $this->assertSame(1, $xpath->query('//*[@id="activity-scope" and contains(@class,"app-note-with-icon")]//svg[@aria-hidden="true"]')->length, $phase);
            $preparationNeeded = ! $appearancePrepared || $phase === 'none';
            $this->assertSame((int) $preparationNeeded, $xpath->query('//main/header//div[a[normalize-space(.)="Ir a Pase"]]/*[@data-flux-badge and normalize-space(.)="Preparación pendiente" and contains(@class,"bg-amber-400/25")]')->length, $phase.' header preparation guidance');
            if ($preparationNeeded) {
                $this->assertSame(2, $xpath->query('//section[@aria-labelledby="preparation-title"]//article')->length, $phase);
                $completed = (int) $appearancePrepared + (int) ($phase !== 'none');
                $counterColor = $completed === 1 ? 'bg-amber-400/25' : 'bg-zinc-400/15';
                $this->assertSame(1, $xpath->query('//section[@aria-labelledby="preparation-title"]/div/*[@data-flux-badge and contains(@class,"'.$counterColor.'") and normalize-space(.)="'.$completed.' de 2 completados"]')->length, $phase.' completion counter');
                $this->assertSame($completed, $xpath->query('//section[@aria-labelledby="preparation-title"]//article//*[@data-flux-badge and normalize-space(.)="Completado" and contains(@class,"bg-green-400/20")]')->length, $phase);
            }
            foreach ($xpath->query('//main//div[contains(@class,"app-note-with-icon")]') as $note) {
                $this->assertSame(1, $xpath->query('./span[@aria-hidden="true" and contains(@class,"h-5") and contains(@class,"w-4")]/svg[@fill="none" and contains(@class,"size-4")]', $note)->length, $phase);
            }
            foreach ($xpath->query('//section[@aria-labelledby="promotion-title" or @aria-labelledby="points-title" or @aria-labelledby="next-promotion-title"] | //section[@aria-labelledby="activity-title"]//dl/div | //section[@aria-labelledby="preparation-title"]//article') as $card) {
                $this->assertStringContainsString('p-4 sm:p-6', $card->getAttribute('class'), $phase);
            }
            $expected = str_starts_with($phase, 'active') ? ['0', '0', '0', '0'] : ['—', '—', '—', '—'];
            $this->assertSame($expected, $this->metricValues($response->getContent()), $phase);
        }
    }

    /**
     * Keeps both populated cards complete and their detail actions aligned with the text column.
     */
    public function test_primary_and_upcoming_cards_share_composition_with_contextual_icons_and_actions(): void
    {
        foreach (['active', 'scheduled'] as $phase) {
            $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
            $primary = $this->promotion($business, 'published', $phase === 'active' ? '2020-01-01 04:00:00+00' : '2098-01-01 04:00:00+00', '2099-01-01 04:00:00+00');
            $primary->update(['reward_title' => 'Recompensa principal', 'reward_description' => 'Descripción de la recompensa principal.']);
            $upcoming = $this->promotion($business, 'published', '2099-01-01 04:00:00+00', '2100-01-01 04:00:00+00');
            $upcoming->update(['reward_title' => 'Recompensa próxima', 'reward_description' => 'Descripción de la recompensa próxima.']);

            $response = $this->actingAs($business->user)->get(route('dashboard'));

            $document = new \DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($document);
            foreach ([
                ['promotion-title', $primary, 'Recompensa principal', 'Descripción de la recompensa principal.', $phase === 'active' ? 'Activa' : 'Programada', 'M21 11.25v8.25'],
                ['next-promotion-title', $upcoming, 'Recompensa próxima', 'Descripción de la recompensa próxima.', 'Programada', 'M6.75 3v2.25'],
            ] as [$heading, $promotion, $title, $description, $status, $iconPath]) {
                $card = $xpath->query('//section[@aria-labelledby="'.$heading.'"]')->item(0);
                $this->assertSame(1, $xpath->query('.//h3[normalize-space(.)="'.$title.'"]', $card)->length);
                $this->assertSame(1, $xpath->query('.//p[normalize-space(.)="'.$description.'"]', $card)->length);
                $this->assertSame(1, $xpath->query('.//*[@data-flux-badge and normalize-space(.)="'.$status.'"]', $card)->length);
                $this->assertSame(['Meta', 'Vigencia'], array_map(fn (\DOMNode $node): string => trim($node->textContent), iterator_to_array($xpath->query('.//dl/dt | .//dl/div/dt', $card))));
                $this->assertSame(2, $xpath->query('.//dl/div/dd', $card)->length);
                $this->assertSame(1, $xpath->query('./div[contains(@class,"grid")]/span[@aria-hidden="true" and contains(@class,"size-12")]/svg[contains(@class,"size-6")]/path[starts-with(@d,"'.$iconPath.'")]', $card)->length);
                $button = $xpath->query('.//button[normalize-space(.)="Ver detalle"]', $card)->item(0);
                $this->assertInstanceOf(\DOMElement::class, $button);
                $this->assertSame(1, $xpath->query('./div[contains(@class,"grid")]/div[contains(@class,"col-start-2")]/button', $card)->length);
                $this->assertStringContainsString('w-full sm:w-auto', $button->getAttribute('class'));
                $this->assertSame('disabled', $button->getAttribute('wire:loading.attr'));
                $this->assertSame('showPromotionDetail', $button->getAttribute('wire:target'));
                $this->assertStringContainsString($promotion->public_id, $button->getAttribute('wire:click'));
                $role = $heading === 'promotion-title' && $phase === 'active' ? 'app-button-secondary-on-emphasis' : 'app-button-secondary';
                $this->assertContains($role, explode(' ', $button->getAttribute('class')));
                if ($role === 'app-button-secondary-on-emphasis') {
                    $this->assertContains('border', explode(' ', $button->getAttribute('class')));
                    $this->assertContains('disabled:pointer-events-none', explode(' ', $button->getAttribute('class')));
                }
            }
            $this->assertSame(1, $xpath->query('//main/header//a[contains(@class,"app-button-primary") and normalize-space(.)="Ir a Pase"]')->length);
            $this->assertSame(1, $xpath->query('//section[@aria-labelledby="next-promotion-title" and contains(@class,"bg-app-surface")]')->length);
        }
    }

    /**
     * Distinguishes upcoming Promotion identity from its calendar metadata.
     */
    public function test_upcoming_card_uses_a_calendar_hero_icon(): void
    {
        $business = Business::factory()->create();
        $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2099-01-01 04:00:00+00');
        $this->promotion($business, 'published', '2099-01-01 04:00:00+00', '2100-01-01 04:00:00+00');

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="next-promotion-title"]/div[contains(@class,"grid")]/span/svg/path[starts-with(@d,"M6.75 3v2.25")]')->length);
    }

    /**
     * Separates the primary workspace access from contextual Promotion detail actions.
     */
    public function test_summary_buttons_use_primary_navigation_and_surface_specific_secondary_roles(): void
    {
        $business = Business::factory()->create();
        $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2099-01-01 04:00:00+00');
        $this->promotion($business, 'published', '2099-01-01 04:00:00+00', '2100-01-01 04:00:00+00');

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame([
            'navigation' => 1, 'emphasized_detail' => 1, 'neutral_detail' => 1,
        ], [
            'navigation' => $xpath->query('//main/header//a[contains(@class,"app-button-primary") and normalize-space(.)="Ir a Pase"]')->length,
            'emphasized_detail' => $xpath->query('//section[@aria-labelledby="promotion-title"]//button[contains(@class,"app-button-secondary-on-emphasis")]')->length,
            'neutral_detail' => $xpath->query('//section[@aria-labelledby="next-promotion-title"]//button[contains(concat(" ",@class," ")," app-button-secondary ")]')->length,
        ]);
    }

    /**
     * Keeps first-time setup closed after publication while retaining missing appearance work.
     */
    public function test_publication_history_does_not_reopen_onboarding_for_a_later_draft(): void
    {
        foreach ([
            ['published', '2098-10-01 04:00:00+00', '2098-11-01 04:00:00+00'],
            ['published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00'],
            ['published', '2020-01-01 04:00:00+00', '2020-02-01 04:00:00+00'],
            ['cancelled', '2020-01-01 04:00:00+00', '2020-02-01 04:00:00+00'],
        ] as [$status, $startsAt, $endsAt]) {
            foreach ([false, true] as $appearancePrepared) {
                $business = Business::factory()->create(['pass_background_color' => $appearancePrepared ? '#A77BFF' : null]);
                $this->promotion($business, $status, $startsAt, $endsAt);
                $business->promotions()->create([
                    'local_start_date' => '2098-12-01', 'local_end_date' => '2098-12-31',
                    'target_points' => 8, 'reward_title' => 'Nuevo borrador privado',
                ]);

                $response = $this->actingAs($business->user)->get(route('dashboard'));

                $appearancePrepared ? $response->assertDontSee('2. Crea tu primera promoción') : $response->assertSee('2. Crea tu primera promoción');
                $document = new \DOMDocument;
                $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
                $xpath = new \DOMXPath($document);
                $expectedItems = $appearancePrepared ? 0 : 1;
                $this->assertSame($expectedItems, $xpath->query('//section[@aria-labelledby="preparation-title"]')->length);
                $this->assertSame($expectedItems, $xpath->query('//article[@aria-labelledby="preparation-appearance"]')->length);
                $this->assertSame($expectedItems, $xpath->query('//article[@aria-labelledby="preparation-promotion"]//*[@data-flux-badge and normalize-space(.)="Completado"]')->length);
            }
        }
    }

    public function test_scheduled_promotion_completes_preparation_but_waits_for_activity(): void
    {
        $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
        $this->promotion($business, 'published', '2098-10-01 04:00:00+00', '2098-11-01 04:00:00+00');

        $this->actingAs($business->user)->get(route('dashboard'))
            ->assertDontSee('Preparación del negocio')
            ->assertDontSee('2 de 2 completados')
            ->assertSee('Próxima promoción')
            ->assertSee('Un café de cortesía')
            ->assertSee('datetime="2098-10-01"', false)
            ->assertSee('datetime="2098-10-31"', false)
            ->assertSee('10/01/2098')->assertSee('10/31/2098')
            ->assertSee('Aún no admite visitas ni canjes')
            ->assertSee('Recompensas desbloqueadas');
    }

    public function test_two_future_publications_keep_primary_rules_and_upcoming_reward_distinct(): void
    {
        $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
        $second = $this->promotion($business, 'published', '2098-11-01 04:00:00+00', '2098-12-01 04:00:00+00');
        $second->update(['reward_title' => 'Recompensa posterior']);
        $second->extraPoints()->create(['weekday' => 5, 'multiplier' => 5]);
        $first = $this->promotion($business, 'published', '2098-10-01 04:00:00+00', '2098-11-01 04:00:00+00');
        $first->update(['reward_title' => 'Recompensa inicial']);
        $first->extraPoints()->create(['weekday' => 1, 'multiplier' => 2]);

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $primary = $xpath->query('//section[@aria-labelledby="promotion-title"]')->item(0)->textContent;
        $points = $xpath->query('//section[@aria-labelledby="points-title"]')->item(0)->textContent;
        $upcoming = $xpath->query('//section[@aria-labelledby="next-promotion-title"]')->item(0)->textContent;

        $this->assertStringContainsString('Recompensa inicial', $primary);
        $this->assertStringNotContainsString('Recompensa posterior', $primary);
        $this->assertStringContainsString('Lunes · Todo el día', $points);
        $this->assertStringContainsString('×2', $points);
        $this->assertStringContainsString('Recompensa posterior', $upcoming);
        $this->assertStringNotContainsString('Viernes · Todo el día', $upcoming);
        $this->assertStringNotContainsString('×5', $upcoming);
        $this->assertStringNotContainsString('Recompensa inicial', $upcoming);
        $this->assertSame(['—', '—', '—', '—'], $this->metricValues($response->getContent()));
        $response->assertDontSee('Preparación del negocio');
    }

    public function test_waiting_summary_keeps_primary_context_and_two_informational_preparation_cards(): void
    {
        $business = Business::factory()->create();

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//section[@aria-labelledby="preparation-title"]//article[.//h3]')->length);
        foreach ($xpath->query('//section[@aria-labelledby="preparation-title"]//*[@data-flux-badge]') as $badge) {
            $this->assertStringNotContainsString('bg-app-surface', $badge->getAttribute('class'));
            $this->assertStringNotContainsString('text-app-ink-secondary', $badge->getAttribute('class'));
        }
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="preparation-title"]//*[self::a or self::button or self::input]')->length);
        $this->assertSame(1, $xpath->query('//main//a[normalize-space(.)="Ir a Pase"]')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="promotion-title" and @aria-describedby="promotion-description"]')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="promotion-title"]/div/span/svg[@aria-hidden="true" and @fill="none"]/path[starts-with(@d,"M10.34 15.84")]')->length);
        $this->assertSame('Aquí verás la recompensa, la meta y la vigencia de tu promoción activa o programada.', trim($xpath->query('//*[@id="promotion-description"]')->item(0)?->textContent ?? ''));
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="promotion-title"]//svg[not(@aria-hidden="true")]')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="points-title"]')->length);
        $this->assertSame(['—', '—', '—', '—'], $this->metricValues($response->getContent()));
    }

    public function test_scheduled_summary_groups_frozen_metadata_without_inventing_saved_appearance(): void
    {
        $business = Business::factory()->create(['timezone' => 'Asia/Tokyo']);
        $this->promotion($business, 'published', '2098-10-01 04:00:00+00', '2098-11-01 04:00:00+00');

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $response->assertSee('1 de 2 completados')->assertSee('Dale a tu pase el estilo de tu negocio.')
            ->assertSee('2. Crea tu primera promoción')->assertDontSee('Asia/Tokyo')
            ->assertSeeTextInOrder(['Promoción', 'Programada', 'Un café de cortesía', 'Meta', 'Vigencia', 'Actividad de esta promoción', 'Puntos de esta promoción', 'Próxima promoción'])
            ->assertSee('No hay una próxima promoción programada.')
            ->assertSeeText('Comienza el')
            ->assertSee('10/01/2098')->assertSeeText('Aún no admite visitas ni canjes.');
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(['8 puntos', '10/01/2098 – 10/31/2098'], array_map(
            fn (\DOMNode $node): string => trim($node->textContent),
            iterator_to_array($xpath->query('//section[@aria-labelledby="promotion-title"]//dd')),
        ));
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="next-promotion-title"]//*[self::dl or self::h3 or @data-flux-badge]')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="points-title"]//p[normalize-space(.)="Sin puntos extra"]')->length);
        $this->assertSame(['—', '—', '—', '—'], $this->metricValues($response->getContent()));
    }

    /**
     * Keeps primary point rules in Summary and upcoming frozen rules in owned detail.
     */
    public function test_summary_keeps_primary_rules_visible_and_upcoming_rules_in_detail(): void
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
            $rewardPanel = $hasActive ? 'next-promotion-title' : 'promotion-title';
            $panel = $xpath->query('//section[@aria-labelledby="points-title"]')->item(0);

            $this->assertStringContainsString($hasActive ? 'Lunes · Todo el día' : 'Miércoles · 09:00–12:00', $panel->textContent);
            $this->assertStringContainsString($hasActive ? '×2' : '×5', $panel->textContent);
            $this->assertSame('Café de especialidad recién preparado.', trim($xpath->query('//section[@aria-labelledby="'.$rewardPanel.'"]//p[contains(@id,"description")]')->item(0)?->textContent ?? ''));
            $this->assertSame(1, $xpath->query('//section[@aria-labelledby="points-title"]//ul/li')->length);
            $this->assertStringNotContainsString($hasActive ? 'Miércoles' : 'Lunes', $panel->textContent);
            $this->assertStringNotContainsString('Zona horaria', $panel->textContent);
            $upcomingPanel = $xpath->query('//section[@aria-labelledby="next-promotion-title"]')->item(0);
            $this->assertStringNotContainsString('Puntos extra', $upcomingPanel->textContent);
            $this->assertSame(0, $xpath->query('.//ul | .//details', $upcomingPanel)->length);

            Livewire::actingAs($business->user)->test('pages::business.summary')
                ->call('showPromotionDetail', $next->public_id, $hasActive ? 'upcoming' : 'primary')
                ->assertSee('Miércoles · 09:00–12:00')->assertSee('×5')
                ->assertSee('1 configuración')->assertSee('Volver al resumen');
        }
    }

    /**
     * Avoids an extra-points placeholder in a populated upcoming card without configured rules.
     */
    public function test_upcoming_without_extra_rules_keeps_only_its_reward_facts_and_detail_action(): void
    {
        $business = Business::factory()->create();
        $primary = $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00');
        $primary->extraPoints()->create(['weekday' => 1, 'multiplier' => 2]);
        $this->promotion($business, 'published', '2098-10-01 04:00:00+00', '2098-11-01 04:00:00+00');

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $upcoming = $xpath->query('//section[@aria-labelledby="next-promotion-title"]')->item(0);
        $this->assertStringNotContainsString('Sin puntos extra', $upcoming->textContent);
        $this->assertStringNotContainsString('configuraciones', $upcoming->textContent);
        $this->assertSame(1, $xpath->query('.//button[normalize-space(.)="Ver detalle"]', $upcoming)->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="points-title"]//ul/li')->length);
        $this->assertStringContainsString('Lunes · Todo el día', $xpath->query('//section[@aria-labelledby="points-title"]')->item(0)->textContent);
    }

    public function test_active_promotion_displays_frozen_terms_and_extra_points_before_preparation(): void
    {
        $business = Business::factory()->create(['timezone' => 'Asia/Tokyo', 'pass_background_color' => '#A77BFF']);
        $promotion = $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00');
        $promotion->update(['reward_description' => 'Preparado al momento con café de especialidad.']);
        $promotion->extraPoints()->create(['weekday' => 2, 'multiplier' => 3, 'start_time' => '14:00', 'end_time' => '17:00']);
        $promotion->extraPoints()->create(['weekday' => 5, 'multiplier' => 5, 'start_time' => null, 'end_time' => null]);

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $response->assertSeeTextInOrder(['Promoción', 'Un café de cortesía', 'Meta', '8 puntos', '01/01/2020 – 12/31/2097', 'Actividad de esta promoción', 'Puntos de esta promoción', 'Visita habitual', '1 punto', 'Puntos extra', 'Martes · 14:00–17:00', '×3'])
            ->assertDontSee('Preparación del negocio')
            ->assertSee('Viernes · Todo el día')
            ->assertSee('×5')
            ->assertDontSee('Cada visita confirmada suma 1 punto.')
            ->assertDontSee('Asia/Tokyo');
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="promotion-title" and @aria-describedby="promotion-description"]')->length);
        $this->assertSame('Preparado al momento con café de especialidad.', trim($xpath->query('//*[@id="promotion-description"]')->item(0)?->textContent ?? ''));
        $this->assertSame(['Meta', 'Vigencia'], array_map(
            fn (\DOMNode $node): string => trim($node->textContent),
            iterator_to_array($xpath->query('//section[@aria-labelledby="promotion-title"]//dt')),
        ));
        $this->assertSame(['8 puntos', '01/01/2020 – 12/31/2097'], array_map(
            fn (\DOMNode $node): string => trim($node->textContent),
            iterator_to_array($xpath->query('//section[@aria-labelledby="promotion-title"]//dd')),
        ));
        $this->assertSame(1, $xpath->query('//main//a[normalize-space(.)="Ir a Pase"]')->length);
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="promotion-title"]//svg[not(@aria-hidden="true")]')->length);
        $this->assertStringNotContainsString('14:00', $xpath->query('//section[@aria-labelledby="promotion-title"]')->item(0)->textContent);
        $this->assertSame(2, $xpath->query('//section[@aria-labelledby="points-title"]//ul/li')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="points-title"]//section[@aria-label="Puntos extra"]/div/span[contains(@class,"app-role-support") and contains(@class,"font-medium") and normalize-space(.)="Puntos extra"]')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="points-title"]//section[@aria-label="Puntos extra"]/div/span[contains(@class,"app-role-body") and contains(@class,"font-semibold") and normalize-space(.)="2 configuraciones"]')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="points-title"]//section[@aria-label="Puntos extra"]/div[contains(@class,"items-center")]/span[@aria-hidden="true" and not(contains(@class,"row-span-2")) and not(contains(@class,"self-stretch"))]')->length);
        $this->assertStringNotContainsString('Zona horaria', $xpath->query('//main')->item(0)->textContent);
        $this->assertStringNotContainsString('America/La_Paz', $xpath->query('//main')->item(0)->textContent);
    }

    public function test_active_summary_without_extra_points_or_upcoming_promotion_keeps_preparation_truthful(): void
    {
        $business = Business::factory()->create(['timezone' => 'Asia/Tokyo']);
        $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00');

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $response->assertSeeTextInOrder(['Promoción', 'Actividad de esta promoción', 'Puntos de esta promoción', 'Visita habitual', '1 punto', 'Sin puntos extra', 'Próxima promoción', 'Preparación del negocio'])
            ->assertSee('1 de 2 completados')
            ->assertSee('Dale a tu pase el estilo de tu negocio.')
            ->assertSee('2. Crea tu primera promoción')
            ->assertDontSee('Cada visita confirmada suma 1 punto.')
            ->assertDontSee('Prepara tu pase y tu primera promoción desde Pase.')
            ->assertDontSee('Asia/Tokyo');
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="points-title" and not(@aria-describedby)]')->length);
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="points-title"]//ul/li')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="points-title"]/../section[@aria-labelledby="next-promotion-title"]')->length);
        $this->assertSame('No hay una próxima promoción programada.', trim($xpath->query('//section[@aria-labelledby="next-promotion-title"]//p[@id="next-promotion-empty"]')->item(0)?->textContent ?? ''));
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="next-promotion-title"]//*[self::dl or self::h3 or @data-flux-badge]')->length);
        $this->assertSame(2, $xpath->query('//section[@aria-labelledby="preparation-title"]//article')->length);
        $this->assertStringContainsString('Pendiente', $xpath->query('//article[@aria-labelledby="preparation-appearance"]')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//article[@aria-labelledby="preparation-promotion"]//*[@data-flux-badge and normalize-space(.)="Completado"]')->length);
    }

    public function test_terminal_publications_leave_primary_empty_without_reopening_preparation(): void
    {
        $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
        $promotion = $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2020-02-01 04:00:00+00');

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $response->assertSeeTextInOrder(['Promoción', 'No hay una promoción activa ni programada', 'Meta', 'Vigencia'])
            ->assertDontSee('Preparación del negocio')
            ->assertSee('Un café de cortesía')->assertSee('Finalizada');
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertStringNotContainsString('Un café de cortesía', $xpath->query('//section[@aria-labelledby="promotion-title"]')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="promotion-history-heading"]//li[@data-promotion-phase="ended"]')->length);
        $this->assertSame(['—', '—', '—', '—'], $this->metricValues($response->getContent()));
        $promotion->forceFill(['status' => 'cancelled', 'cancelled_at' => '2020-01-10 12:00:00+00'])->save();

        $this->get(route('dashboard'))->assertSee('No hay una promoción activa ni programada')
            ->assertSee('Cancelada')->assertSee('Un café de cortesía')
            ->assertDontSee('Preparación del negocio');
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

            $visible = $label !== 'Borrador';
            $this->assertSame((int) $visible, $badges->length, $label);
            if ($visible) {
                $this->assertContains($colorClass, explode(' ', $badges->item(0)->getAttribute('class')), $label);
            }
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
        $response->assertSee('Todavía no tiene visitas confirmadas')->assertDontSee('Reintentar')->assertDontSee('Puntos acumulados');
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="activity-title" and @aria-describedby="activity-scope"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="activity-title"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="activity-scope"]')->length);
        $this->assertSame(4, $xpath->query('//section[@aria-labelledby="activity-title"]//dl/div[dt and count(dd)=2]')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="activity-title"]//dt[span[normalize-space(.)="Pases que regresaron"]]/svg[@aria-hidden="true" and @fill="none" and contains(@class,"text-app-accent")]/path[starts-with(@d,"M19.5 12c0")]')->length);
        $this->assertSame(['Pases con actividad', 'Pases que regresaron', 'Recompensas desbloqueadas', 'Recompensas canjeadas'], array_map(
            fn (\DOMNode $node): string => trim($node->textContent),
            iterator_to_array($xpath->query('//section[@aria-labelledby="activity-title"]//dt')),
        ));
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="promotion-title" and @aria-describedby]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="promotion-description"]')->length);
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="activity-title"]//dl[@aria-describedby]')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="activity-title"]//dl/div[dt[normalize-space(.)="Pases con actividad"]]/dd//p[normalize-space(.)="Todavía no tiene visitas confirmadas."]')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="points-title"]/../section[@aria-labelledby="next-promotion-title" and @aria-describedby="next-promotion-empty"]')->length);
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
        DB::table('visits')->insert([
            'business_id' => $business->id, 'promotion_id' => $promotion->id, 'customer_pass_id' => $pass->id,
            'confirmed_by_user_id' => $business->user_id, 'operation_id' => (string) Str::uuid(),
            'awarded_points' => 1, 'confirmed_at' => '2026-01-02 13:00:00+00',
        ]);
        DB::table('reward_entitlements')->insert([
            'business_id' => $business->id, 'promotion_id' => $promotion->id, 'customer_pass_id' => $pass->id,
            'unlocked_at' => '2026-01-02 12:00:00+00', 'redeemed_at' => '2026-01-02 13:00:00+00',
            'redeemed_by_user_id' => $business->user_id,
        ]);
        $next = $this->promotion($business, 'published', '2098-01-31 15:00:00+00', '2098-02-28 15:00:00+00');
        $next->forceFill(['reward_title' => 'Recompensa de la próxima', 'timezone_snapshot' => 'Asia/Tokyo', 'target_points' => 12])->save();

        $response = $this->actingAs($business->user)->get(route('dashboard'));

        $this->assertSame(['1', '1', '1', '1'], $this->metricValues($response->getContent()));
        $response->assertSeeTextInOrder(['Promoción', 'Actividad de esta promoción', 'Puntos de esta promoción', 'Próxima promoción', 'Recompensa de la próxima'])
            ->assertDontSee('Preparación del negocio')
            ->assertDontSee('No hay una próxima promoción programada.')
            ->assertSee('Desde el inicio de esta promoción.')
            ->assertSee('Pases que ya registraron una visita en esta promoción.')
            ->assertSee('Pases con dos o más visitas en esta promoción.')
            ->assertSee('Recompensas obtenidas, incluidas las ya canjeadas.')
            ->assertSee('Recompensas que tus clientes ya canjearon.')
            ->assertDontSee('Un pase no equivale a una persona única ni a una instalación de Wallet.')
            ->assertDontSee('Todavía no tiene visitas confirmadas');
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(['12 puntos', '02/01/2098 – 02/28/2098'], array_map(
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
        $this->assertSame(['1', '0', '0', '0'], $this->metricValues($component->html()));
        $fail = true;

        $this->get(route('dashboard'))
            ->assertSee(url('/visits/create'))->assertSee(url('/invite'));
        $component->call('$refresh')
            ->assertSee('No pudimos cargar toda la información.')->assertSee('Reintentar')
            ->assertSee('Inténtalo de nuevo en unos momentos.')
            ->assertSee('Actualizando…')
            ->assertDontSee('Todavía no tiene visitas confirmadas.')
            ->assertDontSee('Este fallo no desactiva la invitación ni el registro de visitas.');
        $component->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['slots']['text'] === 'No pudimos cargar toda la información.' && $params['dataset']['variant'] === 'danger');
        $this->assertCount(1, array_filter($component->effects['dispatches'], fn (array $event): bool => $event['name'] === 'toast-show'));
        $this->assertSame(['—', '—', '—', '—'], $this->metricValues($component->html()));
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$component->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@role="alert" and @aria-labelledby="summary-error" and @aria-describedby="summary-recovery"]')->length);
        $this->assertSame('No pudimos cargar toda la información.', trim($xpath->query('//*[@id="summary-error"]')->item(0)?->textContent ?? ''));
        $this->assertSame('Inténtalo de nuevo en unos momentos.', trim($xpath->query('//*[@id="summary-recovery"]')->item(0)?->textContent ?? ''));
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="activity-title"]//dl[@aria-describedby="summary-error summary-recovery"]')->length);
        $this->assertSame(1, $xpath->query('//button[@aria-describedby="summary-recovery"]')->length);
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="activity-title"]//*[@role="alert"]')->length);
        $this->assertSame(1, $xpath->query('//main/header/following-sibling::*[1][@role="alert"]')->length);

        $fail = false;
        $first->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        $replacement = $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00');
        $replacement->update(['reward_title' => 'Recompensa nueva']);

        $component->call('$refresh')->assertSee('Recompensa nueva')->assertSee('Un café de cortesía')
            ->assertDontSee('No pudimos cargar toda la información.');
        $this->assertSame(['0', '0', '0', '0'], $this->metricValues($component->html()));
        $component->assertDontSee('summary-error', false)->assertDontSee('summary-recovery', false);
        $component->assertNotDispatched('toast-show');
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

        $component->call('$refresh')->assertSee('No hay una promoción activa ni programada')
            ->assertSee('Finalizada')->assertSee('Actividad de esta promoción');
        $this->assertSame(['—', '—', '—', '—'], $this->metricValues($component->html()));
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

    public function test_populated_primary_and_upcoming_cards_offer_owned_detail_actions(): void
    {
        foreach (['active', 'scheduled'] as $primaryPhase) {
            $business = Business::factory()->create();
            $primary = $this->promotion($business, 'published',
                $primaryPhase === 'active' ? '2020-01-01 04:00:00+00' : '2097-01-01 04:00:00+00',
                '2098-01-01 04:00:00+00');
            $upcoming = $this->promotion($business, 'published', '2098-02-01 04:00:00+00', '2098-03-01 04:00:00+00');

            $component = Livewire::actingAs($business->user)->test('pages::business.summary');
            $document = new \DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8">'.$component->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($document);
            foreach (['promotion-title' => $primary, 'next-promotion-title' => $upcoming] as $heading => $promotion) {
                $origin = $heading === 'promotion-title' ? 'primary' : 'upcoming';
                $buttons = $xpath->query('//*[@aria-labelledby="'.$heading.'"]//button[@id="summary-'.$origin.'-detail-trigger-'.$promotion->public_id.'"]');
                $this->assertSame(1, $buttons->length);
                $this->assertSame('Ver detalle', trim($buttons->item(0)->textContent));
            }
            $component->call('showPromotionDetail', $upcoming->public_id, 'upcoming')
                ->assertSet('promotionDetailOrigin', 'upcoming')
                ->assertDispatched('modal-show', name: 'promotion-detail')
                ->assertSee('data-promotion-detail-phase="scheduled"', false)
                ->assertDontSee('Cancelar promoción');
        }
    }

    public function test_placeholder_cards_have_no_detail_action(): void
    {
        $business = Business::factory()->create();

        $this->actingAs($business->user)->get(route('dashboard'))
            ->assertDontSee('wire:click="showPromotionDetail(', false)
            ->assertDontSee('Ver detalle');

        $this->promotion($business, 'published', '2020-01-01 04:00:00+00', '2098-01-01 04:00:00+00');
        $component = Livewire::actingAs($business->user)->test('pages::business.summary');
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$component->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//button[starts-with(@*[name()="wire:click"], "showPromotionDetail(")]')->length);
        $this->assertSame(0, $xpath->query('//*[@aria-labelledby="next-promotion-title"]//button')->length);
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
            'cancelled_at' => $status === 'cancelled' ? $start : null,
        ])->save();

        return $promotion;
    }
}
