<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use App\Services\LocalInterpreterEngine;
use App\Services\PropertySearchEngine;
use App\Services\GeoLocationEngine;
use App\Services\FilterEngine;
use App\Services\MapEngine;
use App\Services\LyraContextService;
use App\Services\LyraVoicePersonality;

class LyraBrainService
{
    private LocalInterpreterEngine  $localInterpreter;
    private PropertySearchEngine    $searchEngine;
    private GeoLocationEngine       $locationEngine;
    private FilterEngine            $filterEngine;
    private MapEngine               $mapEngine;
    private LyraContextService      $contextService;
    private LyraVoicePersonality    $personality;
    private ?float $currentUserLat = null;
    private ?float $currentUserLng = null;

    public function __construct(
        LocalInterpreterEngine  $localInterpreter,
        PropertySearchEngine    $searchEngine,
        GeoLocationEngine       $locationEngine,
        FilterEngine            $filterEngine,
        MapEngine               $mapEngine,
        LyraContextService      $contextService,
        LyraVoicePersonality    $personality
    ) {
        $this->localInterpreter = $localInterpreter;
        $this->searchEngine     = $searchEngine;
        $this->locationEngine   = $locationEngine;
        $this->filterEngine     = $filterEngine;
        $this->mapEngine        = $mapEngine;
        $this->contextService   = $contextService;
        $this->personality      = $personality;
    }

    // ═══════════════════════════════════════════════════════════════
    //  ENTRADA PRINCIPAL
    // ═══════════════════════════════════════════════════════════════

    /**
     * Main Lyra brain.
     * Flow: interpret → accumulate filters → resolve ambiguity → search DB → humanize → response
     */
    public function process(string $text, string $sessionId, ?float $userLat = null, ?float $userLng = null, bool $pendingSuggestion = false, ?string $suggestedCity = null, ?string $chipAction = null, array $contextParams = []): array
    {
        $this->currentUserLat = $userLat;
        $this->currentUserLng = $userLng;
        $textLower   = mb_strtolower(trim($text));
        $historyKey  = "lyra_history_{$sessionId}";
        $context     = $this->prepareContext($sessionId);
        $history     = $context['historial'];
        $turnCounterKey = "lyra_turns_{$sessionId}";
        $turnCount   = Cache::get($turnCounterKey, 0) + 1;
        Cache::put($turnCounterKey, $turnCount, 3600);

        // Sincroniza nivel de confianza con la personalidad
        $this->personality->setTurnCount($turnCount);

        // ── LOG DIAGNÓSTICO (temporal) ────────────────────────────────────
        Log::info('[LyraBrain] Estado de cache al entrar', [
            'sessionId'     => $sessionId,
            'text'          => substr($text, 0, 60),
            'awaitingVisit' => Cache::get("lyra_awaiting_visit_datetime_{$sessionId}") ? 'SÍ' : 'NO',
            'postSelection' => Cache::get("lyra_post_selection_{$sessionId}") ? 'SÍ' : 'NO',
            'selectedProp'  => Cache::get("lyra_selected_property_{$sessionId}") ? 'SÍ' : 'NO',
            'chipAction'    => $chipAction ?? 'NINGUNA'
        ]);

        // ══════════════════════════════════════════════════════════════
        // BLOQUE 0-C: Chip Action Bypass (Direct Intent)
        // ══════════════════════════════════════════════════════════════
        if ($chipAction) {
            Log::info('[LyraBrain] Chip Action Detectada', ['action' => $chipAction]);
            
            if ($chipAction === 'buscar_ciudad_cercana') {
                if ($pendingSuggestion || Cache::has("lyra_pending_action_{$sessionId}")) {
                    $textLower = 'sí'; 
                } else {
                    $ctxCoords = $contextParams['coordinates'] ?? null;
                    $ctxLastCity = $contextParams['last_city'] ?? null;
                    
                    if ($ctxCoords) {
                        $userLat = $ctxCoords['lat'] ?? $userLat;
                        $userLng = $ctxCoords['lng'] ?? $userLng;
                    }
                    
                    $intent = ['intent' => 'buscar_ciudad_cercana'];
                    if ($ctxLastCity) {
                        $intent['city'] = $ctxLastCity;
                    }

                    return $this->handleAmpliZona($intent, $sessionId, $text, $historyKey, $history, $turnCount, $userLat, $userLng);
                }
            } elseif ($chipAction === 'reiniciar' || $chipAction === 'cambiar_ciudad') {
                Cache::forget("lyra_filters_{$sessionId}");
                Cache::forget("lyra_last_city_{$sessionId}");
                Cache::forget("lyra_results_{$sessionId}");
                Cache::forget("lyra_pending_action_{$sessionId}");
                Cache::forget("lyra_post_selection_{$sessionId}");
                
                $msg = $this->personality->onRestart();
                $this->storeHistory($historyKey, $history, $text, $msg);
                return $this->buildResponse($msg, [], null, [], [], false, null, $turnCount, ['voice_ready' => true]);
            } elseif ($chipAction === 'ver_todas') {
                // Si existe un intent previo, lo limpiamos de filtros restrictivos para mostrar todo
                $filters = Cache::get("lyra_filters_{$sessionId}", []);
                unset($filters['property_type'], $filters['min_price'], $filters['max_price'], $filters['bedrooms']);
                Cache::put("lyra_filters_{$sessionId}", $filters, 3600);
            } elseif ($chipAction === 'quitar_filtros') {
                $this->filterEngine->clear($sessionId);
                $msg = $this->personality->onFiltersCleared();
                $this->storeHistory($historyKey, $history, $text, $msg);
                return $this->buildResponse($msg, [], null, [
                    'city' => null, 'neighborhood' => null, 'property_type' => null,
                    'min_price' => null, 'max_price' => null, 'bedrooms' => null,
                    'bathrooms' => null, 'zone' => null,
                ], ['intent' => 'quitar_filtros']);
            }
        }

        // ══════════════════════════════════════════════════════════════
        // BLOQUE 0-A: ¿Esperando fecha/hora para visita?
        // ══════════════════════════════════════════════════════════════
        $awaitingVisit = Cache::get("lyra_awaiting_visit_datetime_{$sessionId}");
        if ($awaitingVisit) {
            $fecha = $this->extractDateFromText($textLower) ?: ($awaitingVisit['fecha'] ?? null);
            $hora  = $this->extractTimeFromText($textLower) ?: ($awaitingVisit['hora'] ?? null);
            $prop  = $awaitingVisit['property'] ?? Cache::get("lyra_selected_property_{$sessionId}");
            $retries = $awaitingVisit['retries'] ?? 0;

            Log::info('[LyraBrain] Bloque 0-A activo', [
                'fecha'   => $fecha ?: 'NINGUNA',
                'hora'    => $hora ?: 'NINGUNA',
                'prop'    => $prop['title'] ?? 'SIN PROP',
                'retries' => $retries,
            ]);

            // ── Escape hatch: si el usuario quiere cancelar o cambiar de tema ──
            $cancelPatterns = ['no', 'cancelar', 'cancela', 'no quiero', 'olvidalo', 'olvídalo', 'otra cosa', 'buscar', 'otra propiedad', 'cambiar'];
            $wantsToCancel = false;
            foreach ($cancelPatterns as $cp) {
                if (str_contains($textLower, $cp) && !str_contains($textLower, 'agendar') && !str_contains($textLower, 'cita')) {
                    $wantsToCancel = true;
                    break;
                }
            }

            if ($wantsToCancel || $retries >= 3) {
                // Liberar el estado y dejar que el flujo normal maneje el mensaje
                Cache::forget("lyra_awaiting_visit_datetime_{$sessionId}");
                Log::info('[LyraBrain] Bloque 0-A: escape hatch activado', ['reason' => $wantsToCancel ? 'cancel' : 'max_retries']);
                // No retornar — dejar que el código continúe al intérprete
            } elseif ($fecha && $hora) {
                // ── CHECK AUTH: necesita estar logueado para crear la cita ────
                if (!Auth::guard('api')->check()) {
                    // Guardar datos de la visita pendiente para retomar después del login
                    Cache::put("lyra_pending_visit_{$sessionId}", [
                        'property' => $prop,
                        'fecha'    => $fecha,
                        'hora'     => $hora,
                    ], 3600);

                    $msg = $this->personality->onAuthRequired($prop ?? []);
                    $this->storeHistory($historyKey, $history, $text, $msg);
                    return $this->buildResponse($msg, $prop ? [$prop] : [], null, [], [], false, null, $turnCount, [
                        'voice_ready'    => true,
                        'requires_auth'  => true,
                        'voice_action'   => 'auth_resume',
                        'voice_action_payload' => [
                            'fecha'    => $fecha,
                            'hora'     => $hora,
                            'property' => $prop
                        ]
                    ]);
                }

                // Tenemos la info + usuario autenticado — enviar solicitud
                $title = $prop['title'] ?? 'esa propiedad';
                $msg   = $this->personality->onVisitRequestSent($title, $fecha ?? '', $hora ?? '');
                $this->createVisitRequest($sessionId, $prop, $fecha ?? '', $hora ?? '');
                Cache::forget("lyra_awaiting_visit_datetime_{$sessionId}");
                Cache::forget("lyra_post_selection_{$sessionId}");
                Cache::forget("lyra_pending_visit_{$sessionId}");
                $this->storeHistory($historyKey, $history, $text, $msg);
                return $this->buildResponse($msg, $prop ? [$prop] : [], null, [], [], false, null, $turnCount, ['voice_ready' => true]);
            } else {
                // No dio fecha aún — insistir amablemente (con contador de reintentos)
                Cache::put("lyra_awaiting_visit_datetime_{$sessionId}", [
                    'property' => $prop,
                    'step'     => 'awaiting_datetime',
                    'retries'  => $retries + 1,
                    'fecha'    => $fecha,
                    'hora'     => $hora,
                ], 1800);

                $msg = $this->personality->onScheduleVisitRequest($prop ?? []);
                Log::info('[LyraBrain] Bloque 0-A: respuesta generada', ['msg' => substr($msg, 0, 80)]);
                $this->storeHistory($historyKey, $history, $text, $msg);
                return $this->buildResponse($msg, $prop ? [$prop] : [], null, [], [], false, null, $turnCount, ['voice_ready' => true]);
            }
        }

        // ══════════════════════════════════════════════════════════════
        // BLOQUE 0-B: ¿Hay propiedad seleccionada y el usuario actúa sobre ella?
        // ══════════════════════════════════════════════════════════════
        $pendingPostSelection = Cache::get("lyra_post_selection_{$sessionId}");

        // Si el usuario claramente cambió de tema a ubicación o búsqueda, olvidamos la selección previa
        if ($pendingPostSelection && ($this->isLocationIntent($textLower) || $this->isSearchIntent($textLower))) {
             Cache::forget("lyra_post_selection_{$sessionId}");
             $pendingPostSelection = null;
        }

        if ($pendingPostSelection) {
            $prop = Cache::get("lyra_selected_property_{$sessionId}");

            // ── ¿Quiere AGENDAR? ─────────────────────────────────────
            if ($this->wantsScheduleVisit($textLower)) {
                $msg = $this->personality->onScheduleVisitRequest($prop ?? []);
                Cache::put("lyra_awaiting_visit_datetime_{$sessionId}", [
                    'property' => $prop,
                    'step'     => 'awaiting_datetime',
                ], 1800);
                Cache::forget("lyra_post_selection_{$sessionId}");
                $this->storeHistory($historyKey, $history, $text, $msg);
                return $this->buildResponse($msg, $prop ? [$prop] : [], null, [], [], false, null, $turnCount, ['voice_ready' => true]);
            }

            // ── ¿Quiere VER los detalles? ─────────────────────────────
            if ($this->wantsPropertyDetails($textLower)) {
                $msg = $this->personality->onViewPropertyDetails($prop ?? []);
                Cache::forget("lyra_post_selection_{$sessionId}");
                $this->storeHistory($historyKey, $history, $text, $msg);

                // Incluir redirect_to_property + voice_action para que el frontend navegue
                return $this->buildResponse(
                    $msg,
                    !empty($prop) ? [$prop] : [],
                    null, [], [], false,
                    null, $turnCount,
                    [
                        'redirect_to_property' => $prop['id'] ?? null,
                        'voice_action'         => 'view_property',
                        'voice_action_payload' => ['id' => $prop['id'] ?? null],
                        'voice_ready'          => true,
                    ]
                );
            }
            
            // Fallback para contacto o señales de sí
            $contactoSignals = ['contactar', 'contacto', 'llamar', 'escribir', 'whatsapp', 'teléfono', 'número', 'comunic', 'datos'];
            foreach ($contactoSignals as $s) {
                if (str_contains($textLower, $s)) {
                    Cache::forget("lyra_post_selection_{$sessionId}");
                    $msg = $this->personality->onContactOwner([]);
                    $this->storeHistory($historyKey, $history, $text, $msg);
                    return $this->buildResponse($msg, [], null, [], [], false, null, $turnCount, ['voice_ready' => true]);
                }
            }
        }

        // ── 0.5. Pending Action Gateway (Confirmaciones/Negaciones) ──
        $pendingAction = Cache::get("lyra_pending_action_{$sessionId}");
        
        // Si el frontend nos confirma que hay una sugerencia activa, reforzamos el estado
        if (!$pendingAction && $pendingSuggestion && $suggestedCity) {
            Log::info('[LyraBrain] Reconstruyendo pending_action desde frontend', ['city' => $suggestedCity]);
            // Nota: El payload completo se perdió si la caché expiró, pero al menos sabemos la ciudad.
            // Por ahora confiamos en la caché, esto es un refuerzo.
        }

        if ($pendingAction || ($pendingSuggestion && $this->isAffirmation($textLower))) {
            $isYes = $this->isAffirmation($textLower);
            $isNo  = $this->isNegation($textLower);
            
            Log::info('[LyraBrain] Pending Action Gateway', [
                'hasActionInCache' => !!$pendingAction,
                'isYes'  => $isYes,
                'isNo'   => $isNo,
                'text'   => $textLower
            ]);

            if ($isYes) {
                $action = $pendingAction['action'] ?? ($pendingSuggestion ? 'buscar_ciudades_cercanas' : null);

                if ($action === 'buscar_ciudades_cercanas') {
                    $payload = $pendingAction['payload'] ?? null;
                    
                    if ($payload || $suggestedCity) {
                        $suggResults = $payload['results'] ?? [];
                        $suggIntent  = $payload['intent']  ?? ['city' => $suggestedCity, 'intent' => 'buscar_propiedad'];
                        $suggCity    = $payload['city']    ?? $suggestedCity;
                        
                        Cache::put("lyra_filters_{$sessionId}",   $suggIntent, 3600);
                        Cache::put("lyra_results_{$sessionId}",   $suggResults, 1800);
                        Cache::put("lyra_last_city_{$sessionId}", $suggCity, 1800);
                        Cache::forget("lyra_pending_action_{$sessionId}");

                        Log::info('[LyraBrain] Suggestion -> APLICADA (Gate 0.5)', ['city' => $suggCity, 'source' => $payload ? 'cache' : 'frontend']);

                        $count = count($suggResults);
                        $msg = $this->personality->onSuggestionApplied($suggCity);
                        
                        $this->storeHistory($historyKey, $history, $text, $msg);
                        $mapConfig = $this->mapEngine->buildConfig($payload['map_center'] ?? null, $count, $suggCity);
                        
                        return $this->buildResponse($msg, $suggResults, $mapConfig, $this->extractFilters($suggIntent), $suggIntent, false, null, $turnCount, ['voice_ready' => true]);
                    }
                }
            } elseif ($isNo) {
                Cache::forget("lyra_pending_action_{$sessionId}");
                Log::info('[LyraBrain] Pending Action -> NEGADO (Gate 0.5)');
                $msg = $this->personality->onActionCancelled(); 
                $this->storeHistory($historyKey, $history, $text, $msg);
                return $this->buildResponse($msg, [], null, [], ['intent' => 'cancelacion'], false, null, $turnCount);
            }
        }

        // ══════════════════════════════════════════════════════════════
        // BLOQUE 0-C: ¿Esperando GPS?
        // ══════════════════════════════════════════════════════════════
        $awaitingGps = Cache::get("lyra_awaiting_gps_{$sessionId}");
        if ($awaitingGps && $userLat !== null && $userLng !== null) {
            Log::info('[LyraBrain] Bloque 0-C (GPS) activo y con coordenadas');
            Cache::forget("lyra_awaiting_gps_{$sessionId}");
            return $this->handleUbicacionUsuario(
                ['intent' => 'ubicacion_usuario'],
                $sessionId, $text, $historyKey, $history, 
                $userLat, $userLng, $turnCount
            );
        }

        // ══════════════════════════════════════════════════════════════
        // BLOQUE 0-D: ¿Visita pendiente post-login?
        // ══════════════════════════════════════════════════════════════
        $pendingVisit = Cache::get("lyra_pending_visit_{$sessionId}");
        if ($textLower === 'retomar visita pendiente') {
            return $this->executeDirectAction('agendar_visita', $pendingVisit, $sessionId);
        }

        if ($pendingVisit && Auth::guard('api')->check()) {
            $confirmKeywords = ['retomar', 'enviar', 'si', 'sí', 'dale', 'confirmar', 'adelante', 'hazlo', 'solicitud', 'ok', 'bueno'];
            $isConfirmation = false;
            foreach ($confirmKeywords as $kw) {
                if (str_contains($textLower, $kw)) {
                    $isConfirmation = true;
                    break;
                }
            }

            if ($isConfirmation) {
                $prop  = $pendingVisit['property'] ?? null;
                $fecha = $pendingVisit['fecha'] ?? '';
                $hora  = $pendingVisit['hora'] ?? '';
                $title = $prop['title'] ?? 'esa propiedad';

                $msg = $this->personality->onVisitRequestSent($title, $fecha, $hora);
                $this->createVisitRequest($sessionId, $prop, $fecha, $hora);

                Cache::forget("lyra_pending_visit_{$sessionId}");
                Cache::forget("lyra_awaiting_visit_datetime_{$sessionId}");
                Cache::forget("lyra_post_selection_{$sessionId}");

                $this->storeHistory($historyKey, $history, $text, $msg);
                return $this->buildResponse($msg, $prop ? [$prop] : [], null, [], [], false, null, $turnCount, ['voice_ready' => true]);
            } else {
                // Si el usuario dijo "no" o cambió de tema, liberamos el bloqueo para que el intérprete actúe
                Cache::forget("lyra_pending_visit_{$sessionId}");
                Cache::forget("lyra_awaiting_visit_datetime_{$sessionId}");
                Log::info('[LyraBrain] Bloque 0-D: usuario no confirmó retomar, continuando flujo normal');
            }
        }

        // ── Contexto emocional antes de interpretar ───────────────
        // (solo se aplica si el intent lo permite — ver sección clarificación)
        $emotionalResponse = $this->personality->detectEmotionalContext($text);


        // ── 1. Interpret ──────────────────────────────────────────
        $intent = $this->localInterpreter->interpret($text, $context);
        Log::info('[LyraBrain] Intent procesado', [
            'intent' => $intent['intent'] ?? 'unknown',
            'confidence' => $intent['confidence'] ?? 1.0
        ]);

        // ── 1-B. Umbral de confianza (Confidence Threshold) ──────────
        // Si el mensaje es muy corto y no tiene entidades claras, pedimos más info
        // para evitar que FilterEngine resetee el contexto por una mala interpretación.
        $wordCount = count(explode(' ', trim($text)));
        $hasEntity = !empty($intent['city']) || !empty($intent['property_type']) || 
                    !empty($intent['min_price']) || !empty($intent['max_price']) ||
                    !empty($intent['rooms']) || !empty($intent['neighborhood']) ||
                    !empty($intent['zone']);
        
        $isVague = ($wordCount < 2 && !$hasEntity && !in_array($intent['intent'] ?? '', ['saludo', 'despedida', 'afirmacion', 'negacion', 'gps_reply', 'ubicacion_usuario']));

        if ($isVague && $turnCount > 1) {
            $msg = $this->personality->onNeedsClarification();
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, true, null, $turnCount, [
                'voice_ready' => true,
                'awaiting_continuation' => true
            ]);
        }

