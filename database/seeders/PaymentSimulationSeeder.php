<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Property;
use App\Models\Contract;

class PaymentSimulationSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@rentus.com')->first();

        if (!$admin) {
            $admin = User::create([
                "name" => "Administrador Rentus",
                "email" => "admin@rentus.com",
                "password" => bcrypt("password"),
                "status" => "active",
                "role" => "admin",
                "verification_status" => "verified"
            ]);
        }

        // We need a landlord for the contract
        $landlord = User::where('email', '!=', 'admin@rentus.com')->first();
        if (!$landlord) {
            $landlord = User::create([
                "name" => "Propietario de Prueba",
                "email" => "owner@example.com",
                "password" => bcrypt("password"),
                "status" => "active",
                "role" => "landlord",
            ]);
        }

        // We need a property
        $property = Property::first();
        if (!$property) {
            $property = Property::create([
                'title' => 'Apartamento de Lujo en Chapinero',
                'description' => 'Un hermoso apartamento para pruebas de pago.',
                'address' => 'Carrera 7 # 45-12',
                'city' => 'Bogotá',
                'monthly_price' => 2500000,
                'user_id' => $landlord->id,
                'status' => 'available',
                'type' => 'apartment',
                'bedrooms' => 2,
                'bathrooms' => 2,
                'area' => 85,
            ]);
        }

        // Create a pending contract for the admin (as tenant)
        Contract::updateOrCreate(
            [
                'tenant_id' => $admin->id,
                'status' => 'pending'
            ],
            [
                'property_id' => $property->id,
                'landlord_id' => $landlord->id,
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'deposit' => $property->monthly_price,
                'accepted_by_tenant' => 'no',
                'validated_by_support' => 'no',
            ]
        );
    }
}
