<?php

namespace App\Observers;

use App\Models\User;
use App\Services\ActivityService;

class UserObserver
{
    public function created(User $user): void
    {
        ActivityService::logUserCreatedByAdmin($user);
    }
}
