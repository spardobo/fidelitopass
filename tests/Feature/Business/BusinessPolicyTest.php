<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('allows a verified owner to create only before a business exists', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(Gate::allows('create', Business::class))->toBeTrue();
    Business::factory()->for($user)->create();
    expect(Gate::allows('create', Business::class))->toBeFalse();
});

it('denies another owner and an unverified owner from updating', function () {
    $business = Business::factory()->create();
    $other = User::factory()->create();
    $unverified = User::factory()->unverified()->create();

    expect($other->can('update', $business))->toBeFalse()
        ->and($unverified->can('update', $business))->toBeFalse()
        ->and($unverified->can('create', Business::class))->toBeFalse()
        ->and($business->user->can('update', $business))->toBeTrue();
});
