<?php

namespace App\Actions\Promotions;

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SavePromotionDraft
{
    /**
     * Persist a complete draft aggregate for the authenticated Business owner.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input, ?Promotion $promotion = null): Promotion
    {
        $data = $this->validateInput($input);

        return DB::transaction(function () use ($actor, $data, $promotion): Promotion {
            $business = $actor->business()->lockForUpdate()->firstOrFail();

            if ($promotion === null) {
                Gate::forUser($actor)->authorize('create', [Promotion::class, $business]);
                $draft = $business->promotions()->make();
                $draft->status = PromotionStatus::Draft;
            } else {
                $draft = $business->promotions()
                    ->whereKey($promotion->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                Gate::forUser($actor)->authorize('update', $draft);

                if ($draft->status !== PromotionStatus::Draft) {
                    throw ValidationException::withMessages([
                        'promotion' => 'Solo se pueden editar promociones en borrador.',
                    ]);
                }
            }

            $draft->fill([
                'local_start_date' => $data['local_start_date'],
                'local_end_date' => $data['local_end_date'],
                'target_points' => $data['target_points'],
                'reward_title' => $data['reward_title'],
                'reward_description' => $data['reward_description'] ?? null,
            ]);
            $draft->save();

            $draft->extraPoints()->delete();
            $draft->extraPoints()->createMany($data['extra_points']);

            return $draft->load('extraPoints');
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateInput(array $input): array
    {
        $data = Validator::make($input, [
            'local_start_date' => ['required', 'date_format:Y-m-d'],
            'local_end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:local_start_date'],
            'target_points' => ['required', 'integer', 'min:1'],
            'reward_title' => ['required', 'string'],
            'reward_description' => ['nullable', 'string'],
            'extra_points' => ['present', 'array'],
            'extra_points.*' => ['array:weekday,start_time,end_time,multiplier'],
            'extra_points.*.weekday' => ['required', 'integer', 'between:1,7'],
            'extra_points.*.multiplier' => ['required', 'integer', 'in:2,3,5'],
            'extra_points.*.start_time' => ['nullable', 'date_format:H:i'],
            'extra_points.*.end_time' => ['nullable', 'date_format:H:i'],
        ])->validate();

        $this->validateWindows($data['extra_points']);

        return $data;
    }

    /** @param array<int, array{weekday: int|string, start_time?: string|null, end_time?: string|null, multiplier: int|string}> $windows */
    private function validateWindows(array $windows): void
    {
        $byWeekday = [];

        foreach ($windows as $window) {
            $weekday = (int) $window['weekday'];
            $start = $window['start_time'] ?? null;
            $end = $window['end_time'] ?? null;

            if (($start === null) !== ($end === null)) {
                throw ValidationException::withMessages([
                    'extra_points' => 'Cada regla de puntos extra debe incluir ambas horas o dejar las dos vacías.',
                ]);
            }

            if ($start !== null && $start >= $end) {
                throw ValidationException::withMessages([
                    'extra_points' => 'El horario de puntos extra debe terminar después de su inicio, dentro del mismo día.',
                ]);
            }

            $byWeekday[$weekday][] = [$start, $end];
        }

        foreach ($byWeekday as $dayWindows) {
            if (count($dayWindows) > 1 && in_array([null, null], $dayWindows, true)) {
                throw ValidationException::withMessages([
                    'extra_points' => 'No se puede combinar una regla de día completo con otros horarios en el mismo día.',
                ]);
            }

            usort($dayWindows, fn (array $left, array $right): int => strcmp($left[0], $right[0]));

            for ($index = 1; $index < count($dayWindows); $index++) {
                if ($dayWindows[$index][0] < $dayWindows[$index - 1][1]) {
                    throw ValidationException::withMessages([
                        'extra_points' => 'Los horarios de puntos extra no pueden superponerse.',
                    ]);
                }
            }
        }
    }
}
