<?php

namespace App\Observers;

use App\Models\Property;
use App\Services\ActivityService;

class PropertyObserver
{
    public function created(Property $property): void
    {
        ActivityService::logPropertyCreated($property, $property->user);
    }
}