        // ── Inject user location ──────────────────────────────────
        if ($userLat !== null && $userLng !== null) {
            $intent['user_lat'] = $userLat;
            $intent['user_lng'] = $userLng;
            $intent['city']     = null;
        }

        // ── 2. Security gate ──────────────────────────────────────
        if (!empty($intent['security_violation'])) {
            $msg = $this->personality->humanizeText('fuera_de_contexto', [], null, null, '', false, $text);
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        // (El bloque de pending action fue movido al inicio como Gate 0.5)

        // ── Check GPS state ───────────────────────────────────────
        $awaitingGps = Cache::get("lyra_awaiting_gps_{$sessionId}");
        
        if ($awaitingGps) {
            if ($userLat !== null && $userLng !== null) {
                Cache::forget("lyra_awaiting_gps_{$sessionId}");
                return $this->handleUbicacionUsuario(
                    ['intent' => 'ubicacion_usuario'],
                    $sessionId, $text, $historyKey, $history, 
                    $userLat, $userLng, $turnCount
                );
            }
            
            // Si dijo "si" o "ya está" pero NO llegaron coordenadas, pedimos de nuevo o esperamos
            if ($this->isAffirmation($textLower) || str_contains($textLower, 'activo') || str_contains($textLower, 'listo')) {
                $msg = $this->personality->onLocationRequest(); // "¿La compartimos?" / "Activa el GPS"
                return $this->buildResponse($msg, [], null, [], ['intent' => 'ubicacion_usuario'], false, null, $turnCount, ['requires_location' => true]);
            }
        }

        // ── 2.5 Input inválido ────────────────────────────────────
        if (($intent['intent'] ?? '') === 'input_invalido') {
            $msg = $this->personality->onInvalidInput($text);
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        // ── 3. Route by intent ────────────────────────────────────
        $intentType = $intent['intent'] ?? 'buscar_propiedad';

        // ── RETOMAR INTENT PENDIENTE si el usuario dio ciudad ─────
        $pendingIntent = Cache::get("lyra_pending_intent_{$sessionId}");
        if ($pendingIntent && !empty($intent['city'])) {
            $intentType       = $pendingIntent;
            $intent['intent'] = $pendingIntent;

            $pendingFilters = Cache::get("lyra_pending_filters_{$sessionId}");
            if (!empty($pendingFilters)) {
                foreach ($pendingFilters as $key => $value) {
                    if (!isset($intent[$key]) || empty($intent[$key])) {
                        $intent[$key] = $value;
                    }
                }
                Cache::forget("lyra_pending_filters_{$sessionId}");
            }
            Cache::forget("lyra_pending_intent_{$sessionId}");
        }

        // ── CIUDAD REQUERIDA para intents de búsqueda ─────────────
        $intentosQueBuscan = [
            'buscar_propiedad',
            'refinar_busqueda',
            'recomendacion',
            'recomendar_propiedades',
        ];

        if (in_array($intentType, $intentosQueBuscan)) {
            $ciudadActiva = $intent['city']
                ?? Cache::get("lyra_last_city_{$sessionId}")
                ?? null;

            if (!$ciudadActiva) {
                Cache::put("lyra_pending_intent_{$sessionId}", $intentType, 1800);

                $filtrosPendientes = array_filter([
                    'property_type' => $intent['property_type'] ?? null,
                    'min_price'     => $intent['min_price']     ?? null,
                    'max_price'     => $intent['max_price']     ?? null,
                    'bedrooms'      => $intent['bedrooms']      ?? null,
                    'bathrooms'     => $intent['bathrooms']     ?? null,
                    'amenities'     => $intent['amenities']     ?? null,
                    'zone'          => $intent['zone']          ?? null,
                ]);
                if (!empty($filtrosPendientes)) {
                    Cache::put("lyra_pending_filters_{$sessionId}", $filtrosPendientes, 1800);
                }

                $clarificationFromIntent = $intent['clarification_question'] ?? null;

                if ($clarificationFromIntent) {
                    $msg = $clarificationFromIntent;
                } else {
                    $msg = $this->buildMissingCityResponse($intent);
                }

                $this->storeHistory($historyKey, $history, $text, $msg);
                return $this->buildResponse($msg, [], null, [], $intent, true, $msg);
            }
        }

        // ── Intents conversacionales ──────────────────────────────
        if (in_array($intentType, ['saludo', 'preguntar_rentus', 'fuera_de_contexto'])) {
            $placeholder = match ($intentType) {
                'saludo'            => '__SALUDO__',
                'preguntar_rentus'  => '__RENTUS__',
                'fuera_de_contexto' => '__OFFTOPIC__',
                default             => '__SALUDO__',
            };
            $this->storeHistory($historyKey, $history, $text, '');
            return $this->buildResponse($placeholder, [], null, [], $intent);
        }

        if ($intentType === 'buscar_ciudad_cercana' || $intentType === 'ampliar_zona') {
            return $this->handleAmpliZona($intent, $sessionId, $text, $historyKey, $history, $turnCount);
        }

        if ($intentType === 'ubicacion_usuario') {
            return $this->handleUbicacionUsuario($intent, $sessionId, $text, $historyKey, $history, $userLat, $userLng, $turnCount);
        }

        if ($intentType === 'consulta_resultados') {
            return $this->handleConsultaResultados($text, $sessionId, $historyKey, $history, $intent, $turnCount);
        }

        if ($intentType === 'analisis_datos' || $intentType === 'consulta_analitica') {
            return $this->handleConsultaAnalitica($text, $sessionId, $historyKey, $history, $intent, $turnCount);
        }

        if ($intentType === 'recomendacion' || $intentType === 'recomendar_propiedades') {
            return $this->handleRecommendingProperties($text, $sessionId, $historyKey, $history, $intent, $turnCount);
        }

        // ── Flujos de sesión ──────────────────────────────────────
        if (in_array($intentType, ['reiniciar', 'nueva_busqueda'])) {
            $this->filterEngine->clear($sessionId);
            Cache::forget("lyra_last_city_{$sessionId}");
            Cache::forget("lyra_results_{$sessionId}");
            $msg = $this->personality->onRestart();
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        if (in_array($intentType, ['limpiar_filtros', 'quitar_filtros'])) {
            $this->filterEngine->clear($sessionId);
            $lastCity    = Cache::get("lyra_last_city_{$sessionId}");
            $lastResults = Cache::get("lyra_results_{$sessionId}", []);
            $lastType    = $intent['property_type'] ?? null;
            $msg = $this->personality->onFiltersCleared();
            $this->storeHistory($historyKey, $history, $text, $msg);
            // Enviar filters_applied con todos null para que el frontend limpie el mapa
            $clearedFilters = [
                'city' => null, 'neighborhood' => null, 'property_type' => null,
                'min_price' => null, 'max_price' => null, 'bedrooms' => null,
                'bathrooms' => null, 'zone' => null,
            ];
            return $this->buildResponse($msg, $lastResults, null, $clearedFilters, $intent);
        }

        if (in_array($intentType, ['volver_resultados', 'ver_anteriores'])) {
            $lastCity    = Cache::get("lyra_last_city_{$sessionId}");
            $lastResults = Cache::get("lyra_results_{$sessionId}", []);
            $lastType    = $intent['property_type'] ?? null;
            $msg = $this->personality->onGoBack($lastCity, $lastType, count($lastResults));
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, $lastResults, null, [], $intent);
        }

        if ($intentType === 'ver_todas') {
            $lastCity    = Cache::get("lyra_last_city_{$sessionId}");
            $lastResults = Cache::get("lyra_results_{$sessionId}", []);
            $msg = $this->personality->onShowAll($lastCity, count($lastResults));
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, $lastResults, null, [], $intent);
        }

        if ($intentType === 'ver_nuevas' || $intentType === 'nuevas_propiedades') {
            $lastCity    = Cache::get("lyra_last_city_{$sessionId}");
            $lastResults = Cache::get("lyra_results_{$sessionId}", []);
            $msg = $this->personality->onNewListings($lastCity, count($lastResults));
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, $lastResults, null, [], $intent);
        }

