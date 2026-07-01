<?php

namespace App\Http\Controllers;

use App\Services\LyraBrainService;
use App\Services\LyraContextService;
use App\Services\LyraVoicePersonality;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class LyraController extends Controller
{
    private LyraBrainService $brain;
    private LyraContextService $contextService;
    private LyraVoicePersonality $personality;

    public function __construct(
        LyraBrainService $brain,
        LyraContextService $contextService,
        LyraVoicePersonality $personality
    ) {
        $this->brain          = $brain;
        $this->contextService = $contextService;
        $this->personality    = $personality;
    }

    /**
     * POST /api/lyra/interpret
     */
    public function interpret(Request $request): JsonResponse
    {
        // ── NUEVO: verificar modo mantenimiento ──
        try {
            if (\App\Models\LyraConfig::getValue('maintenance_mode', false)) {
                $message = \App\Models\LyraConfig::getValue(
                    'maintenance_message',
                    'Lyra está en mantenimiento en este momento. Vuelve pronto.'
                );
                return response()->json([
                    'success'  => false,
                    'maintenance' => true,
                    'message'  => $message,
                ], 503);
            }
        } catch (\Exception $persistEx) {
            // MODIFIED: log completo para debug
            Log::warning('[Lyra] Persistance failed but continuing', [
                'error' => $persistEx->getMessage(),
                'trace' => $persistEx->getTraceAsString(), // AGREGAR ESTA LÍNEA
            ]);
        }

        $request->validate([
            'text'       => 'required|string|max:500',
            'session_id' => 'required|string|max:100',
            'user_lat'   => 'nullable|numeric',
            'user_lng'   => 'nullable|numeric',
            'pending_suggestion' => 'nullable|boolean',
            'suggested_city'     => 'nullable|string',
            'chip_action'        => 'nullable|string',
            'context'            => 'nullable|array',
        ]);

        try {
            $text      = trim($request->input('text'));
            $sessionId = $request->input('session_id');
            $userLat   = $request->input('user_lat');
            $userLng   = $request->input('user_lng');
            $pendingSugg = $request->input('pending_suggestion', false);
            $suggCity    = $request->input('suggested_city');
            $chipAction  = $request->input('chip_action');

            Log::info('[Lyra] interpret', [
                'session_id' => $sessionId,
                'text'       => $text,
                'pending_sugg' => $pendingSugg,
                'sugg_city'    => $suggCity,
                'chip_action'  => $chipAction,
                'context'      => $request->input('context')
            ]);

            $contextParams = $request->input('context', []);
            $result = $this->brain->process($text, $sessionId, $userLat, $userLng, (bool)$pendingSugg, $suggCity, $chipAction, $contextParams);
            $result = $this->personality->humanize($result, $text);

            // MODIFIED: agregado persistencia de historial para panel admin
            try {
                $service = \app(\App\Services\LyraSessionService::class);

                $userMessage = $service->persistMessage([
                    'session_id'         => $sessionId,
                    'user_id'            => \auth()->id(),
                    'role'               => 'user',
                    'content'            => $text,
                    'intent'             => $result['intent']['intent'] ?? null,
                    'confidence'         => $result['intent']['confidence'] ?? null,
                    'entities'           => $result['entities'] ?? [],
                    'processing_time_ms' => $result['processing_time_ms'] ?? 0,
                ]);

                $assistantMessage = $service->persistMessage([
                    'session_id'         => $sessionId,
                    'user_id'            => \auth()->id(),
                    'role'               => 'assistant',
                    'content'            => $result['response_text'] ?? '',
                    'intent'             => $result['intent']['intent'] ?? null,
                    'thinking_process'   => $result['thinking_process'] ?? $result, // Fallback to full result for detail
                ]);

                // Broadcasters
                $session = \App\Models\LyraSession::where('session_id', $sessionId)->first();
                $messageCount = $session->messages()->count();
                $isNewSession = $messageCount <= 2; // user + assistant = primeros 2 mensajes

                broadcast(new \App\Events\LyraMessageNew($sessionId, [
                    'id'                => $userMessage->id,
                    'role'              => 'user',
                    'content'           => $userMessage->content,
                    'intent'            => $result['intent']['intent'] ?? null,
                    'confidence'        => $result['intent']['confidence'] ?? null,
                    'entities'          => $result['entities'] ?? [],
                    'thinking_process'  => null,
                    'processing_time_ms' => null,
                    'created_at'        => $userMessage->created_at->toISOString(),
                ]));

                broadcast(new \App\Events\LyraMessageNew($sessionId, [
                    'id'                => $assistantMessage->id,
                    'role'              => 'assistant',
                    'content'           => $assistantMessage->content,
                    'intent'            => $assistantMessage->intent,
                    'confidence'        => $assistantMessage->confidence,
                    'entities'          => $assistantMessage->entities ?? [],
                    'thinking_process'  => $assistantMessage->thinking_process,
                    'processing_time_ms' => $assistantMessage->processing_time_ms,
                    'created_at'        => $assistantMessage->created_at->toISOString(),
                ]));

                if ($isNewSession) {
                    $session->load('user');
                    broadcast(new \App\Events\LyraNewSession([
                        'id'           => $session->session_id,
                        'last_intent'  => $result['intent']['intent'] ?? 'desconocido',
                        'city'         => $result['intent']['city'] ?? null,
                        'status'       => 'active',
                        'user_id'      => $session->user_id,
                        'user'         => $session->user ? [
                            'id'       => $session->user->id,
                            'name'     => $session->user->name,
                            'email'    => $session->user->email,
                            'initials' => strtoupper(substr($session->user->name, 0, 1)),
                        ] : null,
                        'messages_count' => 1,
                        'updated_at'   => $session->updated_at->toISOString(),
                        'created_at'   => $session->created_at->toISOString(),
                        'metadata'     => $session->metadata ?? [],
                    ]));
                }

                broadcast(new \App\Events\LyraSessionUpdated(
                    $session->session_id,
                    $session->status,
                    $session->last_intent,
                    $session->city
                ));
            } catch (\Exception $persistEx) {
                Log::warning('[Lyra] Persistance failed but continuing', ['error' => $persistEx->getMessage()]);
            }

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('[Lyra] Fatal error in interpret', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success'                => true,
                'response_text'          => 'Cuéntame qué tipo de propiedad buscas y en qué ciudad, y te ayudo a encontrarla.',
                'properties'             => [],
                'map_center'             => null,
                'filters_applied'        => [],
                'needs_clarification'    => true,
                'clarification_question' => '¿En qué ciudad buscas y qué tipo de propiedad necesitas?',
                'intent'                 => ['intent' => 'saludo'],
                'agent_name'             => 'Lyra',
            ]);
        }
    }

    /**
     * GET /api/lyra/context
     */
    public function context(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->contextService->getContext(),
            ]);
        } catch (\Exception $e) {
            Log::error('[Lyra] Context error', ['message' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Error loading Lyra context.',
            ], 500);
        }
    }
    /**
     * GET /api/lyra/status
     */
    public function status(): JsonResponse
    {
        try {
            $maintenance = \App\Models\LyraConfig::getValue('maintenance_mode', false);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Lyra] Could not check maintenance mode, defaulting to false', ['error' => $e->getMessage()]);
            $maintenance = false;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'status'  => $maintenance ? 'maintenance' : 'online'
            ]
        ]);
    }

    /**
     * POST /api/lyra/execute
     */
    public function execute(Request $request): JsonResponse
    {
        $request->validate([
            'action'     => 'required|string',
            'session_id' => 'required|string',
            'payload'    => 'required|array',
        ]);

        try {
            $action    = $request->input('action');
            $sessionId = $request->input('session_id');
            $payload   = $request->input('payload');

            $result = $this->brain->executeDirectAction($action, $payload, $sessionId);
            $result = $this->personality->humanize($result, "EJECUCIÓN DIRECTA: $action");

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('[Lyra] Error in execute', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['success' => false, 'message' => 'Error ejecutando acción directa.'], 500);
        }
    }
}
