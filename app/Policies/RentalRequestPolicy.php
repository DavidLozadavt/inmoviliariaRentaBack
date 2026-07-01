<?php

namespace App\Policies;

use App\Models\RentalRequest;
use App\Models\User;

class RentalRequestPolicy
{
    private const ADMIN_ROLES = ['admin', 'support'];

    public function before(User $user, string $ability): ?bool
    {
        if (in_array($user->role, self::ADMIN_ROLES, true)) {
            return true;
        }
        return null;
    }

    public function view(User $user, RentalRequest $rentalRequest): bool
    {
        return $user->id === $rentalRequest->user_id || $user->id === $rentalRequest->owner_id;
    }

    public function acceptAsOwner(User $user, RentalRequest $rentalRequest): bool
    {
        return $user->id === $rentalRequest->owner_id;
    }

    public function acceptAsTenant(User $user, RentalRequest $rentalRequest): bool
    {
        return $user->id === $rentalRequest->user_id;
    }

    public function cancel(User $user, RentalRequest $rentalRequest): bool
    {
        return $user->id === $rentalRequest->user_id || $user->id === $rentalRequest->owner_id;
    }
}
