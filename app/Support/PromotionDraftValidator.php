<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PromotionDraftValidator
{
    /**
     * Validate untrusted Promotion draft fields and normalize multiplier-window values.
     *
     * @param  array<string, mixed>  $input  Client-provided draft fields and rule entries.
     * @return array<string, mixed> Validated draft values with normalized child rule fields.
     *
     * @throws ValidationException When a field or rule window violates the draft contract.
     */
    public function validateInput(array $input): array
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
            'extra_points.*.start_time' => ['nullable', 'required_with:extra_points.*.end_time', 'date_format:H:i'],
            'extra_points.*.end_time' => ['nullable', 'required_with:extra_points.*.start_time', 'date_format:H:i'],
        ])->validate();

        $this->validateExtraPointWindows($data['extra_points']);

        return $data;
    }

    /**
     * Ensure a submitted start date is not earlier than the supplied Business-local current date.
     *
     * @param  string  $startDate  ISO calendar date submitted for the draft.
     * @param  string  $businessToday  ISO calendar date captured for the Business timezone.
     *
     * @throws ValidationException When the submitted date precedes the supplied current date.
     */
    public function validateCurrentStartDate(string $startDate, string $businessToday): void
    {
        Validator::make(
            ['local_start_date' => $startDate],
            ['local_start_date' => ['after_or_equal:'.$businessToday]],
            ['local_start_date.after_or_equal' => __('business.promotion.start_date_current')],
        )->validate();
    }

    /**
     * Reject invalid same-day combinations while allowing disjoint or touching multiplier windows.
     *
     * @param  array<int, array{weekday: int|string, start_time?: string|null, end_time?: string|null, multiplier: int|string}>  $windows  Validated rule windows with scalar values in project-supported formats.
     *
     * @throws ValidationException When a window is incomplete, reversed, overlapping, duplicated, or conflicts with an all-day rule.
     */
    public function validateExtraPointWindows(array $windows): void
    {
        $byWeekday = [];

        foreach ($windows as $index => $window) {
            $weekday = (int) $window['weekday'];
            $start = $window['start_time'] ?? null;
            $end = $window['end_time'] ?? null;

            if (($start === null) !== ($end === null)) {
                $field = $start === null ? 'start_time' : 'end_time';
                $attribute = __('validation.attributes.extra_points.*.'.$field);

                throw ValidationException::withMessages([
                    "extra_points.{$index}.{$field}" => __('validation.required', ['attribute' => $attribute]),
                ]);
            }

            if ($start !== null && $start >= $end) {
                throw ValidationException::withMessages([
                    'extra_points' => __('business.promotion.extra_points_window_order'),
                ]);
            }

            $byWeekday[$weekday][] = [$start, $end];
        }

        foreach ($byWeekday as $dayWindows) {
            if (count($dayWindows) > 1 && in_array([null, null], $dayWindows, true)) {
                throw ValidationException::withMessages([
                    'extra_points' => __('business.promotion.extra_points_all_day_conflict'),
                ]);
            }

            usort($dayWindows, fn (array $left, array $right): int => strcmp($left[0], $right[0]));

            for ($index = 1; $index < count($dayWindows); $index++) {
                if ($dayWindows[$index][0] < $dayWindows[$index - 1][1]) {
                    throw ValidationException::withMessages([
                        'extra_points' => __('business.promotion.extra_points_overlap'),
                    ]);
                }
            }
        }
    }
}
