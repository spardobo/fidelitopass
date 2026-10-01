<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_landing_invites_business_owners_without_payment_note_or_robotic_copy(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSeeText('Configura tu negocio y crea Promociones que dan a tus clientes una razón para volver.')
            ->assertSeeText('Comparte tu QR público para que tus clientes guarden el Pase en Google Wallet, sin crear una cuenta.')
            ->assertDontSeeText('El formulario de registro no solicita datos de pago.')
            ->assertDontSeeText('La propuesta:')
            ->assertDontSeeText('comparte su QR público');
    }

    public function test_landing_presents_the_product_workflow_and_one_illustrative_promotion(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSeeText('Promociones')
            ->assertSeeText('PROMOCIÓN')
            ->assertSeeText('9 / 15 puntos')
            ->assertSeeText('Hamburguesa gratis')
            ->assertSeeText('Ejemplo: 1 punto por visita, 2 los martes. Al llegar a 15 puntos antes del plazo, tu cliente obtiene una hamburguesa.')
            ->assertSeeText('Crea tu cuenta, configura tu negocio y publica tu Promoción. Comparte el Pase, confirma las visitas y entrega las recompensas.')
            ->assertSeeText('Cada visita habitual suma 1 punto. Ofrece Puntos extra en los días u horarios definidos para tu Promoción.')
            ->assertSeeText('No necesita una cuenta de FidelitoPass. Tu cliente participa con el Pase de tu negocio en Google Wallet.')
            ->assertDontSeeText('Cuando esté disponible')
            ->assertDontSeeText('más adelante')
            ->assertDontSeeText('aún no está disponible')
            ->assertSee('href="'.route('register').'"', false)
            ->assertSee('href="'.route('login').'"', false)
            ->assertDontSeeText('Reto actual')
            ->assertDontSeeText('Retos de puntos');
    }

    public function test_an_anonymous_visitor_can_follow_the_localized_product_story(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee("localStorage.setItem('flux.appearance', 'dark')", false)
            ->assertSee('class="dark"', false)
            ->assertSee('Dale a tus clientes una razón para volver.')
            ->assertSee('Diseña la Promoción')
            ->assertSee('Comparte el Pase')
            ->assertSee('Reconoce cada visita')
            ->assertSee('9 / 15 puntos')
            ->assertSee('Ejemplo: 1 punto por visita, 2 los martes. Al llegar a 15 puntos antes del plazo, tu cliente obtiene una hamburguesa.')
            ->assertSee('Código manual')
            ->assertSee('Un motivo para volver')
            ->assertSee('Progreso a la vista')
            ->assertSee('QR de ejemplo')
            ->assertDontSee('landing.hero.sample.progress')
            ->assertSee('La Promoción cambia. El Pase se queda.')
            ->assertSee('Una meta con fecha. Una visita que suma.')
            ->assertDontSee('tarjeta Wallet')
            ->assertDontSee('landing.hero.card_aria')
            ->assertSee('href="#content"', false)
            ->assertSee('href="#how-it-works"', false)
            ->assertSee('href="#challenges"', false)
            ->assertSee('href="#business"', false)
            ->assertSee('href="'.route('register').'"', false)
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('Crear mi cuenta')
            ->assertSee('id="content" tabindex="-1"', false)
            ->assertDontSee('href="#contenido"', false)
            ->assertSee('Inicia sesión')
            ->assertDontSee('aria-label="Modo oscuro"', false)
            ->assertSee('name="description"', false)
            ->assertSee('Crea Promociones de puntos y ofrece a tus clientes un Pase para Google Wallet con su progreso y recompensas.')
            ->assertSee('FidelitoPass - Dale a tus clientes una razón para volver.')
            ->assertDontSee('landing.css')
            ->assertDontSee('landing.js')
            ->assertDontSee('href="#"', false)
            ->assertDontSee('href="/dashboard"', false)
            ->assertSee('href="#pass"', false)
            ->assertSee('href="#questions"', false)
            ->assertDontSee('Compartir un QR · En desarrollo');
    }
}
