<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_an_anonymous_visitor_can_understand_the_product_and_follow_section_links(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSee('Haz que volver sea parte del juego.')
            ->assertSee('Crea un reto')
            ->assertSee('Comparte tu QR')
            ->assertSee('Valida visitas')
            ->assertSee('Entrega la recompensa')
            ->assertSee('10 puntos')
            ->assertSee('2 puntos')
            ->assertSee('Google Wallet')
            ->assertSee('href="#como-funciona"', false)
            ->assertSee('href="#retos"', false)
            ->assertSee('href="#negocios"', false)
            ->assertSee('href="'.asset('landing.css').'"', false)
            ->assertDontSee('href="'.route('login').'"', false);
    }
}
