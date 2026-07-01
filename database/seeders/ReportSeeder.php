<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Report;

class ReportSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@rentus.com')->first();

        if (!$admin) {
            $this->command->error('No se encontró el usuario admin@rentus.com');
            return;
        }

        $reportTypes = [
            ['type' => 'payments',   'applied_filter' => 'monthly',    'description' => 'Reporte de pagos mensuales'],
            ['type' => 'contracts',  'applied_filter' => 'active',     'description' => 'Reporte de contratos activos'],
            ['type' => 'users',      'applied_filter' => 'all',        'description' => 'Reporte general de usuarios'],
            ['type' => 'properties', 'applied_filter' => 'available',  'description' => 'Reporte de propiedades disponibles'],
            ['type' => 'incidents',  'applied_filter' => 'general',    'description' => 'Reporte general de incidencias'],
        ];

        foreach ($reportTypes as $data) {
            Report::create([
                'user_id'         => $admin->id,
                'type'            => $data['type'],
                'applied_filter'  => $data['applied_filter'],
                'description'     => $data['description'],
                'generation_date' => now()->toDateString(),
            ]);
        }
    }
}