        if ($intentType === 'despedida') {
            $msg = $this->personality->onDespedida();
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount, [
                'voice_action'        => 'auto_minimize',
                'auto_minimize_delay' => 3000,
                'voice_ready'         => true,
            ]);
        }

        // ── Intents de información / proceso ─────────────────────
        if (in_array($intentType, ['consulta_contrato', 'pregunta_contrato'])) {
            $msg = $this->personality->onContractQuestion($text);
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        if (in_array($intentType, ['consulta_proceso', 'como_funciona'])) {
            $msg = $this->personality->onProcessQuestion();
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        if (in_array($intentType, ['consulta_documentos', 'que_documentos', 'documentacion'])) {
            $city = $intent['city'] ?? Cache::get("lyra_last_city_{$sessionId}");
            $msg  = $this->personality->onDocumentationQuestion($city);
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        if ($intentType === 'consulta_mercado_timing') {
            $city = $intent['city'] ?? Cache::get("lyra_last_city_{$sessionId}");
            $msg  = $this->personality->onMarketTimingQuestion($city);
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        if ($intentType === 'consulta_tendencia_precios') {
            $city = $intent['city'] ?? Cache::get("lyra_last_city_{$sessionId}");
            $msg  = $this->personality->onPriceTrendQuestion($city);
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        if ($intentType === 'comparar_propiedades') {
            return $this->handleCompareProperties($text, $sessionId, $historyKey, $history, $intent);
        }

        if ($intentType === 'contactar_propietario') {
            $msg = $this->personality->onContactOwner([]);
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        if ($intentType === 'agendar_visita') {
            $prop = Cache::get("lyra_selected_property_{$sessionId}");
            $msg  = $this->personality->onScheduleVisitRequest($prop ?? []);

            // CRÍTICO: guardar estado para que el siguiente mensaje entre al Bloque 0-A
            Cache::put("lyra_awaiting_visit_datetime_{$sessionId}", [
                'property' => $prop,
                'step'     => 'awaiting_datetime',
            ], 1800);

            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, $prop ? [$prop] : [], null, [], $intent, false, null, $turnCount, [
                'voice_ready'          => true,
                'voice_action'         => 'open_visit_modal',
                'voice_action_payload' => $prop ?? [],
            ]);
        }

        if ($intentType === 'seleccionar_propiedad') {
            return $this->handlePropertySelection($text, $sessionId, $historyKey, $history, $intent, $turnCount);
        }

        if ($intentType === 'consulta_seguridad') {
            $city = $intent['city'] ?? Cache::get("lyra_last_city_{$sessionId}");
            $zone = $intent['zone'] ?? null;
            $msg  = $this->personality->onSecurityQuestion($zone, $city);
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        if ($intentType === 'consulta_transporte') {
            $city = $intent['city'] ?? Cache::get("lyra_last_city_{$sessionId}");
            $zone = $intent['zone'] ?? null;
            $msg  = $this->personality->onTransportQuestion($zone, $city);
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        if ($intentType === 'recomendar_ciudad') {
            return $this->handleCityRecommendation($text, $sessionId, $historyKey, $history, $intent, $turnCount);
        }

        if ($intentType === 'indeciso_ciudades') {
            $cities = $intent['cities_considered'] ?? [];
            $msg    = $this->personality->onCityUndecided($cities);
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        if ($intentType === 'nuevo_usuario') {
            $msg = $this->personality->onNewUser();
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        if ($intentType === 'disponibilidad_inmediata') {
            $city        = $intent['city'] ?? Cache::get("lyra_last_city_{$sessionId}");
            $lastResults = Cache::get("lyra_results_{$sessionId}", []);
            $msg = $this->personality->onImmediateAvailability($city, count($lastResults));
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, $lastResults, null, [], $intent);
        }

        if (in_array($intentType, ['busqueda_urgente', 'urgente'])) {
            $msg = $this->personality->onUrgentSearch($intent['deadline'] ?? '');
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, true, $msg);
        }

        if ($intentType === 'fecha_especifica') {
            $city = $intent['city'] ?? Cache::get("lyra_last_city_{$sessionId}");
            $msg  = $this->personality->onDateSpecific($intent['date'] ?? '', $city);
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, true, $msg);
        }

        // ── 4. Acumulación de filtros ─────────────────────────────
        if (!empty($intent['is_new_search']) && $intent['is_new_search'] === true) {
            $this->filterEngine->resetAmenities($sessionId);
        }
        $mergedIntent = $this->filterEngine->merge($sessionId, $intent);
        $radiusKm     = $this->calculateSmartRadius($mergedIntent);

        if ($intentType === 'ampliar_busqueda') {
            $radiusKm = max($radiusKm, 30);
            $mergedIntent['radius_km']    = $radiusKm;
            $mergedIntent['neighborhood'] = null;
        }

        if ($intentType === 'sugerir_ciudades') {
            $mergedIntent['city']                = null;
            $mergedIntent['neighborhood']        = null;
            $mergedIntent['needs_clarification'] = false;
        }

        // ── 5. Clarification needed? ──────────────────────────────
        if (!empty($mergedIntent['needs_clarification'])) {
            $rawQ = $mergedIntent['clarification_question'] ?? $mergedIntent['response_text'] ?? '';

            // Si hay contexto emocional, usarlo como prefijo
            if ($emotionalResponse) {
                $rawQ = $emotionalResponse;
            }

            $this->storeHistory($historyKey, $history, $text, $rawQ);
            return $this->buildResponse(
                $rawQ,
                [],
                null,
                $this->extractFilters($mergedIntent),
                $mergedIntent,
                true,
                $mergedIntent['clarification_question'] ?? null
            );
        }

        // ── 6. Resolución de ambigüedad de barrio ─────────────────
        if (!empty($mergedIntent['neighborhood']) && empty($mergedIntent['city'])) {
            $ambiguityResult = $this->resolveNeighborhoodAmbiguity($mergedIntent['neighborhood']);

            if ($ambiguityResult['count'] === 0) {
                $nb          = $mergedIntent['neighborhood'];
                $responseTxt = "No encontré propiedades en {$nb}. ¿Puedes decirme en qué ciudad queda?";
                $this->storeHistory($historyKey, $history, $text, $responseTxt);
                return $this->buildResponse($responseTxt, [], null, $this->extractFilters($mergedIntent), $mergedIntent);
            }

            if ($ambiguityResult['count'] > 1) {
                $nb          = $mergedIntent['neighborhood'];
                $cities      = implode(' y ', $ambiguityResult['cities']);
                $responseTxt = "Encontré el barrio {$nb} en {$cities}. ¿En cuál de las dos buscamos?";
                $this->storeHistory($historyKey, $history, $text, $responseTxt);
                return $this->buildResponse($responseTxt, [], null, $this->extractFilters($mergedIntent), $mergedIntent, true, $responseTxt);
            }

            $mergedIntent['city'] = $ambiguityResult['cities'][0];
            $this->filterEngine->merge($sessionId, $mergedIntent);
        }

        // ── Validación de precio ──────────────────────────────────
        $ctx        = $this->contextService->getContext();
        $minPriceDb = $ctx['price_range']['min'] ?? 0;
        $askedPrice = $mergedIntent['max_price'] ?? $mergedIntent['min_price'] ?? null;

        if ($askedPrice !== null && $minPriceDb > 0 && $askedPrice < $minPriceDb) {
            $formattedMin = number_format($minPriceDb / 1000000, 1, ',', '.');
            $msg          = "Las propiedades en RentUs arrancan desde {$formattedMin} millones. ¿Quisiste decir eso?";
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, $this->extractFilters($mergedIntent), $mergedIntent, true, $msg);
        }

        // ── 7. Geolocalización ────────────────────────────────────
        $mapCenter = $this->locationEngine->resolve($mergedIntent);

        if (!empty($mergedIntent['zone'])) {
            $offsets = [
                'norte'      => ['lat' => +0.025, 'lng' =>  0.00],
                'sur'        => ['lat' => -0.025, 'lng' =>  0.00],
                'este'       => ['lat' =>  0.00,  'lng' => +0.025],
                'oriente'    => ['lat' =>  0.00,  'lng' => +0.025],
                'oeste'      => ['lat' =>  0.00,  'lng' => -0.025],
                'occidente'  => ['lat' =>  0.00,  'lng' => -0.025],
                'centro'     => ['lat' =>  0.00,  'lng' =>  0.00],
            ];
            $z = mb_strtolower($mergedIntent['zone']);
            if (isset($offsets[$z]) && $mapCenter) {
                $mapCenter['lat'] += $offsets[$z]['lat'];
                $mapCenter['lng'] += $offsets[$z]['lng'];
            }
        }

        // ── 8. Búsqueda ───────────────────────────────────────────
        $results = $this->searchEngine->search($mergedIntent, $mapCenter, $radiusKm);

        // Fallback: si no hay en zona específica, buscar en toda la ciudad
        if (empty($results) && !empty($mergedIntent['zone'])) {
            $intentSinZona = $mergedIntent;
            $intentSinZona['zone']            = null;
            $intentSinZona['reference_place'] = null;
            $resultsNoZone = $this->searchEngine->search($intentSinZona, $mapCenter, $radiusKm);
            if (!empty($resultsNoZone)) {
                $results                             = $resultsNoZone;
                $mergedIntent['zone_sin_resultados'] = $mergedIntent['zone'];
            }
        }

        $count        = count($results);
        $humanResponse = null;

        if ($count === 0) {
            // Ampliar radio
            $radiusKm = empty($mergedIntent['neighborhood']) ? 25 : 10;
            if ($mapCenter) {
                $results = $this->searchEngine->search($mergedIntent, $mapCenter, $radiusKm);
                $count   = count($results);
            }

            if ($count === 0) {
                // Quitar barrio/ref
                if (!empty($mergedIntent['neighborhood']) || !empty($mergedIntent['reference_place'])) {
                    $mergedIntent['neighborhood']    = null;
                    $mergedIntent['reference_place'] = null;
                    $results = $this->searchEngine->search($mergedIntent, $mapCenter, $radiusKm);
                    $count   = count($results);
                }

                // Quitar habitaciones
                if ($count === 0 && !empty($mergedIntent['bedrooms'])) {
                    $mergedIntent['bedrooms'] = null;
                    $results = $this->searchEngine->search($mergedIntent, $mapCenter, $radiusKm);
                    $count   = count($results);
                }

                // Quitar precio
                if ($count === 0 && !empty($mergedIntent['max_price'])) {
                    $mergedIntent['max_price'] = null;
                    $results = $this->searchEngine->search($mergedIntent, $mapCenter, $radiusKm);
                    $count   = count($results);
                }

                if ($count === 0) {
                    $isRefinement = (($intent['intent'] ?? '') === 'refinar_busqueda');
                    if ($isRefinement) {
                        $filterName = implode(' y ', $mergedIntent['amenities'] ?? [])
                            ?: ($mergedIntent['bedrooms'] ? $mergedIntent['bedrooms'] . ' habitaciones' : 'ese criterio');
                        $city       = $mergedIntent['city'] ?? 'esa ciudad';
                        $humanResponse = "No encontré propiedades con {$filterName} en {$city}. ¿Quitamos ese filtro o buscamos en otra ciudad?";
                        $this->storeHistory($historyKey, $history, $text, $humanResponse);
                        return $this->buildResponse($humanResponse, [], null, $this->extractFilters($mergedIntent), $mergedIntent);
                    }

                    // Ciudades adyacentes
                    $city          = $mergedIntent['city'] ?? null;
                    $adjacentFound = false;

                    if ($city) {
                        $suggestion = $this->getAdjacentCityWithResults($city, $mergedIntent);
                        if (!empty($suggestion)) {
                            $adjacentFound = true;
                            $adjCity = $suggestion['city'];
                            
                            // SUGERENCIA AISLADA: No tocamos el estado principal aún.
                            // Solo guardamos en el pending action para cuando diga "sí".
                            Log::info('[LyraBrain] GUARDANDO pending_action sugerencia', [
                                'city' => $adjCity,
                                'results_count' => count($suggestion['results'] ?? [])
                            ]);
                            Cache::put("lyra_pending_action_{$sessionId}", [
                                'action'         => 'buscar_ciudades_cercanas',
                                'reference_city' => $adjCity,
                                'payload'        => $suggestion
                            ], 1800);

                            // La respuesta humana indica que encontramos algo cerca
                            // Retornamos la respuesta de sugerencia pero CON LOS RESULTADOS VACÍOS (los del mapa actual)
                            return $this->buildResponse($humanResponse, [], null, $this->extractFilters($mergedIntent), $mergedIntent, false, null, $turnCount, [
                                'pending_suggestion' => true,
                                'suggested_city'     => $adjCity
                            ]);
                        }
                    }

                    if (!$adjacentFound) {
                        Cache::put("lyra_pending_action_{$sessionId}", [
                            'action'         => 'buscar_ciudades_cercanas',
                            'reference_city' => $city ?? 'Bogotá',
                        ], 1800);
                    }
                }
            }
        }

        // ── 9. Configuración de mapa ──────────────────────────────
        $mapConfig = $this->mapEngine->buildConfig($mapCenter, $count, $mergedIntent['city'] ?? null);

        $this->storeHistory($historyKey, $history, $text, '');
        Cache::put("lyra_last_intent_{$sessionId}",  $intent, 1800);
        Cache::put("lyra_last_city_{$sessionId}",    $mergedIntent['city'] ?? null, 1800);
        Cache::put("lyra_results_{$sessionId}",      $results, 1800);

        // Si hay solo un resultado, lo marcamos como seleccionado para facilitar "quiero verla"
        if ($count === 1 && !empty($results[0])) {
            Cache::put("lyra_selected_property_{$sessionId}", $results[0], 1800);
            Cache::put("lyra_post_selection_{$sessionId}", [
                'property_id' => $results[0]['id'] ?? null,
                'asked_at'    => Carbon::now()->timestamp,
            ], 1800);
        }

        // Contador de intentos para detectar búsquedas repetidas
        $attemptKey = "lyra_search_attempt_{$sessionId}";
        $attempt    = Cache::get($attemptKey, 0) + 1;
        Cache::put($attemptKey, $attempt, 1800);

        // ── 10. Humanizar con personalidad ────────────────────────
        $baseText = $humanResponse ?? $this->buildSmartResponse($mergedIntent, $results, $attempt, $sessionId, $text);

        $rawResult = $this->buildResponse(
            $baseText,
            $results,
            $mapConfig,
            $this->extractFilters($mergedIntent),
            $mergedIntent
        );

        if (isset($zoneFallbackCity)) {
            $rawResult['zone_fallback_city'] = $zoneFallbackCity;
        }

        return $rawResult;
    }

    // ═══════════════════════════════════════════════════════════════
    //  BUILDER DE RESPUESTA INTELIGENTE
    //  Reemplaza buildSuccessResponse — usa personalidad directamente
    // ═══════════════════════════════════════════════════════════════

    /**
     * Genera la respuesta final usando la personalidad según el contexto completo.
     */
    private function buildSmartResponse(
        array  $mergedIntent,
        array  $results,
        int    $attempt,
        string $sessionId,
        string $originalText = ''
    ): string {
        $count    = count($results);
        $city     = $mergedIntent['city']          ?? null;
        $type     = $mergedIntent['property_type'] ?? null;
        $filters  = $this->extractFilters($mergedIntent);
        $amenidas = $mergedIntent['amenities']      ?? [];

        // ── Sin resultados ─────────────────────────────────────────
        if ($count === 0) {
            return $this->personality->onNoResults($city, $type, '', $filters);
        }

        // ── Búsqueda repetida ─────────────────────────────────────
        if ($attempt >= 2) {
            return $this->personality->onRepeatedSearch($attempt, $city, $type);
        }

        // ── Contexto de zona fallback (no había en zona, sí en ciudad) ─
        if (!empty($mergedIntent['zone_sin_resultados'])) {
            return $this->personality->onZoneFallback(
                $mergedIntent['zone_sin_resultados'],
                $city,
                $count,
                $type
            );
        }

        // ── Búsqueda por barrio ────────────────────────────────────
        if (!empty($mergedIntent['neighborhood'])) {
            return $this->personality->onNeighborhoodSearch(
                $mergedIntent['neighborhood'],
                $city,
                $count,
                $type
            );
        }

        // ── Búsqueda por zona cardinal ────────────────────────────
        if (!empty($mergedIntent['zone'])) {
            return $this->personality->onZoneSearch($mergedIntent['zone'], $city, $count, $type);
        }

        // ── Amenidades específicas ────────────────────────────────
        if (!empty($amenidas)) {
            $amenLower = array_map('mb_strtolower', (array)$amenidas);
            if (array_intersect($amenLower, ['mascotas', 'pet-friendly', 'mascota'])) {
                return $this->personality->onPetsSearch($city, $type, $count);
            }
            if (array_intersect($amenLower, ['amoblado', 'amueblado', 'amoblada', 'amueblada'])) {
                return $this->personality->onFurnishedSearch($city, $type, $count);
            }
            if (array_intersect($amenLower, ['parqueadero', 'garage', 'garaje', 'estacionamiento'])) {
                return $this->personality->onParkingSearch($city, $type, $count);
            }
            return $this->personality->onAmenitySearch((array)$amenidas, $city, $type, $count);
        }

        // ── Contexto de temporada alta ────────────────────────────
        $highSeasonMsg = $this->personality->onHighSeasonContext($city);
        $prefix = $highSeasonMsg ? $highSeasonMsg . ' ' : '';

        // ── Selección por cantidad ────────────────────────────────
        if ($count === 1) {
            return $prefix . $this->personality->onSearchSuccess(1, $city, $type);
        }

        if ($count <= 5) {
            return $prefix . $this->personality->onFewResults($count, $city, $type, $filters);
        }

        // ── Alta oferta: ciudad principal ─────────────────────────
        $bigCities = ['Bogotá', 'Bogota', 'Medellín', 'Medellin', 'Cali', 'Barranquilla', 'Cartagena'];
        $isBigCity = $city && count(array_filter($bigCities, fn($c) => stripos($city, $c) !== false)) > 0;

        if ($isBigCity && $count >= 20) {
            return $prefix . $this->personality->onMajorCitySearch($city, $count, $type);
        }

        return $prefix . $this->personality->onManyResults($count, $city, $type, $filters);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTA CUANDO FALTA LA CIUDAD
    // ═══════════════════════════════════════════════════════════════

    /**
     * Construye respuesta de "falta ciudad" usando la personalidad según
     * qué datos SÍ tiene el intent.
     */
    private function buildMissingCityResponse(array $intent): string
    {
        $type  = $intent['property_type'] ?? null;
        $price = $intent['max_price'] ?? $intent['min_price'] ?? null;
        $beds  = $intent['bedrooms'] ?? null;

        if ($type && $price) {
            return $this->personality->onTypeAndPriceWithoutCity($type, (float)$price);
        }

        if ($type) {
            return $this->personality->onTypeWithoutCity($type);
        }

        if ($price) {
            return $this->personality->onPriceWithoutCity((float)$price);
        }

        if ($beds) {
            return $this->personality->onBedroomsOnly((int)$beds);
        }

        // Genérica con ciudades disponibles de la DB
        $ciudadesDisponibles = Property::where('approval_status', 'approved')
            ->where('visibility', 'published')
            ->where('status', 'available')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->pluck('city')
            ->take(5)
            ->implode(', ');

        return $this->personality->onMissingCity(null, null, null);
    }

    // ═══════════════════════════════════════════════════════════════
    //  HANDLERS ESPECÍFICOS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Comparación entre dos propiedades.
     */
    private function handleCompareProperties(
        string $text,
        string $sessionId,
        string $historyKey,
        array  $history,
        array  $intent
    ): array {
        $lastResults = Cache::get("lyra_results_{$sessionId}", []);

        if (count($lastResults) < 2) {
            $msg = $this->personality->onUndecided(count($lastResults));
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, $lastResults, null, [], $intent);
        }

        $propRef = $intent['propiedad_referenciada'] ?? null;
        $prop1   = $lastResults[0];
        $prop2   = $lastResults[1];

        if ($propRef) {
            $filtered = $this->filterByPropertyReference($lastResults, $propRef);
            if (count($filtered) >= 2) {
                [$prop1, $prop2] = $filtered;
            } elseif (count($filtered) === 1) {
                $prop1 = $filtered[0];
                foreach ($lastResults as $r) {
                    if (($r['id'] ?? null) !== ($prop1['id'] ?? null)) {
                        $prop2 = $r;
                        break;
                    }
                }
            }
        }

        $msg = $this->personality->onCompareProperties($prop1, $prop2);
        $this->storeHistory($historyKey, $history, $text, $msg);
        return $this->buildResponse($msg, [$prop1, $prop2], null, [], $intent);
    }

    /**
     * Recomendación de ciudad según oferta disponible.
     */
    private function handleCityRecommendation(
        string $text,
        string $sessionId,
        string $historyKey,
        array  $history,
        array  $intent,
        int    $turnCount = 1
    ): array {
        $availableCities = Property::where('approval_status', 'approved')
            ->where('visibility', 'published')
            ->where('status', 'available')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->selectRaw('city, COUNT(*) as total')
            ->groupBy('city')
            ->orderByDesc('total')
            ->limit(5)
            ->pluck('city')
            ->toArray();

        $intent['cities_available'] = $availableCities;

        Log::info('[CITY_REC] Ciudades disponibles', ['cities' => $availableCities]);  // ← AGREGA ESTA

        $msg = $this->personality->onCityRecommendation($availableCities);

        Log::info('[CITY_REC] Mensaje generado', ['msg' => $msg]);  // ← Y ESTA

        $this->storeHistory($historyKey, $history, $text, $msg);
        return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
    }

    /**
     * Ampliar zona / buscar en ciudades cercanas.
     */
    private function handleAmpliZona(
        array  $intent,
        string $sessionId,
        string $text,
        string $historyKey,
        array  $history,
        int    $turnCount = 1,
        ?float $userLat = null,
        ?float $userLng = null
    ): array {
        $referenceCity = Cache::get("lyra_last_city_{$sessionId}") ?? ($intent['city'] ?? null);

        // Si tenemos coordenadas GPS, es la prioridad máxima para "Cercanas"
        if ($userLat && $userLng && ($intent['intent'] ?? '') === 'buscar_ciudad_cercana') {
            $closestCity = $this->getClosestAvailableCityToCoordinates($userLat, $userLng);
            if ($closestCity) {
                $referenceCity = $closestCity;
            }
        }

        if (!$referenceCity) {
            $msg = "No estoy segura de qué ciudad tomar como referencia. ¿Puedes decirme cerca de dónde buscamos?";
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        try {
            $ciudades = collect($this->locationEngine->getCiudadesCercanas($referenceCity));
        } catch (\Exception $e) {
            $ciudades = collect();
        }

        if ($ciudades->isEmpty()) {
            $ciudadesAlt = Property::where('approval_status', 'approved')
                ->where('visibility', 'published')
                ->where('status', 'available')
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->selectRaw('city, COUNT(*) as total')
                ->groupBy('city')
                ->orderByDesc('total')
                ->limit(5)
                ->pluck('city')
                ->toArray();

            $msg = $this->personality->onAlternativeSuggestion($referenceCity, $ciudadesAlt);
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        $targetCity  = $ciudades->first()['city'];
        $allCities   = $ciudades->pluck('city')->implode(', ');

        $searchIntent = $this->filterEngine->merge($sessionId, [
            'city'      => $targetCity,
            'radius_km' => 20,
            'intent'    => 'buscar_propiedad',
        ]);

        Cache::forget("lyra_pending_action_{$sessionId}");

        $mapCenter = $this->locationEngine->resolve($searchIntent);
        $results   = $this->searchEngine->search($searchIntent, $mapCenter, 20);
        $count     = count($results);

        if ($count > 0) {
            $tipo = $searchIntent['property_type'] ?? null;
            $msg  = $this->personality->onExpandZoneWithResults($targetCity, $allCities, $count, $tipo);
        } else {
            $ciudadesAlt = Property::where('approval_status', 'approved')
                ->where('visibility', 'published')
                ->where('status', 'available')
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->selectRaw('city, COUNT(*) as total')
                ->groupBy('city')
                ->orderByDesc('total')
                ->limit(5)
                ->pluck('city')
                ->toArray();

            $msg = $this->personality->onAlternativeSuggestion($referenceCity, $ciudadesAlt);
        }

        $this->storeHistory($historyKey, $history, $text, $msg);
        Cache::put("lyra_results_{$sessionId}", $results, 1800);
        Cache::put("lyra_last_city_{$sessionId}", $targetCity, 1800);

        $mapConfig = $this->mapEngine->buildConfig($mapCenter, $count, $targetCity);
        return $this->buildResponse(
            $msg,
            $results,
            $mapConfig,
            $this->extractFilters($searchIntent),
            $intent
        );
    }

    /**
     * Búsqueda por ubicación GPS del usuario.
     */
    private function handleUbicacionUsuario(
        array  $intent,
        string $sessionId,
        string $text,
        string $historyKey,
        array  $history,
        ?float $userLat,
        ?float $userLng,
        int    $turnCount = 1
    ): array {
        if ($userLat === null || $userLng === null) {
            Cache::put("lyra_awaiting_gps_{$sessionId}", true, 300);
            $msg = $this->personality->onLocationRequest();
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount, [
                'requires_location' => true,
                'voice_ready'       => true
            ]);
        }

        $intent['map_lat']   = $userLat;
        $intent['map_lng']   = $userLng;
        $intent['radius_km'] = 5;

        // Limpiar los filtros de ubicación previos (ciudad, barrio, zona) para no interferir con el GPS
        $mergedIntent = $this->filterEngine->merge($sessionId, $intent);
        unset($mergedIntent['city'], $mergedIntent['neighborhood'], $mergedIntent['zone'], $mergedIntent['reference_place'], $mergedIntent['street']);
        Cache::put("lyra_filters_{$sessionId}", $mergedIntent, 3600);
        Cache::forget("lyra_last_city_{$sessionId}");

        $results      = $this->searchEngine->search($mergedIntent, ['lat' => $userLat, 'lng' => $userLng], 5);
        $count        = count($results);

        if ($count > 0) {
            $tipo = $mergedIntent['property_type'] ?? null;
            $msg  = $this->personality->onNearbyPropertiesFound($count, $tipo);
        } else {
            // SUGERENCIA AISLADA PARA GPS
            $suggestion = $this->getClosestCityWithResults($userLat, $userLng, $mergedIntent);
            
            if (!empty($suggestion)) {
                $closestCity = $suggestion['city'];
                $msg = "No encontré propiedades justo aquí, pero la ciudad más cercana con opciones es **{$closestCity}**. ¿Quieres que busquemos allá?";
                
                Cache::put("lyra_pending_action_{$sessionId}", [
                    'action'         => 'buscar_ciudades_cercanas',
                    'reference_city' => $closestCity,
                    'payload'        => $suggestion
                ], 1800);
            } else {
                $msg = $this->personality->onNearbyPropertiesNotFound(null);
            }
        }

        $mapConfig = $this->mapEngine->buildConfig(['lat' => $userLat, 'lng' => $userLng], $count, null);

        $this->storeHistory($historyKey, $history, $text, $msg);
        Cache::put("lyra_results_{$sessionId}", $results, 1800);
        Cache::put("lyra_last_intent_{$sessionId}", $intent, 1800);

        // Retornamos filtros todos nulos para la ubicación (para que blanquee el mapa) excepto si hay tipo, precio
        $filtrosAplicados = $this->extractFilters($mergedIntent);
        $filtrosAplicados['city'] = null;
        $filtrosAplicados['neighborhood'] = null;
        $filtrosAplicados['zone'] = null;

        $extra = [
            'voice_action' => 'enable_gps_tracking',
            'voice_ready'  => true
        ];

        if (!empty($suggestion)) {
            $extra['pending_suggestion'] = true;
            $extra['suggested_city']     = $suggestion['city'];
        }

        return $this->buildResponse($msg, $results, $mapConfig, $filtrosAplicados, $intent, false, null, $turnCount, $extra);
    }

    /**
     * Consulta sobre resultados previos en sesión.
     */
    private function handleConsultaResultados(
        string $text,
        string $sessionId,
        string $historyKey,
        array  $history,
        array  $intent,
        int    $turnCount = 1
    ): array {
        $lastResults = Cache::get("lyra_results_{$sessionId}", []);

        if (empty($lastResults)) {
            $msg = $this->personality->onNoRecentResults();
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        $propRef        = $intent['propiedad_referenciada'] ?? null;
        $workingResults = $lastResults;

        if ($propRef) {
            $filtered = $this->filterByPropertyReference($lastResults, $propRef);
            if (!empty($filtered)) $workingResults = $filtered;
        }

        $pregunta = $intent['pregunta_sobre_resultado'] ?? 'general';
        $isSingle = count($workingResults) === 1;
        $prop     = $workingResults[0] ?? null;

        if ($isSingle && $prop) {
            Cache::put("lyra_selected_property_{$sessionId}", $prop, 1800);
            Cache::put("lyra_post_selection_{$sessionId}", [
                'property_id' => $prop['id'] ?? null,
                'asked_at'    => Carbon::now()->timestamp,
            ], 1800);
        } elseif (!$isSingle) {
            // Fallback a la previamente seleccionada si existe
            $cachedProp = Cache::get("lyra_selected_property_{$sessionId}");
            if ($cachedProp) {
                $prop = $cachedProp;
                $isSingle = true;
            }
        }

        switch ($pregunta) {

            case 'barrio':
                if ($isSingle && $prop) {
                    $barrio = $this->extractBarrioFromProperty($prop);
                    $titulo = $this->shortenTitle($prop['title'] ?? 'La propiedad');
                    $msg    = $barrio
                        ? $this->personality->onResultBarrio($barrio, $titulo)
                        : $this->personality->onNoBarrioRegistered($prop['address'] ?? 'no disponible');
                } else {
                    $barrios = array_filter(array_map(fn($p) => $this->extractBarrioFromProperty($p), $workingResults));
                    $unique  = array_unique(array_values($barrios));
                    if (!empty($unique)) {
                        $list = implode(', ', array_slice($unique, 0, 5));
                        $msg  = count($unique) === 1
                            ? "Esa propiedad se encuentra en **{$unique[0]}**."
                            : "Las propiedades encontradas están en: **{$list}**.";
                    } else {
                        $msg = "No tengo registrados los barrios de esas propiedades.";
                    }
                }
                break;

            case 'precio':
                if ($isSingle && $prop) {
                    $titulo = $this->shortenTitle($prop['title'] ?? 'La propiedad');
                    $precio = $this->formatPriceText($prop['monthly_price'] ?? 0);

                    // Detectar si es el extremo de los resultados actuales
                    $currentPrices = array_filter(array_column($workingResults, 'monthly_price'));
                    $isMin = !empty($currentPrices) && ($prop['monthly_price'] ?? null) == min($currentPrices);
                    $isMax = !empty($currentPrices) && ($prop['monthly_price'] ?? null) == max($currentPrices);

                if ($isMin && count($workingResults) > 1 && (str_contains(mb_strtolower($text), 'barat') || str_contains(mb_strtolower($text), 'econom'))) {
                    $msg = $this->personality->onCheapestProperty($titulo, $precio);
                } elseif ($isMax && count($workingResults) > 1 && (str_contains(mb_strtolower($text), 'car') || str_contains(mb_strtolower($text), 'costos'))) {
                    $msg = $this->personality->onMostExpensiveProperty($titulo, $precio);
                } else {
                        $msg = $this->personality->onResultPrecio($precio, $titulo);
                    }
                } else {
                    $precios = [];
                    foreach (array_slice($workingResults, 0, 5) as $i => $p) {
                        $num    = $i + 1;
                        $titulo = $this->shortenTitle($p['title'] ?? "Propiedad $num");
                        $precio = $this->formatPriceText($p['monthly_price'] ?? 0);
                        $precios[] = "**{$num}.** $titulo — $precio";
                    }
                    $listado = implode("\n", $precios);
                    $prices = array_filter(array_column($workingResults, 'monthly_price'));
                    $min = !empty($prices) ? min($prices) : 0;
                    $max = !empty($prices) ? max($prices) : 0;
                    
                    $msg = $this->personality->onPriceRangeList(
                        count($workingResults),
                        $listado,
                        $this->formatPriceText($min),
                        $this->formatPriceText($max)
                    );
                }
                break;

            case 'promedio':
                $avg = collect($workingResults)->avg('monthly_price');
                $msg = $this->personality->onResultPromedio($this->formatPriceText($avg), count($workingResults));
                break;

            case 'caracteristicas':
                if ($prop) {
                    $beds    = $prop['num_bedrooms']  ?? null;
                    $baths   = $prop['num_bathrooms'] ?? null;
                    $area    = $prop['area']           ?? null;
                    $svcs    = $prop['included_services'] ?? null;
                    $details = [];
                    if ($beds)  $details[] = $beds == 1  ? "1 habitación"  : "{$beds} habitaciones";
                    if ($baths) $details[] = $baths == 1 ? "1 baño"        : "{$baths} baños";
                    if ($area)  $details[] = "{$area} m²";
                    if ($svcs) {
                        $decoded = is_array($svcs) ? $svcs : json_decode($svcs, true);
                        if (is_array($decoded) && !empty($decoded)) {
                            $details[] = "servicios: " . implode(', ', array_slice($decoded, 0, 4));
                        }
                    }
                    $detailStr = !empty($details) ? implode(', ', $details) : 'información básica no disponible';
                    $titulo    = $prop['title'] ?? 'La propiedad';
                    $msg       = $this->personality->onResultCaracteristicas($titulo, $detailStr);
                } else {
                    $msg = "No tengo suficiente detalle de esa propiedad específica.";
                }
                break;

            case 'recomendacion':
                usort($workingResults, function ($a, $b) {
                    $imgA  = isset($a['images']) ? count($a['images']) : 0;
                    $imgB  = isset($b['images']) ? count($b['images']) : 0;
                    $svcsA = $a['included_services'] ?? null;
                    $svcsB = $b['included_services'] ?? null;
                    $svcA  = !empty($svcsA) ? (is_array($svcsA) ? count($svcsA) : count((array)json_decode($svcsA, true))) : 0;
                    $svcB  = !empty($svcsB) ? (is_array($svcsB) ? count($svcsB) : count((array)json_decode($svcsB, true))) : 0;
                    $scoreA = $imgA * 10 + $svcA * 5 - (($a['monthly_price'] ?? 0) / 500000);
                    $scoreB = $imgB * 10 + $svcB * 5 - (($b['monthly_price'] ?? 0) / 500000);
                    return $scoreB <=> $scoreA;
                });
                $best   = $workingResults[0];
                $titulo = $best['title'] ?? 'Esta propiedad';
                $barrio = $this->extractBarrioFromProperty($best);
                $loc    = $barrio ? " en {$barrio}" : '';
                $rawRec = "**{$titulo}**{$loc} a **" . $this->formatPriceText($best['monthly_price'] ?? 0) . "**.";
                $msg = $this->personality->onRecommendationDetail($titulo, $rawRec);
                break;

            case 'tamaño':
                if ($isSingle && $prop) {
                    $titulo = $prop['title'] ?? 'La propiedad';
                    $area   = $prop['area']  ?? null;
                    $beds   = $prop['num_bedrooms'] ?? null;
                    $msg    = $area
                        ? "**{$titulo}** tiene {$area} m²" . ($beds ? " y {$beds} habitaciones" : "") . "."
                        : "No tengo el área registrada para **{$titulo}**" . ($beds ? ", pero tiene {$beds} habitaciones." : ".");
                } else {
                    usort($workingResults, fn($a, $b) => ($b['area'] ?? 0) <=> ($a['area'] ?? 0));
                    $largest = $workingResults[0];
                    $area    = $largest['area'] ?? null;
                    $titulo  = $largest['title'] ?? 'Una propiedad';
                    $msg     = $area
                        ? "La más grande es **{$titulo}** con {$area} m²."
                        : "No tengo información de área registrada para comparar.";
                }
                break;

            case 'edad':
                if ($prop) {
                    $msg = $this->personality->onPropertyAgeQuestion($prop);
                } else {
                    $msg = "¿De cuál propiedad quieres saber la antigüedad?";
                }
                break;

            case 'estado':
                if ($prop) {
                    $msg = $this->personality->onPropertyConditionQuestion($prop);
                } else {
                    $msg = "¿De cuál propiedad quieres saber el estado?";
                }
                break;

            default:
                if ($isSingle && $prop) {
                    $titulo   = $prop['title'] ?? 'La propiedad';
                    $priceStr = $this->formatPriceText($prop['monthly_price'] ?? 0);
                    $barrio   = $this->extractBarrioFromProperty($prop);
                    $beds     = $prop['num_bedrooms']  ?? null;
                    $baths    = $prop['num_bathrooms'] ?? null;
                    $area     = $prop['area']           ?? null;
                    $svcs     = $prop['included_services'] ?? null;
                    $detalles = [];
                    if ($barrio) $detalles[] = "ubicada en **{$barrio}**";
                    if ($beds)   $detalles[] = $beds == 1  ? "1 habitación"  : "{$beds} habitaciones";
                    if ($baths)  $detalles[] = $baths == 1 ? "1 baño"        : "{$baths} baños";
                    if ($area)   $detalles[] = "{$area} m²";
                    if ($svcs) {
                        $decoded = is_array($svcs) ? $svcs : json_decode(is_string($svcs) ? $svcs : '[]', true);
                        if (is_array($decoded) && !empty($decoded)) {
                            $detalles[] = "incluye: " . implode(', ', array_slice($decoded, 0, 4));
                        } elseif (is_string($svcs) && trim($svcs)) {
                            $detalles[] = "incluye: " . $svcs;
                        }
                    }
                    $detStr = !empty($detalles) ? implode(', ', $detalles) : 'información básica no disponible';
                    $msg = $this->personality->onResultGeneral($titulo, $priceStr, $detStr);
                } else {
                    $count = count($workingResults);
                    if ($count === 1) {
                        $msg = $this->personality->onResultGeneralSingleChoice();
                        if ($propRef) {
                            $msg = $this->personality->onPropertySelected($workingResults[0]);
                            Cache::put("lyra_selected_property_{$sessionId}", $workingResults[0], 1800);
                        }
                    } else {
                        $msg = $this->personality->onResultMultipleQuery($count);
                    }
                }
                break;
        }

        $this->storeHistory($historyKey, $history, $text, $msg);
        return $this->buildResponse($msg, $workingResults, null, [], $intent);
    }

    /**
     * Análisis estadístico del mercado.
     */
    private function handleConsultaAnalitica(
        string $text,
        string $sessionId,
        string $historyKey,
        array  $history,
        array  $intent,
        int    $turnCount = 1
    ): array {
        if (!empty($intent['has_unknown_entity']) && $intent['has_unknown_entity'] === true) {
            $ent = $intent['unknown_entity_name'] ?? 'esa ubicación';
            $msg = "No reconozco '{$ent}' como una ciudad o plataforma en nuestra base de datos. ¿Te refieres a alguna ciudad en Colombia o quieres saber las estadísticas generales de RentUs?";
            $this->storeHistory($historyKey, $history, $text, $msg);
            $intent['needs_clarification'] = true;
            $intent['clarification_question'] = $msg;
            return $this->buildResponse($msg, [], null, [], $intent, true, $msg);
        }

        $city     = $intent['city'] ?? Cache::get("lyra_last_city_{$sessionId}");
        $isGlobal = $intent['global'] ?? false;

        if ($city && !$isGlobal) {
            $query = Property::where('approval_status', 'approved')
                ->where('visibility', 'published')
                ->where('status', 'available')
                ->where('city', 'LIKE', '%' . $city . '%');

            $total = $query->count();

            if ($total === 0) {
                $msg = "No encontré propiedades activas en {$city} en este momento para darte estadísticas.";
                $this->storeHistory($historyKey, $history, $text, $msg);
                return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
            }

            $avg = $query->avg('monthly_price');
            $min = $query->min('monthly_price');
            $max = $query->max('monthly_price');

            $rawText = "En **{$city}** tenemos **{$total} propiedades activas**. El precio promedio es de **"
                . $this->formatPriceText($avg) . "**, con opciones desde "
                . $this->formatPriceText($min) . " hasta " . $this->formatPriceText($max) . ".";

            $msg = $this->personality->onAnalytics($rawText, $city);

            $results   = $query->limit(20)->get()->toArray();
            $mapCenter = $this->locationEngine->findCityCenter($city);
            $mapConfig = $this->mapEngine->buildConfig($mapCenter, $total, $city);

            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, $results, $mapConfig, [], $intent);
        }

        // Stats globales
        $query = Property::where('approval_status', 'approved')
            ->where('visibility', 'published')
            ->where('status', 'available');

        $total = $query->count();

        if ($total === 0) {
            $msg = "Actualmente no tenemos propiedades registradas en la plataforma.";
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        $avg = $query->avg('monthly_price');
        $min = $query->min('monthly_price');
        $max = $query->max('monthly_price');

        $cities = $query->whereNotNull('city')
            ->where('city', '!=', '')
            ->selectRaw('city, COUNT(*) as total')
            ->groupBy('city')
            ->orderByDesc('total')
            ->limit(5)
            ->pluck('total', 'city')
            ->toArray();

        $cityLines = [];
        foreach ($cities as $c => $cnt) $cityLines[] = "{$c} ({$cnt})";
        $cityStr = implode(', ', $cityLines);

        $rawText = "En total RentUs tiene **{$total} propiedades activas**. "
            . "El precio promedio general es de **" . $this->formatPriceText($avg) . "**, "
            . "con opciones desde " . $this->formatPriceText($min) . " hasta " . $this->formatPriceText($max) . ". "
            . "Las ciudades con más oferta son: {$cityStr}.";

        $msg = $this->personality->onAnalytics($rawText, null);

        $this->storeHistory($historyKey, $history, $text, $msg);
        return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
    }

    /**
     * Recomendación inteligente de propiedad.
     */
    private function handleRecommendingProperties(
        string $text,
        string $sessionId,
        string $historyKey,
        array  $history,
        array  $intent,
        int    $turnCount = 1
    ): array {
        $mergedIntent = $this->filterEngine->merge($sessionId, $intent);

        if (empty($mergedIntent['city'])) {
            $msg = $this->personality->onTypeWithoutCity($mergedIntent['property_type'] ?? 'propiedad');
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, true, $msg);
        }

        $radiusKm  = $this->calculateSmartRadius($mergedIntent);
        $mapCenter = $this->locationEngine->resolve($mergedIntent);
        $results   = $this->searchEngine->search($mergedIntent, $mapCenter, $radiusKm);

        if (empty($results)) {
            $rawErr = "No encontré propiedades que coincidan con esas características para recomendarte algo. ¿Buscas en otra zona o flexibilizamos los filtros?";
            $msg    = $this->personality->onRecommendation($rawErr, ['properties' => []]);
            $this->storeHistory($historyKey, $history, $text, $msg);
            $this->filterEngine->clear($sessionId);
            return $this->buildResponse($msg, [], null, $this->extractFilters($mergedIntent), $intent);
        }

        // Ordenar por calidad (fotos + servicios - precio relativo)
        usort($results, function ($a, $b) {
            $svcsA  = $a['included_services'] ?? null;
            $svcsB  = $b['included_services'] ?? null;
            $cntA   = !empty($svcsA) ? (is_array($svcsA) ? count($svcsA) : count(explode(',', $svcsA))) : 0;
            $cntB   = !empty($svcsB) ? (is_array($svcsB) ? count($svcsB) : count(explode(',', $svcsB))) : 0;
            $scoreA = (isset($a['images']) ? count($a['images']) : 0) * 10 + $cntA * 5 - ($a['monthly_price'] / 100000);
            $scoreB = (isset($b['images']) ? count($b['images']) : 0) * 10 + $cntB * 5 - ($b['monthly_price'] / 100000);
            return $scoreB <=> $scoreA;
        });

        $best   = $results[0];
        $barrio = $this->extractBarrioFromProperty($best);
        $ciudad = $best['city'] ?? ($mergedIntent['city'] ?? 'esa zona');
        $beds   = $best['num_bedrooms']  ?? null;
        $baths  = $best['num_bathrooms'] ?? null;
        $area   = $best['area']           ?? null;

        $detalles = [];
        if ($beds)  $detalles[] = "{$beds} hab.";
        if ($baths) $detalles[] = "{$baths} baños";
        if ($area)  $detalles[] = "{$area} m²";
        $detStr = !empty($detalles) ? ' (' . implode(', ', $detalles) . ')' : '';

        $loc    = $barrio ? "en {$barrio}, {$ciudad}" : "en {$ciudad}";
        $rawRec = "**{$best['title']}** {$loc}{$detStr} por **" . $this->formatPriceText($best['monthly_price'] ?? 0) . "**.";

        $msg = $rawRec;

        $this->storeHistory($historyKey, $history, $text, $msg);
        Cache::put("lyra_results_{$sessionId}", $results, 1800);

        $mapConfig = $this->mapEngine->buildConfig($mapCenter, count($results), $mergedIntent['city'] ?? null);
        return $this->buildResponse($msg, $results, $mapConfig, $this->extractFilters($mergedIntent), $intent);
    }

    // ═══════════════════════════════════════════════════════════════
    //  HELPERS PRIVADOS
    // ═══════════════════════════════════════════════════════════════

    private function prepareContext(string $sessionId): array
    {
        return [
            'ultimo_intent'      => Cache::get("lyra_last_intent_{$sessionId}"),
            'ultima_ciudad'      => Cache::get("lyra_last_city_{$sessionId}"),
            'ultimos_resultados' => Cache::get("lyra_results_{$sessionId}", []),
            'accion_pendiente'   => Cache::get("lyra_pending_action_{$sessionId}"),
            'historial'          => Cache::get("lyra_history_{$sessionId}", []),
            'filtros_acumulados' => Cache::get("lyra_filters_{$sessionId}", []),
        ];
    }

    private function storeHistory(string $key, array $history, string $userText, string $assistantText): void
    {
        $history[] = ['role' => 'user',      'content' => $userText];
        $history[] = ['role' => 'assistant', 'content' => $assistantText];
        Cache::put($key, array_slice($history, -20), 3600); // subir de 10 a 20

        // Contador global de turnos — nunca se trunca
        $counterKey = str_replace('lyra_history_', 'lyra_turns_', $key);
        Cache::put($counterKey, Cache::get($counterKey, 0) + 1, 3600);
    }

    private function handlePropertySelection(
        string $text,
        string $sessionId,
        string $historyKey,
        array  $history,
        array  $intent,
        int    $turnCount = 1
    ): array {
        $lastResults = Cache::get("lyra_results_{$sessionId}", []);

        if (empty($lastResults)) {
            $msg = $this->personality->onSelectionWithoutResults();
            $this->storeHistory($historyKey, $history, $text, $msg);
            return $this->buildResponse($msg, [], null, [], $intent, false, null, $turnCount);
        }

        // Determinar qué propiedad seleccionó
        $propRef      = $intent['propiedad_referenciada'] ?? null;
        $selectedProp = null;

        if ($propRef) {
            $filtered     = $this->filterByPropertyReference($lastResults, $propRef);
            $selectedProp = $filtered[0] ?? null;
        }

        if (!$selectedProp) {
            // Default: la seleccionada, la recomendada, o la primera
            $selectedProp = Cache::get("lyra_selected_property_{$sessionId}") ?? Cache::get("lyra_recommended_property_{$sessionId}", $lastResults[0] ?? null);
        }

        if (!$selectedProp && !empty($lastResults)) {
            $selectedProp = $lastResults[0];
        }

        // Guardar propiedad seleccionada
        Cache::put("lyra_selected_property_{$sessionId}", $selectedProp, 1800);

        // GUARDAR ESTADO — Lyra ya hizo la pregunta, no debe repetirla
        Cache::put("lyra_post_selection_{$sessionId}", [
            'property_id' => $selectedProp['id'] ?? null,
            'asked_at'    => Carbon::now()->timestamp,
        ], 1800);

        $msg = $this->personality->onPropertySelected($selectedProp ?: []);
        $this->storeHistory($historyKey, $history, $text, $msg);
        return $this->buildResponse($msg, $selectedProp ? [$selectedProp] : [], null, [], $intent, false, null, $turnCount);
    }

    private function extractDateFromText(string $text): string
    {
        // Frases relativas (prioridad alta)
        if (str_contains($text, 'pasado mañana'))   return 'pasado mañana';
        if (str_contains($text, 'mañana'))          return 'mañana';
        if (str_contains($text, 'hoy'))             return 'hoy';
        if (str_contains($text, 'esta semana'))     return 'esta semana';
        if (str_contains($text, 'fin de semana'))   return 'este fin de semana';

        // Días de la semana
        $dias = [
            'lunes', 'martes', 'miércoles', 'miercoles',
            'jueves', 'viernes', 'sábado', 'sabado', 'domingo'
        ];
        foreach ($dias as $dia) {
            if (str_contains($text, $dia)) return "el $dia";
        }

        // "10 de marzo", "10 de marzo del 2026", "5 de enero"
        $meses = [
            'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'
        ];
        foreach ($meses as $mes) {
            if (preg_match('/(\d{1,2})\s+de\s+' . $mes . '/ui', $text, $m)) {
                return $m[1] . ' de ' . $mes;
            }
        }

        // "10/03" o "10-03"
        if (preg_match('/(\d{1,2})[\/\-](\d{1,2})/u', $text, $m)) {
            return $m[1] . '/' . $m[2];
        }

        return '';
    }

    private function extractFilters(array $intent): array
    {
        $applied = [];
        foreach (['city', 'neighborhood', 'property_type', 'max_price', 'min_price', 'bedrooms', 'bathrooms', 'zone'] as $key) {
            if (!empty($intent[$key])) $applied[$key] = $intent[$key];
        }
        return $applied;
    }

    /**
     * Extrae hora de texto natural en español.
     */
    private function extractTimeFromText(string $text): string
    {
        // ── 1. "a las 8 de la mañana", "a las 10:30 de la tarde" ──
        if (preg_match('/a las\s+(\d{1,2}(?::\d{2})?)\s*(?:am|pm|de la mañana|de la tarde|de la noche|en la mañana|en la tarde|en la noche)/ui', $text, $m)) {
            return $m[0];
        }
        // ── 2. "a las 8", "a las 10:30" ──────────────────────────
        if (preg_match('/a las\s+(\d{1,2}(?::\d{2})?)/ui', $text, $m)) {
            return $m[0];
        }
        // ── 3. "8 de la mañana", "3 de la tarde", "8 en la mañana"
        if (preg_match('/(\d{1,2})\s+(?:de|en)\s+la\s+(mañana|tarde|noche)/ui', $text, $m)) {
            return $m[0];
        }
        // ── 4. "por la tarde", "por la mañana" ───────────────────
        if (preg_match('/por la\s+(mañana|tarde|noche)/ui', $text, $m)) {
            return $m[0];
        }
        // ── 5. "8am", "10pm", "8 am" ──────────────────────────────
        if (preg_match('/(\d{1,2}(?::\d{2})?)\s*(?:am|pm)/ui', $text, $m)) {
            return $m[0];
        }
        return '';
    }

    /**
     * Crea la solicitud de visita en la base de datos.
     */
    public function createVisitRequest(string $sessionId, ?array $prop, string $fecha, string $hora): void
    {
        if (!$prop || empty($prop['id'])) {
            Log::warning('[LyraBrain] createVisitRequest: propiedad vacía o sin ID');
            return;
        }

        try {
            $parsedDate = $this->parseNaturalDate($fecha);
            $parsedTime = $this->parseNaturalTime($hora);

            $userId  = auth('api')->id();
            $ownerId = $prop['user_id'] ?? null;

            Log::info('[LyraBrain] createVisitRequest: preparando', [
                'property_id' => $prop['id'],
                'user_id'     => $userId,
                'owner_id'    => $ownerId,
                'fecha_raw'   => $fecha,
                'hora_raw'    => $hora,
                'parsed_date' => $parsedDate,
                'parsed_time' => $parsedTime,
            ]);

            if (!$parsedDate) {
                Log::warning('[LyraBrain] createVisitRequest: no se pudo parsear la fecha', ['fecha' => $fecha]);
                return;
            }

            if (!$userId) {
                Log::warning('[LyraBrain] createVisitRequest: usuario no autenticado');
                return;
            }

            if (!$ownerId) {
                // Intentar obtener el owner desde la BD directamente
                $ownerId = \App\Models\Property::where('id', $prop['id'])->value('user_id');
                Log::info('[LyraBrain] createVisitRequest: owner_id obtenido de BD', ['owner_id' => $ownerId]);
            }

            if (!$ownerId) {
                Log::warning('[LyraBrain] createVisitRequest: no se encontró owner_id');
                return;
            }

            \App\Models\RentalRequest::create([
                'property_id'    => $prop['id'],
                'user_id'        => $userId,
                'owner_id'       => $ownerId,
                'requested_date' => $parsedDate,
                'requested_time' => $parsedTime ?: '10:00:00',
                'status'         => 'pending',
            ]);

            Log::info('[LyraBrain] ✅ Visita creada exitosamente', [
                'property_id' => $prop['id'],
                'user_id'     => $userId,
                'owner_id'    => $ownerId,
                'date'        => $parsedDate,
                'time'        => $parsedTime,
            ]);
        } catch (\Exception $e) {
            Log::error('[LyraBrain] ❌ Error creando visita', [
                'error'       => $e->getMessage(),
                'property_id' => $prop['id'] ?? 'N/A',
                'trace'       => $e->getTraceAsString(),
            ]);
        }
    }

    private function parseNaturalDate(string $text): ?string
    {
        $today   = now();
        $textLow = mb_strtolower($text);

        if (str_contains($textLow, 'hoy'))           return $today->format('Y-m-d');
        if (str_contains($textLow, 'mañana'))        return $today->copy()->addDay()->format('Y-m-d');
        if (str_contains($textLow, 'pasado mañana')) return $today->copy()->addDays(2)->format('Y-m-d');

        $days = [
            'lunes' => 1, 'martes' => 2, 'miércoles' => 3, 'miercoles' => 3,
            'jueves' => 4, 'viernes' => 5, 'sábado' => 6, 'sabado' => 6, 'domingo' => 7,
        ];
        foreach ($days as $day => $num) {
            if (str_contains($textLow, $day)) {
                $currentDay = $today->dayOfWeekIso;
                $diff       = ($num - $currentDay + 7) % 7 ?: 7;
                return $today->copy()->addDays($diff)->format('Y-m-d');
            }
        }

        if (preg_match('/(\d{1,2})\s*(?:de\s+(\w+))?/u', $textLow, $m)) {
            $day   = (int) $m[1];
            $month = isset($m[2]) ? $this->spanishMonthToNumber($m[2]) : $today->month;
            $year  = $today->year;
            if ($day > 0 && $day <= 31 && $month > 0) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return null;
    }

    private function parseNaturalTime(string $text): ?string
    {
        if (empty($text)) return null;
        $textLow = mb_strtolower($text);
        
        // ── Defaults para expresiones sin número ─────────────────
        if (str_contains($textLow, 'por la mañana')) return '09:00:00';
        if (str_contains($textLow, 'por la tarde'))  return '14:00:00';
        if (str_contains($textLow, 'por la noche'))  return '19:00:00';

        if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm|de|en)?\s*(?:la)?\s*(mañana|tarde|noche)?/ui', $textLow, $m)) {
            $hour   = (int)$m[1];
            $min    = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
            $periodIndicator = mb_strtolower($m[4] ?? '');
            $amPm            = mb_strtolower($m[3] ?? '');
            
            if ($amPm === 'pm' || str_contains($periodIndicator, 'tarde') || str_contains($periodIndicator, 'noche')) {
                if ($hour < 12) $hour += 12;
            } elseif ($amPm === 'am' || str_contains($periodIndicator, 'mañana')) {
                if ($hour === 12) $hour = 0;
            } else {
                // Heurística: Si la hora es 1-6 y no hay periodo, asumir tarde (13:00-18:00)
                if ($hour >= 1 && $hour <= 6) {
                    $hour += 12;
                }
            }
            
            return sprintf('%02d:%02d:00', $hour, $min);
        }
        
        return null;
    }

    private function extractBarrioFromProperty(array $prop): ?string
    {
        if (!empty($prop['neighborhood']) && trim($prop['neighborhood']) !== '') {
            return ucwords(mb_strtolower(trim($prop['neighborhood'])));
        }
        if (!empty($prop['address'])) {
            $address = trim($prop['address']);
            if (preg_match('/^([^,#]+),/u', $address, $m)) {
                $candidate = trim($m[1]);
                if (!preg_match('/^(?:calle|carrera|avenida|av\.|cra|cl|transversal|diagonal)\s/ui', $candidate)) {
                    return ucwords(mb_strtolower($candidate));
                }
            }
            if (preg_match('/barrio\s+([A-Za-záéíóúñÁÉÍÓÚÑ\s]+?)(?:\s*,|\s*$)/ui', $address, $m)) {
                return ucwords(mb_strtolower(trim($m[1])));
            }
        }
        return null;
    }

    private function shortenTitle(string $title): string
    {
        $words = explode(' ', $title);
        if (count($words) <= 5) return $title;
        return implode(' ', array_slice($words, 0, 5)) . '...';
    }

    private function applyFilters(\Illuminate\Database\Eloquent\Builder $query, array $filters): bool
    {
        $appliedAny = false;

        if (!empty($filters['property_type'])) {
            $query->where('property_type', $filters['property_type']);
            $appliedAny = true;
        }
        if (!empty($filters['min_price'])) {
            $query->where('monthly_price', '>=', $filters['min_price']);
            $appliedAny = true;
        }
        if (!empty($filters['max_price'])) {
            $query->where('monthly_price', '<=', $filters['max_price']);
            $appliedAny = true;
        }
        if (!empty($filters['bedrooms'])) {
            $query->where('bedrooms', $filters['bedrooms']);
            $appliedAny = true;
        }
        if (!empty($filters['bathrooms'])) {
            $query->where('bathrooms', $filters['bathrooms']);
            $appliedAny = true;
        }
        if (!empty($filters['city'])) {
            $query->where('city', 'LIKE', '%' . $filters['city'] . '%');
            $appliedAny = true;
        }
        if (!empty($filters['neighborhood'])) {
            $query->where('neighborhood', 'LIKE', '%' . $filters['neighborhood'] . '%');
            $appliedAny = true;
        }

        return $appliedAny;
    }

    private function filterByPropertyReference(array $results, string $ref): array
    {
        $refLower = mb_strtolower($ref);

        if (str_contains($refLower, 'primera'))  return isset($results[0]) ? [$results[0]] : [];
        if (str_contains($refLower, 'segunda'))  return isset($results[1]) ? [$results[1]] : [];
        if (str_contains($refLower, 'tercera'))  return isset($results[2]) ? [$results[2]] : [];
        if (str_contains($refLower, 'cuarta'))   return isset($results[3]) ? [$results[3]] : [];
        if (str_contains($refLower, 'quinta'))   return isset($results[4]) ? [$results[4]] : [];

        if (str_contains($refLower, 'barat') || str_contains($refLower, 'económ')) {
            usort($results, fn($a, $b) => ($a['monthly_price'] ?? 0) <=> ($b['monthly_price'] ?? 0));
            return isset($results[0]) ? [$results[0]] : [];
        }
        if (str_contains($refLower, 'car') || str_contains($refLower, 'costos')) {
            usort($results, fn($a, $b) => ($b['monthly_price'] ?? 0) <=> ($a['monthly_price'] ?? 0));
            return isset($results[0]) ? [$results[0]] : [];
        }
        if (str_contains($refLower, 'grand')) {
            usort($results, fn($a, $b) => ($b['area'] ?? 0) <=> ($a['area'] ?? 0));
            return isset($results[0]) ? [$results[0]] : [];
        }
        if (str_contains($refLower, 'pequeñ')) {
            usort($results, fn($a, $b) => ($a['area'] ?? 0) <=> ($b['area'] ?? 0));
            return isset($results[0]) ? [$results[0]] : [];
        }

        preg_match('/(\d+(?:[.,]\d+)?)/u', $refLower, $numMatch);
        $refNumString = !empty($numMatch[1]) ? $numMatch[1] : null;
        $refNum = null;
        
        if ($refNumString !== null) {
            // Lógica similar a parseNumber del Interpreter
            if (str_contains($refNumString, '.') && !str_contains($refNumString, ',')) {
                if (preg_match('/^\d+\.\d{1,2}$/', $refNumString)) {
                    $refNum = (float) $refNumString;
                }
            }
            
            if ($refNum === null) {
                $refNum = (float) str_replace(['.', ','], ['', '.'], $refNumString);
            }
        }

        if ($refNum !== null) {
            $filtered = array_filter($results, function ($p) use ($refNum) {
                $price  = (float)($p['monthly_price'] ?? 0);
                $priceM = $price / 1000000;
                if ($refNum < 100) return abs($priceM - $refNum) / max($priceM, 0.001) <= 0.08;
                return abs($price - $refNum) / max($price, 1) <= 0.08;
            });
            if (!empty($filtered)) return array_values($filtered);
        }

        $filtered = array_filter($results, fn($p) => str_contains(mb_strtolower($p['title'] ?? ''), $refLower));
        return !empty($filtered) ? array_values($filtered) : [];
    }

    private function resolveNeighborhoodAmbiguity(string $neighborhood): array
    {
        $cities = Property::where('approval_status', 'approved')
            ->where('visibility', 'published')
            ->where('status', 'available')
            ->where('address', 'LIKE', '%' . $neighborhood . '%')
            ->distinct()
            ->pluck('city')
            ->filter()
            ->values()
            ->toArray();

        return ['count' => count($cities), 'cities' => $cities];
    }

    private function formatPriceText(float $price): string
    {
        return $this->personality->formatPriceForDisplay($price);
    }

    private function calculateDistance($lat1, $lon1, $lat2, $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function pluralize(string $type, int $count = 2): string
    {
        if ($count === 1) return $type;
        $wordMap = [
            'casa'          => 'casas',
            'apartamento'   => 'apartamentos',
            'apto'          => 'apartamentos',
            'apartaestudio' => 'apartaestudios',
            'estudio'       => 'estudios',
            'propiedad'     => 'propiedades',
            'finca'         => 'fincas',
            'local'         => 'locales',
            'oficina'       => 'oficinas',
            'bodega'        => 'bodegas',
            'habitacion'    => 'habitaciones',
            'habitación'    => 'habitaciones',
            'lote'          => 'lotes',
            'terreno'       => 'terrenos',
            'penthouse'     => 'penthouses',
        ];
        return $wordMap[$type] ?? $type . 's';
    }

    private function getArticle(string $type, int $count): string
    {
        $feminine = ['casa', 'finca', 'bodega', 'oficina', 'propiedad'];
        $isFem    = in_array(mb_strtolower($type), $feminine);
        return $count === 1 ? ($isFem ? 'una' : 'un') : '';
    }

    private function cleanReferencePlace(string $ref): string
    {
        $ref = preg_replace('/^(el|la|los|las)\s+/ui', '', trim($ref));
        return mb_strtolower($ref);
    }

    private function handleClosestCity(array &$intent): string
    {
        $referenceCity = $intent['city'] ?? null;
        if (!$referenceCity) {
            return "No estoy segura de qué ciudad tomar como referencia. ¿Puedes decirme cerca de dónde buscamos?";
        }
        $refCoords = $this->locationEngine->findCityCenter($referenceCity);
        if (!$refCoords) {
            return "No tengo las coordenadas de {$referenceCity}. ¿Me dices otra cercana?";
        }
        $availableCities = Property::where('approval_status', 'approved')
            ->where('visibility', 'published')
            ->where('status', 'available')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->selectRaw('city, count(*) as total')
            ->groupBy('city')
            ->orderByDesc('total')
            ->get();

        $closestCity  = null;
        $closestDist  = PHP_FLOAT_MAX;
        $closestCount = 0;

        foreach ($availableCities as $ac) {
            $cityCoords = $this->locationEngine->findCityCenter($ac->city);
            if ($cityCoords && mb_strtolower($ac->city) !== mb_strtolower($referenceCity)) {
                $dist = $this->calculateDistance($refCoords['lat'], $refCoords['lng'], $cityCoords['lat'], $cityCoords['lng']);
                if ($dist < $closestDist) {
                    $closestDist  = $dist;
                    $closestCity  = $ac->city;
                    $closestCount = $ac->total;
                }
            }
        }

        if ($closestCity) {
            $intent['city'] = $closestCity;
            return "La ciudad más cercana a {$referenceCity} donde tenemos propiedades disponibles es {$closestCity} con {$closestCount} propiedades. ¿Te las muestro?";
        }
        return "Lo siento, no tenemos otras ciudades con propiedades disponibles actualmente.";
    }

    private function getClosestAvailableCityToCoordinates(float $lat, float $lng): ?string
    {
        $availableCities = Property::where('approval_status', 'approved')
            ->where('visibility', 'published')
            ->where('status', 'available')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->selectRaw('city, count(*) as total')
            ->groupBy('city')
            ->orderByDesc('total')
            ->get();

        $closestCity  = null;
        $closestDist  = PHP_FLOAT_MAX;

        foreach ($availableCities as $ac) {
            $cityCoords = $this->locationEngine->findCityCenter($ac->city);
            if ($cityCoords) {
                $dist = $this->calculateDistance($lat, $lng, $cityCoords['lat'], $cityCoords['lng']);
                if ($dist < $closestDist) {
                    $closestDist = $dist;
                    $closestCity = $ac->city;
                }
            }
        }

        return $closestCity;
    }

    private function getClosestCityWithResults(float $lat, float $lng, array $baseIntent): array
    {
        $closestCity = $this->getClosestAvailableCityToCoordinates($lat, $lng);
        if (!$closestCity) return [];

        $suggestionIntent = $baseIntent;
        $suggestionIntent['city'] = $closestCity;
        // Clean location filters for the new city
        unset($suggestionIntent['neighborhood'], $suggestionIntent['zone'], $suggestionIntent['reference_place'], $suggestionIntent['street'], $suggestionIntent['map_lat'], $suggestionIntent['map_lng']);
        
        $mapCenter = $this->locationEngine->findCityCenter($closestCity);
        $results   = $this->searchEngine->search($suggestionIntent, $mapCenter, 20);
        
        return [
            'city'       => $closestCity,
            'results'    => $results,
            'intent'     => $suggestionIntent,
            'map_center' => $mapCenter
        ];
    }

    private function getAdjacentCityWithResults(string $city, array $baseIntent): array
    {
        $adjacentCities = $this->getAdjacentCities($city);
        foreach ($adjacentCities as $adjCity) {
            $suggestionIntent = $baseIntent;
            $suggestionIntent['city'] = $adjCity;
            // Clean location filters for the new city
            unset($suggestionIntent['neighborhood'], $suggestionIntent['zone'], $suggestionIntent['reference_place'], $suggestionIntent['street']);
            
            $mapCenter = $this->locationEngine->findCityCenter($adjCity);
            $results   = $this->searchEngine->search($suggestionIntent, $mapCenter, 20);
            
            if (count($results) > 0) {
                return [
                    'city'       => $adjCity,
                    'results'    => $results,
                    'intent'     => $suggestionIntent,
                    'map_center' => $mapCenter
                ];
            }
        }
        return [];
    }

    private function getDefaultResponse(string $intentType): string
    {
        return match ($intentType) {
            'saludo'            => '¡Hola! Soy Lyra, tu asistente inmobiliaria en RentUs. ¿En qué ciudad buscas y qué tipo de propiedad necesitas?',
            'preguntar_rentus'  => 'RentUs es una plataforma inmobiliaria latinoamericana donde puedes buscar casas, apartamentos, locales y más. ¿Qué tipo de propiedad buscas?',
            default             => 'Solo puedo ayudarte con temas inmobiliarios y de RentUs. ¿Buscas alguna propiedad en particular?',
        };
    }

    /**
     * Detecta si el usuario quiere ver los detalles/ficha de la propiedad.
     */
    private function wantsPropertyDetails(string $text): bool
    {
        $patterns = [
            'quiero verla',
            'quiero verlo',
            'ver la propiedad',
            'ver el inmueble',
            'ver más detalles',
            'ver mas detalles',
            'ver detalles',
            'más información',
            'mas informacion',
            'cuéntame más',
            'cuéntame mas',
            'dime más',
            'dime mas',
            'quiero saber más',
            'mas sobre esta',
            'ver esta propiedad',
            'mostrar detalles',
            'abrir la propiedad',
            'ver la ficha',
            'revisar la propiedad',
            'la revisemos',
            'revisemos juntos',
            'revisémosla',
            'ver revisemos',
            'ver la revisemos',
            'vamos a verla',
            'vamos a verlo',
            'ir a verla',
            'ir a verlo',
        ];

        $textLower = mb_strtolower($text);
        foreach ($patterns as $pattern) {
            if (str_contains($textLower, $pattern)) return true;
        }
        return false;
    }

    /**
     * Detecta si el usuario quiere agendar/solicitar una visita formal.
     */
    private function wantsScheduleVisit(string $text): bool
    {
        $patterns = [
            'agendar',
            'agenda',
            'agendemos',
            'pedir cita',
            'pedir una cita',
            'solicitar visita',
            'solicitar una visita',
            'hacer una cita',
            'quiero una cita',
            'hacer cita',
            'cita con',
            'programar visita',
            'programar una visita',
            'reservar visita',
            'reservar una visita',
            'contactar al dueño',
            'contactar propietario',
            'hablar con el dueño',
            'hablar con el propietario',
        ];

        $textLower = mb_strtolower($text);
        foreach ($patterns as $pattern) {
            if (str_contains($textLower, $pattern)) return true;
        }
        return false;
    }

    /**
     * Fallback para compatibilidad — buildSmartResponse es el método principal.
     * Solo se llama si hay un path no cubierto arriba.
     */
    private function buildSuccessResponse(array $intent, int $count): string
    {
        $city    = $intent['city']          ?? null;
        $type    = $intent['property_type'] ?? null;
        $filters = $this->extractFilters($intent);

        if ($count === 0) {
            return $this->personality->onNoResults($city, $type, '', $filters);
        }
        if ($count === 1) {
            return $this->personality->onSearchSuccess(1, $city, $type);
        }
        if ($count <= 5) {
            return $this->personality->onFewResults($count, $city, $type, $filters);
        }
        return $this->personality->onManyResults($count, $city, $type, $filters);
    }
    private function spanishMonthToNumber(string $month): int
    {
        $months = [
            'enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,
            'julio'=>7,'agosto'=>8,'septiembre'=>9,'octubre'=>10,'noviembre'=>11,'diciembre'=>12
        ];
        return $months[mb_strtolower($month)] ?? 0;
    }

    private function buildResponse(
        string  $text,
        array   $properties = [],
        ?array  $mapConfig = null,
        array   $filters = [],
        array   $intent = [],
        bool    $needsClarification = false,
        ?string $clarificationQuestion = null,
        int     $turnCount = 1,
        array   $extraData = []
    ): array {
        $speechText = preg_replace('/\$[\d.,]+\s*pesos/ui', '', $text);
        $speechText = preg_replace('/\s{2,}/', ' ', trim($speechText));

        $tempResponse = [
            'success'                => true,
            'response_text'          => $text,
            'response_speech'        => $speechText ?: $text,
            'properties'             => $properties,
            'map_center'             => $mapConfig,
            'filters_applied'        => $filters,
            'needs_clarification'    => $needsClarification,
            'clarification_question' => $clarificationQuestion,
            'intent'                 => $intent,
            'agent_name'             => 'Lyra',
            'zone'                   => $intent['zone'] ?? null,
            'context'                => [
                'turnCount' => $turnCount,
                'user_lat'  => $this->currentUserLat,
                'user_lng'  => $this->currentUserLng,
            ]
        ];

        // Generar chips dinámicos
        $tempResponse['suggested_chips'] = $this->personality->getSuggestedChips(array_merge($tempResponse, $extraData));

        return array_merge($tempResponse, $extraData);
    }

    private function calculateSmartRadius(array $intent): float
    {
        if (!empty($intent['radius_km']) && (float)$intent['radius_km'] > 5) {
            return (float)$intent['radius_km'];
        }
        if (!empty($intent['neighborhood']))    return 3.0;
        if (!empty($intent['reference_place'])) return 6.0;
        if (!empty($intent['city'])) {
            $bigCities = ['Bogotá', 'Bogota', 'Medellín', 'Medellin', 'Cali', 'Barranquilla', 'Cartagena', 'Bucaramanga', 'Cúcuta', 'Cucuta'];
            foreach ($bigCities as $big) {
                if (stripos($intent['city'], $big) !== false) return 30.0;
            }
            return 20.0;
        }
        return 15.0;
    }

    private function getAdjacentCities(string $city): array
    {
        $map = [
            'bogota'       => ['Soacha', 'Chía', 'Zipaquirá', 'Facatativá', 'Mosquera', 'Funza'],
            'medellin'     => ['Bello', 'Envigado', 'Itagüí', 'Sabaneta', 'Rionegro', 'Copacabana'],
            'cali'         => ['Palmira', 'Yumbo', 'Jamundí', 'Buga', 'Candelaria'],
            'barranquilla' => ['Soledad', 'Malambo', 'Puerto Colombia', 'Galapa'],
            'cartagena'    => ['Turbaco', 'Arjona', 'Santa Rosa'],
            'bucaramanga'  => ['Floridablanca', 'Piedecuesta', 'Girón', 'Lebrija'],
            'santa marta'  => ['Ciénaga', 'Aracataca', 'Fundación'],
            'pereira'      => ['Dosquebradas', 'La Virginia', 'Santa Rosa de Cabal'],
            'manizales'    => ['Villamaría', 'Chinchiná', 'Neira'],
            'popayan'      => ['Timbío', 'El Tambo', 'Cajibío', 'Piendamó', 'Santander de Quilichao'],
        ];

        $normalized = mb_strtolower(trim($city));
        return $map[$normalized] ?? [];
    }

    private function isLocationIntent(string $text): bool
    {
        $patterns = ['ubicacion', 'ubicación', 'donde estoy', 'donde me encuentro', 'cerca a mi', 'cerca de mi', ' gps', 'mi zona', 'mi sector'];
        foreach ($patterns as $p) {
            if (str_contains($text, $p)) return true;
        }
        return false;
    }

    private function isSearchIntent(string $text): bool
    {
        $filters = ['casa', 'apartamento', 'apto', 'precio', 'habitación', 'baño', 'barrio', 'zona', ' ciudad', 'buscar', 'encuentra', 'ver '];
        foreach ($filters as $f) {
            if (str_contains($text, $f)) return true;
        }
        return false;
    }

    private function isAffirmation(string $text): bool
    {
        $text = mb_strtolower(trim($text));
        $affirmations = ['si', 'sí', 'claro', 'dale', 'ok', 'bueno', 'listo', 'activo', 'hazlo', 'venga', 'por supuesto', 'de acuerdo', 'quiero'];
        foreach ($affirmations as $a) {
            if ($text === $a || (strlen($text) > strlen($a) && (str_contains($text, ' ' . $a) || str_contains($text, $a . ' ')))) return true;
        }
        return false;
    }

    private function isNegation(string $text): bool
    {
        $text = mb_strtolower(trim($text));
        $negations = ['no', 'nopes', 'nunca', 'jamás', 'jamás', 'mejor no', 'para', 'cancela', 'cancelar', 'olvídalo', 'olvidalo'];
        foreach ($negations as $n) {
            if ($text === $n || (strlen($text) > strlen($n) && (str_contains($text, ' ' . $n) || str_contains($text, $n . ' ')))) return true;
        }
        return false;
    }

    public function executeDirectAction(string $action, array $payload, string $sessionId): array
    {
        Log::info('[LyraBrain] executeDirectAction', ['action' => $action, 'sessionId' => $sessionId]);

        if ($action === 'agendar_visita') {
            $prop  = $payload['property'] ?? null;
            $fecha = $payload['fecha'] ?? '';
            $hora  = $payload['hora'] ?? '';

            $this->createVisitRequest($sessionId, $prop, $fecha, $hora);

            // ── NUEVO: Limpiar contexto guardado tras ejecución exitosa ──
            Cache::forget("lyra_pending_visit_{$sessionId}");
            Cache::forget("lyra_awaiting_visit_datetime_{$sessionId}");

            $title = $prop['title'] ?? 'esa propiedad';
            $msg   = $this->personality->onVisitRequestSent($title, $fecha, $hora);

            return $this->buildResponse($msg, $prop ? [$prop] : [], null, [], [], false, null, 1, [
                'voice_ready' => true,
                'voice_action' => null // Clear any pending actions
            ]);
        }

        return $this->buildResponse("Acción no reconocida.", [], null, [], [], true);
    }
}
