<?php

namespace Database\Factories;

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Support\DatabaseClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defines coherent café rewards and editable drafts without provider effects.
 *
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    public const REWARDS = [
        ['Café y tostada', 'Un café de la casa y una tostada con tomate.'],
        ['Desayuno completo', 'Bebida caliente, tostada y zumo de naranja.'],
        ['Merienda para dos', 'Dos cafés y dos porciones de bizcocho casero.'],
        ['Café de especialidad', 'Un café de origen preparado al momento.'],
        ['Porción de tarta', 'Una porción de la tarta casera disponible en vitrina.'],
        ['Bocadillo artesano', 'Un bocadillo de pan artesano con el relleno del día.'],
        ['Chocolate con churros', 'Una taza de chocolate y una ración de churros.'],
        ['Tostada de aguacate', 'Una tostada de pan integral con aguacate y tomate.'],
        ['Café frío y galleta', 'Un café con hielo y una galleta recién horneada.'],
        ['Brioche de la casa', 'Un brioche artesano acompañado de una bebida caliente.'],
        ['Zumo y croissant', 'Un zumo de naranja y un croissant de mantequilla.'],
    ];

    public const TARGETS = [6, 8, 10, 12, 15];

    /**
     * Defines a draft with one lazy Business-local clock reading unless dates are supplied explicitly.
     *
     * @return array<string, mixed> Owned draft dates, curated reward terms and no published snapshot.
     */
    public function definition(): array
    {
        $reward = fake()->randomElement(self::REWARDS);

        return [
            'business_id' => Business::factory(),
            'local_start_date' => fn (array $attributes): string => app(DatabaseClock::class)
                ->capture(Business::query()->whereKey($attributes['business_id'])->sole()->timezone)->localDate,
            'local_end_date' => fn (array $attributes): string => CarbonImmutable::parse(
                $attributes['local_start_date'], 'UTC',
            )->addDays(13)->toDateString(),
            'target_points' => fake()->randomElement(self::TARGETS),
            'reward_title' => $reward[0],
            'reward_description' => $reward[1],
            'status' => PromotionStatus::Draft,
            'timezone_snapshot' => null,
            'starts_at' => null,
            'ends_at' => null,
            'cancelled_at' => null,
        ];
    }
}
