<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\User;

class BusinessPolicy
{
    public function update(User $user, Business $business): bool
    {
        return $user->hasVerifiedEmail() && $business->user_id === $user->id;
    }
}
