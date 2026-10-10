<?php

namespace App\Support;

use App\Enums\PromotionStatus;
use App\Models\Promotion;
use App\Models\PromotionMultiplierWindow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Reads owned published or cancelled terms without changing Promotion state.
 *
 * @phpstan-type Detail array{promotion: Promotion, phase: string, start_date: string, end_date: string, extra_points: list<array{weekday: int, start_time: string|null, end_time: string|null, multiplier: int}>}
 */
class PromotionDetail
{
    /**
     * Re-resolves ownership and frozen terms, deriving phase from a fresh database instant.
     *
     * @param  User  $actor  Current authenticated owner supplied by the server.
     * @param  mixed  $publicId  Untrusted detail selection; drafts are never readable here.
     * @return Detail Saved terms with inclusive dates in the publication timezone.
     *
     * @throws AuthorizationException When the actor cannot update the Business.
     * @throws ModelNotFoundException When the actor has no Business.
     * @throws HttpException When the identifier is invalid, foreign, missing or a draft.
     */
    public function read(User $actor, mixed $publicId): array
    {
        abort_unless(is_string($publicId) && Str::isUuid($publicId), 404);
        $business = $actor->business()->firstOrFail();
        Gate::forUser($actor)->authorize('update', $business);

        $promotion = $business->promotions()
            ->where('public_id', $publicId)
            ->whereIn('status', [PromotionStatus::Published->value, PromotionStatus::Cancelled->value])
            ->with(['extraPoints' => fn ($query) => $query->orderBy('weekday')->orderBy('start_time')->orderBy('id')])
            ->first();
        abort_if($promotion === null, 404);

        $startsAt = CarbonImmutable::parse($promotion->getRawOriginal('starts_at'));
        $endsAt = CarbonImmutable::parse($promotion->getRawOriginal('ends_at'));
        $instant = CarbonImmutable::parse(
            app(DatabaseClock::class)->captureForBusinessTimezone($business->timezone)['instant'],
        );
        $phase = match (true) {
            $promotion->status === PromotionStatus::Cancelled => 'cancelled',
            $instant->lessThan($startsAt) => 'scheduled',
            $instant->greaterThanOrEqualTo($endsAt) => 'ended',
            default => 'active',
        };

        return [
            'promotion' => $promotion,
            'phase' => $phase,
            'start_date' => $startsAt->setTimezone($promotion->timezone_snapshot)->format('d/m/Y'),
            'end_date' => $endsAt->setTimezone($promotion->timezone_snapshot)->subDay()->format('d/m/Y'),
            'extra_points' => array_values($promotion->extraPoints->map(fn (PromotionMultiplierWindow $rule): array => [
                'weekday' => $rule->weekday,
                'start_time' => $rule->start_time === null ? null : substr($rule->start_time, 0, 5),
                'end_time' => $rule->end_time === null ? null : substr($rule->end_time, 0, 5),
                'multiplier' => $rule->multiplier,
            ])->all()),
        ];
    }
}
