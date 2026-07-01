<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Maintenance;
use App\Models\Property;
use App\Models\User;

class MaintenanceSeeder extends Seeder
{
    public function run(): void
    {
        if (Property::count() === 0) {
            $this->command->error("No se detectaron propiedades. Ejecuta primero PropertySeeder.");
            return;
        }

        if (User::count() === 0) {
            $this->command->error("No hay usuarios para generar mantenimientos.");
            return;
        }

        $statuses = ['pending', 'in_progress', 'finished'];

        Property::all()->each(function ($property) use ($statuses) {
            // Seleccionar entre 1 y 3 usuarios distintos por propiedad
            $users = User::inRandomOrder()->take(rand(1, 3))->get();

            foreach ($users as $user) {
                // Un mantenimiento por cada status disponible — respeta la restricción única
                foreach ($statuses as $status) {
                    Maintenance::factory()->create([
                        'property_id' => $property->id,
                        'user_id'    => $user->id,
                        'status'     => $status,
                    ]);
                }
            }
        });

        $this->command->info("Mantenimientos generados correctamente.");
    }
}
