<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_factor_challenge_is_unavailable(): void
    {
        $this->get('/two-factor-challenge')->assertNotFound();
        $this->post('/two-factor-challenge', ['code' => '123456'])->assertNotFound();
    }
}
