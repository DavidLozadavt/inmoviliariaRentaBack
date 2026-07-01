<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        // Listas realistas
        $names = [
            "Juan Pérez", "María Rodríguez", "Carlos Gómez", "Ana Torres",
            "Luis Martínez", "Sofía Ramírez", "Andrés Herrera", "Laura Fernández",
            "Jorge Castro", "Valentina Sánchez", "Mateo Morales", "Camila Vargas"
        ];

        $departments = [
            "Antioquia", "Cundinamarca", "Valle del Cauca", "Atlántico",
            "Santander", "Bolívar", "Risaralda", "Caldas"
        ];

        $citiesByDepartment = [
            "Antioquia" => ["Medellín", "Envigado", "Itagüí", "Bello"],
            "Cundinamarca" => ["Bogotá", "Soacha", "Chía", "Zipaquirá"],
            "Valle del Cauca" => ["Cali", "Palmira", "Buenaventura"],
            "Atlántico" => ["Barranquilla", "Soledad", "Malambo"],
            "Santander" => ["Bucaramanga", "Floridablanca", "Girón"],
            "Bolívar" => ["Cartagena", "Magangué"],
            "Risaralda" => ["Pereira", "Dosquebradas"],
            "Caldas" => ["Manizales", "Villamaría"]
        ];

        $statuses = ["active", "inactive", "pending_verification"];

        $name = $names[array_rand($names)];
        $department = $departments[array_rand($departments)];
        $city = $citiesByDepartment[$department][array_rand($citiesByDepartment[$department])];

        return [
            "name" => $name,
            "phone" => "+57" . rand(3000000000, 3999999999),
            "email" => strtolower(str_replace(" ", ".", $name)) . rand(1, 200) . "@gmail.com",

            // Contraseña estándar de Laravel
            "password" => Hash::make("12345678"),

            "address" => "Calle " . rand(1, 120) . " #" . rand(1, 90) . "-" . rand(1, 60) . ", " . $city,
            "id_documento" => strval(rand(1000000000, 1999999999)),
            "status" => $statuses[array_rand($statuses)],

            "email_verified_at" => now(),

            "photo" => null, // se puede reemplazar por una imagen base64 si lo deseas
            "bio" => "Persona responsable, con buenos antecedentes de arriendo.",
            "department" => $department,
            "city" => $city,

            "remember_token" => Str::random(10),
        ];
    }
}
