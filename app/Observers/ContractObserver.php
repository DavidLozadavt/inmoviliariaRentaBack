<?php

namespace App\Observers;

use App\Models\Contract;
use App\Services\ActivityService;

class ContractObserver
{
    public function created(Contract $contract): void
    {
        $tenant = $contract->tenant;
        ActivityService::logContractCreated($contract, $tenant);
    }
}
