<?php

namespace App\Models;

use App\Enums\PromotionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** @property PromotionStatus $status */
#[Fillable(['local_start_date', 'local_end_date', 'target_points', 'reward_title', 'reward_description'])]
class Promotion extends Model
{
    /**
     * Defines the date, publication instant, points, and status casts used by the promotion aggregate.
     *
     * @return array<string, string|class-string> Attribute names mapped to Eloquent cast definitions.
     */
    protected function casts(): array
    {
        return [
            'local_start_date' => 'immutable_date',
            'local_end_date' => 'immutable_date',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'target_points' => 'integer',
            'status' => PromotionStatus::class,
        ];
    }

    /**
     * Registers a creation hook that assigns a stable public identifier when one is missing.
     */
    protected static function booted(): void
    {
        static::creating(function (Promotion $promotion): void {
            $promotion->public_id ??= (string) Str::uuid();
        });
    }

    /**
     * Defines the owning Business relationship.
     *
     * @return BelongsTo<Business, $this> Business that owns this Promotion.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Defines the Promotion-owned multiplier windows.
     *
     * @return HasMany<PromotionMultiplierWindow, $this> Extra-point rules attached to this Promotion.
     */
    public function extraPoints(): HasMany
    {
        return $this->hasMany(PromotionMultiplierWindow::class);
    }
}
