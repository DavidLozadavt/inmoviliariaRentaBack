<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Property;
use App\Models\User;
use App\Models\RentalRequest;

class RentalRequestSeeder extends Seeder
{
    public function run()
    {
        $properties = Property::with('user')->get(); // user = dueño

        foreach ($properties as $property) {

            // Escoger un usuario real que no sea el dueño para ser el solicitante
            $tenant = User::where('id', '!=', $property->user_id)->inRandomOrder()->first();

            if (!$tenant) {
                continue; // por seguridad si no hay otro usuario registrado
            }

            RentalRequest::factory()->create([
                'property_id' => $property->id,
                'owner_id' => $property->user_id,
                'user_id' => $tenant->id,
                'status' => 'pending',
            ]);
        }
    }
}
