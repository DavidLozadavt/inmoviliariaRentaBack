<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

class ContractPolicy
{
    private const ADMIN_ROLES = ['admin', 'support'];

    public function before(User $user, string $ability): ?bool
    {
        if (in_array($user->role, self::ADMIN_ROLES, true)) {
            return true;
        }
        return null;
    }

    public function view(User $user, Contract $contract): bool
    {
        return $user->id === $contract->tenant_id || $user->id === $contract->landlord_id;
    }

    public function accept(User $user, Contract $contract): bool
    {
        return $user->id === $contract->tenant_id;
    }

    public function reject(User $user, Contract $contract): bool
    {
        return $user->id === $contract->tenant_id;
    }
}
