<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('denies another owner and an unverified owner from updating', function () {
    $business = Business::factory()->create();
    $otherBusiness = Business::factory()->create();
    $unverified = User::factory()->unverified()->create();
    $unverifiedBusiness = Business::factory()->for($unverified)->create();

    expect($otherBusiness->user->can('update', $business))->toBeFalse()
        ->and($unverified->can('update', $unverifiedBusiness))->toBeFalse()
        ->and($business->user->can('update', $business))->toBeTrue();
});

it('denies a signed profile refresh after the owners verification is revoked', function (array $updates) {
    $business = Business::factory()->create(['name' => 'Verified profile', 'timezone' => 'UTC']);
    $user = $business->user;
    $page = $this->actingAs($user)->get(route('business.edit'))->assertOk();
    $document = new DOMDocument;
    $document->loadHTML($page->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $snapshot = null;
    foreach ($xpath->query('//*[@*[name()="wire:snapshot"]]') as $element) {
        $candidate = json_decode($element->getAttribute('wire:snapshot'), true, flags: JSON_THROW_ON_ERROR);
        if (($candidate['memo']['name'] ?? null) === 'pages::business.profile') {
            $snapshot = $element->getAttribute('wire:snapshot');
            break;
        }
    }
    expect($snapshot)->not->toBeNull();
    $user->forceFill(['email_verified_at' => null])->save();
    $business->update(['name' => 'Private name after verification revocation']);

    $this->postJson(Livewire::getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]],
    ], ['X-Livewire' => 'true'])
        ->assertDontSee('Private name after verification revocation')
        ->assertForbidden();
    $this->assertDatabaseHas('businesses', ['id' => $business->id, 'name' => 'Private name after verification revocation', 'timezone' => 'UTC']);
})->with(['refresh only' => [[]], 'property update' => [['name' => 'Unverified edit']]]);

it('denies direct Livewire save after the owners verification is revoked', function () {
    $business = Business::factory()->create(['name' => 'Original', 'timezone' => 'UTC']);
    $user = $business->user;
    $this->actingAs($user);
    $form = Livewire::test('pages::business.profile')
        ->set('name', 'Negocio no verificado')
        ->set('timezone', 'Europe/Madrid');
    $user->forceFill(['email_verified_at' => null])->save();

    $form->call('save')
        ->assertForbidden();

    expect($business->fresh()->name)->toBe('Original')
        ->and($business->fresh()->timezone)->toBe('UTC');
});

it('authorizes direct save against the current session and current ownership', function () {
    $business = Business::factory()->create(['name' => 'Original', 'timezone' => 'UTC']);
    $otherBusiness = Business::factory()->create(['name' => 'Otro negocio', 'timezone' => 'UTC']);
    $this->actingAs($business->user);
    $form = Livewire::test('pages::business.profile')
        ->set('name', 'Nombre actualizado')
        ->set('timezone', 'Europe/Madrid');

    $this->actingAs($otherBusiness->user);
    $form->call('save')->assertRedirect(route('dashboard'));

    expect($business->fresh()->name)->toBe('Original')
        ->and($business->fresh()->timezone)->toBe('UTC');
    expect($otherBusiness->fresh()->name)->toBe('Nombre actualizado')
        ->and($otherBusiness->fresh()->timezone)->toBe('Europe/Madrid');
    expect(Business::count())->toBe(2);
});
