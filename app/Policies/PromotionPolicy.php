<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;

class PromotionPolicy
{
    /**
     * Decide whether a verified Business owner may create a Promotion for that Business.
     *
     * @param  User  $user  Authenticated actor requesting creation.
     * @param  Business  $business  Business selected as the Promotion owner.
     * @return bool Whether the user is verified and owns the Business.
     */
    public function create(User $user, Business $business): bool
    {
        return $user->hasVerifiedEmail() && $business->user_id === $user->id;
    }

    /**
     * Decide whether a verified Business owner may update the specified Promotion.
     *
     * @param  User  $user  Authenticated actor requesting the update.
     * @param  Promotion  $promotion  Promotion whose ownership is checked.
     * @return bool Whether the user is verified and owns the Promotion's Business.
     */
    public function update(User $user, Promotion $promotion): bool
    {
        return $user->hasVerifiedEmail()
            && $promotion->business()->where('user_id', $user->id)->exists();
    }
}
