<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * VoiceService — Entrada de voz de Lyra.
 *
 * Pipeline:
 *   texto usuario → LyraBrainService (lógica + búsqueda) → LyraVoicePersonality (humanizar) → respuesta
 */
class VoiceService
{
    private LyraBrainService     $brain;
    private LyraVoicePersonality $personality;

    public function __construct(
        LyraBrainService     $brain,
        LyraVoicePersonality $personality
    ) {
        $this->brain       = $brain;
        $this->personality = $personality;
    }

    /**
     * Interpreta el texto de voz del usuario y retorna propiedades + respuesta humana.
     */
    public function interpret(
        string $text,
        array  $history   = [],
        string $sessionId = 'voice_default',
        ?float $userLat   = null,
        ?float $userLng   = null
    ): array {
        Log::info('[VoiceService] Entrada de voz recibida', [
            'text'      => $text,
            'sessionId' => $sessionId,
        ]);

        // 1. Procesar con el cerebro de Lyra
        $result = $this->brain->process($text, $sessionId, $userLat, $userLng);

        // 2. Humanizar la respuesta con la personalidad de Lyra
        $result = $this->personality->humanize($result, $text);

        // 3. Adaptar al contrato del frontend de voz
        return $this->formatForVoice($result);
    }

    /**
     * Adapta la respuesta al formato esperado por el frontend de voz.
     */
    private function formatForVoice(array $result): array
    {
        $properties = $result['properties'] ?? [];
        $mapConfig  = $result['map_center'] ?? null;
        $count      = count($properties);

        $zoom = match (true) {
            $count > 10 => 13,
            $count > 3  => 14,
            default     => 15,
        };

        $mapCenter = null;
        if ($mapConfig) {
            $mapCenter = [
                'lat'  => $mapConfig['lat']  ?? $mapConfig['center']['lat'] ?? null,
                'lng'  => $mapConfig['lng']  ?? $mapConfig['center']['lng'] ?? null,
                'zoom' => $mapConfig['zoom'] ?? $zoom,
            ];
        }

        return [
            'success'                => $result['success']                ?? true,
            'intent'                 => $result['intent']                 ?? [],
            'properties'             => $properties,
            'map_center'             => $mapCenter,
            'response_text'          => $result['response_text']          ?? 'No pude procesar tu solicitud, inténtalo de nuevo.',
            'response_speech'        => $result['response_speech']        ?? $result['response_text'] ?? '',
            'filters_applied'        => $result['filters_applied']        ?? [],
            'needs_clarification'    => $result['needs_clarification']    ?? false,
            'clarification_question' => $result['clarification_question'] ?? null,
            'zone'                   => $result['zone']                   ?? ($result['intent']['zone'] ?? null),
        ];
    }
}