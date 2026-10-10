<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historical unlock and final redemption facts; this model does not authorize either operation. */
class RewardEntitlement extends Model
{
    /** Preserve the supplied domain instant's offset and microseconds when writing to PostgreSQL. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * Reads the authoritative unlock and optional final redemption instants without mutation.
     *
     * @return array<string, string> Domain instant attributes mapped to immutable date casts.
     */
    protected function casts(): array
    {
        return [
            'unlocked_at' => 'immutable_datetime',
            'redeemed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Defines the Business shared by this entitlement's pass and Promotion.
     *
     * @return BelongsTo<Business, $this> Business that owns these historical facts.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Defines the anonymous pass that completed the Promotion.
     *
     * @return BelongsTo<CustomerPass, $this> Persistent pass retained across Promotions.
     */
    public function customerPass(): BelongsTo
    {
        return $this->belongsTo(CustomerPass::class);
    }

    /**
     * Defines the Promotion that grants this one-time Reward entitlement.
     *
     * @return BelongsTo<Promotion, $this> Promotion owned by the same Business as the pass.
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * Defines the authenticated actor who explicitly confirmed final redemption, when present.
     *
     * @return BelongsTo<User, $this> Redeeming User; the future command must authorize Business ownership.
     */
    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_user_id');
    }
}
