<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NeighborhoodSeeder extends Seeder
{
    public function run(): void
    {
        $neighborhoods = [
            // ── Popayán – Barrios con coordenadas reales ───────────────────────
            ['name' => 'Centro Histórico',      'city' => 'Popayán', 'lat' => 2.4419, 'lng' => -76.6067],
            ['name' => 'La Esmeralda',           'city' => 'Popayán', 'lat' => 2.4475, 'lng' => -76.6020],
            ['name' => 'El Libertador',          'city' => 'Popayán', 'lat' => 2.4530, 'lng' => -76.6110],
            ['name' => 'Bolívar',                'city' => 'Popayán', 'lat' => 2.4390, 'lng' => -76.6095],
            ['name' => 'Los Comuneros',          'city' => 'Popayán', 'lat' => 2.4350, 'lng' => -76.6140],
            ['name' => 'La Colina',              'city' => 'Popayán', 'lat' => 2.4480, 'lng' => -76.5980],
            ['name' => 'La Paz',                 'city' => 'Popayán', 'lat' => 2.4500, 'lng' => -76.5950],
            ['name' => 'Pubenza',                'city' => 'Popayán', 'lat' => 2.4370, 'lng' => -76.6130],
            ['name' => 'Las Palmas',             'city' => 'Popayán', 'lat' => 2.4510, 'lng' => -76.5970],
            ['name' => 'El Lago',                'city' => 'Popayán', 'lat' => 2.4550, 'lng' => -76.6050],
            ['name' => 'Yanaconas',              'city' => 'Popayán', 'lat' => 2.4310, 'lng' => -76.6160],
            ['name' => 'Camilo Torres',          'city' => 'Popayán', 'lat' => 2.4290, 'lng' => -76.6200],
            ['name' => 'El Uvo',                 'city' => 'Popayán', 'lat' => 2.4250, 'lng' => -76.6190],
            ['name' => 'La Estancia',            'city' => 'Popayán', 'lat' => 2.4560, 'lng' => -76.5930],
            ['name' => 'Pomona',                 'city' => 'Popayán', 'lat' => 2.4430, 'lng' => -76.5990],
            ['name' => 'Alfonso López',          'city' => 'Popayán', 'lat' => 2.4340, 'lng' => -76.6050],
            ['name' => 'Lomas de Pomona',        'city' => 'Popayán', 'lat' => 2.4450, 'lng' => -76.5960],
            ['name' => 'El Recuerdo',            'city' => 'Popayán', 'lat' => 2.4400, 'lng' => -76.5940],
            ['name' => 'Los Sauces',             'city' => 'Popayán', 'lat' => 2.4520, 'lng' => -76.5910],
            ['name' => 'La Floresta',            'city' => 'Popayán', 'lat' => 2.4460, 'lng' => -76.5950],
            ['name' => 'Bello Horizonte',        'city' => 'Popayán', 'lat' => 2.4540, 'lng' => -76.5920],
            ['name' => 'El Placer',              'city' => 'Popayán', 'lat' => 2.4380, 'lng' => -76.6170],
            ['name' => 'Santa Rosa',             'city' => 'Popayán', 'lat' => 2.4350, 'lng' => -76.6080],
            ['name' => 'El Carmen',              'city' => 'Popayán', 'lat' => 2.4410, 'lng' => -76.6110],
            ['name' => 'Pandiguando',            'city' => 'Popayán', 'lat' => 2.4470, 'lng' => -76.6150],
            ['name' => 'Vereda de Torres',       'city' => 'Popayán', 'lat' => 2.4300, 'lng' => -76.6220],
            ['name' => 'Las Américas',           'city' => 'Popayán', 'lat' => 2.4360, 'lng' => -76.6020],
            ['name' => 'El Empedrado',           'city' => 'Popayán', 'lat' => 2.4420, 'lng' => -76.6035],
            ['name' => 'Modelo',                 'city' => 'Popayán', 'lat' => 2.4440, 'lng' => -76.6045],
            ['name' => 'Tejares',                'city' => 'Popayán', 'lat' => 2.4490, 'lng' => -76.6130],
        ];

        $now = now();
        $records = array_map(function ($n) use ($now) {
            return array_merge($n, [
                'slug'       => Str::slug($n['name']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, $neighborhoods);

        DB::table('neighborhoods')->insert($records);
    }
}
