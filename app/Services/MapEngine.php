<?php

namespace App\Services;

class MapEngine
{
    private const CITY_ZOOMS = [
        'Bogotá'        => 12, 'Medellín'      => 12, 'Cali'          => 12,
        'Barranquilla'  => 12, 'Cartagena'      => 12, 'Cúcuta'        => 12,
        'Bucaramanga'   => 12, 'Pereira'        => 12, 'Santa Marta'   => 12,
        'Ibagué'        => 12, 'Pasto'          => 12, 'Villavicencio' => 12,
        'Neiva'         => 12, 'Armenia'        => 12, 'Montería'      => 12,
        'Valledupar'    => 12, 'Manizales'      => 12, 'Popayán'       => 13,
    ];

    /**
     * Build optimal map center + zoom configuration based on results and context.
     */
    public function buildConfig(?array $center, int $propertyCount, ?string $city = null): ?array
    {
        if (!$center) return null;

        return [
            'lat'  => $center['lat'],
            'lng'  => $center['lng'],
            'zoom' => $this->calculateZoom($propertyCount, $city),
        ];
    }

    private function calculateZoom(int $count, ?string $city): int
    {
        $baseZoom = self::CITY_ZOOMS[$city] ?? 13;

        if ($count === 0)   return max($baseZoom - 1, 10);
        if ($count === 1)   return 16;
        if ($count <= 3)    return 15;
        if ($count <= 10)   return 14;
        if ($count <= 25)   return 13;

        return $baseZoom;
    }
}