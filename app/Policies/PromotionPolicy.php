<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;

class PromotionPolicy
{
    public function create(User $user, Business $business): bool
    {
        return $user->hasVerifiedEmail() && $business->user_id === $user->id;
    }

    public function update(User $user, Promotion $promotion): bool
    {
        return $user->hasVerifiedEmail()
            && $promotion->business()->where('user_id', $user->id)->exists();
    }
}
