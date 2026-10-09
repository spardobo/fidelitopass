<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A same-Business pass and Promotion association, not evidence of an accepted Visit. */
class PromotionParticipation extends Model
{
    /**
     * Defines the Business shared by the associated pass and Promotion.
     *
     * @return BelongsTo<Business, $this> Business that owns this association.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Defines the persistent anonymous identity participating in the Promotion.
     *
     * @return BelongsTo<CustomerPass, $this> Customer pass retained across Promotions.
     */
    public function customerPass(): BelongsTo
    {
        return $this->belongsTo(CustomerPass::class);
    }

    /**
     * Defines the Promotion associated with the anonymous pass.
     *
     * @return BelongsTo<Promotion, $this> Promotion owned by the same Business as the pass.
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
