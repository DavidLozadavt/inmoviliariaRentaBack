<?php

namespace App\Services\Visor3d;

use App\Models\Building;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * Crea una Property por cada posición del layout que aún no tenga unidad.
 * No toca las existentes. Quedan ocultas (visibility=hidden) hasta que se publiquen.
 * Lo usan UnitController::generate y Visor3dDemoSeeder.
 */
class UnitGenerator
{
    /** @return array{created:int, total_slots:int} */
    public function generate(Building $building, array $prices = [], ?float $defaultPrice = null): array
    {
        $types    = $building->unitTypes()->get()->keyBy('code');
        $slots    = BuildingLayout::slots($building->config, $types->keys()->all());
        $existing = $building->units()->get(['tower', 'unit_code'])
            ->map(fn ($u) => "{$u->tower}|{$u->unit_code}")->flip();

        $created = 0;
        DB::transaction(function () use ($slots, $existing, $types, $building, $prices, $defaultPrice, &$created) {
            foreach ($slots as $slot) {
                if (isset($existing["{$slot['tower']}|{$slot['unit_code']}"])) {
                    continue;
                }
                $type = $slot['type_code'] ? $types->get($slot['type_code']) : null;

                Property::create([
                    'title'             => "{$building->name} · {$slot['tower']}-{$slot['unit_code']}",
                    'description'       => "Unidad {$slot['unit_code']} de la torre {$slot['tower']}, {$building->name}.",
                    'address'           => $building->address ?? '',
                    'city'              => $building->city ?? '',
                    'status'            => 'available',
                    'visibility'        => 'hidden',
                    'monthly_price'     => $prices[$slot['type_code']] ?? $defaultPrice ?? 0,
                    'area_m2'           => (int) round((float) ($type?->area_m2 ?? 0)),
                    'num_bedrooms'      => (string) ($type?->bedrooms ?? 0),
                    'num_bathrooms'     => (string) ($type?->bathrooms ?? 0),
                    'included_services' => [],
                    'publication_date'  => now()->toDateString(),
                    'image_url'         => [],
                    'user_id'           => $building->user_id,
                    'building_id'       => $building->id,
                    'unit_type_id'      => $type?->id,
                    'tower'             => $slot['tower'],
                    'floor'             => $slot['floor'],
                    'unit_code'         => $slot['unit_code'],
                ]);
                $created++;
            }
        });

        return ['created' => $created, 'total_slots' => count($slots)];
    }
}
