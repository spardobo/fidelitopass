<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['weekday', 'multiplier', 'start_time', 'end_time'])]
class PromotionMultiplierWindow extends Model
{
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'multiplier' => 'integer',
        ];
    }

    /** @return BelongsTo<Promotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
