<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Accepted historical points and confirmation facts; this model does not authorize or accept Visits. */
class Visit extends Model
{
    /** Preserve the supplied domain instant's offset and microseconds when writing to PostgreSQL. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * Reads stored awarded outcomes and the immutable authoritative confirmation instant.
     *
     * @return array<string, string> Attribute names mapped to Eloquent casts.
     */
    protected function casts(): array
    {
        return [
            'awarded_points' => 'integer',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Defines the Business shared by the accepted Visit's pass and Promotion.
     *
     * @return BelongsTo<Business, $this> Business that owns this historical fact.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Defines the persistent anonymous identity that received the awarded points.
     *
     * @return BelongsTo<CustomerPass, $this> Pass retained across Promotions.
     */
    public function customerPass(): BelongsTo
    {
        return $this->belongsTo(CustomerPass::class);
    }

    /**
     * Defines the Promotion whose accepted historical outcome is stored.
     *
     * @return BelongsTo<Promotion, $this> Promotion belonging to the same Business as the pass.
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * Defines the authenticated actor who explicitly confirmed the Visit.
     *
     * @return BelongsTo<User, $this> Confirming User; the future command must authorize Business ownership.
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}
