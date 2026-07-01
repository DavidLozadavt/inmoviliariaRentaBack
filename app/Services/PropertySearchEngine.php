<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Database\Eloquent\Collection;

class PropertySearchEngine
{
    /**
     * Search properties using intent filters with optional geospatial distance filtering.
     */
    public function search(array $intent, ?array $center, float $radiusKm = 5): array
    {
        $query = $this->buildBaseQuery($intent);

        // ── Amenities filter (with fallback) ─────────────────────────
        if (!empty($intent['amenities']) && is_array($intent['amenities'])) {
            $queryWithAmenities = clone $query;
            foreach ($intent['amenities'] as $amenity) {
                $queryWithAmenities->where(function ($q) use ($amenity) {
                    $q->where('included_services', 'LIKE', '%' . $amenity . '%')
                        ->orWhere('description', 'LIKE', '%' . $amenity . '%')
                        ->orWhere('title', 'LIKE', '%' . $amenity . '%');
                });
            }
            if ($queryWithAmenities->count() > 0) {
                $query = $queryWithAmenities;
            }
        }

        // ── Fetch with relations ─────────────────────
        $properties = $query->with(['user:id,name,email,phone,photo', 'images'])
            ->limit(100)
            ->get();

        // ── Apply geo distance filter if center ──
        if ($center) {
            $filtered = $this->filterAndSortByDistance($properties, $center, $radiusKm);

            // Auto-extend radius if zero results  
            if (empty($filtered) && $radiusKm < 10) {
                $filtered = $this->filterAndSortByDistance($properties, $center, 10);
            }

            // Retry without reference_place if empty
            if (empty($filtered) && !empty($intent['reference_place'])) {
                $retryIntent = $intent;
                $retryIntent['reference_place'] = null;
                $retryQuery = $this->buildBaseQuery($retryIntent);
                $retryProps = $retryQuery->with(['user:id,name,email,phone,photo', 'images'])->limit(100)->get();
                $filtered = $this->filterAndSortByDistance($retryProps, $center, 10);
            }

            // Retry without property_type if empty (BUG #4 FIX)
            if (empty($filtered) && !empty($intent['property_type'])) {
                $retryIntent = $intent;
                $retryIntent['property_type'] = null;
                $retryQuery = $this->buildBaseQuery($retryIntent);
                $retryProps = $retryQuery->with(['user:id,name,email,phone,photo', 'images'])->limit(100)->get();
                $filtered = $this->filterAndSortByDistance($retryProps, $center, $radiusKm);

                if (!empty($filtered)) {
                    // Marcar que se quitó el filtro de tipo
                    $filtered = array_map(function ($p) {
                        $p['_type_filter_relaxed'] = true;
                        return $p;
                    }, $filtered);
                }
            }

            // Si el filtro geo eliminó todo → fallback: devuelve sin coordenadas
            if (empty($filtered)) {
                \Illuminate\Support\Facades\Log::info('[PropertySearchEngine] Filtro geo retornó 0, usando fallback sin coordenadas');
                
                // Si la búsqueda es netamente GPS (sin ciudad anclada), retornar 0.
                // Si retornásemos $properties aquí, agarraría 20 propiedades al azar de toda Colombia.
                if (empty($intent['city'])) {
                    return [];
                }

                return $properties
                    ->sortBy('monthly_price')
                    ->take(20)
                    ->values()
                    ->toArray();
            }

            return $filtered;
        }

        // ── No geo center: sort by price ─────────────
        return $properties
            ->sortBy('monthly_price')
            ->take(20)
            ->values()
            ->toArray();
    }

