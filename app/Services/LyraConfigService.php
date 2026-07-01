<?php

namespace App\Services;

use App\Models\LyraConfig;
use Illuminate\Support\Facades\Log;

class LyraConfigService
{
    public function getAll(): array
    {
        try {
            // MODIFIED: Seed defaults if empty
            if (LyraConfig::count() === 0) {
                $defaults = [
                    'maintenance_message'  => 'Lo siento, Lyra está en mantenimiento.',
                    'confidence_threshold' => 0.6,
                    'enabled_cities'       => ['Bogotá', 'Medellín', 'Cali'],
                    'enabled_intents'      => ['buscar_propiedad', 'consulta_analitica', 'saludo'],
                    'cache_ttl'            => 3600,
                    'debug_mode'           => false,
                ];
                foreach ($defaults as $k => $v) {
                    LyraConfig::set($k, $v);
                }
            }

            return [
                'maintenance_message'  => LyraConfig::get('maintenance_message'),
                'confidence_threshold' => (float)LyraConfig::get('confidence_threshold', 0.6),
                'enabled_cities'       => (array)LyraConfig::get('enabled_cities', []),
                'enabled_intents'      => (array)LyraConfig::get('enabled_intents', []),
                'cache_ttl'            => (int)LyraConfig::get('cache_ttl', 3600),
                'debug_mode'           => (bool)LyraConfig::get('debug_mode', false),
            ];
        } catch (\Throwable $e) {
            Log::warning('[LyraConfig] Table or database missing, returning hardcoded defaults', ['error' => $e->getMessage()]);
            return [
                'maintenance_message'  => 'Lo siento, Lyra está en mantenimiento.',
                'confidence_threshold' => 0.6,
                'enabled_cities'       => ['Bogotá', 'Medellín', 'Cali'],
                'enabled_intents'      => ['buscar_propiedad', 'consulta_analitica', 'saludo'],
                'cache_ttl'            => 3600,
                'debug_mode'           => false,
            ];
        }
    }

    public function update(array $data, int $userId): bool
    {
        foreach ($data as $key => $value) {
            $oldValue = LyraConfig::get($key);
            if ($oldValue !== $value) {
                LyraConfig::set($key, $value);
                Log::info('[LyraConfig] Key changed', [
                    'key'       => $key,
                    'old_value' => $oldValue,
                    'new_value' => $value,
                    'user_id'   => $userId,
                    'timestamp' => now()
                ]);
            }
        }
        return true;
    }
}
