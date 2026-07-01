<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;

class PropertyPolicy
{
    private const ADMIN_ROLES = ['admin', 'support'];

    /**
     * Admin/support can do anything.
     */
    public function before(User $user, string $ability): ?bool
    {
        if (in_array($user->role, self::ADMIN_ROLES, true)) {
            return true;
        }
        return null;
    }

    public function update(User $user, Property $property): bool
    {
        return $user->id === $property->user_id;
    }

    public function delete(User $user, Property $property): bool
    {
        return $user->id === $property->user_id;
    }

    public function savePoint(User $user, Property $property): bool
    {
        return $user->id === $property->user_id;
    }
}
