<?php

namespace App\Providers;

use App\Models\Contract;
use App\Models\Notification;
use App\Models\Property;
use App\Models\RentalRequest;
use App\Policies\ContractPolicy;
use App\Policies\NotificationPolicy;
use App\Policies\PropertyPolicy;
use App\Policies\RentalRequestPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Property::class      => PropertyPolicy::class,
        Contract::class      => ContractPolicy::class,
        RentalRequest::class => RentalRequestPolicy::class,
        Notification::class  => NotificationPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
