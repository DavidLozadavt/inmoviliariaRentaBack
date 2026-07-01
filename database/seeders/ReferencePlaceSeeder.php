<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReferencePlaceSeeder extends Seeder
{
    public function run(): void
    {
        $places = [
            // ── Popayán – Lugares de referencia con coordenadas reales ───────
            ['name' => 'Centro Comercial Campanario', 'type' => 'mall',       'city' => 'Popayán', 'lat' => 2.4485, 'lng' => -76.5963],
            ['name' => 'Parque Caldas',               'type' => 'park',       'city' => 'Popayán', 'lat' => 2.4421, 'lng' => -76.6065],
            ['name' => 'Universidad del Cauca',       'type' => 'university', 'city' => 'Popayán', 'lat' => 2.4393, 'lng' => -76.6085],
            ['name' => 'Terminal de Transportes',     'type' => 'terminal',   'city' => 'Popayán', 'lat' => 2.4586, 'lng' => -76.6100],
            ['name' => 'Hospital Universitario San José', 'type' => 'hospital', 'city' => 'Popayán', 'lat' => 2.4439, 'lng' => -76.6031],
            ['name' => 'Cementerio Central',          'type' => 'cemetery',   'city' => 'Popayán', 'lat' => 2.4385, 'lng' => -76.6120],
            ['name' => 'Iglesia Santo Domingo',       'type' => 'church',     'city' => 'Popayán', 'lat' => 2.4415, 'lng' => -76.6074],
            ['name' => 'Iglesia San Francisco',       'type' => 'church',     'city' => 'Popayán', 'lat' => 2.4432, 'lng' => -76.6058],
            ['name' => 'Puente del Humilladero',      'type' => 'landmark',   'city' => 'Popayán', 'lat' => 2.4408, 'lng' => -76.6095],
            ['name' => 'Estadio Ciro López',          'type' => 'stadium',    'city' => 'Popayán', 'lat' => 2.4362, 'lng' => -76.6030],
            ['name' => 'Universidad Autónoma del Cauca', 'type' => 'university', 'city' => 'Popayán', 'lat' => 2.4530, 'lng' => -76.5985],
            ['name' => 'Centro Comercial Terra Plaza', 'type' => 'mall',      'city' => 'Popayán', 'lat' => 2.4460, 'lng' => -76.5940],
            ['name' => 'Complejo Deportivo Las Américas', 'type' => 'stadium', 'city' => 'Popayán', 'lat' => 2.4370, 'lng' => -76.6010],
            ['name' => 'Parque Mosquera',             'type' => 'park',       'city' => 'Popayán', 'lat' => 2.4405, 'lng' => -76.6050],
            ['name' => 'Cerro de las Tres Cruces',    'type' => 'landmark',   'city' => 'Popayán', 'lat' => 2.4470, 'lng' => -76.6140],
        ];

        $now = now();
        $records = array_map(fn($p) => array_merge($p, [
            'created_at' => $now,
            'updated_at' => $now,
        ]), $places);

        DB::table('reference_places')->insert($records);
    }
}
