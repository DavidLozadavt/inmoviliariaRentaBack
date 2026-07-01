<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\User;

class PropertyFactory extends Factory
{
    public function definition()
    {
        $cities = [
            "Bogotá",
            "Medellín",
            "Cali",
            "Barranquilla",
            "Bucaramanga",
            "Cartagena",
            "Manizales",
            "Pereira",
            "Santa Marta"
        ];

        $city = fake()->randomElement($cities);

        $coords = [
            "Bogotá" => ["lat" => 4.7110, "lng" => -74.0721],
            "Medellín" => ["lat" => 6.2442, "lng" => -75.5812],
            "Cali" => ["lat" => 3.4516, "lng" => -76.5320],
            "Barranquilla" => ["lat" => 10.9878, "lng" => -74.7889],
            "Bucaramanga" => ["lat" => 7.1193, "lng" => -73.1227],
            "Cartagena" => ["lat" => 10.3910, "lng" => -75.4794],
            "Manizales" => ["lat" => 5.0703, "lng" => -75.5138],
            "Pereira" => ["lat" => 4.8133, "lng" => -75.6961],
            "Santa Marta" => ["lat" => 11.2408, "lng" => -74.1990],
        ];

        $base = $coords[$city];

        // Variación realista de 20–250 metros (6 decimales)
        $lat = $base["lat"] + fake()->randomFloat(6, -0.0025, 0.0025);
        $lng = $base["lng"] + fake()->randomFloat(6, -0.0025, 0.0025);

        return [
            "title"             => fake()->randomElement([
                "Apartamento moderno en zona exclusiva",
                "Casa amplia y familiar",
                "Estudio amueblado cerca al centro",
                "Apartamento con vista panorámica",
                "Casa campestre con jardín",
                "Loft minimalista",
                "Apartamento cerca de universidades",
                "Casa con excelente iluminación natural"
            ]),
            "description"       => fake()->randomElement([
                "Propiedad en excelente estado, ideal para familias o parejas jóvenes.",
                "Ubicación estratégica cerca de transporte público y centros comerciales.",
                "Espacios modernos con acabados de primera calidad.",
                "Seguridad 24 horas y zonas verdes comunitarias."
            ]),
            "address"           => "Calle " . rand(1, 150) . " #" . rand(1, 120) . "-" . rand(1, 80),
            "city"              => $city,
            "status"            => "available",
            "approval_status"   => "approved",
            "visibility"        => "published",
            "monthly_price"     => fake()->numberBetween(
                $city === "Bogotá" || $city === "Medellín" ? 1200000 : 900000,
                $city === "Cartagena" ? 4500000 : 3200000
            ),
            "area_m2"           => rand(40, 180),
            "num_bedrooms"      => rand(1, 4),
            "num_bathrooms"     => rand(1, 3),
            "included_services" => fake()->randomElement([
                "Agua, Luz, Internet",
                "Agua, Gas",
                "Agua, Luz",
                "Agua, Luz, Gas, Administración incluida"
            ]),
            "publication_date"  => now()->subDays(rand(1, 60))->toDateString(),
            "image_url"         => [fake()->randomElement([
                "https://images.unsplash.com/photo-1600585154340-be6161a56a0c",
                "https://images.unsplash.com/photo-1580587771525-78b9dba3b914",
                "https://images.unsplash.com/photo-1560185127-6ed84b937f65",
                "https://images.unsplash.com/photo-1605276374104-dee2a0ed3cd3",
                "https://images.unsplash.com/photo-1507089947368-19c1da9775ae"
            ]) . "?auto=format&w=800"], // Casted as array in model

            "lat"               => $lat,
            "lng"               => $lng,

            "accuracy"          => fake()->randomFloat(2, 3.00, 18.00),
            "views"             => fake()->numberBetween(10, 300),

            "user_id"           => User::inRandomOrder()->first()?->id ?? User::factory()->create()->id,
        ];
    }
}
