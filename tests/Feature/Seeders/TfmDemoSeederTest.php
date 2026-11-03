<?php

use App\Models\User;
use Database\Seeders\TfmDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds Brais & Beans branding while retaining the Zero business and account identities', function () {
    config(['tfm.review_password' => 'fixture-only-password']);

    $this->seed(TfmDemoSeeder::class);

    $this->assertDatabaseHas('businesses', [
        'user_id' => User::query()->where('email', 'mouredev@fidelito.com')->sole()->id,
        'name' => 'Brais & Beans',
        'pass_background_color' => '#009beb',
    ]);
    $this->assertDatabaseHas('businesses', [
        'user_id' => User::query()->where('email', 'mouredev-zero@fidelito.com')->sole()->id,
        'name' => 'MoureDev Café Zero',
        'pass_background_color' => null,
    ]);
    $this->assertDatabaseHas('users', ['name' => 'MoureDev', 'email' => 'mouredev@fidelito.com']);
    $this->assertDatabaseHas('users', ['name' => 'MoureDev Zero', 'email' => 'mouredev-zero@fidelito.com']);
});
