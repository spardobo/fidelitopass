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
    protected function casts(): array
    {
        return [
            'local_start_date' => 'immutable_date',
            'local_end_date' => 'immutable_date',
            'target_points' => 'integer',
            'status' => PromotionStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Promotion $promotion): void {
            $promotion->public_id ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return HasMany<PromotionMultiplierWindow, $this> */
    public function extraPoints(): HasMany
    {
        return $this->hasMany(PromotionMultiplierWindow::class);
    }
}
