<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\CustomerPass;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerPass> */
class CustomerPassFactory extends Factory
{
    /**
     * Defines an unprovisioned anonymous pass with an isolated Business owner.
     *
     * @return array<string, mixed> Ownership facts without credentials or provider effects.
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
        ];
    }
}
