<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // 10 usuarios en total
        User::factory()->count(10)->create();

        // Usuario fijo para pruebas
        User::factory()->create([
            "name" => "Administrador Rentus",
            "email" => "admin@rentus.com",
            "password" => bcrypt("password"),
            "status" => "active",
            "role" => "admin",
            "verification_status" => "verified"
        ]);
    }
}
