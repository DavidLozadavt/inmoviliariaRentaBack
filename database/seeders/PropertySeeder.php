<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Property;
use App\Models\User;
use Carbon\Carbon;

class PropertySeeder extends Seeder
{
    public function run(): void
    {
        // Limpiar propiedades previas para asegurar conteo exacto
        Property::query()->forceDelete();

        $user = User::first() ?? User::factory()->create([
            'name' => 'Agente NexiLiving',
            'email' => 'agente@rentus.com',
            'password' => bcrypt('password'),
        ]);

        $cities = [
            'Medellín'     => ['lat' => 6.2442, 'lng' => -75.5812],
            'Bogotá'       => ['lat' => 4.7110, 'lng' => -74.0721],
            'Cali'         => ['lat' => 3.4516, 'lng' => -76.5320],
            'Barranquilla' => ['lat' => 10.9639, 'lng' => -74.7964],
            'Cartagena'    => ['lat' => 10.3910, 'lng' => -75.4794],
            'Manizales'    => ['lat' => 5.0689, 'lng' => -75.5174],
        ];

        $images = [
            "https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?auto=format&fit=crop&w=800&q=80",
            "https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=800&q=80",
            "https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=800&q=80",
            "https://images.unsplash.com/photo-1580587771525-78b9dba3b914?auto=format&fit=crop&w=800&q=80",
            "https://images.unsplash.com/photo-1568605114967-8130f3a36994?auto=format&fit=crop&w=800&q=80",
            "https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=800&q=80",
            "https://images.unsplash.com/photo-1493809842364-78817add7ffb?auto=format&fit=crop&w=800&q=80",
            "https://images.unsplash.com/photo-1600047509807-ba8f99d2cdde?auto=format&fit=crop&w=800&q=80",
            "https://images.unsplash.com/photo-1554995207-c18c203602cb?auto=format&fit=crop&w=800&q=80",
            "https://images.unsplash.com/photo-1598928506311-c55ded91a20c?auto=format&fit=crop&w=800&q=80",
        ];

        $zones = [
            'Centro' => ['lat_off' => 0, 'lng_off' => 0],
            'Norte'  => ['lat_off' => 0.04, 'lng_off' => 0.02],
            'Sur'    => ['lat_off' => -0.04, 'lng_off' => -0.01],
            'Este'   => ['lat_off' => 0.01, 'lng_off' => 0.05],
            'Oeste'  => ['lat_off' => -0.01, 'lng_off' => -0.05],
        ];

        $types = [
            ['type' => 'apartamento', 'bedrooms' => [1, 2], 'area' => [50, 130], 'price' => [1200000, 2800000]],
            ['type' => 'apartamento', 'bedrooms' => [1, 2], 'area' => [50, 130], 'price' => [1200000, 2800000]],
            ['type' => 'casa', 'bedrooms' => [2, 4], 'area' => [80, 200], 'price' => [1500000, 4000000]],
            ['type' => 'casa', 'bedrooms' => [2, 4], 'area' => [80, 200], 'price' => [1500000, 4000000]],
            ['type' => 'estudio', 'bedrooms' => [0, 0], 'area' => [30, 80], 'price' => [800000, 1500000]],
        ];

        $service_rotations = [
            "Agua, Luz",
            "Agua, Gas",
            "Agua, Luz, Internet",
            "Agua, Luz, Gas, Administración incluida"
        ];

        $image_index = 0;

        foreach ($cities as $cityName => $center) {
            $zone_keys = array_keys($zones);

            for ($i = 0; $i < 5; $i++) {
                $config = $types[$i];
                $zoneName = $zone_keys[$i];
                $zoneOff = $zones[$zoneName];

                $lat = $center['lat'] + $zoneOff['lat_off'] + (rand(-500, 500) / 100000);
                $lng = $center['lng'] + $zoneOff['lng_off'] + (rand(-500, 500) / 100000);

                $price = rand($config['price'][0], $config['price'][1]) + (rand(10, 99) / 100);

                // Rotar imágenes (máximo 30 total, 10 imágenes disponibles -> 3 repeticiones cada una)
                $current_image = $images[$image_index % count($images)];
                $image_index++;

                Property::create([
                    'title'             => ucfirst($config['type']) . " en " . $cityName . " - Zona " . $zoneName,
                    'description'       => "Excelente " . $config['type'] . " ubicado en el sector de " . $zoneName . ". Cuenta con acabados modernos, buena iluminación y fácil acceso.",
                    'address'           => "Carrera " . rand(1, 100) . " #" . rand(1, 100) . "-" . rand(1, 100),
                    'city'              => $cityName,
                    'status'            => 'available',
                    'approval_status'   => 'approved',
                    'visibility'        => 'published',
                    'monthly_price'     => $price,
                    'area_m2'           => rand($config['area'][0], $config['area'][1]),
                    'num_bedrooms'      => rand($config['bedrooms'][0], $config['bedrooms'][1]),
                    'num_bathrooms'     => rand(1, 3),
                    'included_services' => [$service_rotations[rand(0, 3)]],
                    'views'             => rand(10, 300),
                    'publication_date'  => Carbon::create(2026, rand(1, 3), rand(1, 28))->toDateString(),
                    'image_url'         => [$current_image],
                    'lat'               => $lat,
                    'lng'               => $lng,
                    'accuracy'          => rand(3, 15),
                    'user_id'           => $user->id,
                ]);
            }
        }

        // php artisan migrate:fresh --seed
    }
}
