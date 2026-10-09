<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\User;

class BusinessPolicy
{
    /**
     * Decides whether a verified Business owner may update that Business.
     *
     * @param  User  $user  Authenticated actor requesting the update.
     * @param  Business  $business  Business whose ownership is checked.
     * @return bool Whether the user is verified and owns the Business.
     */
    public function update(User $user, Business $business): bool
    {
        return $user->hasVerifiedEmail() && $business->user_id === $user->id;
    }
}
