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
            ->assertSee('class="dark"', false)
            ->assertSee('Haz que volver sea parte del juego.')
            ->assertSee('Crea un reto')
            ->assertSee('Comparte tu QR')
            ->assertSee('Valida visitas')
            ->assertSee('Entrega la recompensa')
            ->assertSee('10 puntos')
            ->assertSee('2 puntos')
            ->assertSee('Google Wallet')
            ->assertSee('href="#contenido"', false)
            ->assertSee('href="#como-funciona"', false)
            ->assertSee('href="#retos"', false)
            ->assertSee('href="#negocios"', false)
            ->assertSee('href="'.route('register').'"', false)
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('Crear mi reto')
            ->assertSee('Crea tu primer reto')
            ->assertSee('Entrar')
            ->assertSee('name="description"', false)
            ->assertSee('Crea retos de puntos para tus clientes')
            ->assertSee('Haz que volver sea parte del juego - '.config('app.name'))
            ->assertDontSee('landing.css')
            ->assertDontSee('landing.js')
            ->assertDontSee('theme-toggle')
            ->assertDontSee('aria-pressed=')
            ->assertDontSee('href="#"', false)
            ->assertDontSee('href="/dashboard"', false);
    }
}
