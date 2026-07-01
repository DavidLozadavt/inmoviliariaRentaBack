<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class FilterEngine
{
    /**
     * Fields that persist across conversation turns.
     */
    private const PERSIST_FIELDS = [
        'city', 'neighborhood', 'street', 'reference_place', 'zone',
        'property_type', 'max_price', 'min_price', 'bedrooms', 'bathrooms', 'amenities',
    ];

    /**
     * Fields that should be reset when the city changes (new search context).
     */
    private const CITY_DEPENDENT = ['neighborhood', 'street', 'reference_place', 'zone'];

    private const CACHE_TTL = 3600;

    /**
     * Merge new intent filters with accumulated session filters.
     * If city changes, clear city-dependent fields to avoid contamination.
     */
    public function merge(string $sessionId, array $newIntent): array
    {
        $current = $this->getCurrent($sessionId);
        $merged  = $newIntent;

        // Completely new search → don't inherit old context
        if (!empty($newIntent['is_new_search']) && $newIntent['is_new_search'] === true) {
            $current = [];
        }

        $newCity = $newIntent['city'] ?? null;
        $oldCity = $current['city']  ?? null;
        $cityChanged = ($newCity && $oldCity && mb_strtolower($newCity) !== mb_strtolower($oldCity));

        foreach (self::PERSIST_FIELDS as $field) {
            $newValue = $newIntent[$field] ?? null;
            $oldValue = $current[$field]    ?? null;

            if ($cityChanged && in_array($field, self::CITY_DEPENDENT)) {
                // If city changed, we DON'T persist neighborhood/zone/etc from old city
                // unless the new intent explicitly provides a new value for it.
                $merged[$field] = $newValue;
            } else {
                // Normal accumulation: if NEW is empty, take OLD
                if ($this->isEmpty($newValue) && !$this->isEmpty($oldValue)) {
                    $merged[$field] = $oldValue;
                } else {
                    $merged[$field] = $newValue;
                }
            }
        }

        if ($cityChanged) {
            // Also clear technical location fields that are not in PERSIST_FIELDS
            $merged['map_lat'] = null;
            $merged['map_lng'] = null;
            $merged['radius_km'] = 5; // Reset to default
        }

        Cache::put("lyra_filters_{$sessionId}", $merged, self::CACHE_TTL);

        return $merged;
    }

    public function getCurrent(string $sessionId): array
    {
        return Cache::get("lyra_filters_{$sessionId}", []);
    }

    public function clear(string $sessionId): void
    {
        Cache::forget("lyra_filters_{$sessionId}");
    }

    /**
     * Resetea solo las amenidades al cambiar de ciudad,
     * conservando tipo de propiedad y rango de precio.
     */
    public function resetAmenities(string $sessionId): void
    {
        $filters = Cache::get("lyra_filters_{$sessionId}", []);
        unset($filters['amenities']);
        Cache::put("lyra_filters_{$sessionId}", $filters, self::CACHE_TTL);
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === false) return true;
        if (is_array($value) && count($value) === 0) return true;
        return false;
    }
}