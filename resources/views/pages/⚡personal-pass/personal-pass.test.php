<?php

use App\Support\DatabaseClock;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('does not turn direct mounting into a private read and refuses native refresh hydration', function () {
    $this->mock(DatabaseClock::class)->shouldNotReceive('capture');

    Livewire::test('pages::personal-pass', ['businessPublicId' => (string) Str::uuid(), 'passPublicId' => (string) Str::uuid()])
        ->assertSee('No pudimos abrir este enlace.')
        ->call('$refresh')
        ->assertForbidden();
});
