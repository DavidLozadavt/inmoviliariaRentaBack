<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Contract;
use App\Models\Payment;

class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $contracts = Contract::doesntHave('payments')->get();

        if ($contracts->count() === 0) {
            $this->command->error("No existen contratos elegibles para pagos.");
            return;
        }

        foreach ($contracts as $contract) {
            Payment::factory()->create([
                'contract_id' => $contract->id,
            ]);
        }

        $this->command->info("Pagos generados correctamente para contratos existentes.");
    }
}
