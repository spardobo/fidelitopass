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
            ->assertSee("localStorage.setItem('flux.appearance', 'light')", false)
            ->assertDontSee('class="dark"', false)
            ->assertSee('Haz que volver sea parte del juego.')
            ->assertSee('Registra tu negocio')
            ->assertSee('Compartir un QR · En desarrollo')
            ->assertSee('Registrar visitas · En desarrollo')
            ->assertSee('Reconocer la constancia · En desarrollo')
            ->assertSee('6 de 10 puntos')
            ->assertSee('Ejemplo conceptual · No disponible todavía')
            ->assertSee('Google Wallet · En desarrollo')
            ->assertSee('No. Los retos de puntos y recompensas todavía están en desarrollo.')
            ->assertSee('href="#contenido"', false)
            ->assertSee('href="#como-funciona"', false)
            ->assertSee('href="#retos"', false)
            ->assertSee('href="#negocios"', false)
            ->assertSee('href="'.route('register').'"', false)
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('Crear mi cuenta')
            ->assertSee('Inicia sesión')
            ->assertSee('aria-label="Modo oscuro"', false)
            ->assertSee('name="description"', false)
            ->assertSee('Crea tu cuenta y registra tu negocio en FidelitoPass.')
            ->assertSee('Haz que volver sea parte del juego - '.config('app.name'))
            ->assertDontSee('landing.css')
            ->assertDontSee('landing.js')
            ->assertDontSee('href="#"', false)
            ->assertDontSee('href="/dashboard"', false);
    }
}
