<?php

namespace App\Services;

use App\Models\Neighborhood;
use App\Models\ReferencePlace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GeoLocationEngine
{
    /**
     * City centers matching the frontend's CITY_CENTERS for consistent behavior.
     * 60+ Colombian cities with real coordinates.
     */
    private const CITY_CENTERS = [
        'Bogotá'          => ['lat' => 4.7110, 'lng' => -74.0721],
        'Medellín'        => ['lat' => 6.2442, 'lng' => -75.5812],
        'Cali'            => ['lat' => 3.4516, 'lng' => -76.5320],
        'Barranquilla'    => ['lat' => 10.9685, 'lng' => -74.7813],
        'Cartagena'       => ['lat' => 10.3910, 'lng' => -75.4794],
        'Cúcuta'          => ['lat' => 7.8899, 'lng' => -72.4966],
        'Bucaramanga'     => ['lat' => 7.1193, 'lng' => -73.1227],
        'Pereira'         => ['lat' => 4.8133, 'lng' => -75.6961],
        'Santa Marta'     => ['lat' => 11.2408, 'lng' => -74.2110],
        'Ibagué'          => ['lat' => 4.4389, 'lng' => -75.2322],
        'Pasto'           => ['lat' => 1.2136, 'lng' => -77.2811],
        'Villavicencio'   => ['lat' => 4.1420, 'lng' => -73.6266],
        'Neiva'           => ['lat' => 2.9273, 'lng' => -75.2819],
        'Armenia'         => ['lat' => 4.5339, 'lng' => -75.6811],
        'Montería'        => ['lat' => 8.7479, 'lng' => -75.8814],
        'Valledupar'      => ['lat' => 10.4631, 'lng' => -73.2532],
        'Manizales'       => ['lat' => 5.0689, 'lng' => -75.5174],
        'Popayán'         => ['lat' => 2.4448, 'lng' => -76.6147],
        'Sincelejo'       => ['lat' => 9.3047, 'lng' => -75.3978],
        'Tunja'           => ['lat' => 5.5353, 'lng' => -73.3678],
        'Florencia'       => ['lat' => 1.6144, 'lng' => -75.6062],
        'Riohacha'        => ['lat' => 11.5444, 'lng' => -72.9072],
        'Yopal'           => ['lat' => 5.3378, 'lng' => -72.3959],
        'Soacha'          => ['lat' => 4.5793, 'lng' => -74.2170],
        'Soledad'         => ['lat' => 10.9179, 'lng' => -74.7668],
        'Bello'           => ['lat' => 6.3386, 'lng' => -75.5575],
        'Envigado'        => ['lat' => 6.1752, 'lng' => -75.5905],
        'Itagüí'          => ['lat' => 6.1844, 'lng' => -75.5990],
        'Sabaneta'        => ['lat' => 6.1514, 'lng' => -75.6158],
        'Dosquebradas'    => ['lat' => 4.8388, 'lng' => -75.6644],
        'Palmira'         => ['lat' => 3.5394, 'lng' => -76.3035],
        'Buenaventura'    => ['lat' => 3.8801, 'lng' => -77.0311],
        'Floridablanca'   => ['lat' => 7.0641, 'lng' => -73.0885],
        'Girardot'        => ['lat' => 4.3033, 'lng' => -74.8028],
        'Tumaco'          => ['lat' => 1.8096, 'lng' => -78.7598],
        'Duitama'         => ['lat' => 5.8265, 'lng' => -73.0295],
        'Sogamoso'        => ['lat' => 5.7141, 'lng' => -72.9259],
        'Facatativá'      => ['lat' => 4.8141, 'lng' => -74.3568],
        'Zipaquirá'       => ['lat' => 5.0232, 'lng' => -74.0068],
        'Fusagasugá'      => ['lat' => 4.3426, 'lng' => -74.3641],
        'Chía'            => ['lat' => 4.8618, 'lng' => -74.0608],
        'Pitalito'        => ['lat' => 1.8520, 'lng' => -76.0510],
        'Rionegro'        => ['lat' => 6.1548, 'lng' => -75.3737],
        'Apartadó'        => ['lat' => 7.8825, 'lng' => -76.6270],
        'Leticia'         => ['lat' => -4.2153, 'lng' => -69.9406],
        'San Andrés'      => ['lat' => 12.5547, 'lng' => -81.7185],
        'Girón'           => ['lat' => 7.0742, 'lng' => -73.1683],
        'Mosquera'        => ['lat' => 4.7069, 'lng' => -74.2312],
        'La Dorada'       => ['lat' => 5.4514, 'lng' => -74.6680],
        'Aguazul'         => ['lat' => 5.1735, 'lng' => -72.5508],
        'Magangué'        => ['lat' => 9.2416, 'lng' => -74.7542],
        'Puerto Colombia' => ['lat' => 10.9956, 'lng' => -74.9552],
        'Tulúa'           => ['lat' => 4.0840, 'lng' => -76.1978],
    ];

    /**
     * Resolve latitude and longitude from the intent.
     *
     * Resolution priority:
     * 1. AI-provided map_lat / map_lng (Gemini's geographic knowledge)
     * 2. Database: reference_places table
     * 3. Database: neighborhoods table
     * 4. Nominatim geocoding (for streets / specific addresses)
     * 5. Hardcoded city centers (60+ Colombian cities)
     * 6. Default to Popayán
     */
    public function resolve(array $intent): ?array
    {
        // 1. AI-provided coordinates (highest precision — Gemini knows Colombian geography)
        if (!empty($intent['map_lat']) && !empty($intent['map_lng'])) {
            $lat = (float) $intent['map_lat'];
            $lng = (float) $intent['map_lng'];

            // Sanity check: must be within Colombia's bounding box
            if ($lat >= -4.3 && $lat <= 13.5 && $lng >= -82 && $lng <= -66.5) {
                return ['lat' => $lat, 'lng' => $lng];
            }
        }

        $city = $intent['city'] ?? null;

        try {
            // 2. Reference place lookup
            if (!empty($intent['reference_place'])) {
                $place = ReferencePlace::query()
                    ->when($city, fn ($q) => $q->where('city', 'LIKE', '%' . $city . '%'))
                    ->where('name', 'LIKE', '%' . $intent['reference_place'] . '%')
                    ->first();

                if ($place && $place->lat && $place->lng) {
                    return ['lat' => (float) $place->lat, 'lng' => (float) $place->lng];
                }
            }

            // 3. Neighborhood lookup
            if (!empty($intent['neighborhood'])) {
                $nb = Neighborhood::query()
                    ->when($city, fn ($q) => $q->where('city', 'LIKE', '%' . $city . '%'))
                    ->where('name', 'LIKE', '%' . $intent['neighborhood'] . '%')
                    ->first();

                if ($nb && $nb->lat && $nb->lng) {
                    return ['lat' => (float) $nb->lat, 'lng' => (float) $nb->lng];
                }
            }
        } catch (\Exception $e) {
            Log::warning('[GeoLocationEngine] DB lookup exception', ['error' => $e->getMessage()]);
        }

        // 4. Nominatim geocoding for streets or specific addresses (cached)
        if (!empty($intent['street']) || !empty($intent['reference_place'])) {
            $geocoded = $this->geocodeViaNominatim($intent);
            if ($geocoded) {
                return $geocoded;
            }
        }

        // 5. City center lookup (60+ cities)
        if ($city) {
            $center = $this->findCityCenter($city);
            if ($center) {
                return $center;
            }
        }

        // 6. Default fallback
        return self::CITY_CENTERS['Popayán'];
    }

    /**
     * Find city center using case-insensitive, accent-insensitive matching.
     */
    public function findCityCenter(string $city): ?array
    {
        // Matching extra por alias comunes
        $aliases = [
            'manizalez'    => 'Manizales',
            'manizales'    => 'Manizales',
            'mani'         => 'Manizales',
            'popayan'      => 'Popayán',
            'bogota'       => 'Bogotá',
            'medellin'     => 'Medellín',
            'cali'         => 'Cali',
            'barranquilla' => 'Barranquilla',
            'cartagena'    => 'Cartagena',
            'bucaramanga'  => 'Bucaramanga',
            'pereira'      => 'Pereira',
            'santa marta'  => 'Santa Marta',
            'cucuta'       => 'Cúcuta',
            'ibague'       => 'Ibagué',
            'pasto'        => 'Pasto',
            'neiva'        => 'Neiva',
            'armenia'      => 'Armenia',
            'villavicencio'=> 'Villavicencio',
            'monteria'     => 'Montería',
            'valledupar'   => 'Valledupar',
        ];

        $normalizedInput = $this->normalize($city);
        if (isset($aliases[$normalizedInput])) {
            return self::CITY_CENTERS[$aliases[$normalizedInput]] ?? null;
        }

        // Direct match
        if (isset(self::CITY_CENTERS[$city])) {
            return self::CITY_CENTERS[$city];
        }

        // Normalized match (case + accents)
        $normalized = $this->normalize($city);
        foreach (self::CITY_CENTERS as $name => $coords) {
            if ($this->normalize($name) === $normalized) {
                return $coords;
            }
        }

        // Partial match
        foreach (self::CITY_CENTERS as $name => $coords) {
            if (str_contains($this->normalize($name), $normalized) ||
                str_contains($normalized, $this->normalize($name))) {
                return $coords;
            }
        }

        return null;
    }

    /**
     * Geocode an address via Nominatim (OpenStreetMap) with caching.
     */
    private function geocodeViaNominatim(array $intent): ?array
    {
        $parts = array_filter([
            $intent['street'] ?? null,
            $intent['reference_place'] ?? null,
            $intent['neighborhood'] ?? null,
            $intent['city'] ?? null,
            'Colombia',
        ]);

        $query = implode(', ', $parts);
        $cacheKey = 'lyra_geo_' . md5(mb_strtolower($query));

        return Cache::remember($cacheKey, 86400, function () use ($query) {
            try {
                $response = Http::timeout(5)
                    ->withHeaders([
                        'User-Agent' => 'RentUsApp/2.0 (contact@rentus.co)',
                    ])
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'format'       => 'json',
                        'q'            => $query,
                        'limit'        => 1,
                        'countrycodes' => 'co',
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    if (!empty($data[0]['lat']) && !empty($data[0]['lon'])) {
                        return [
                            'lat' => (float) $data[0]['lat'],
                            'lng' => (float) $data[0]['lon'],
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('[GeoLocationEngine] Nominatim error', ['error' => $e->getMessage()]);
            }

            return null;
        });
    }

    /**
     * Normalize string for comparison (lowercase, remove accents).
     */
    private function normalize(string $str): string
    {
        if (class_exists('\Normalizer')) {
            // Normalización Unicode NFD para separar base+diacrítico
            $normalized = \Normalizer::normalize($str, \Normalizer::FORM_D);
            // Eliminar marcas diacríticas (caracteres en rango combining)
            $normalized = preg_replace('/[\x{0300}-\x{036f}]/u', '', $normalized);
            $normalized = mb_strtolower($normalized);
            return trim($normalized);
        }

        $normalized = mb_strtolower($str);
        // Fallback remove common diacritics
        $normalized = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ', 'Ü'],
            ['a', 'e', 'i', 'o', 'u', 'n', 'u', 'a', 'e', 'i', 'o', 'u', 'n', 'u'],
            $normalized
        );
        return trim($normalized);
    }

    /**
     * Find nearest cities with available inventory
     */
    public function getCiudadesCercanas(string $ciudad): array
    {
        $center = $this->findCityCenter($ciudad);
        if (!$center) {
            return [];
        }

        $lat = $center['lat'];
        $lng = $center['lng'];

        $results = \App\Models\Property::where('status', 'available')
            ->where('approval_status', 'approved')
            ->where('visibility', 'published')
            ->whereNotNull('city')
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->selectRaw("city, COUNT(*) as total,
              (6371 * acos(cos(radians(?)) * cos(radians(lat)) * 
               cos(radians(lng) - radians(?)) + 
               sin(radians(?)) * sin(radians(lat)))) AS distancia", [$lat, $lng, $lat])
            ->groupBy('city')
            ->havingRaw('total > 0')
            ->havingRaw('distancia > 5') // Ignore the exact city
            ->orderBy('distancia', 'ASC')
            ->limit(3)
            ->get();

        return $results->toArray();
    }
}
