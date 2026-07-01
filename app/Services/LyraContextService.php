<?php

namespace App\Services;

use App\Models\Property;
use App\Models\Neighborhood;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LyraContextService
{
    /**
     * Get real-time context from RentUs database to train Lyra.
     * Cached for 1 hour to avoid hammering the DB on every request.
     */
    public function getContext(): array
    {
        return Cache::remember('lyra_context', 3600, function () {
            return [
                'property_types'    => $this->getPropertyTypes(),
                'cities'            => $this->getActiveCities(),
                'neighborhoods'     => $this->getNeighborhoodsByCity(),
                'price_range'       => $this->getPriceRange(),
                'available_filters' => $this->getAvailableFilters(),
                'amenities'         => $this->getCommonAmenities(),
                'total_properties'  => $this->getTotalActiveProperties(),
            ];
        });
    }

    /**
     * RentUs property types.
     * The properties table doesn't have a 'type' column — types are embedded in titles.
     */
    private function getPropertyTypes(): array
    {
        return ['casa', 'apartamento', 'lote', 'terreno', 'local', 'oficina', 'estudio', 'finca', 'bodega'];
    }

    /**
     * Cities with active (approved + published) properties.
     */
    private function getActiveCities(): array
    {
        return Property::where('approval_status', 'approved')
            ->where('visibility', 'published')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->pluck('city')
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Neighborhoods grouped by city from the neighborhoods table.
     */
    private function getNeighborhoodsByCity(): array
    {
        try {
            return Neighborhood::select('city', 'name')
                ->orderBy('city')
                ->orderBy('name')
                ->get()
                ->groupBy('city')
                ->map(fn ($items) => $items->pluck('name')->toArray())
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Price range from approved properties.
     */
    private function getPriceRange(): array
    {
        $stats = Property::where('approval_status', 'approved')
            ->where('visibility', 'published')
            ->selectRaw('MIN(monthly_price) as min_price, MAX(monthly_price) as max_price')
            ->first();

        return [
            'min'      => (float) ($stats->min_price ?? 0),
            'max'      => (float) ($stats->max_price ?? 0),
            'currency' => 'COP',
        ];
    }

    /**
     * Available filter categories.
     */
    private function getAvailableFilters(): array
    {
        return [
            'geográficos' => ['departamento', 'ciudad', 'barrio', 'calle', 'lugar de referencia'],
            'fisicos'     => ['habitaciones', 'baños', 'área m2'],
            'economicos'  => ['precio mínimo', 'precio máximo'],
            'tipo'        => $this->getPropertyTypes(),
            'amenidades'  => $this->getCommonAmenities(),
        ];
    }

    /**
     * Extract real amenities from the included_services JSON column across all properties.
     * Falls back to known amenities if extraction yields nothing.
     */
    private function getCommonAmenities(): array
    {
        try {
            $allServices = Property::where('approval_status', 'approved')
                ->whereNotNull('included_services')
                ->where('included_services', '!=', '[]')
                ->where('included_services', '!=', 'null')
                ->pluck('included_services')
                ->filter();

            $amenitySet = [];
            foreach ($allServices as $services) {
                $decoded = is_array($services) ? $services : json_decode($services, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $item) {
                        $normalized = mb_strtolower(trim($item));
                        if ($normalized !== '') {
                            $amenitySet[$normalized] = ($amenitySet[$normalized] ?? 0) + 1;
                        }
                    }
                }
            }

            if (!empty($amenitySet)) {
                // Sort by frequency (most common first), return unique names
                arsort($amenitySet);
                return array_keys($amenitySet);
            }
        } catch (\Exception $e) {
            // Silently fall through to default
        }

        // Fallback
        return [
            'amueblado', 'piscina', 'gimnasio', 'parqueadero',
            'ascensor', 'balcón', 'baño privado', 'vigilancia',
        ];
    }

    /**
     * Total count of active, approved, published properties.
     */
    private function getTotalActiveProperties(): int
    {
        return Property::where('approval_status', 'approved')
            ->where('visibility', 'published')
            ->where('status', 'available')
            ->count();
    }
}
