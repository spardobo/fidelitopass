<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['weekday', 'multiplier', 'start_time', 'end_time'])]
class PromotionMultiplierWindow extends Model
{
    /**
     * Define integer casts for the weekday and multiplier columns.
     *
     * @return array<string, string> Attribute names mapped to their Eloquent cast definitions.
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'multiplier' => 'integer',
        ];
    }

    /**
     * Define the parent Promotion relationship for this multiplier window.
     *
     * @return BelongsTo<Promotion, $this> Promotion that owns the window.
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
