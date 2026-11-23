<?php

namespace App\Services\Visor3d;

/**
 * Posiciones de unidades a partir de la configuración paramétrica.
 * Misma regla que src/utils/visor3d/layout.ts del front: si cambia una, cambia la otra.
 *
 * - Pisos residenciales: 1..floors, menos los marcados como especiales para esa torre.
 * - Código de unidad: piso * 100 + posición (101, 102, ... 1204).
 * - Tipo: layout[posición - 1]; si no hay layout, el primer tipo declarado.
 */
class BuildingLayout
{
    public const SPECIAL_KINDS = ['lobby', 'parking', 'terrace', 'amenities'];

    /** @return array<int, array{tower:string, floor:int, position:int, unit_code:string, type_code:?string}> */
    public static function slots(array $config, array $typeCodes = []): array
    {
        $slots = [];

        foreach ($config['towers'] ?? [] as $tower) {
            $code     = (string) $tower['code'];
            $special  = self::specialFloors($config, $code);
            $perFloor = (int) $tower['units_per_floor'];
            $layout   = $tower['layout'] ?? [];

            for ($floor = 1; $floor <= (int) $tower['floors']; $floor++) {
                if (in_array($floor, $special, true)) {
                    continue;
                }
                for ($pos = 1; $pos <= $perFloor; $pos++) {
                    $slots[] = [
                        'tower'     => $code,
                        'floor'     => $floor,
                        'position'  => $pos,
                        'unit_code' => (string) ($floor * 100 + $pos),
                        'type_code' => $layout[$pos - 1] ?? ($typeCodes[0] ?? null),
                    ];
                }
            }
        }

        return $slots;
    }

    /** @return int[] */
    public static function specialFloors(array $config, string $towerCode): array
    {
        return collect($config['special_floors'] ?? [])
            ->filter(fn ($s) => empty($s['tower']) || $s['tower'] === $towerCode)
            ->map(fn ($s) => (int) $s['floor'])
            ->values()
            ->all();
    }
}
