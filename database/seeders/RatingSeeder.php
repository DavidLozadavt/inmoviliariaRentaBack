<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Contract;
use App\Models\Rating;

class RatingSeeder extends Seeder
{
    public function run(): void
    {
        $contracts = Contract::all();

        foreach ($contracts as $contract) {

            // Landlord califica al Tenant
            if (! Rating::where('contract_id', $contract->id)->where('user_id', $contract->tenant_id)->exists()) {
                \App\Models\Rating::factory()->create([
                    'contract_id' => $contract->id,
                    'user_id' => $contract->tenant_id,
                    'recipient_role' => 'tenant',
                ]);
            }

            // Tenant califica al Landlord
            if (! Rating::where('contract_id', $contract->id)->where('user_id', $contract->landlord_id)->exists()) {
                \App\Models\Rating::factory()->create([
                    'contract_id' => $contract->id,
                    'user_id' => $contract->landlord_id,
                    'recipient_role' => 'landlord',
                ]);
            }
        }

        $this->command->info("Ratings generados sin duplicados.");
    }
}
