<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_an_anonymous_visitor_can_follow_the_localized_product_story(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee("localStorage.setItem('flux.appearance', 'dark')", false)
            ->assertSee('class="dark"', false)
            ->assertSee('Dale a tus clientes una razón para volver.')
            ->assertSee('Diseña el reto')
            ->assertSee('Comparte el pase')
            ->assertSee('Reconoce cada visita')
            ->assertSee('9 / 15 puntos')
            ->assertSee('Ejemplo: 1 punto por visita, 2 los martes. Al llegar a 10 puntos antes del plazo, tu cliente obtiene un café.')
            ->assertSee('Código manual')
            ->assertSee('Un motivo para volver')
            ->assertSee('Progreso a la vista')
            ->assertSee('QR de muestra con el texto FidelitoPass')
            ->assertDontSee('landing.hero.sample.progress')
            ->assertSee('El reto cambia. El pase se queda.')
            ->assertSee('Una meta con fecha. Una visita que suma.')
            ->assertSee('QR y código de ejemplo.')
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
            ->assertSee('Convierte cada visita en una razón para volver con retos de puntos y un pase de Google Wallet para tu negocio.')
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
