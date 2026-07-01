<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Contract;
use App\Models\Property;
use App\Models\User;

class ContractSeeder extends Seeder
{
    public function run(): void
    {
        // Validaciones mínimas
        if (User::count() < 2) {
            $this->command->error("Se requieren mínimo 2 usuarios para generar contratos.");
            return;
        }

        if (Property::count() === 0) {
            $this->command->error("No se detectaron propiedades. Ejecuta primero PropertySeeder.");
            return;
        }

        // Generar 1–2 contratos por propiedad en promedio
        Property::all()->each(function ($property) {
            // Si solo hay 1 usuario no se asigna contrato
            if (User::count() < 2) {
                return;
            }

            Contract::factory(rand(1, 2))->create([
                'property_id' => $property->id,
                'landlord_id' => $property->user_id,
            ]);
        });

        $this->command->info("Contratos generados correctamente.");
    }
}
