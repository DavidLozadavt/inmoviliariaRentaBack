<?php

namespace App\Policies;

use App\Models\Building;
use App\Models\User;

class BuildingPolicy
{
    private const ADMIN_ROLES = ['admin', 'support'];

    public function before(User $user, string $ability): ?bool
    {
        return in_array($user->role, self::ADMIN_ROLES, true) ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->role === 'landlord';
    }

    public function view(User $user, Building $building): bool
    {
        return $user->id === $building->user_id;
    }

    public function create(User $user): bool
    {
        return $user->role === 'landlord';
    }

    public function update(User $user, Building $building): bool
    {
        return $user->id === $building->user_id;
    }

    public function delete(User $user, Building $building): bool
    {
        return $user->id === $building->user_id;
    }
}
