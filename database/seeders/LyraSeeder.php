<?php

namespace Database\Seeders;

use App\Models\LyraConfig;
use App\Models\LyraVersion;
use App\Models\LyraSession;
use App\Models\LyraMessage;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LyraSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Versión Actual
        LyraVersion::create([
            'version'     => 'v2.1.0',
            'changelog'   => "• Implementación de persistencia de historial\n• Dashboard administrativo v1.0\n• Mejora en detección de zonas cardinales",
            'deployed_by' => 'Admin System',
            'is_current'  => true,
            'deployed_at' => now(),
            'metrics'     => ['success_rate' => 92, 'avg_response_ms' => 450]
        ]);

        // 2. Configs Default
        LyraConfig::set('maintenance_mode', false);
        LyraConfig::set('maintenance_message', 'Lyra está recibiendo una actualización de cerebro. Volverá en unos minutos.');
        LyraConfig::set('confidence_threshold', 0.6);
        LyraConfig::set('enabled_cities', ['Bogotá', 'Medellín', 'Cali', 'Barranquilla']);
        LyraConfig::set('enabled_intents', ['buscar_propiedad', 'ver_detalles', 'saludo', 'despedida', 'agendar_visita']);
        LyraConfig::set('cache_ttl', 3600);
        LyraConfig::set('debug_mode', false);

        // 3. Sesiones de Ejemplo
        $user = User::first();

        for ($i = 0; $i < 10; $i++) {
            $sessionId = (string)Str::uuid();
            $session = LyraSession::create([
                'session_id'  => $sessionId,
                'user_id'     => $i % 2 === 0 ? ($user->id ?? null) : null,
                'city'        => ['Bogotá', 'Medellín', 'Cali'][rand(0, 2)],
                'last_intent' => 'buscar_propiedad',
                'status'      => 'active',
                'created_at'  => now()->subDays(rand(0, 7))->subHours(rand(0, 23))
            ]);

            // Mensaje Usuario
            LyraMessage::create([
                'lyra_session_id' => $session->id,
                'role'            => 'user',
                'content'         => 'Busco un apartamento en arriendo en Bogotá con parqueadero',
                'intent'          => 'buscar_propiedad',
                'confidence'      => 0.89,
                'entities'        => ['city' => 'Bogotá', 'type' => 'apartamento', 'amenities' => ['parqueadero']],
                'created_at'      => $session->created_at
            ]);

            // Mensaje Asistente
            LyraMessage::create([
                'lyra_session_id'    => $session->id,
                'role'               => 'assistant',
                'content'            => 'He encontrado 5 apartamentos en Bogotá que coinciden con lo que buscas. ¿Te gustaría verlos?',
                'intent'             => 'buscar_propiedad',
                'thinking_process'   => ['step' => 'search', 'results_count' => 5],
                'processing_time_ms' => 1200,
                'created_at'         => $session->created_at->addSeconds(2)
            ]);
        }
    }
}