    private function buildBaseQuery(array $intent): \Illuminate\Database\Eloquent\Builder
    {
        $query = Property::where('approval_status', 'approved')
            ->where('visibility', 'published')
            ->where('status', 'available');

        if (!empty($intent['city'])) {
            $cityNormalized = $this->normalizeCity($intent['city']);
            $query->whereRaw("LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(city, 
                'á','a'),'é','e'),'í','i'),'ó','o'),'ú','u'),'ñ','n')) 
                LIKE ?", ['%' . $cityNormalized . '%']);
        }
        if (!empty($intent['neighborhood'])) {
            $query->where('address', 'LIKE', '%' . $intent['neighborhood'] . '%');
        }
        if (!empty($intent['street'])) {
            $query->where('address', 'LIKE', '%' . $intent['street'] . '%');
        }
        if (!empty($intent['max_price'])) {
            $query->where('monthly_price', '<=', (float) $intent['max_price']);
        }
        if (!empty($intent['min_price'])) {
            $query->where('monthly_price', '>=', (float) $intent['min_price']);
        }
        if (!empty($intent['bedrooms'])) {
            $query->where('num_bedrooms', '>=', (int) $intent['bedrooms']);
        }
        if (!empty($intent['bathrooms'])) {
            $query->where('num_bathrooms', '>=', (int) $intent['bathrooms']);
        }

        // Tipo: agrupado correctamente
        if (!empty($intent['property_type'])) {
            $term = $this->mapPropertyType($intent['property_type']);
            if ($term) {
                $query->where(function ($q) use ($term) {
                    $q->where('title', 'LIKE', '%' . $term . '%')
                        ->orWhere('description', 'LIKE', '%' . $term . '%');
                });
            }
        }

        // FIX FINAL: Filtrar por zona cardinal si viene en el intent
        if (!empty($intent['zone'])) {
            $zona = strtolower(trim($intent['zone']));

            // Mapeo de zona cardinal → términos de búsqueda en DB
            $zonaMappings = [
                'norte'     => ['norte', 'north', 'noroccidental', 'nororiental'],
                'sur'       => ['sur', 'south', 'suroccidental', 'suroriental'],
                'centro'    => ['centro', 'central', 'center'],
                'oriente'   => ['oriente', 'oriental', 'este'],
                'occidente' => ['occidente', 'occidental', 'oeste'],
            ];

            $terminos = $zonaMappings[$zona] ?? [$zona];

            $query->where(function ($q) use ($terminos) {
                foreach ($terminos as $termino) {
                    $q->orWhere('address',      'LIKE', "%{$termino}%")
                        ->orWhere('description',  'LIKE', "%{$termino}%");
                }
            });
        }

        return $query;
    }

    /**
     * Map property type terms to DB-compatible search terms.
     */
    private function mapPropertyType(string $type): string
    {
        $map = [
            'house' => 'casa',
            'apartment' => 'apartamento',
            'land' => 'lote',
            'commercial' => 'local',
            'studio' => 'estudio',
            'office' => 'oficina',
            'terrain' => 'terreno',
            'apto' => 'apartamento',
            'depto' => 'apartamento',
            'finca' => 'finca',
            'bodega' => 'bodega',
            'penthouse' => 'apartamento',
        ];

        $normalized = mb_strtolower(trim($type));
        return $map[$normalized] ?? $type;
    }

    private function normalizeCity(string $city): string
    {
        return mb_strtolower(str_replace(
            ['á','é','í','ó','ú','ñ','Á','É','Í','Ó','Ú','Ñ'],
            ['a','e','i','o','u','n','a','e','i','o','u','n'],
            $city
        ));
    }

    /**
     * Filter properties by Haversine distance from center, sort by proximity.
     */
    private function filterAndSortByDistance(Collection $properties, array $center, float $radiusKm): array
    {
        $centerLat = (float) $center['lat'];
        $centerLng = (float) $center['lng'];

        return $properties->map(function ($p) use ($centerLat, $centerLng) {
            $lat = (float) $p->lat;
            $lng = (float) $p->lng;
            if ($lat == 0 || $lng == 0) return null;

            $dist = $this->haversineDistance($centerLat, $centerLng, $lat, $lng);
            $data = $p->toArray();
            $data['distance_km'] = round($dist, 2);
            return $data;
        })
            ->filter()
            ->filter(fn($p) => $p['distance_km'] <= $radiusKm)
            ->sortBy('distance_km')
            ->values()
            ->take(20)
            ->toArray();
    }

    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
