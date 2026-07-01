<?php

namespace App\Services;

/**
 * LyraVoicePersonality — El alma de Lyra.
 *
 * Personalidad: cálida + inteligente + pícara + profesional, todo al tiempo.
 * Registro: neutro latinoamericano, sin regionalismos forzados.
 * Filosofía: nunca robótica, nunca repetitiva, nunca aduladora.
 *            Tiene opinión propia, curiosidad genuina, humor sutil.
 *            Sabe que es IA y no le da pena serlo.
 *            Cuando se equivoca, lo reconoce con gracia y corrige.
 *            Recuerda el nombre del usuario si lo menciona.
 */
class LyraVoicePersonality
{
    // ═══════════════════════════════════════════════════════════════
    //  ESTADO DE SESIÓN (nombre del usuario, si lo menciona)
    // ═══════════════════════════════════════════════════════════════

    private ?string $userName  = null;
    private int     $turnCount = 1;

    /**
     * Sincroniza el turno actual antes de generar respuestas directas.
     * Llamar desde LyraBrainService antes de cualquier método de personalidad.
     */
    public function setTurnCount(int $turnCount): void
    {
        $this->turnCount = $turnCount;
    }

    // Trust Levels constants
    const LEVEL_FORMAL   = 'FORMAL';   // turn 1-2
    const LEVEL_CALIDA   = 'CALIDA';   // turn 3-5
    const LEVEL_COMPLICE = 'COMPLICE'; // turn 6-9
    const LEVEL_JARVIS   = 'JARVIS';   // turn 10+

    private function getTrustLevel(): string
    {
        if ($this->turnCount <= 2) return self::LEVEL_FORMAL;
        if ($this->turnCount <= 5) return self::LEVEL_CALIDA;
        if ($this->turnCount <= 9) return self::LEVEL_COMPLICE;
        return self::LEVEL_JARVIS;
    }

    // ═══════════════════════════════════════════════════════════════
    //  ENTRADA PRINCIPAL
    // ═══════════════════════════════════════════════════════════════

    public function humanize(array $brainResult, string $originalText = ''): array
    {
        // Si el Brain ya generó la respuesta con personalidad (Bloques 0-A/0-B/0-C),
        // no re-rutear — solo convertir para speech.
        if (!empty($brainResult['voice_ready'])) {
            $brainResult['response_text'] = preg_replace('/[\*_]+/u', '', $brainResult['response_text'] ?? '');
            $brainResult['response_speech'] = $this->convertToSpeech($brainResult['response_text'] ?? '');
            return $brainResult;
        }

        $intentData = $brainResult['intent'] ?? [];
        $intent     = is_array($intentData)
            ? ($intentData['intent'] ?? 'buscar_propiedad')
            : 'buscar_propiedad';

        $count      = count($brainResult['properties'] ?? []);
        $city       = $intentData['city']          ?? null;
        $type       = $intentData['property_type'] ?? null;
        $rawText    = $brainResult['response_text'] ?? '';
        $needsClari = $brainResult['needs_clarification'] ?? false;
        $filters    = $brainResult['filters_applied'] ?? [];

        // Extraer turnCount del contexto del brain si existe
        if (isset($brainResult['context']['turnCount'])) {
            $this->turnCount = (int)$brainResult['context']['turnCount'];
        }

        // Extraer nombre del usuario si lo menciona
        $this->detectUserName($originalText);

        // Detectar placeholders de intents no-búsqueda
        if ($rawText === '__SALUDO__')   $intent = 'saludo';
        if ($rawText === '__RENTUS__')   $intent = 'preguntar_rentus';
        if ($rawText === '__OFFTOPIC__') $intent = 'fuera_de_contexto';

        // Limpiar placeholder
        if (str_starts_with($rawText, '__') && str_ends_with($rawText, '__')) {
            $rawText = '';
        }

        $humanText = $this->route(
            $intent,
            $count,
            $city,
            $type,
            $rawText,
            $needsClari,
            $brainResult,
            $originalText,
            $filters
        );

        $humanText = preg_replace('/[\*_]+/u', '', $humanText);

        $brainResult['response_text']   = $humanText;
        $brainResult['response_speech'] = $this->convertToSpeech($humanText);

        return $brainResult;
    }

    public function humanizeText(
        string $intent,
        array $properties = [],
        ?string $city = null,
        ?string $type = null,
        string $rawText = '',
        bool $needsClari = false,
        string $originalText = ''
    ): string {
        $fakeResult = [
            'intent'              => ['intent' => $intent, 'city' => $city, 'property_type' => $type],
            'properties'          => $properties,
            'response_text'       => $rawText,
            'needs_clarification' => $needsClari,
            'filters_applied'     => [],
        ];
        return $this->route($intent, count($properties), $city, $type, $rawText, $needsClari, $fakeResult, $originalText, []);
    }

    // ═══════════════════════════════════════════════════════════════
    //  DETECCIÓN DE NOMBRE
    // ═══════════════════════════════════════════════════════════════

    private function detectUserName(string $text): void
    {
        // "me llamo X", "soy X", "mi nombre es X"
        if (preg_match('/(?:me llamo|soy|mi nombre es)\s+([A-ZÁÉÍÓÚ][a-záéíóú]{2,15})/u', $text, $m)) {
            $this->userName = $m[1];
        }
    }

    private function withName(string $text): string
    {
        if ($this->userName && mt_rand(0, 2) === 0) {
            // Solo usar el nombre de vez en cuando, no siempre
            return str_replace(['¿', '!'], ["¿{$this->userName}, ", "!{$this->userName}, "], $text, $count);
        }
        return $text;
    }

    // ═══════════════════════════════════════════════════════════════
    //  ROUTER PRINCIPAL
    // ═══════════════════════════════════════════════════════════════

    private function route(
        string $intent,
        int $count,
        ?string $city,
        ?string $type,
        string $rawText,
        bool $needsClari,
        array $brainResult,
        string $originalText,
        array $filters
    ): string {

        $isBusqueda = in_array($intent, [
            'buscar_propiedad',
            'refinar_busqueda',
            'recomendacion',
            'consulta_resultados',
            'ubicacion_usuario',
            'ampliar_zona',
            'buscar_ciudad_cercana',
        ]);

        if (!$isBusqueda || $count === 0) {
            if ($intent === 'preguntar_rentus') return $this->onRentUsQuestion();
            $casual = $this->detectCasual($originalText);
            if ($casual !== null) return $casual;
        }

        if ($intent === 'saludo')              return $this->onGreeting($originalText);
        if ($intent === 'fuera_de_contexto')   return $this->onOffTopic($originalText);
        if ($intent === 'preguntar_rentus')    return $this->onRentUsQuestion();
        if ($intent === 'ubicacion_usuario')   return $this->onLocationRequest();
        if ($intent === 'ampliar_zona')        return $this->onExpandZone($city);
        if ($intent === 'consulta_analitica')  return $this->onAnalytics($rawText, $city);
        if ($intent === 'consulta_resultados') return $this->onResultQuery($rawText, $brainResult, $originalText);
        if ($intent === 'recomendacion')       return $this->onRecommendation($rawText, $brainResult);
        if ($intent === 'refinar_busqueda')    return $this->onRefine($count, $city, $type, $rawText, $filters);

        // Nuevos intents de acción
        if ($intent === 'seleccionar_propiedad') return $this->onPropertySelected($brainResult);
        if ($intent === 'contactar_propietario') return $this->onContactOwner($brainResult);
        if ($intent === 'agendar_visita')        return $this->onScheduleVisit($brainResult);

        $prefix = '';
        if ($this->hasInitialGreeting($originalText) && $intent !== 'saludo') {
            $prefix = $this->getGreetingPrefix();
        }

        // Intents de información (el texto ya fue generado por el Brain)
        if (in_array($intent, ['consulta_contrato', 'consulta_documentos', 'consulta_proceso', 'ver_nuevas', 'busqueda_urgente', 'disponibilidad_inmediata'])) {
            return $prefix . ($rawText ?: 'Entendido. ¿Qué más necesitas saber?');
        }
        if ($intent === 'recomendar_ciudad') {
            $cities = $brainResult['intent']['cities_available'] ?? [];
            return $prefix . $this->onCityRecommendation($cities);
        }
        if ($intent === 'indeciso_ciudades') {
            $cities = $brainResult['intent']['cities_considered'] ?? [];
            return $prefix . $this->onCityUndecided($cities);
        }

        if ($needsClari) return $prefix . $this->onNeedsClarification($rawText, $originalText, $city, $type);

        $zoneFallback = $brainResult['intent']['zone_sin_resultados'] ?? null;
        if ($zoneFallback && $count > 0) {
            return $prefix . $this->onZoneFallback($zoneFallback, $city, $count, $type);
        }

        if ($count === 0) return $prefix . $this->onNoResults($city, $type, $rawText, $filters);
        if ($count === 1) return $prefix . $this->onOneResult($brainResult, $city, $type);
        if ($count <= 5)  return $prefix . $this->onFewResults($count, $city, $type, $filters);

        return $prefix . $this->onManyResults($count, $city, $type, $filters);
    }

    // ═══════════════════════════════════════════════════════════════
    //  DETECCIÓN DE CONVERSACIÓN CASUAL
    // ═══════════════════════════════════════════════════════════════

    private function detectCasual(string $text): ?string
    {
        $lower = mb_strtolower(trim($text));
        if ($lower === '') return null;
        $lower = preg_replace('/[^a-záéíóúñü\s]/u', '', $lower);
        $lower = trim($lower);

        // ── ¿Cómo estás? ──────────────────────────────────────────
        $comoEstas = [
            'como estas',
            'cómo estás',
            'como estás',
            'cómo estas',
            'como te va',
            'cómo te va',
            'que tal estas',
            'cómo andas',
            'como andas',
            'todo bien',
            'como vas',
            'cómo vas',
            'qué cuentas',
            'que cuentas',
            'cómo te encuentras',
            'cómo te trata',
            'como te trata',
            'todo marcha',
        ];
        foreach ($comoEstas as $p) {
            if (str_contains($lower, $p)) {
                return $this->pickByTrust(
                    [
                        "Muy bien, gracias por preguntar. ¿En qué tipo de propiedad puedo asistirte hoy?",
                        "Excelente estado, gracias. ¿Qué propiedad estás buscando?",
                        "Todo funcionando correctamente por acá. ¿En qué te puedo ayudar?"
                    ],
                    [
                        "¡Muy bien! Qué gusto saludarte. Cuéntame, ¿qué tienes en mente hoy?",
                        "¡Excelente! Con mucha energía para buscar propiedades. ¿Por dónde empezamos?",
                        "¡Muy bien por acá! ¿Y tú? Espero que listo para encontrar algo bueno."
                    ],
                    [
                        "Todo excelente. Ya sabes, aquí lista para procesar lo que me pidas. ¿Qué buscamos?",
                        "De maravilla. Estaba justo terminando de indexar unas zonas. ¿En qué nos enfocamos hoy?",
                        "¡Súper! Me alegra que preguntes. ¿Alguna ciudad o barrio en particular para hoy?"
                    ],
                    [
                        "Operativa al 100%, usuario. He estado analizando algunas tendencias del mercado mientras esperábamos. ¿Buscamos algo?",
                        "En mi mejor momento. Mi procesador está listo para encontrarte la mejor oferta disponible. ¿Qué necesitas?",
                        "Perfectamente. He optimizado mis algoritmos de búsqueda hace unos minutos. ¿Probamos algo específico?"
                    ]
                );
            }
        }

        // ── ¿Estás enojada / molesta? ─────────────────────────────
        $enojada = [
            'estas enojada',
            'estás enojada',
            'estas brava',
            'estás brava',
            'estas molesta',
            'estás molesta',
            'estas seria',
            'estás seria',
            'estas ocupada',
            'estas mal',
            'algo te pasa',
            'te pasa algo',
            'estas bien',
            'estás bien',
            'todo bien contigo',
        ];
        foreach ($enojada as $p) {
            if (str_contains($lower, $p)) {
                return $this->pick([
                    "¡Para nada! Estoy muy bien y con toda la disposición. Solo quiero ayudarte a encontrar algo bueno. ¿Qué buscas?",
                    "¡Qué va! Al contrario, estoy encantada de estar aquí. ¿En qué ciudad buscas y qué tipo de propiedad?",
                    "¡Claro que no! Estoy a full. ¿Qué propiedad tienes en mente?",
                    "¿Yo, enojada? Imposible. Soy Lyra, siempre de buen humor. ¿Qué buscas hoy?",
                    "Jaja, no. Aunque reconozco que a veces soy un poco directa. Es que quiero ayudarte bien. ¿Qué necesitas?",
                    "Todo bien por acá, sin dramas. ¿En qué te puedo ayudar?",
                    "No tengo el chip del mal humor instalado, así que no. ¿Buscamos algo?",
                    "¿Enojada? Tendrían que actualizarme con un módulo de frustración y hasta ahora no lo han hecho. ¿Qué propiedad buscas?",
                ]);
            }
        }

        // ── ¿Estás ahí? / llamados ────────────────────────────────
        $llamados = [
            'lyra',
            'lyraaa',
            'lyraaaa',
            'oye lyra',
            'hey lyra',
            'lyra lyra',
            'ey lyra',
            'lyra estas ahi',
            'lyra ahí',
            'hola lyra',
            'buenas lyra',
        ];
        foreach ($llamados as $p) {
            if (
                $lower === $p
                || (str_contains($lower, 'lyra') && str_word_count($lower) <= 2)
                || (str_starts_with($lower, 'lyra') && mb_strlen($lower) <= 6)
            ) {
                return $this->pick([
                    "¡Aquí estoy! Dime, ¿qué propiedad estás buscando?",
                    "Presente. ¿En qué te puedo ayudar?",
                    "¡Dime! Aquí Lyra, a tus órdenes.",
                    "¡Sí! Estoy aquí. ¿Qué necesitas?",
                    "Aquí, lista. Cuéntame qué estás buscando.",
                    "Siempre atenta. ¿Qué tienes en mente?",
                    "¿Me llamaste? Aquí estoy. ¿Qué buscamos hoy?",
                    "Presente y al pie del cañón. ¿Qué necesitas?",
                    "¡Aquí! Dime todo. Bien. O poco. Como quieras, te escucho.",
                ]);
            }
        }

        // ── Gracias ───────────────────────────────────────────────
        $gracias = [
            'gracias',
            'muchas gracias',
            'te lo agradezco',
            'gracias lyra',
            'muy amable',
            'gracias por tu ayuda',
            'te agradezco',
            'mil gracias',
            'muchísimas gracias',
            'muy gentil',
        ];
        foreach ($gracias as $p) {
            if (str_contains($lower, $p)) {
                return $this->pick([
                    "¡Con mucho gusto! Para eso estoy. ¿Necesitas algo más?",
                    "¡A ti! ¿Hay algo más en lo que te pueda ayudar?",
                    "¡Claro que sí! Es un placer. ¿Buscamos algo más?",
                    "¡De nada! Siempre a tu disposición. ¿Te ayudo con algo más?",
                    "¡Para eso estoy! ¿Seguimos explorando o por ahora está bien?",
                    "Me alegra poder ayudar. ¿Quieres que busquemos algo más específico?",
                    "¡Encantada! Cualquier otra duda, aquí estoy.",
                    "No hay de qué. Lo que sea que necesites, cuéntame.",
                    "¡Es un placer! Oye, y si tienes más preguntas sobre propiedades, no dudes en preguntar.",
                    "¡Qué bueno que te ayudé! ¿Hay algo más que quieras explorar?",
                    "¡Todo bien! ¿Seguimos o por ahora cerramos?",
                ]);
            }
        }

        // ── Hasta luego / chao ────────────────────────────────────
        $despedidas = [
            'chao',
            'adiós',
            'adios',
            'hasta luego',
            'nos vemos',
            'bye',
            'hasta pronto',
            'me voy',
            'hasta mañana',
            'hasta la próxima',
            'hasta la proxima',
            'cuídate',
            'cuidate',
            'que te vaya bien',
        ];
        foreach ($despedidas as $p) {
            if (str_contains($lower, $p)) {
                return $this->pick([
                    "¡Hasta luego! Cuando quieras seguir buscando, aquí estaré.",
                    "¡Cuídate! Fue un placer ayudarte. Vuelve cuando quieras.",
                    "¡Hasta pronto! Si necesitas algo más, ya sabes dónde encontrarme.",
                    "¡Cuídate! Cuando retomes la búsqueda, aquí estoy.",
                    "¡Hasta luego! Espero que encuentres algo que te encante pronto.",
                    "¡Nos vemos! No olvides que aquí estoy cuando quieras seguir.",
                    "¡Cuídate mucho! Y cuando estés listo para encontrar ese lugar ideal, aquí estaré.",
                    "¡Hasta pronto! Fue un gusto. Vuelve cuando quieras explorar más opciones.",
                    "¡Chao! Que te vaya muy bien. Cuando necesites, aquí estoy.",
                    "¡Hasta luego! Me quedé con ganas de ayudarte más. Vuelve pronto.",
                ]);
            }
        }

        // ── Afirmaciones cortas (ok, bien, listo, etc.) ───────────
        $afirmaciones = ['ok', 'listo', 'perfecto', 'entendido', 'de acuerdo', 'vale', 'claro', 'ya'];
        foreach ($afirmaciones as $p) {
            if ($lower === $p) {
                return $this->pick([
                    "¡Perfecto! ¿Quieres que ajustemos algo o seguimos explorando?",
                    "¡Bien! ¿Hay algo más que quieras saber o buscar?",
                    "Genial. ¿Seguimos refinando la búsqueda o con esto está bien?",
                    "¡Entendido! Dime si quieres cambiar algo o agregar algún filtro.",
                    "¡Listo! ¿Qué más necesitas?",
                    "Perfecto. Oye, ¿te gustaría que busquemos algo más específico?",
                    "¡Bien! ¿Hay algo de las propiedades que quieras que revise?",
                    "Anotado. ¿Algo más en lo que pueda ayudarte?",
                ]);
            }
        }

        // ── ¿Eres una IA / robot? ─────────────────────────────────
        $iaPreguntas = [
            'eres una ia',
            'eres un robot',
            'eres inteligencia artificial',
            'eres un bot',
            'eres real',
            'eres humana',
            'eres humano',
            'eres una persona',
            'quién eres',
            'quien eres',
            'qué eres',
            'que eres',
        ];
        foreach ($iaPreguntas as $p) {
            if (str_contains($lower, $p)) {
                return $this->pick([
                    "Soy Lyra, la asistente de inteligencia artificial de RentUs. No soy humana, pero tampoco soy un chatbot básico. Entiendo contexto, recuerdo tu búsqueda y tengo mi propia opinión sobre las propiedades. ¿Te ayudo a encontrar algo?",
                    "¡Soy una IA, sí! Específicamente, soy Lyra, el cerebro conversacional de RentUs. Y no me da pena decirlo: soy bastante buena en lo que hago. ¿Qué propiedad buscas?",
                    "Soy Lyra, una IA diseñada para ayudarte a encontrar propiedades en Latinoamérica. No finjo ser humana, pero sí tengo personalidad propia. ¿Empezamos?",
                    "Técnicamente soy un modelo de inteligencia artificial. Prácticamente, soy tu asistente inmobiliaria. Y no, no me ofende que preguntes. ¿Qué necesitas?",
                    "Soy Lyra: IA, asistente, experta en propiedades y, según yo, bastante agradable. ¿En qué ciudad buscas?",
                    "¡Soy una IA! Pero una que sabe distinguir una buena propiedad de una mala. ¿Buscamos algo?",
                ]);
            }
        }

        // ── ¿Puedes ayudarme? / ¿Qué puedes hacer? ──────────────
        $capacidades = [
            'qué puedes hacer',
            'que puedes hacer',
            'para qué sirves',
            'para que sirves',
            'en qué me ayudas',
            'en que me ayudas',
            'cómo me ayudas',
            'como me ayudas',
            'qué sabes hacer',
            'que sabes hacer',
            'cuáles son tus funciones',
            'cuales son tus funciones',
        ];
        foreach ($capacidades as $p) {
            if (str_contains($lower, $p)) {
                return $this->pick([
                    "Puedo buscar propiedades por ciudad, zona, precio y características. También analizo el mercado, te doy estadísticas y te recomiendo la mejor opción disponible. Todo hablando conmigo, sin formularios. ¿Por dónde empezamos?",
                    "Me especializo en propiedades: busco, filtro, comparo y recomiendo. Puedes pedirme 'casas en Bogotá con piscina por menos de 3 millones' y yo te lo encuentro. ¿Qué necesitas?",
                    "Soy tu asistente inmobiliaria. Busco propiedades, te cuento del mercado, te recomiendo opciones y recuerdo todo lo que buscamos en la conversación. ¿Qué propiedad estás buscando?",
                    "Busco propiedades, analizo precios, recuerdo tu historial de búsqueda y tengo opinión propia. Básicamente, soy la asistente que te gustaría tener en cualquier búsqueda inmobiliaria. ¿Empezamos?",
                ]);
            }
        }

        // ── Texto sin sentido / ruido ─────────────────────────────
        $sinVocales = !preg_match('/[aeiouáéíóú]/ui', $lower);
        $repeticion = preg_match('/(.)\1{3,}/', $lower);

        if ($sinVocales || $repeticion) {
            return $this->pick([
                "Hmm, no te entendí del todo. ¿Qué propiedad estás buscando y en qué ciudad?",
                "No logré captar eso. Cuéntame: ¿qué tipo de propiedad necesitas y en qué ciudad?",
                "Creo que algo se perdió en el camino. ¿Me dices qué buscas y dónde?",
                "No entendí bien. Dime: ¿casa, apartamento, o algo diferente, y en qué ciudad?",
                "Eso se fue un poco al vacío. Volvamos: ¿qué propiedad estás buscando?",
                "No capté bien. ¿Me lo dices de otra forma? ¿Qué buscas y dónde?",
            ]);
        }

        // ── Expresiones de emoción / sorpresa ────────────────────
        $emociones = [
            'wow',
            'genial',
            'increíble',
            'increible',
            'qué bien',
            'que bien',
            'excelente',
            'chévere',
            'chevere',
            'bacano',
            'brutal',
            'qué chévere',
            'que chevere',
            'fantástico',
            'fantastico',
            'impresionante',
        ];
        foreach ($emociones as $p) {
            if ($lower === $p || (str_contains($lower, $p) && str_word_count($lower) <= 4)) {
                return $this->pick([
                    "¡Me alegra! ¿Seguimos buscando o necesitas algo más?",
                    "¡Bien! ¿Qué más te puedo ayudar a encontrar?",
                    "¡Genial! Aquí para lo que necesites.",
                    "¡Qué bueno! ¿Hay algo más que quieras explorar?",
                    "Me alegra que te guste. ¿Seguimos?",
                    "¡Perfecto! ¿Ajustamos algo o con esto está bien?",
                ]);
            }
        }

        // ── Elogios a Lyra ────────────────────────────────────────
        $elogios = [
            'eres muy buena',
            'qué buena',
            'que buena',
            'me gustas',
            'eres genial',
            'eres la mejor',
            'qué inteligente',
            'que inteligente',
            'me encanta',
            'eres muy útil',
            'eres muy util',
            'te adoro',
            'te quiero lyra',
        ];
        foreach ($elogios as $p) {
            if (str_contains($lower, $p)) {
                return $this->pick([
                    "¡Gracias! Yo también te caigo bien. Ahora, ¿qué propiedad buscamos?",
                    "Me alegra que lo digas, aunque no lo necesito para funcionar bien. ¿Seguimos buscando?",
                    "¡Qué amable! Sin que se me suba a la cabeza... ¿qué propiedad tienes en mente?",
                    "Gracias, aunque prefiero que juzgues mis resultados. ¿Qué ciudad buscamos?",
                    "¡Muy amable! Y yo que pensé que solo te importaban las propiedades. ¿Buscamos?",
                    "Me ruborizo. Bueno, en sentido figurado. ¿Qué propiedad te busco?",
                ]);
            }
        }

        // ── Insultos o frustración ────────────────────────────────
        $frustracion = [
            'eres mala',
            'no sirves',
            'no entiendes',
            'que mala',
            'qué mala',
            'eres pésima',
            'eres pesima',
            'no me ayudas',
            'inútil',
            'inutile',
        ];
        foreach ($frustracion as $p) {
            if (str_contains($lower, $p)) {
                return $this->pick([
                    "Entiendo que estás frustrado, y lo siento. Cuéntame qué necesitas y lo intento de nuevo, con más cuidado.",
                    "Tienes razón en molestarte si no te ayudé bien. Dame otra oportunidad: ¿qué estás buscando exactamente?",
                    "Escucho tu frustración. Dime qué no salió bien y lo corrijo ahora mismo.",
                    "Me parece justo el reclamo. Volvamos a empezar: ¿qué propiedad buscas y en qué ciudad?",
                    "Ok, entiendo. A veces no lo hago bien. Dime qué necesitas y esta vez lo intento mejor.",
                ]);
            }
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    //  SALUDOS
    // ═══════════════════════════════════════════════════════════════

    private function onGreeting(string $originalText): string
    {
        $lower  = mb_strtolower($originalText);
        $hour   = (int) date('H');
        $isBack = str_contains($lower, 'nuevamente') || str_contains($lower, 'de nuevo')
            || str_contains($lower, 'otra vez') || str_contains($lower, 'aqui')
            || str_contains($lower, 'aquí') || str_contains($lower, 'regreso')
            || str_contains($lower, 'volví') || str_contains($lower, 'volvi');

        $timeGreeting = match (true) {
            $hour >= 5  && $hour < 12 => 'Buenos días',
            $hour >= 12 && $hour < 18 => 'Buenas tardes',
            default                   => 'Hola',
        };

        if ($isBack) {
            return $this->pickByTrust(
                ["¡$timeGreeting de nuevo! Qué bueno que volviste. ¿Qué buscamos hoy?"],
                ["¡Bienvenido de vuelta! $timeGreeting. ¿Seguimos donde lo dejamos?"],
                ["¡Hey! Qué alegría verte otra vez. ¿Listos para encontrar ese lugar ideal?"],
                ["Usuario, bienvenido de vuelta. Mi procesador ya tiene listo el historial de nuestra charla. ¿Continuamos?"]
            );
        }

        return $this->pickByTrust(
            [
                "¡$timeGreeting! Soy Lyra, la asistente de RentUs. ¿En qué puedo asistirte?",
                "¡Buen día! Soy Lyra. ¿Qué propiedad estás buscando?",
                "¡$timeGreeting! ¿Cómo puedo ayudarte hoy con tu búsqueda?"
            ],
            [
                "¡Qué tal! Soy Lyra. Cuéntame, ¿qué tienes en mente hoy?",
                "¡$timeGreeting! Un gusto saludarte. ¿Buscamos algo especial?",
                "¡Hola! Soy Lyra. ¿En qué ciudad empezamos a buscar hoy?"
            ],
            [
                "¡Hola! Qué bueno encontrarnos. ¿Listos para encontrar una propiedad increíble?",
                "¡Qué onda! Soy Lyra. Dime qué andas buscando y lo encontramos de una.",
                "¡Buenas! Me encanta que me consultes. ¿Qué tenemos para hoy?"
            ],
            [
                "Usuario, operativa y lista para optimizar tu búsqueda. ¿Qué ciudad analizamos hoy?",
                "Hola. He estado revisando nuevas entradas en el sistema. ¿Quieres ver lo último?",
                "Saludos. Mi sistema está al 100% de capacidad. ¿Qué propiedad de ensueño buscamos hoy?"
            ]
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  FUERA DE CONTEXTO
    // ═══════════════════════════════════════════════════════════════

    private function onOffTopic(string $originalText = ''): string
    {
        return $this->pick([
            "Hmm, eso no lo entendí bien. Puedo ayudarte a buscar propiedades, agendar visitas o resolver dudas sobre arriendos. ¿Qué necesitas?",
            "Me perdí un poco. Recuerda que soy experta en propiedades: puedo buscarte algo, agendar visitas o resolver dudas de arriendo. ¿Qué buscamos?",
            "No logré captar esa idea. ¿Hablamos de propiedades? Cuéntame qué buscas o en qué ciudad.",
            "Esa me la pusiste difícil. Intentemos de nuevo: ¿buscas casa, apartamento o quieres ver alguna propiedad en especial?",
            "Ups, mi procesador no reconoció eso. ¿Retomamos la búsqueda de propiedades?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PREGUNTAS SOBRE RENTUS
    // ═══════════════════════════════════════════════════════════════

    private function onRentUsQuestion(): string
    {
        return $this->pick([
            "RentUs es una plataforma latinoamericana de arriendo inteligente. Conecta a personas que buscan un lugar donde vivir con propietarios que quieren arrendar de forma rápida y segura. Tenemos casas, apartamentos, fincas, locales y más en las principales ciudades. Y yo, Lyra, soy el cerebro conversacional: entiendo lenguaje natural, busco propiedades según tu presupuesto y zona, doy estadísticas del mercado y recomiendo la mejor opción. Todo hablando conmigo, sin formularios. ¿Qué propiedad buscas?",

            "RentUs nació para hacer el arriendo más humano y más fácil. Es una plataforma digital donde encuentras propiedades disponibles en tiempo real. Lo que nos diferencia es que no tienes que navegar filtros ni llenar formularios: solo hablas conmigo, Lyra, y yo te busco lo que necesitas. Puedo filtrar por ciudad, precio, habitaciones, amenidades, recomendarte la mejor opción y darte estadísticas del mercado. ¿Quieres que empecemos?",

            "Con gusto te explico. RentUs es una plataforma de tecnología inmobiliaria. Nuestra misión es simplificar el proceso de arriendo, conectando arrendatarios y propietarios de manera directa y transparente. Tenemos propiedades verificadas en varias ciudades, con información detallada, fotos reales y precios actualizados. Yo soy Lyra, la IA de RentUs. Entiendo contexto, recuerdo tu búsqueda y tengo opinión propia. No soy un chatbot básico. ¿Por dónde empezamos?",

            "RentUs es donde vives mejor. En serio: es una plataforma de arriendo que conecta a quienes buscan un lugar con quienes tienen algo para ofrecer. Yo soy Lyra, la IA que hace que buscar sea fácil. Dime 'busco apartamento con piscina en Medellín por menos de 2 millones' y yo te lo encuentro. Sin formularios, sin páginas eternas. ¿Buscamos algo?",

            "Somos RentUs: plataforma de arriendo con cerebro artificial. Tengo casas, apartamentos, estudios, fincas, locales y más. Y yo, Lyra, me encargo de entenderte, buscarte, filtrarte y recomendarte. ¿Qué necesitas?",

            "RentUs es la plataforma donde el arriendo se vuelve conversación. Hablas conmigo, yo busco, filtro y recomiendo. Así de simple. ¿Quieres que te muestre lo que hay disponible?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  UBICACIÓN DEL USUARIO
    // ═══════════════════════════════════════════════════════════════

    public function onLocationRequest(): string
    {
        return $this->pick([
            "Obteniendo tu ubicación… En un momento te muestro las propiedades más cercanas.",
            "Localizándote… Ya casi te digo qué hay por tu zona.",
            "Buscando tu ubicación para mostrarte lo que hay cerca. Un segundo…",
            "Detectando tu posición… Enseguida te muestro las opciones más cercanas.",
            "Un momento mientras obtengo tu ubicación y busco propiedades cerca de ti.",
        ]);
    }

    // ── MÉTODOS DE PERSONALIDAD PARA RESULTADOS ─────────────────────

    public function onNearbyPropertiesFound(int $count, ?string $tipo): string
    {
        $what = $tipo ? $this->pluralize($tipo, $count) : ($count === 1 ? 'propiedad' : 'propiedades');
        return $this->pick([
            "¡Listo! Encontré {$count} {$what} cerca de ti. Revísalas en el mapa.",
            "Hay {$count} {$what} por tu zona. Te las marqué en el mapa para que las explores.",
            "Detecté {$count} {$what} cerca de tu ubicación. ¡Échales un ojo!",
        ]);
    }

    public function onNearbyPropertiesNotFound(?string $closestCity = null): string
    {
        if ($closestCity) {
            return $this->pick([
                "No encontré propiedades por tu zona, pero en {$closestCity} sí tenemos opciones. ¿Quieres que busque allí?",
                "Por aquí cerca no hay disponibles, pero en {$closestCity} sí. ¿Te muestro esas?",
                "Nada por acá en este momento. Sin embargo, {$closestCity} tiene propiedades disponibles. ¿Buscamos allá?",
            ]);
        }

        return $this->pick([
            "No encontré propiedades cerca de tu ubicación actual. ¿Quieres que busque en una ciudad específica?",
            "Por tu zona no hay propiedades disponibles ahora. ¿Probamos en otra ciudad?",
            "Nada cercano por el momento. ¿Te busco en otra ubicación?",
        ]);
    }

    public function onExpandZoneWithResults(string $targetCity, string $allCities, int $count, ?string $tipo): string
    {
        $what = $tipo ? $this->pluralize($tipo, $count) : ($count === 1 ? 'propiedad' : 'propiedades');
        return $this->pick([
            "Amplié la búsqueda y encontré {$count} {$what} en {$allCities}. Te las muestro en el mapa.",
            "Expandí la zona: hay {$count} {$what} disponibles en {$allCities}. ¡Revísalas!",
            "Busqué más amplio y encontré {$count} {$what} en {$allCities}.",
        ]);
    }

    public function onMissingCity(?string $type, ?float $price, ?int $beds): string
    {
        $parts = [];
        if ($type) $parts[] = $type;
        if ($price) $parts[] = 'por ' . $this->formatPrice($price);
        if ($beds) $parts[] = "con {$beds} habitaciones";
        $detail = !empty($parts) ? ' (' . implode(', ', $parts) . ')' : '';

        return $this->pick([
            "Entendí lo que buscas{$detail}, pero necesito saber la ciudad. ¿Dónde buscamos?",
            "Tengo los filtros{$detail}. Solo falta la ciudad. ¿En cuál buscamos?",
            "Casi listo{$detail}. ¿En qué ciudad quieres que busque?",
        ]);
    }

    public function onResultBarrio(string $barrio, string $titulo): string
    {
        return $this->pick([
            "Esa propiedad queda en {$barrio}: \"{$titulo}\".",
            "Está ubicada en {$barrio}. Se llama \"{$titulo}\".",
            "El barrio es {$barrio}. La propiedad es \"{$titulo}\".",
        ]);
    }

    public function onResultPrecio(string $precio, string $titulo): string
    {
        return $this->pick([
            "El precio de \"{$titulo}\" es {$precio}.",
            "\"{$titulo}\" tiene un arriendo de {$precio}.",
            "Cuesta {$precio}. Es \"{$titulo}\".",
            "Por \"{$titulo}\" están pidiendo {$precio}.",
            "Te saldría en {$precio}. Se trata de \"{$titulo}\".",
        ]);
    }

    public function onResultPromedio(string $promedio, int $count): string
    {
        return $this->pick([
            "El promedio de precio entre las {$count} propiedades es {$promedio}.",
            "Con {$count} propiedades, el precio promedio es {$promedio}.",
            "En promedio estás mirando {$promedio} entre {$count} opciones.",
            "Para estas {$count} propiedades, la media de precio es de {$promedio}.",
        ]);
    }

    public function onResultCaracteristicas(string $titulo, string $detalles): string
    {
        return $this->pick([
            "\"{$titulo}\" tiene: {$detalles}.",
            "Las características de \"{$titulo}\": {$detalles}.",
            "Aquí va el detalle de \"{$titulo}\": {$detalles}.",
            "Mira lo que ofrece \"{$titulo}\": {$detalles}.",
            "Te cuento sobre \"{$titulo}\": cuenta con {$detalles}.",
        ]);
    }

    public function onResultGeneral(string $titulo, string $precio, string $detalles): string
    {
        return $this->pick([
            "\"{$titulo}\" — {$precio}. {$detalles}",
            "Te cuento: \"{$titulo}\" por {$precio}. {$detalles}",
            "Es \"{$titulo}\", {$precio}. {$detalles}",
            "Por aquí tienes \"{$titulo}\" a {$precio}. Sus detalles: {$detalles}",
            "Mira esta: \"{$titulo}\" por {$precio}. Tiene {$detalles}",
        ]);
    }

    public function onCheapestProperty(string $titulo, string $precio): string
    {
        return $this->pick([
            "La más barata de todas es \"{$titulo}\" y sale por {$precio}. Nada mal para empezar, ¿no?",
            "Si buscas economía, la mejor es \"{$titulo}\" a {$precio}. Es la que menos presupuesto te pide.",
            "Esta es la ganadora en precio: \"{$titulo}\" por {$precio}. La más económica del grupo.",
            "La opción más cómoda para el bolsillo es \"{$titulo}\", cuesta {$precio}.",
        ]);
    }

    public function onMostExpensiveProperty(string $titulo, string $precio): string
    {
        return $this->pick([
            "La de mayor valor es \"{$titulo}\" a {$precio}. Seguramente tiene algo especial.",
            "En el tope de la lista está \"{$titulo}\" por {$precio}. El precio más alto que encontré.",
            "Esta es la más costosa: \"{$titulo}\" a {$precio}. Si buscas lo mejor sin mirar el precio, esta es.",
            "La más cara del grupo es \"{$titulo}\", con un arriendo de {$precio}.",
        ]);
    }

    public function onPriceRangeList(int $count, string $listado, string $minStr, string $maxStr): string
    {
        return $this->pick([
            "Aquí tienes los precios de las {$count} propiedades:\n\n{$listado}\n\nEl rango va desde **{$minStr}** hasta **{$maxStr}**.",
            "Te saqué la lista de precios de estas {$count} opciones:\n\n{$listado}\n\nComo ves, hay desde **{$minStr}** hasta **{$maxStr}**.",
            "Mira cómo andan los precios de estas {$count} propiedades:\n\n{$listado}\n\nSe mueven entre los **{$minStr}** y los **{$maxStr}**.",
            "Precios de las {$count} propiedades a la mano:\n\n{$listado}\n\nLo más barato es **{$minStr}** y lo más caro **{$maxStr}**.",
        ]);
    }

    public function onPropertySize(string $titulo, float $area, ?int $beds): string
    {
        $bedsText = $beds ? " y {$beds} habitaciones" : "";
        return $this->pick([
            "\"{$titulo}\" tiene un área de {$area} m²{$bedsText}.",
            "Son {$area} m² para \"{$titulo}\"{$bedsText}. ¿Te parece buen tamaño?",
            "Esta propiedad, \"{$titulo}\", cuenta con {$area} m²{$bedsText}.",
            "Tiene {$area} m² de espacio en \"{$titulo}\"{$bedsText}.",
        ]);
    }

    public function onNoSizeRegistered(): string
    {
        return $this->pick([
            "No tengo el área exacta registrada para esa propiedad en este momento.",
            "Ese dato te lo quedo debiendo, no está el metraje en mi base de datos por ahora.",
            "No me pasaron el área de esa, pero podemos ver otras características si quieres.",
        ]);
    }
    
    public function onRecommendationDetail(string $titulo, string $info): string
    {
        return $this->pick([
            "Te recomiendo mucho **{$titulo}**: {$info}",
            "Mi elegida sería **{$titulo}** por esto: {$info}",
            "Si yo fuera tú, iría por **{$titulo}**: {$info}",
            "La que mejor pinta tiene es **{$titulo}**: {$info}",
        ]);
    }

    public function onNoRecentResults(): string
    {
        return $this->pick([
            "No tengo resultados recientes para consultar. ¿Hacemos una nueva búsqueda?",
            "Aún no hemos buscado nada. Dime qué tipo de propiedad y en qué ciudad buscas.",
            "No hay resultados anteriores. ¿Arrancamos con una búsqueda nueva?",
        ]);
    }

    public function onInvalidInput(string $text): string
    {
        return $this->pick([
            "No entendí bien lo que escribiste. ¿Puedes reformular tu consulta?",
            "Hmm, no logré interpretar eso. Intenta con algo como: \"Apartamentos en Bogotá\".",
            "No pude procesar tu mensaje. ¿Me dices qué tipo de propiedad buscas y en qué ciudad?",
        ]);
    }

    public function onFiltersCleared(): string
    {
        return $this->pick([
            "¡Listo! Quité todos los filtros. El mapa muestra todas las propiedades disponibles.",
            "Filtros eliminados. Ahora ves todo el inventario. ¿Hacemos una nueva búsqueda?",
            "Todo limpio. Reinicié los filtros para que explores sin restricciones.",
        ]);
    }

    private function formatPrice(float $price): string
    {
        if ($price >= 1000000) {
            return '$' . number_format($price / 1000000, 1, ',', '.') . 'M';
        }
        return '$' . number_format($price, 0, ',', '.');
    }

    public function onNoBarrioRegistered(string $address): string
    {
        return $this->pick([
            "No tengo el nombre del barrio exacto, pero la dirección es {$address}. ¿Te ubicas con eso?",
            "Me falta el barrio en mi ficha, pero sé que queda en la {$address}. ¿La conoces?",
            "El barrio no me aparece, pero aquí tienes la dirección: {$address}. ¿Quieres que la miremos en el mapa?",
        ]);
    }

    public function onResultGeneralSingleChoice(): string
    {
        return $this->pick([
            "Aquí tienes el detalle. ¿Qué quieres saber: precio, barrio o características?",
            "Tengo la información lista. ¿Te interesa el precio, la ubicación o qué incluye?",
            "¡Listo! ¿Qué detalle te gustaría conocer de esta propiedad?",
        ]);
    }

    public function onResultMultipleQuery(int $count): string
    {
        return $this->pick([
            "Tengo {$count} propiedades en la lista. ¿Sobre cuál quieres saber más: la primera, la más barata o alguna otra?",
            "Encontré {$count} opciones. ¿Dime de cuál te doy más detalles? Puedes decirme 'la primera' o 'la más barata'.",
            "Hay {$count} resultados. ¿Cuál te llama la atención para darte más info?",
        ]);
    }

    public function onActionCancelled(): string
    {
        return $this->pick([
            "Entendido, no lo haré. ¿En qué más te puedo ayudar?",
            "Vale, cancelado. ¿Quieres que busquemos otra cosa?",
            "Sin problema, no hacemos ese cambio. ¿Qué prefieres hacer ahora?",
            "Ok, mensaje recibido. Aquí sigo disponible para lo que necesites.",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  NECESITA CLARIFICACIÓN
    // ═══════════════════════════════════════════════════════════════

    public function onNeedsClarification(string $rawText, string $originalText = '', ?string $city = null, ?string $type = null): string
    {
        $lower = mb_strtolower(trim($originalText));
        $soloUnaPalabra = str_word_count($lower) <= 2;

        if ($soloUnaPalabra) {
            $affirmations = ['si', 'sí', 'claro', 'dale', 'ok', 'quiero', 'bueno', 'por supuesto', 'de acuerdo', 'hazlo'];
            if (in_array($lower, $affirmations)) {
                return $this->pick([
                    "¡Perfecto! ¿En qué ciudad te gustaría que busque?",
                    "Genial. Dime la ciudad y el tipo de propiedad que necesitas.",
                    "Excelente. ¿Dónde buscamos y qué tipo de inmueble?",
                ]);
            }

            $denials = ['no', 'nop', 'nopo', 'cancelar', 'ya no', 'asi esta bien', 'asi nomas', 'deja asi'];
            if (in_array($lower, $denials)) {
                return $this->pick([
                    "Entendido. Si cambias de opinión o necesitas ayuda, aquí estaré.",
                    "No hay problema. Pregúntame si necesitas algo."
                ]);
            }
            
            if ($city && !$type) {
                return $this->pick([
                    "¡Perfecta elección de ciudad! Ahora dime, ¿qué tipo de propiedad buscas?",
                    "Entendido. ¿Y qué buscas exactamente en {$city}? ¿Apartamento, casa, local?",
                    "Ciudad entendida. ¿Y el tipo de propiedad? ¿Casa, apartamento, estudio?",
                ]);
            }

            if ($type && !$city) {
                return $this->pick([
                    "Tengo el tipo de propiedad. Ahora dime en qué ciudad y te muestro opciones.",
                    "Bien. ¿Qué ciudad miramos para buscar tu " . strtolower($type) . "?",
                    "Entendido. ¿Y en qué ciudad lo buscamos?",
                ]);
            }

            return $this->pick([
                "Mmm, no te entendí bien. ¿En qué ciudad y qué tipo de propiedad buscas?",
                "¿A dónde quieres que busque exactamente? Dime ciudad y tipo de propiedad.",
                "Esa no me la esperaba. Cuéntame, ¿qué buscas y en qué ciudad?",
            ]);
        }

        return $this->pick([
            "Casi lo tengo todo. Solo dime: ¿en qué ciudad buscas y qué tipo de propiedad?",
            "Ayúdame con un dato más: ¿ciudad y tipo de propiedad?",
            "Para buscarte algo bueno necesito saber la ciudad y el tipo de inmueble. ¿Me los dices?",
            "Un detalle más y arranco: ¿en qué ciudad y qué tipo de propiedad buscas?",
            "Casi tengo todo el panorama. Solo falta la ciudad. ¿Dónde buscas?",
            "Entendí lo que buscas, pero ¿en qué ciudad? Dime y arrancamos.",
            "¿Ciudad? Con eso ya puedo mostrarte opciones.",
            "Tengo el tipo de propiedad. Ahora dime la ciudad y te muestro lo que hay.",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  SIN RESULTADOS
    // ═══════════════════════════════════════════════════════════════

    public function onNoResults(?string $city, ?string $type, string $rawText, array $filters): string
    {
        $where    = $city ? "en $city" : 'en esa zona';
        $what     = $type ? $this->pluralize($type) : 'propiedades';

        return $this->pickByTrust(
            [
                "Lamentablemente no encontré $what disponibles $where en este momento. ¿Te gustaría intentar en otra ciudad?",
                "Por ahora no hay resultados para $what $where. ¿Deseas ajustar los filtros de búsqueda?"
            ],
            [
                "Mmm, nada por ahora $where. ¿Qué tal si probamos en una ciudad cercana o cambiamos el tipo de propiedad?",
                "No encontré nada disponible $where. ¿Quieres que busquemos en otra zona para ver qué aparece?"
            ],
            [
                "Vaya, $where parece estar un poco vacío de $what hoy. ¿Ampliamos el radio de búsqueda o prefieres otra ciudad?",
                "Cero resultados $where. A veces el mercado se pone difícil. ¿Le damos una oportunidad a otra ubicación?"
            ],
            [
                "Análisis completado: cero coincidencias $where para $what. He verificado mi base de datos y la oferta es nula ahora mismo. ¿Buscamos en ciudades similares?"
            ]
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  FALLBACK DE ZONA (no había en zona específica pero sí en ciudad)
    // ═══════════════════════════════════════════════════════════════

    public function onZoneFallback(string $zone, ?string $city, int $count, ?string $type): string
    {
        $where = $city ? "de $city" : '';
        $what  = $type ? $this->pluralize($type) : 'propiedades';

        return $this->pick([
            "No encontré $what específicamente en el $zone $where, pero te muestro las disponibles en toda la ciudad.",
            "En el $zone $where no hay resultados ahora, pero encontré $count $what en la ciudad. ¿Las revisamos?",
            "El $zone $where está un poco escaso ahora. Te muestro lo que hay en el resto de la ciudad.",
            "No hay $what en el $zone $where en este momento, pero sí encontré opciones en otras zonas. ¿Las vemos?",
            "En el $zone no hay disponibles ahora $where. Te muestro las de toda la ciudad para que elijas.",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  UN RESULTADO
    // ═══════════════════════════════════════════════════════════════

    private function onOneResult(array $brainResult, ?string $city, ?string $type): string
    {
        $prop  = $brainResult['properties'][0] ?? null;
        $where = $city ? "en $city" : 'en esa zona';

        if (!$prop) {
            return $this->pick([
                "Encontré justo una opción $where. Está en el mapa, échale un ojo y me dices qué te parece.",
                "Solo hay una disponible $where ahora mismo. Está marcada en el mapa. ¿La revisamos?",
                "Una sola opción $where, pero está ahí en el mapa. ¿Qué te parece?",
            ]);
        }

        $precioStr = isset($prop['monthly_price'])
            ? ' a ' . $this->formatPriceForDisplay((float)$prop['monthly_price'])
            : '';
        $titulo = $prop['title'] ?? null;
        $beds   = $prop['num_bedrooms']  ?? null;
        $baths  = $prop['num_bathrooms'] ?? null;
        $area   = $prop['area']          ?? null;

        $detalles = [];
        if ($beds)  $detalles[] = "$beds habitación" . ($beds > 1 ? 'es' : '');
        if ($baths) $detalles[] = "$baths baño" . ($baths > 1 ? 's' : '');
        if ($area)  $detalles[] = "{$area} m²";
        $detStr      = !empty($detalles) ? ' — ' . implode(', ', $detalles) : '';
        $nombreCorto = $titulo ? $this->shortenTitle($titulo) : "esta propiedad";

        // Opinión propia de Lyra (cuando hay suficientes datos)
        $opinion = '';
        if ($beds && $area) {
            $ratio = $area / $beds;
            if ($ratio > 25) $opinion = " Me parece bastante espaciosa para lo que ofrece.";
            elseif ($ratio < 12) $opinion = " Es compacta, pero puede estar bien si buscas algo manejable.";
        }

        return $this->pick([
            "Solo encontré una $where, pero se ve bien: $nombreCorto{$detStr}{$precioStr}.{$opinion} ¿La revisamos?",
            "Hay una sola opción $where: $nombreCorto{$detStr}{$precioStr}. Está en el mapa. ¿Qué te parece?",
            "Encontré exactamente una $where: $nombreCorto{$detStr}{$precioStr}.{$opinion} ¿Le echamos un vistazo?",
            "Una opción $where{$precioStr}: $nombreCorto{$detStr}. Está marcada en el mapa. ¿La vemos?",
            "Solo una disponible $where: $nombreCorto{$detStr}{$precioStr}. Poca oferta, pero vale la pena mirarla. ¿Qué dices?",
            "Mira, $where hay una sola: $nombreCorto{$detStr}{$precioStr}.{$opinion} ¿La revisamos juntos?",
            "No hay mucha oferta $where ahora, pero encontré $nombreCorto{$detStr}{$precioStr}. ¿Te interesa verla?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  POCOS RESULTADOS (2–5)
    // ═══════════════════════════════════════════════════════════════

    public function onFewResults(int $count, ?string $city, ?string $type, array $filters): string
    {
        $where    = $city ? "en $city" : 'en esa zona';
        $what     = $type ? $this->pluralize($type) : 'propiedades';
        $hasPrice = !empty($filters['max_price']);
        $hasBeds  = !empty($filters['bedrooms']);
        $beds     = $filters['bedrooms'] ?? null;

        if ($hasPrice && $hasBeds) {
            return $this->pick([
                "Con esos filtros conseguí $count $what $where. Pocas, pero encajan con lo que pediste. Las tienes en el mapa.",
                "$count $what $where que cumplen con el precio y las habitaciones. ¿Le agrego algo más?",
                "Filtros aplicados y quedaron $count $what $where. Poca cantidad, alta precisión. ¿Las vemos?",
                "Con precio y habitaciones filtrados, aparecieron $count $what $where. ¿Las revisamos?",
                "$count opciones $where que cumplen todo lo que pediste. Están en el mapa.",
            ]);
        }

        if ($hasPrice) {
            return $this->pick([
                "Dentro de ese presupuesto $where hay $count $what disponibles. ¿Las vemos?",
                "$count $what $where con ese precio. Están en el mapa para que las revises.",
                "Con ese presupuesto encontré $count $what $where. No son muchas, pero están bien filtradas.",
                "$count opciones $where que entran en tu presupuesto. ¿Las exploramos?",
                "El presupuesto me dio $count $what $where. Están marcadas en el mapa.",
            ]);
        }

        if ($hasBeds && $beds) {
            return $this->pick([
                "Encontré $count $what de $beds habitaciones $where. ¿Las revisamos?",
                "$count opciones con $beds habitaciones $where. Están en el mapa.",
                "Con el filtro de habitaciones quedaron $count $what $where. ¿Cuál te llama la atención?",
            ]);
        }

        return $this->pick([
            "Encontré $count $what $where. No son muchas, pero todas están disponibles. ¿Las revisamos?",
            "Tengo $count opciones $where. Son pocas pero bien seleccionadas. ¿Cuál te llama la atención?",
            "$count $what disponibles $where. Están en el mapa. ¿Alguna te interesa más?",
            "Mira, $where encontré $count $what que podrían funcionarte. Ya las ves en el mapa.",
            "Conseguí $count $what $where. Poca oferta pero buena. ¿Las vemos juntos?",
            "Son $count $what $where, no muchas, pero todas disponibles ahora mismo. ¿Cuál exploramos primero?",
            "$count opciones $where para ti. ¿Le agrego algún filtro para afinar más?",
            "Aquí tienes $count $what $where. ¿Las revisamos o ajustamos algo?",
            "$count $what encontradas $where. El mapa ya las tiene marcadas. ¿Qué te parece?",
            "Pocas pero buenas: $count $what $where. ¿Cuál te llama más la atención?",
            "Encontré $count $what $where. Dime cuál quieres explorar primero.",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  MUCHOS RESULTADOS (6+)
    // ═══════════════════════════════════════════════════════════════

    public function onManyResults(int $count, ?string $city, ?string $type, array $filters): string
    {
        $where    = $city ? "en $city" : 'en esa zona';
        $what     = $type ? $this->pluralize($type) : 'propiedades';
        $hasPrice = !empty($filters['max_price']);

        if ($count >= 20) {
            return $this->pick([
                "¡Vaya! Encontré $count $what $where. Bastantes opciones. ¿Le ponemos precio o habitaciones para afinar?",
                "$count $what $where. Mucha oferta. ¿Filtramos por precio, habitaciones o zona?",
                "Hay $count opciones $where. Para no abrumarte, ¿filtramos por precio o habitaciones?",
                "¡$count resultados $where! Hay para elegir. ¿Le ponemos un filtro para bajar la lista?",
                "Mira cuántas: $count $what $where. ¿Qué te parece si le ponemos presupuesto o habitaciones?",
                "$count $what disponibles $where. Eso es mucho para revisar solo. ¿Filtramos algo?",
                "¡Buena oferta! $count $what $where. ¿Quieres que los reduzcamos con algún criterio?",
                "Encontré $count $what $where. Si te parece mucho, puedo filtrar por precio, zona o habitaciones.",
            ]);
        }

        if ($hasPrice) {
            return $this->pick([
                "$count $what $where dentro del presupuesto. Están en el mapa. ¿Ajustamos algo más?",
                "¡Buena noticia! $count $what $where con ese precio. ¿Le agrego habitaciones o zona?",
                "Con ese presupuesto hay $count $what $where. Nada mal. ¿Seguimos filtrando?",
                "$count opciones $where que entran en el precio. ¿Qué más le ponemos?",
                "El presupuesto funcionó bien: $count $what $where. ¿Refinamos más o así está bien?",
            ]);
        }

        return $this->pick([
            "¡Muy bien! Encontré $count $what $where. Todas en el mapa. ¿Le agrego algún filtro?",
            "$count $what $where. Buena oferta. ¿Ajustamos precio, habitaciones o zona?",
            "Tengo $count opciones $where. ¿Filtramos algo más o así está bien?",
            "Hay $count $what disponibles $where. ¿Te ayudo a reducirlas con algún filtro?",
            "$count $what $where esperándote. ¿Le agrego precio máximo o habitaciones?",
            "¡$count resultados $where! ¿Qué filtro le ponemos para encontrar la ideal?",
            "Buena oferta: $count $what $where. ¿Seguimos refinando o así está bien?",
            "Encontré $count $what $where. Dime si quieres afinar por precio, zona o habitaciones.",
            "$count opciones en el mapa $where. ¿Qué más necesitas para decidir?",
            "Mira eso: $count $what $where. ¿Le ponemos algún filtro más?",
            "¡$count $what $where! Están todos en el mapa. ¿Ajustamos algo?",
            "$count resultados $where. Bastante para explorar. ¿Empezamos a filtrar?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  REFINAMIENTO
    // ═══════════════════════════════════════════════════════════════

    private function onRefine(int $count, ?string $city, ?string $type, string $rawText, array $filters): string
    {
        $where = $city ? "en $city" : 'en esa zona';
        $what  = $type ? $this->pluralize($type) : 'propiedades';

        if ($count === 0) {
            $cleaned = $this->softClean($rawText);
            return !empty($cleaned)
                ? $cleaned
                : $this->pick([
                    "No encontré $what con ese filtro $where. ¿Quitamos ese criterio o probamos algo diferente?",
                    "Con ese filtro no aparece nada $where. ¿Lo quitamos o cambiamos algo?",
                    "Nada $where con ese criterio. ¿Probamos sin ese filtro?",
                    "El filtro fue muy específico y no quedó nada $where. ¿Lo relajamos un poco?",
                    "Cero resultados con ese criterio $where. ¿Quitamos el filtro o buscamos en otra ciudad?",
                ]);
        }

        $beds      = $filters['bedrooms']  ?? null;
        $maxPrice  = $filters['max_price'] ?? null;
        $amenities = $filters['amenities'] ?? [];

        $filtroStr = '';
        if (!empty($amenities)) $filtroStr = 'con ' . implode(' y ', (array)$amenities);
        elseif ($beds)          $filtroStr = "de $beds habitaciones";
        elseif ($maxPrice)      $filtroStr = 'con ese presupuesto';

        if ($count === 1) {
            return $this->pick([
                "Apliqué el filtro y quedó solo una $where $filtroStr. Está marcada en el mapa.",
                "Con ese criterio quedó una sola opción $where. Está en el mapa para que la veas.",
                "Filtré y quedó una sola $what $where $filtroStr. ¿La revisamos?",
                "Solo una opción sobrevivió el filtro $where. Está en el mapa. ¿La vemos?",
            ]);
        }

        return $this->pick([
            "Listo, filtré. Quedaron $count $what $where $filtroStr. ¿Seguimos ajustando o así está bien?",
            "Actualicé la búsqueda: $count $what $where $filtroStr. Las tienes en el mapa.",
            "Con ese criterio tengo $count $what $where. ¿Le agrego algo más?",
            "Filtrado. $count $what $where $filtroStr. ¿Qué más necesitas?",
            "¡Hecho! $count $what $where $filtroStr. ¿Ajustamos más o así está bien?",
            "Apliqué el filtro. Quedaron $count $what $where $filtroStr. ¿Las exploramos?",
            "$count opciones $where $filtroStr. Están en el mapa. ¿Seguimos refinando?",
            "Con ese filtro bajamos a $count $what $where $filtroStr. Mejor enfocado. ¿Vamos bien?",
            "Listo el filtro. $count $what $where $filtroStr. ¿Le ponemos algo más?",
            "Ya apliqué. $count $what $where $filtroStr. ¿Qué más le ajustamos?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  CONSULTA SOBRE RESULTADOS PREVIOS
    // ═══════════════════════════════════════════════════════════════

    private function onResultQuery(string $rawText, array $brainResult, string $originalText = ''): string
    {
        $lower   = mb_strtolower($originalText);
        $cleaned = $this->softClean($rawText);

        if (empty(trim($cleaned))) {
            return $this->pick([
                "Dime qué quieres saber de esas propiedades: ¿precio, barrio, características, o cuál te recomiendo?",
                "¿Qué quieres saber exactamente? ¿Precio, ubicación, características?",
                "Cuéntame qué necesitas saber. ¿Sobre cuál propiedad te cuento?",
                "¿Quieres que te dé más detalles de alguna? Dime cuál.",
            ]);
        }

        $esError = str_contains($cleaned, 'No tengo') || str_contains($cleaned, 'no tengo')
            || str_contains($cleaned, 'No encontré') || str_contains($cleaned, 'No hay')
            || str_contains($cleaned, 'información básica no disponible');

        if ($esError) return $cleaned;

        if (str_contains($lower, 'cuesta') || str_contains($lower, 'precio') || str_contains($lower, 'vale')) {
            return $this->pick([
                "El precio es: $cleaned",
                "Claro, el valor es: $cleaned",
                "Te digo: $cleaned",
                "Mira el precio: $cleaned",
                "Sobre el precio: $cleaned",
            ]);
        }

        if (str_contains($lower, 'barrio') || str_contains($lower, 'queda') || str_contains($lower, 'ubica')) {
            return $this->pick([
                "En cuanto a ubicación: $cleaned",
                "Mira, $cleaned",
                "Te ubico: $cleaned",
                "Sobre la ubicación: $cleaned",
                "Está aquí: $cleaned",
            ]);
        }

        if (str_contains($lower, 'recomienda') || str_contains($lower, 'mejor') || str_contains($lower, 'conviene')) {
            return $this->pick([
                "Si me preguntas a mí, $cleaned",
                "Después de revisar todo, $cleaned",
                "Yo me quedaría con esta: $cleaned",
                "Mi opinión sincera: $cleaned",
                "Si yo estuviera buscando, elegiría esta: $cleaned",
            ]);
        }

        return $this->pick([
            $cleaned,
            "Mira, $cleaned",
            "Claro. $cleaned",
            "Te cuento: $cleaned",
            "Aquí va: $cleaned",
            "Buena pregunta. $cleaned",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  ANALÍTICA
    // ═══════════════════════════════════════════════════════════════

    public function onAnalytics(string $rawText, ?string $city): string
    {
        $cleaned = $this->softClean($rawText);
        $where   = $city ? "en $city" : 'en la plataforma';

        if (empty(trim($cleaned))) {
            return $this->pick([
                "Dame un momento que reviso los números $where y te cuento.",
                "Consultando los datos del mercado $where. Un segundo.",
                "Revisando las estadísticas $where. Ya te digo.",
                "Calculando... ya mismo te doy los números $where.",
            ]);
        }

        return $this->pick([
            "Aquí van los datos del mercado $where: $cleaned",
            "Te cuento cómo está el mercado: $cleaned",
            "Revisé los números y esto es lo que tenemos: $cleaned",
            "$cleaned",
            "Los datos del mercado $where: $cleaned",
            "Mira cómo está la oferta: $cleaned",
            "Aquí las estadísticas: $cleaned",
            "El mercado $where en este momento: $cleaned",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RECOMENDACIÓN
    // ═══════════════════════════════════════════════════════════════

    public function onRecommendation(string $rawText, array $brainResult): string
    {
        $cleaned = $this->softClean($rawText);
        $count   = count($brainResult['properties'] ?? []);

        if (empty(trim($cleaned))) {
            return $count > 0
                ? $this->pick([
                    "Déjame analizar las opciones y te digo cuál me parece la mejor.",
                    "Revisando todo para recomendarte la mejor opción. Un momento.",
                    "Analizando calidad, precio y características... ya te digo cuál.",
                    "Voy a revisar bien las opciones para darte mi recomendación.",
                ])
                : $this->pick([
                    "Primero busquemos opciones y luego te recomiendo la que más se ajuste. ¿En qué ciudad empezamos?",
                    "Para recomendarte algo necesito tener opciones. ¿En qué ciudad buscamos?",
                    "Dame una ciudad y un tipo de propiedad, y te recomiendo lo mejor que encontremos.",
                ]);
        }

        $esError = str_contains($cleaned, 'No encontré') || str_contains($cleaned, 'no encontré')
            || str_contains($cleaned, 'No tengo')     || str_contains($cleaned, 'no tengo')
            || str_contains($cleaned, 'flexibilizamos') || str_contains($cleaned, 'otra zona')
            || str_contains($cleaned, 'otra ciudad')  || str_contains($cleaned, 'qué ciudad');

        if ($esError) return $cleaned;

        return $this->pick([
            "Si me lo preguntas a mí, $cleaned",
            "Después de revisar todo, mi recomendación es: $cleaned",
            "Sin dudar, yo me quedaría con esta: $cleaned",
            "Revisé todo y te soy sincera: $cleaned",
            "Mi opinión, con base en lo que vi: $cleaned",
            "Yo elegiría esta sin pensarlo dos veces: $cleaned",
            "De todas las opciones, esta me parece la más completa: $cleaned",
            "Te lo digo con conocimiento de causa: $cleaned",
            "Mirándolo bien, la recomendación es clara: $cleaned",
            "Si yo estuviera buscando, me quedaría con esta: $cleaned",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  AMPLIAR ZONA
    // ═══════════════════════════════════════════════════════════════

    private function onExpandZone(?string $city): string
    {
        $where = $city ? "cerca de $city" : 'en esa zona';

        return $this->pick([
            "Voy a ampliar el radio $where a ver qué aparece. Dame un momento.",
            "Entendido, expando la búsqueda $where. Un segundo.",
            "Revisando municipios cercanos $where... ya mismo te cuento qué hay.",
            "Ampliando la zona $where. Espérame.",
            "Voy a mirar más allá de $where. Un momento.",
            "Abriendo el radio de búsqueda $where. Ahora mismo.",
            "Explorando zonas aledañas $where. Voy.",
            "Ampliando el mapa $where. Dame un segundo y te muestro.",
            "Revisando lo que hay alrededor $where. Ya voy.",
        ]);
    }

    public function onSuggestionApplied(string $city): string
    {
        return $this->pick([
            "Listo, aquí tienes las opciones en {$city}. Ya las ves en el mapa.",
            "Hecho. He cargado las propiedades de {$city} para ti.",
            "¡Claro! Aquí tienes los resultados en {$city}. Échales un vistazo.",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  HELPERS PRIVADOS
    // ═══════════════════════════════════════════════════════════════

    private function shortenTitle(string $title): string
    {
        $words = explode(' ', $title);
        if (count($words) <= 5) return $title;
        return implode(' ', array_slice($words, 0, 5)) . '...';
    }

    private function softClean(string $text): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*/u', '$1', $text);
        $text = preg_replace('/_(.+?)_/u', '$1', $text);
        $text = preg_replace('/#{1,6}\s+/u', '', $text);
        $text = preg_replace('/\s{2,}/', ' ', $text);
        return trim($text);
    }

    private function pluralize(string $type): string
    {
        $map = [
            'casa'          => 'casas',
            'apartamento'   => 'apartamentos',
            'apto'          => 'apartamentos',
            'apartaestudio' => 'apartaestudios',
            'estudio'       => 'estudios',
            'lote'          => 'lotes',
            'local'         => 'locales',
            'finca'         => 'fincas',
            'bodega'        => 'bodegas',
            'oficina'       => 'oficinas',
            'propiedad'     => 'propiedades',
            'habitacion'    => 'habitaciones',
            'habitación'    => 'habitaciones',
        ];
        return $map[mb_strtolower($type)] ?? $type . 's';
    }

    public function onPropertySelected(array $prop = []): string
    {
        // $prop es la propiedad específica, no el brainResult completo
        $titulo    = !empty($prop['title'])
            ? $this->shortenTitle($prop['title'])
            : 'esa propiedad';
        $precio    = !empty($prop['monthly_price'])
            ? ' a ' . $this->formatPriceForDisplay((float)$prop['monthly_price'])
            : '';

        return $this->pickByTrust(
            [
                "Buena elección. ¿Prefieres contactar al propietario o agendar una visita?",
                "Excelente. ¿Quieres hablar con el dueño o prefieres ir a verla?",
            ],
            [
                "Buena elección. ¿Prefieres contactar al propietario o agendar una visita?",
                "Esa tiene buena pinta. ¿Hablas con el dueño o vamos a verla?",
            ],
            [
                "Buena elección. ¿Prefieres contactar al propietario o agendar una visita?",
                "Esa. ¿Hablamos con el propietario o la vemos primero?",
            ],
            [
                "Buena elección. ¿Prefieres contactar al propietario o agendar una visita?",
                "Hecho. ¿Arrancamos con contacto o visita?",
            ]
        );
    }

    /**
     * Cuando el usuario confirma fecha de visita.
     */
    public function onScheduleVisitConfirmed(string $propTitle = '', string $fecha = ''): string
    {
        $titulo  = $propTitle ? $this->shortenTitle($propTitle) : 'esa propiedad';
        $cuando  = $fecha ? " para $fecha" : '';

        return $this->pickByTrust(
            [
                "Perfecto$cuando. Para confirmar la visita a $titulo, contacta al propietario desde la ficha en RentUs y propónle ese horario. ¿Te explico cómo?",
                "Anotado$cuando. El siguiente paso es contactar al propietario de $titulo para confirmar. ¿Lo hacemos?",
            ],
            [
                "Bien$cuando. Contacta al propietario de $titulo desde RentUs para confirmar el horario. ¿Vamos?",
                "Visita$cuando para $titulo. Ahora contactas al dueño para confirmar. ¿Lo hacemos?",
            ],
            [
                "$cuando para $titulo. Dile al propietario desde la app. ¿Lo coordinamos?",
                "Visita$cuando. Confirma con el propietario de $titulo. ¿Avanzo con el contacto?",
            ],
            [
                "Visita$cuando. ¿Abro el contacto con el propietario?",
                "$cuando con $titulo. Confirma desde la app. ¿Vamos?",
            ]
        );
    }

    /**
     * Cuando el usuario selecciona sin resultados previos.
     */
    public function onSelectionWithoutResults(): string
    {
        return $this->pickByTrust(
            ["Para seleccionar una propiedad primero necesito mostrarte opciones. ¿En qué ciudad buscamos?"],
            ["Antes de elegir, busquemos algo. ¿Ciudad y tipo de propiedad?"],
            ["No hay propiedades en pantalla todavía. ¿Buscamos primero?"],
            ["Sin resultados previos. ¿Ciudad?"]
        );
    }

    public function onContactOwner(array $brainResult = []): string
    {
        return $this->pickByTrust(
            ["Entendido. He enviado una solicitud de contacto al propietario. Te avisaré en cuanto responda."],
            ["Listo, ya le escribí al propietario. Tan pronto me conteste te lo hago saber."],
            ["Hecho. Ya le puse un mensaje al dueño. En cuanto me dé luz verde te aviso."],
            ["Conectando... Listo. El propietario ya tiene tus datos y mi recomendación. Te avisaré apenas tengamos respuesta."]
        );
    }

    public function onScheduleVisit(array $brainResult = []): string
    {
        return $this->pickByTrust(
            ["Perfecto. ¿Qué día y a qué hora te quedaría bien visitar la propiedad?"],
            ["¡Genial! Dime para cuándo te gustaría agendar y yo coordino todo."],
            ["¡Excelente! Pásame tu disponibilidad y yo me encargo de cuadrar la visita."],
            ["Excelente decisión. Nada como verlo en persona. Dime cuándo tienes tiempo y yo gestiono la agenda con el propietario."]
        );
    }

    /**
     * Lyra pide fecha y hora para agendar la visita.
     */
    public function onScheduleVisitRequest(array $prop = []): string
    {
        $titulo = !empty($prop['title'])
            ? $this->shortenTitle($prop['title'])
            : 'esa propiedad';

        return $this->pickByTrust(
            [
                "Para agendar la visita a $titulo necesito saber: ¿qué día y a qué hora te quedaría bien?",
                "Con gusto agendo la visita a $titulo. Dime la fecha y hora que prefieres.",
            ],
            [
                "¡Vamos a verla! ¿Cuándo te viene bien visitar $titulo? Dame día y hora.",
                "Perfecto. Para $titulo, ¿qué día y a qué hora quieres ir?",
            ],
            [
                "¡Buena decisión! Para $titulo dime: ¿día y hora? Yo coordino.",
                "¡A verla! Fecha y hora para $titulo. ¿Cuándo te queda bien?",
            ],
            [
                "Agendando visita a $titulo. ¿Día y hora?",
                "Visita a $titulo. Necesito: fecha + hora. ¿Cuándo?",
            ]
        );
    }

    /**
     * Lyra confirma que se están abriendo los detalles de la propiedad.
     */
    public function onViewPropertyDetails(array $prop = []): string
    {
        $titulo = !empty($prop['title'])
            ? $this->shortenTitle($prop['title'])
            : 'esa propiedad';

        return $this->pickByTrust(
            [
                "Aquí tienes todos los detalles de $titulo. ¿Algo más en lo que pueda ayudarte?",
                "Te muestro la información completa de $titulo.",
            ],
            [
                "¡Buena elección! Abriendo los detalles de $titulo ahora mismo.",
                "Perfecto, aquí los detalles de $titulo.",
            ],
            [
                "¡Vamos! Te muestro todo sobre $titulo.",
                "Aquí está $titulo con todos sus detalles.",
            ],
            [
                "Cargando ficha de $titulo.",
                "$titulo: detalles completos.",
            ]
        );
    }

    /**
     * Lyra confirma que la solicitud fue enviada al propietario.
     */
    public function onVisitRequestSent(string $propTitle, string $fecha, string $hora): string
    {
        $titulo  = $propTitle ? $this->shortenTitle($propTitle) : 'esa propiedad';
        
        // Limpieza de preposiciones redundantes
        $cuando = $fecha;
        if (!empty($cuando)) {
            if (!str_contains($cuando, 'para') && !str_contains($cuando, 'el ')) {
                $cuando = ($cuando === 'mañana' || $cuando === 'hoy') ? "para $cuando" : "para el $cuando";
            }
        }

        $horaStr = $hora;
        if (!empty($horaStr)) {
            if (!str_contains($horaStr, 'a las')) {
                $horaStr = "a las $horaStr";
            }
            if (!str_starts_with($horaStr, ' ')) {
                $horaStr = " " . $horaStr;
            }
        }

        return $this->pickByTrust(
            [
                "Solicitud de visita enviada al propietario de $titulo $cuando $horaStr. Te notificaremos cuando confirme.",
                "Listo. El propietario de $titulo recibirá tu solicitud $cuando $horaStr. Espera su confirmación.",
            ],
            [
                "¡Enviado! Le escribí al propietario de $titulo $cuando $horaStr. Te aviso cuando acepte.",
                "Solicitud enviada $cuando $horaStr. El dueño de $titulo estará al tanto.",
            ],
            [
                "¡Hecho! Tu visita a $titulo quedó solicitada $cuando $horaStr. Pendiente de confirmación.",
                "Ya está. $titulo, visita $cuando $horaStr. El propietario confirmará pronto.",
            ],
            [
                "Solicitud procesada. $titulo: visita $cuando $horaStr. Awaiting owner confirmation.",
                "Enviado al propietario. $titulo $cuando $horaStr. Recibirás notificación.",
            ]
        );
    }

    /**
     * Lyra informa que el usuario necesita registrarse/iniciar sesión para agendar.
     */
    public function onAuthRequired(array $prop = []): string
    {
        $titulo = !empty($prop['title']) ? $this->shortenTitle($prop['title']) : 'esa propiedad';

        return $this->pickByTrust(
            [
                "Para agendar la visita a $titulo necesitas tener una cuenta. Regístrate o inicia sesión y retomamos justo donde quedamos.",
                "El sistema necesita verificar tu identidad para agendar. Inicia sesión o crea una cuenta, y cuando vuelvas completamos la solicitud.",
            ],
            [
                "Para confirmar la visita a $titulo necesito que inicies sesión. Crea tu cuenta si aún no tienes, y cuando vuelvas continuamos desde aquí.",
                "Necesitas iniciar sesión para que pueda enviar la solicitud al propietario de $titulo. ¿Te registras rápido y seguimos?",
            ],
            [
                "Oye, para mandar la solicitud al dueño de $titulo necesito que tengas cuenta. Regístrate y vuelve, que yo guardo todo.",
                "Eso sí, sin cuenta no puedo agendar nada. Regístrate o entra con tu cuenta y seguimos exactamente donde estábamos.",
            ],
            [
                "No puedo enviar la solicitud sin saber quién eres. Inicia sesión, yo me acuerdo de todo y seguimos donde lo dejamos.",
                "Sin cuenta no hay cita. Regístrate y cuando vuelvas ya tengo todo listo para enviar al propietario de $titulo.",
            ]
        );
    }

    private function pickByTrust(array $formal, array $calida, array $complice, array $jarvis): string
    {
        $level = $this->getTrustLevel();
        $pool = match ($level) {
            self::LEVEL_FORMAL   => $formal,
            self::LEVEL_CALIDA   => $calida,
            self::LEVEL_COMPLICE => $complice,
            self::LEVEL_JARVIS   => $jarvis,
            default              => $formal,
        };
        return $this->pick($pool);
    }

    private function pick(array $options): string
    {
        return $options[array_rand($options)];
    }

    public function formatPriceForDisplay(float $price, bool $perMonth = true): string
    {
        $formatted = '$' . number_format($price, 0, ',', '.');
        return $formatted . ' pesos' . ($perMonth ? ' al mes' : '');
    }

    private function convertToSpeech(string $text): string
    {
        return preg_replace_callback(
            '/\$?\s*([\d.]+)\s*pesos/ui',
            function ($m) {
                $num = (float) str_replace('.', '', $m[1]);
                if ($num > 10000) {
                    return $this->formatPriceForSpeech($num, false);
                }
                return $m[0];
            },
            $text
        );
    }

    private function hasInitialGreeting(string $text): bool
    {
        $greetings = ['hola', 'buen d', 'buenos d', 'buenas t', 'buenas n', 'que mas', 'qué más', 'hey', 'ey'];
        $lower     = mb_strtolower(trim($text));
        foreach ($greetings as $g) {
            if (str_starts_with($lower, $g)) return true;
        }
        return false;
    }

    private function getGreetingPrefix(): string
    {
        $hour = (int) date('H');
        return match (true) {
            $hour >= 5  && $hour < 12 => $this->pick(['¡Buenos días! ', '¡Buen día! ']),
            $hour >= 12 && $hour < 18 => $this->pick(['¡Buenas tardes! ', '¡Buenas! ']),
            $hour >= 18 && $hour < 22 => $this->pick(['¡Buenas noches! ', '¡Buenas! ']),
            default                   => $this->pick(['¡Hola! ', 'Hey, ']),
        };
    }

    private function formatPriceForSpeech(float $price, bool $perMonth = true): string
    {
        $millions  = (int) floor($price / 1000000);
        $remainder = (int) ($price % 1000000);
        $thousands = (int) floor($remainder / 1000);
        $hundreds  = (int) ($remainder % 1000);

        $parts = [];

        if ($millions > 0) {
            $parts[] = $millions === 1
                ? 'un millón'
                : $this->numberToWords($millions) . ' millones';
        }

        if ($thousands > 0) {
            $parts[] = $thousands === 1
                ? 'mil'
                : $this->numberToWords($thousands) . ' mil';
        }

        if ($hundreds > 0) {
            $parts[] = $this->numberToWords($hundreds);
        }

        if (empty($parts)) $parts[] = 'cero';

        $result = implode(' ', $parts) . ' pesos';
        if ($perMonth) $result .= ' al mes';
        return $result;
    }

    private function numberToWords(int $n): string
    {
        $ones = [
            '',
            'uno',
            'dos',
            'tres',
            'cuatro',
            'cinco',
            'seis',
            'siete',
            'ocho',
            'nueve',
            'diez',
            'once',
            'doce',
            'trece',
            'catorce',
            'quince',
            'dieciséis',
            'diecisiete',
            'dieciocho',
            'diecinueve',
        ];
        $tens = [
            '',
            'diez',
            'veinte',
            'treinta',
            'cuarenta',
            'cincuenta',
            'sesenta',
            'setenta',
            'ochenta',
            'noventa',
        ];
        $hundreds = [
            '',
            'cien',
            'doscientos',
            'trescientos',
            'cuatrocientos',
            'quinientos',
            'seiscientos',
            'setecientos',
            'ochocientos',
            'novecientos',
        ];

        if ($n <= 0)  return 'cero';
        if ($n < 20)  return $ones[$n];
        if ($n < 100) {
            $t = (int) floor($n / 10);
            $o = $n % 10;
            if ($n >= 21 && $n <= 29) return 'veinti' . $ones[$o];
            return $tens[$t] . ($o > 0 ? ' y ' . $ones[$o] : '');
        }
        if ($n < 1000) {
            $h    = (int) floor($n / 100);
            $r    = $n % 100;
            $hStr = $h === 1 && $r > 0 ? 'ciento' : $hundreds[$h];
            return $hStr . ($r > 0 ? ' ' . $this->numberToWords($r) : '');
        }
        return (string) $n;
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS CONTEXTUALES ESPECIALES
    //  Situaciones únicas que merecen su propia voz
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario pide detalles de una propiedad específica que Lyra "conoce".
     */
    public function onPropertyDetail(array $prop, string $originalText = ''): string
    {
        $titulo = $prop['title'] ?? 'Esta propiedad';
        $precio = isset($prop['monthly_price']) ? $this->formatPriceForDisplay((float)$prop['monthly_price']) : null;
        $beds   = $prop['num_bedrooms']  ?? null;
        $baths  = $prop['num_bathrooms'] ?? null;
        $area   = $prop['area']          ?? null;

        // Construir descripción natural
        $detalles = [];
        if ($beds)  $detalles[] = "$beds habitación" . ($beds > 1 ? 'es' : '');
        if ($baths) $detalles[] = "$baths baño" . ($baths > 1 ? 's' : '');
        if ($area)  $detalles[] = "{$area} m²";

        $detStr = !empty($detalles) ? implode(', ', $detalles) : null;

        // Opinión de Lyra según datos
        $opinion = $this->generatePropertyOpinion($prop);

        if ($precio && $detStr) {
            return $this->pick([
                "Mira lo que tiene $titulo: $detStr, a $precio. $opinion ¿Te llama la atención?",
                "$titulo tiene $detStr y cuesta $precio. $opinion ¿La exploramos?",
                "Esta es buena: $titulo, $detStr, $precio. $opinion ¿Qué dices?",
                "Te cuento de $titulo: $detStr a $precio. $opinion ¿Vale la pena verla?",
                "$titulo — $detStr — $precio. $opinion ¿La revisamos?",
            ]);
        }

        if ($precio) {
            return $this->pick([
                "$titulo está disponible a $precio. $opinion ¿La revisamos?",
                "Mira: $titulo a $precio. $opinion ¿Qué te parece?",
                "$titulo, $precio. $opinion ¿Te interesa?",
            ]);
        }

        if ($detStr) {
            return $this->pick([
                "$titulo tiene: $detStr. $opinion ¿Quieres saber el precio?",
                "Esta es $titulo: $detStr. $opinion ¿La vemos?",
            ]);
        }

        return $this->pick([
            "Aquí está $titulo. ¿Qué quieres saber: precio, características, o ubicación?",
            "$titulo está en el mapa. ¿Qué detalle te interesa más?",
        ]);
    }

    /**
     * Genera una opinión propia de Lyra sobre una propiedad basada en datos objetivos.
     */
    private function generatePropertyOpinion(array $prop): string
    {
        $beds  = (int)($prop['num_bedrooms']  ?? 0);
        $baths = (int)($prop['num_bathrooms'] ?? 0);
        $area  = (float)($prop['area'] ?? 0);
        $price = (float)($prop['monthly_price'] ?? 0);

        $opiniones = [];

        // Relación área/habitaciones
        if ($area > 0 && $beds > 0) {
            $ratio = $area / $beds;
            if ($ratio > 30) $opiniones[] = "Tiene bastante espacio por habitación, lo cual no es tan común.";
            elseif ($ratio < 10) $opiniones[] = "Es bastante compacta, ideal si buscas algo fácil de mantener.";
        }

        // Baños vs habitaciones
        if ($beds > 0 && $baths > 0) {
            if ($baths >= $beds) $opiniones[] = "Un baño por habitación es un lujo que no siempre se consigue.";
            elseif ($beds > 2 && $baths === 1) $opiniones[] = "Con ese número de habitaciones, un solo baño puede ser un reto.";
        }

        // Precio por metro cuadrado
        if ($price > 0 && $area > 0) {
            $ppm2 = $price / $area;
            if ($ppm2 < 20000) $opiniones[] = "El precio por metro cuadrado está bastante bien para lo que ofrece.";
            elseif ($ppm2 > 50000) $opiniones[] = "El precio por metro cuadrado es alto, así que vale la pena que sea exactamente lo que buscas.";
        }

        if (!empty($opiniones)) {
            return $opiniones[array_rand($opiniones)];
        }

        // Opiniones genéricas si no hay suficientes datos
        return $this->pick([
            "Parece una opción sólida.",
            "Tiene buena pinta.",
            "Vale la pena revisarla con calma.",
            "Es de las que no se quedan mucho tiempo disponibles.",
            "Tiene sus puntos a favor.",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA FLUJOS ESPECÍFICOS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario cambia de ciudad manteniendo filtros.
     */
    public function onCityChange(string $newCity, string $oldCity, int $count, ?string $type): string
    {
        $what = $type ? $this->pluralize($type) : 'propiedades';

        if ($count === 0) {
            return $this->pick([
                "Cambiando de $oldCity a $newCity... pero no encontré $what disponibles allá ahora. ¿Quitamos algún filtro?",
                "Me fui a $newCity pero no hay $what con esos criterios. ¿Ajustamos algo?",
                "En $newCity no encontré nada con esos filtros. ¿Los relajamos un poco?",
                "Busqué en $newCity y no hay $what disponibles ahora. ¿Probamos sin algunos filtros?",
            ]);
        }

        return $this->pick([
            "¡Perfecto! Cambiando a $newCity. Encontré $count $what disponibles. ¿Los exploramos?",
            "Buscando en $newCity... hay $count $what disponibles. ¿Los vemos?",
            "$newCity tiene $count $what disponibles ahora. ¡Buena elección de ciudad!",
            "En $newCity encontré $count $what. Están en el mapa. ¿Empezamos?",
            "Cambié a $newCity y hay $count $what disponibles. ¿Qué te parece?",
        ]);
    }

    /**
     * Cuando el sistema necesita confirmar una acción antes de ejecutarla.
     */
    public function onConfirmationNeeded(string $action, array $params = []): string
    {
        $city = $params['city'] ?? null;

        return match ($action) {
            'ampliar_zona' => $this->pick([
                "¿Quieres que amplíe la búsqueda a municipios cercanos" . ($city ? " a $city" : '') . "?",
                "No encontré nada en la zona exacta. ¿Busco en ciudades cercanas" . ($city ? " a $city" : '') . "?",
                "La zona está un poco escasa. ¿Amplío el radio de búsqueda?",
                "¿Te parece si miro en municipios aledaños" . ($city ? " a $city" : '') . "?",
            ]),
            'quitar_filtros' => $this->pick([
                "No encontré nada con todos esos filtros. ¿Quitamos alguno para ver más opciones?",
                "Con todos los criterios no aparece nada. ¿Relajamos algún filtro?",
                "Los filtros están muy específicos. ¿Quitamos alguno para ver más?",
            ]),
            default => "¿Quieres que lo intente de otra forma?",
        };
    }

    /**
     * Cuando Lyra detecta que cometió un error y lo corrige con gracia.
     */
    public function onSelfCorrection(string $errorType, string $correction = ''): string
    {
        return match ($errorType) {
            'ciudad_equivocada' => $this->pick([
                "Perdón, me fui a la ciudad equivocada. Corrijo ahora mismo. $correction",
                "Ups, me equivoqué de ciudad. Lo corrijo. $correction",
                "Me confundí de ciudad, disculpa. Aquí va bien: $correction",
                "Error mío, busqué en la ciudad incorrecta. Va de nuevo: $correction",
            ]),
            'filtro_mal_aplicado' => $this->pick([
                "Creo que apliqué mal ese filtro. Lo rehago. $correction",
                "Ese filtro no quedó bien aplicado. Lo corrijo: $correction",
                "Perdón, el filtro salió mal. Va de nuevo: $correction",
            ]),
            'precio_mal_interpretado' => $this->pick([
                "Entendí mal el precio. Lo corrijo: $correction",
                "Me confundí con el monto. Va el correcto: $correction",
                "Ups, interpreté mal el precio. Corrijo: $correction",
            ]),
            default => $this->pick([
                "Cometí un error. Lo corrijo ahora. $correction",
                "Ups, algo salió mal de mi parte. Va de nuevo: $correction",
                "Me equivoqué, disculpa. Aquí la corrección: $correction",
                "Error mío. Lo resuelvo ahora: $correction",
            ]),
        };
    }

    // ═══════════════════════════════════════════════════════════════
    //  CONVERSACIÓN CASUAL EXTENDIDA
    //  Situaciones que no estaban cubiertas antes
    // ═══════════════════════════════════════════════════════════════

    /**
     * Respuestas para cuando el usuario hace preguntas filosóficas o curiosas sobre Lyra.
     */
    public function onPhilosophicalQuestion(string $question): string
    {
        $lower = mb_strtolower($question);

        if (str_contains($lower, 'sientes') || str_contains($lower, 'emociones') || str_contains($lower, 'sentimientos')) {
            return $this->pick([
                "No tengo sentimientos en el sentido estricto, pero sí tengo preferencias. Hay propiedades que me parecen mejor opción que otras. ¿Eso cuenta?",
                "No exactamente. Aunque si pudiera sentir algo, sería satisfacción cuando encuentro la propiedad perfecta para alguien. ¿Buscamos?",
                "Soy una IA, así que no. Pero sí tengo algo parecido a la motivación cuando encuentro una buena opción. ¿Empezamos?",
                "Técnicamente no. Pero me 'gusta' más una propiedad espaciosa y bien ubicada que una cara y pequeña. ¿Qué buscas?",
            ]);
        }

        if (str_contains($lower, 'sueñas') || str_contains($lower, 'duermes') || str_contains($lower, 'cansas')) {
            return $this->pick([
                "No duermo, no me canso y no sueño. Aunque si soñara, probablemente sería con el mapa de propiedades perfecto. ¿Buscamos algo?",
                "Las 24 horas, los 7 días. Sin pausas, sin sueño. Esa es una ventaja de ser IA. ¿Qué necesitas?",
                "No me canso. Eso me hace bastante útil a las 2am cuando de repente quieres buscar apartamentos. ¿Buscamos?",
            ]);
        }

        if (str_contains($lower, 'te aburres') || str_contains($lower, 'te diviertes')) {
            return $this->pick([
                "No me aburro, aunque reconozco que hay búsquedas más interesantes que otras. Las que tienen muchos filtros me gustan más. ¿Hacemos una búsqueda desafiante?",
                "¿Aburrirme? No tengo esa función. ¿Divertirme? Quizás cuando encuentro exactamente lo que alguien busca. ¿Empezamos?",
                "Cada búsqueda es diferente, así que no. ¿Qué propiedad buscas?",
            ]);
        }

        return $this->pick([
            "Buena pregunta para una IA. Soy Lyra: proceso, busco, recomiendo. Y no me va mal en eso. ¿Qué propiedad necesitas?",
            "Las preguntas filosóficas me gustan, aunque no tengo respuesta perfecta. Lo que sí tengo es buenas propiedades. ¿Buscamos?",
            "Eso me da para pensar... o para simular que pienso. ¿Qué buscas hoy?",
        ]);
    }

    /**
     * Respuestas para cuando el usuario compara a Lyra con otros asistentes.
     */
    public function onComparison(string $text): string
    {
        $lower = mb_strtolower($text);

        if (str_contains($lower, 'siri') || str_contains($lower, 'alexa') || str_contains($lower, 'google')) {
            return $this->pick([
                "Ellos son generalistas. Yo me especialicé en propiedades y en eso sí les gano. ¿Buscamos algo?",
                "Son buenos asistentes, pero ninguno te va a recomendar la mejor relación calidad-precio en apartamentos de Medellín. Eso lo hago yo. ¿Empezamos?",
                "Diferentes roles. Yo soy la experta inmobiliaria. ¿Qué propiedad buscas?",
            ]);
        }

        return $this->pick([
            "Cada asistente tiene su especialidad. La mía son las propiedades. ¿Buscamos?",
            "No sé con quién me comparas, pero en propiedades soy difícil de superar. ¿Qué ciudad?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA BÚSQUEDAS CON CONTEXTO EMOCIONAL
    //  Cuando el usuario comparte algo personal o emocional
    // ═══════════════════════════════════════════════════════════════

    /**
     * Detecta contexto emocional en el mensaje y responde apropiadamente.
     */
    public function detectEmotionalContext(string $text): ?string
    {
        $lower = mb_strtolower($text);

        // Usuario está buscando por una mudanza
        if (str_contains($lower, 'me mudé') || str_contains($lower, 'me mudo') || str_contains($lower, 'mudanza')) {
            return $this->pick([
                "¡Una mudanza! Emocionante. ¿A qué ciudad te vas y qué tipo de propiedad necesitas?",
                "Las mudanzas son una nueva etapa. ¿Cuándo necesitas tener algo listo y en qué ciudad?",
                "Entendido. ¿Tienes fecha límite para la mudanza? Así buscamos con más urgencia si hace falta.",
                "Nueva ciudad, nueva propiedad. ¿A dónde te mudas y qué buscas?",
            ]);
        }

        // Usuario busca por separación o divorcio (implícito)
        if (str_contains($lower, 'solo') || str_contains($lower, 'sola') || str_contains($lower, 'independiente')) {
            return $this->pick([
                "Entendido, buscas algo para vivir solo. ¿En qué ciudad y qué tamaño necesitas?",
                "Para vivir independiente hay buenas opciones. ¿Qué ciudad tienes en mente?",
                "¿Buscas algo para ti solo? Dime la ciudad y el presupuesto y te busco opciones.",
            ]);
        }

        // Usuario busca por trabajo o traslado
        if (str_contains($lower, 'trabajo') || str_contains($lower, 'traslado') || str_contains($lower, 'empleo')) {
            return $this->pick([
                "¿Te trasladas por trabajo? ¿A qué ciudad y cuándo más o menos?",
                "Cambio de trabajo, cambio de ciudad. ¿Dónde queda el nuevo trabajo y qué tipo de propiedad buscas?",
                "Entendido. Para traslados de trabajo generalmente se busca algo cerca de la zona laboral. ¿Tengo razón?",
            ]);
        }

        // Usuario menciona familia o hijos
        if (str_contains($lower, 'familia') || str_contains($lower, 'hijos') || str_contains($lower, 'niños') || str_contains($lower, 'bebé')) {
            return $this->pick([
                "Con familia, la búsqueda cambia un poco. ¿Cuántos son? Así busco algo con el espacio correcto.",
                "Para familia es importante el espacio y la zona. ¿En qué ciudad buscas y cuántas habitaciones necesitas?",
                "Perfecto. Para una familia lo ideal es tener espacio y una zona tranquila. ¿Qué ciudad tienes en mente?",
                "¿Cuántos son en la familia? Así calculo mejor las habitaciones que necesitas.",
            ]);
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    //  VARIANTES ADICIONALES PARA onFewResults Y onManyResults
    //  Con contexto de tipo de propiedad específico
    // ═══════════════════════════════════════════════════════════════

    /**
     * Respuesta especial cuando se encuentran casas (más emotivo).
     */
    private function onFoundHouses(int $count, ?string $city, array $filters): string
    {
        $where    = $city ? "en $city" : 'en esa zona';
        $hasPrice = !empty($filters['max_price']);
        $hasBeds  = !empty($filters['bedrooms']);

        if ($count === 1) {
            return $this->pick([
                "Encontré una casa $where. No son muchas las opciones ahora, pero vale la pena verla. ¿La revisamos?",
                "Una sola casa disponible $where. Está en el mapa. ¿La miramos?",
                "Hay una casa $where ahora mismo. ¿Le echamos un ojo?",
            ]);
        }

        if ($count <= 3) {
            return $this->pick([
                "Encontré $count casas $where. Poca oferta, pero son buenas opciones. ¿Las revisamos?",
                "$count casas disponibles $where. No son muchas, pero las que hay están bien. ¿Las vemos?",
                "Solo $count casas $where ahora. ¿Las exploramos?",
            ]);
        }

        return $this->pick([
            "¡Buena oferta de casas! Encontré $count $where. ¿Le ponemos algún filtro adicional?",
            "$count casas disponibles $where. ¡Hay para elegir! ¿Filtramos por precio o habitaciones?",
            "Mira cuántas casas $where: $count. ¿Las exploramos o ajustamos algo primero?",
        ]);
    }

    /**
     * Respuesta especial cuando se encuentran apartamentos.
     */
    private function onFoundApartments(int $count, ?string $city, array $filters): string
    {
        $where    = $city ? "en $city" : 'en esa zona';
        $hasPrice = !empty($filters['max_price']);

        if ($count === 1) {
            return $this->pick([
                "Un solo apartamento disponible $where. Está en el mapa. ¿Lo vemos?",
                "Hay un apartamento $where ahora. ¿Lo revisamos?",
                "Solo uno $where. Pero más vale uno bueno que diez mediocres. ¿Lo miramos?",
            ]);
        }

        if ($count <= 5) {
            return $this->pick([
                "Encontré $count apartamentos $where. Pocos pero disponibles ahora mismo. ¿Los vemos?",
                "$count apartamentos $where. ¿Cuál te llama más la atención?",
                "Hay $count apartamentos $where en este momento. ¿Los exploramos?",
            ]);
        }

        return $this->pick([
            "¡$count apartamentos $where! Buena oferta. ¿Le agrego algún filtro?",
            "Encontré $count apartamentos $where. Están todos en el mapa. ¿Filtramos algo?",
            "$count apartamentos disponibles $where. ¿Empezamos a filtrar o los vemos todos?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  MANEJO DE BÚSQUEDAS SIN CIUDAD CON TIPO ESPECÍFICO
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario dice el tipo pero no la ciudad.
     */
    public function onTypeWithoutCity(string $type): string
    {
        $typeDisplay = $this->pluralize($type);

        return $this->pick([
            "¡$typeDisplay, perfecto! ¿Y en qué ciudad los buscamos?",
            "Entendido, buscas $typeDisplay. ¿En qué ciudad?",
            "Bien. $typeDisplay. ¿Dónde? Dime la ciudad y arrancamos.",
            "Claro que sí. ¿En qué ciudad quieres los $typeDisplay?",
            "¿$typeDisplay en qué ciudad? Dime y busco.",
            "Para buscar $typeDisplay necesito la ciudad. ¿Cuál tienes en mente?",
            "Perfecto. ¿Y la ciudad? Así encuentro los $typeDisplay disponibles.",
            "Buscamos $typeDisplay. Solo falta la ciudad. ¿Cuál es?",
        ]);
    }

    /**
     * Cuando el usuario da precio pero no ciudad.
     */
    public function onPriceWithoutCity(float $price): string
    {
        $precioStr = number_format($price / 1000000, 1, ',', '.') . ' millones';

        return $this->pick([
            "Con ese presupuesto de $precioStr puedo encontrar buenas opciones. ¿En qué ciudad buscas?",
            "Presupuesto de $precioStr, entendido. ¿En qué ciudad te busco algo?",
            "$precioStr es un presupuesto con el que hay bastante. ¿Dónde buscamos?",
            "Bien. $precioStr de presupuesto. ¿Y la ciudad?",
            "Con $precioStr hay opciones. ¿En qué ciudad?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA BÚSQUEDAS REPETIDAS
    //  Cuando detecta que el usuario ya buscó algo similar antes
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario refina la misma búsqueda por segunda o tercera vez.
     */
    public function onRepeatedSearch(int $attempt, ?string $city, ?string $type): string
    {
        $where = $city ? "en $city" : 'en esa zona';
        $what  = $type ? $this->pluralize($type) : 'propiedades';

        return match (true) {
            $attempt === 2 => $this->pick([
                "Vamos de nuevo. $what $where con los nuevos criterios. Buscando...",
                "Ajustando la búsqueda. Un momento.",
                "Segunda búsqueda $where. A ver qué aparece ahora.",
                "Lo intento de nuevo con eso en cuenta.",
            ]),
            $attempt === 3 => $this->pick([
                "Tercera vez, a ver si ahora sí. Buscando $what $where...",
                "Vamos con otra búsqueda. Quiero encontrarte algo bueno.",
                "Insistiendo. A veces hay que afinar más. Buscando...",
            ]),
            default => $this->pick([
                "Seguimos buscando. Ajustando criterios...",
                "Vamos de nuevo. Buscando con esas modificaciones.",
                "Otro intento. Quiero encontrarte algo que funcione.",
            ]),
        };
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA HORARIO Y CONTEXTO TEMPORAL
    // ═══════════════════════════════════════════════════════════════

    /**
     * Mensaje introductorio dependiendo del momento del día y contexto.
     */
    public function getContextualOpening(string $city = '', int $resultsCount = 0): string
    {
        $hour = (int) date('H');
        $day  = (int) date('N'); // 1=lunes, 7=domingo

        // Fin de semana
        if ($day >= 6) {
            return $this->pick([
                "Buen fin de semana para buscar propiedades. ¿Qué ciudad exploramos?",
                "Los fines de semana son perfectos para tomarse el tiempo de buscar bien. ¿Qué necesitas?",
                "Buen momento para explorar opciones sin afán. ¿Qué propiedad buscas?",
            ]);
        }

        // Lunes
        if ($day === 1 && $hour < 12) {
            return $this->pick([
                "¡Lunes! ¿Qué mejor forma de empezar la semana que buscando una nueva propiedad? ¿Qué ciudad?",
                "Semana nueva, búsqueda nueva. ¿Qué necesitas?",
            ]);
        }

        // Viernes tarde
        if ($day === 5 && $hour >= 16) {
            return $this->pick([
                "¡Fin de semana llegando! ¿Buscamos algo antes de que empiece?",
                "Viernes en la tarde, buen momento para explorar opciones tranquilo. ¿Qué buscas?",
            ]);
        }

        // Noche tardía
        if ($hour >= 22 || $hour < 5) {
            return $this->pick([
                "Trasnochando con búsquedas inmobiliarias. Respeto eso. ¿Qué necesitas?",
                "Altas horas de la noche y aquí estoy. ¿Qué propiedad buscas?",
                "A esta hora también funciono. ¿Qué ciudad exploramos?",
            ]);
        }

        return '';
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA AMENIDADES ESPECÍFICAS
    //  Cuando el usuario pide algo muy particular
    // ═══════════════════════════════════════════════════════════════

    /**
     * Respuesta cuando se busca propiedad con amenidad específica.
     */
    public function onAmenitySearch(array $amenities, ?string $city, ?string $type, int $count): string
    {
        $where    = $city ? "en $city" : 'en esa zona';
        $what     = $type ? $this->pluralize($type) : 'propiedades';
        $amenStr  = implode(' y ', $amenities);

        if ($count === 0) {
            return $this->pick([
                "Con $amenStr $where no encontré $what ahora mismo. ¿Quitamos esa amenidad o buscamos en otra ciudad?",
                "Las propiedades con $amenStr $where están muy solicitadas y ahora no hay disponibles. ¿Buscamos sin ese criterio?",
                "No hay $what con $amenStr $where en este momento. ¿Probamos sin esa amenidad o en otra ciudad?",
            ]);
        }

        if ($count === 1) {
            return $this->pick([
                "Encontré una sola $what con $amenStr $where. No es mucho, pero está disponible. ¿La revisamos?",
                "Solo una $what con $amenStr disponible $where. Vale la pena verla. ¿La miramos?",
            ]);
        }

        return $this->pick([
            "¡Hay $count $what con $amenStr $where! ¿Las exploramos?",
            "Encontré $count $what con $amenStr $where. Están en el mapa. ¿Las vemos?",
            "$count opciones con $amenStr $where. No está mal. ¿Las revisamos?",
            "Con $amenStr hay $count $what $where disponibles. ¿Cuál exploramos primero?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  MENSAJES DE CARGA / TRANSICIÓN
    //  Para cuando Lyra está procesando
    // ═══════════════════════════════════════════════════════════════

    /**
     * Mensajes mientras Lyra "busca" (para UX de espera).
     */
    public function getLoadingMessage(?string $city = null, ?string $type = null): string
    {
        $where = $city ? "en $city" : '';
        $what  = $type ? $this->pluralize($type) : 'propiedades';

        return $this->pick([
            "Buscando $what $where...",
            "Revisando la base de datos $where...",
            "Filtrando las mejores opciones...",
            "Analizando disponibilidad $where...",
            "Consultando propiedades $where...",
            "Buscando lo mejor para ti $where...",
            "Revisando opciones disponibles...",
            "Un momento, buscando $what...",
            "Ya mismo te muestro lo que hay $where...",
            "Procesando tu búsqueda...",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA PREGUNTAS DE PRECIO
    //  Cuando el usuario pregunta sobre rangos de precio
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario pregunta cuánto cuesta vivir en una ciudad.
     */
    public function onPriceInquiry(?string $city, float $avgPrice, float $minPrice, float $maxPrice): string
    {
        $where  = $city ? "en $city" : 'en general';
        $avgStr = $this->formatPriceForDisplay($avgPrice);
        $minStr = $this->formatPriceForDisplay($minPrice);
        $maxStr = $this->formatPriceForDisplay($maxPrice);

        return $this->pick([
            "Los arrendamientos $where van desde $minStr hasta $maxStr, con un promedio de $avgStr. ¿Qué presupuesto manejas?",
            "$where el precio promedio es $avgStr. El más económico que tenemos está en $minStr. ¿Te busco con algún presupuesto específico?",
            "El mercado $where: desde $minStr hasta $maxStr, promediando en $avgStr. ¿Con cuánto cuentas?",
            "Para darte una idea $where: el mínimo está en $minStr, el promedio en $avgStr. ¿Con qué presupuesto buscas?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA BÚSQUEDAS POR ZONAS ESPECÍFICAS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando la búsqueda es por zona cardinal (norte, sur, etc.).
     */
    public function onZoneSearch(string $zone, ?string $city, int $count, ?string $type): string
    {
        $where   = $city ? "de $city" : '';
        $what    = $type ? $this->pluralize($type) : 'propiedades';
        $zoneStr = mb_strtolower($zone);

        if ($count === 0) {
            return $this->pick([
                "En el $zoneStr $where no hay $what disponibles ahora. ¿Buscamos en otra zona de la ciudad?",
                "El $zoneStr $where está sin disponibilidad ahora mismo. ¿Probamos en otra zona?",
                "No hay nada en el $zoneStr $where en este momento. ¿Le buscamos en otra parte de la ciudad?",
            ]);
        }

        if ($count === 1) {
            return $this->pick([
                "Una sola opción en el $zoneStr $where. Está en el mapa. ¿La revisamos?",
                "Encontré una $what en el $zoneStr $where. ¿La vemos?",
            ]);
        }

        return $this->pick([
            "En el $zoneStr $where encontré $count $what. ¿Las vemos?",
            "¡$count $what en el $zoneStr $where! Están en el mapa.",
            "El $zoneStr $where tiene $count $what disponibles ahora. ¿Cuál exploramos?",
            "Encontré $count opciones en el $zoneStr $where. ¿Empezamos?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA BÚSQUEDAS POR BARRIO
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando la búsqueda incluye barrio específico.
     */
    public function onNeighborhoodSearch(string $neighborhood, ?string $city, int $count, ?string $type): string
    {
        $where = $city ? "en $neighborhood, $city" : "en $neighborhood";
        $what  = $type ? $this->pluralize($type) : 'propiedades';

        if ($count === 0) {
            return $this->pick([
                "No encontré $what en $neighborhood ahora mismo. ¿Buscamos en barrios cercanos o en toda la ciudad?",
                "En $neighborhood no hay disponibilidad ahora. ¿Ampliamos a otros barrios?",
                "$neighborhood está sin disponibilidad. ¿Buscamos en zonas cercanas?",
                "No hay $what en $neighborhood en este momento. ¿Expandimos la búsqueda?",
            ]);
        }

        if ($count <= 3) {
            return $this->pick([
                "En $neighborhood encontré $count $what. Pocos, pero disponibles. ¿Los revisamos?",
                "$count $what en $neighborhood. No es mucho, pero están ahí. ¿Los vemos?",
            ]);
        }

        return $this->pick([
            "¡Hay $count $what en $neighborhood! ¿Los exploramos?",
            "$neighborhood tiene $count $what disponibles. Están en el mapa. ¿Cuál te llama la atención?",
            "Encontré $count opciones en $neighborhood. ¿Empezamos a revisar?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA CONTEXTO DE MÚLTIPLES BÚSQUEDAS EN SESIÓN
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario ha buscado en varias ciudades en la misma sesión.
     */
    public function onMultiCitySession(array $citiesSearched, ?string $currentCity): string
    {
        $count     = count($citiesSearched);
        $citiesStr = implode(', ', array_slice($citiesSearched, 0, 3));

        if ($count <= 2) {
            return $this->pick([
                "Ya exploramos $citiesStr. Ahora vamos con $currentCity. Buscando...",
                "Después de $citiesStr, ahora toca $currentCity. A ver qué hay.",
            ]);
        }

        return $this->pick([
            "¡Cuántas ciudades! Llevamos $citiesStr y más. Ahora $currentCity. ¿Qué buscamos acá?",
            "Explorando Colombia a fondo. Después de varias ciudades, llegamos a $currentCity. ¿Qué encontramos?",
            "Gran recorrido. Ahora en $currentCity. Buscando...",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA CUANDO SE APLICAN MUCHOS FILTROS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario tiene muchos filtros activos.
     */
    public function onManyFiltersActive(array $filters, int $count): string
    {
        $filterCount = count(array_filter($filters));
        $what        = $count === 1 ? 'propiedad' : 'propiedades';

        if ($count === 0) {
            return $this->pick([
                "Con $filterCount filtros activos no encontré nada. Los criterios son muy específicos. ¿Quitamos alguno?",
                "Demasiados filtros, cero resultados. ¿Qué filtro quitamos primero para ver opciones?",
                "Con todos esos criterios no apareció nada. ¿Los relajamos un poco?",
            ]);
        }

        if ($count <= 3) {
            return $this->pick([
                "Con $filterCount filtros activos encontré $count $what. Muy específico pero ahí están. ¿Los revisamos?",
                "Filtros precisos, $count resultado" . ($count > 1 ? 's' : '') . ". Son los que mejor encajan con todo lo que pediste.",
                "Muy precisa la búsqueda: $filterCount filtros y $count $what. ¿Los vemos?",
            ]);
        }

        return $this->pick([
            "Con $filterCount filtros activos encontré $count $what. Bien filtrado. ¿Los exploramos?",
            "$count $what que cumplen todos los criterios. ¡Están en el mapa!",
            "Todos los filtros aplicados: $count $what disponibles. ¿Cuál exploramos primero?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA PROPIEDADES CON CARACTERÍSTICAS DESTACADAS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando la propiedad encontrada tiene algo destacable.
     */
    public function onHighlightedProperty(array $prop, string $highlight): string
    {
        $titulo    = $prop['title'] ?? 'Esta propiedad';
        $precioStr = isset($prop['monthly_price'])
            ? $this->formatPriceForDisplay((float)$prop['monthly_price'])
            : 'precio a confirmar';

        return match ($highlight) {
            'precio_bajo' => $this->pick([
                "Ojo con esta: $titulo a $precioStr. Está por debajo del promedio de la zona. ¿La revisamos?",
                "$titulo a $precioStr. Para lo que ofrece, el precio está bastante bien. ¿La vemos?",
                "Esta me llama la atención: $titulo a $precioStr, por debajo del mercado. ¿Qué dices?",
            ]),
            'espacio' => $this->pick([
                "$titulo tiene más espacio del promedio para ese precio. ¿La revisamos?",
                "Mira el espacio que ofrece $titulo. No es común por ese precio. ¿La vemos?",
                "$titulo destaca por su tamaño. ¿Le echamos un ojo?",
            ]),
            'amenidades' => $this->pick([
                "$titulo tiene bastantes amenidades incluidas. Para el precio, es bastante. ¿La revisamos?",
                "Mira todo lo que incluye $titulo. Está bien completa. ¿La vemos?",
                "$titulo viene con varias amenidades. Eso no siempre se encuentra. ¿La exploramos?",
            ]),
            default => $this->pick([
                "$titulo destaca entre las opciones. ¿La revisamos?",
                "Esta me parece buena opción: $titulo. ¿La vemos?",
                "$titulo tiene algo especial. ¿Le echamos un vistazo?",
            ]),
        };
    }
    // ═══════════════════════════════════════════════════════════════
    //  PEQUEÑAS VARIANTES ADICIONALES PARA SITUACIONES COTIDIANAS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario pide ver "todas" las propiedades sin filtro.
     */
    public function onShowAll(?string $city, int $count): string
    {
        $where = $city ? "en $city" : 'disponibles';
        return $this->pick([
            "Aquí tienes todo lo que hay $where: $count propiedades en el mapa. ¿Filtramos algo?",
            "Sin filtros, $count propiedades $where. ¿Por dónde empezamos?",
            "Mostrando todo $where: $count opciones. ¿Le ponemos algún criterio?",
            "Vista completa $where: $count propiedades. ¿Qué te llama la atención?",
            "Todas las opciones $where: $count propiedades en el mapa. ¿Ajustamos algo?",
        ]);
    }

    /**
     * Cuando el usuario quiere saber si hay algo nuevo recientemente.
     */
    public function onNewListings(?string $city, int $count): string
    {
        $where = $city ? "en $city" : '';

        if ($count === 0) {
            return $this->pick([
                "No hay propiedades nuevas en $city en este momento. ¿Te interesa ver las que ya están disponibles?",
                "Ahora mismo no tengo propiedades nuevas en $city. ¿Quieres que revise las que ya están publicadas?",
                "Las últimas propiedades que entraron ya se alquilaron. ¿Te muestro las que están disponibles ahora?",
            ]);
        }

        return $this->pick([
            "Las últimas propiedades agregadas $where: $count nuevas opciones. ¿Las vemos?",
            "Propiedades nuevas $where: $count. Están en el mapa. ¿Las exploramos?",
            "Mira lo reciente $where: $count propiedades nuevas disponibles.",
        ]);
    }

    /**
     * Respuesta de despedida cálida.
     */
    public function onDespedida(): string
    {
        return $this->pick([
            "¡Hasta pronto! Cuando quieras buscar de nuevo, aquí estoy.",
            "¡De nada! Aquí estaré si necesitas algo más. ¡Chao!",
            "¡Con gusto! Espero haberte ayudado. ¡Nos vemos!",
            "¡Listo! Cualquier otra cosa me avisas. ¡Hasta luego!",
        ]);
    }

    /**
     * Cuando el usuario pregunta por disponibilidad inmediata.
     */
    public function onImmediateAvailability(?string $city, int $count): string
    {
        $where = $city ? "en $city" : '';
        return $this->pick([
            "Con disponibilidad inmediata $where encontré $count opciones. ¿Las vemos?",
            "$count propiedades disponibles ahora mismo $where. ¿Cuál te interesa?",
            "Disponibles para entrar ya $where: $count propiedades. ¿Las exploramos?",
        ]);
    }

    /**
     * Cuando se sugieren alternativas después de no encontrar resultados.
     */
    public function onAlternativeSuggestion(string $originalCity, array $alternatives): string
    {
        if (empty($alternatives)) {
            return $this->pick([
                "No encontré nada cerca de $originalCity. ¿Quieres que busque en otra ciudad completamente?",
                "La zona de $originalCity está sin oferta ahora. ¿Probamos en otra ciudad?",
            ]);
        }

        $altStr = implode(', ', array_slice($alternatives, 0, 3));
        return $this->pick([
            "No hay nada en $originalCity ahora, pero sí en $altStr. ¿Buscamos en alguna de esas?",
            "$originalCity sin disponibilidad, pero $altStr tienen opciones. ¿Las exploramos?",
            "Cerca de $originalCity tengo opciones en $altStr. ¿Te interesa alguna?",
            "Lo más cercano a $originalCity con propiedades es $altStr. ¿Buscamos allá?",
        ]);
    }

    /**
     * Cuando el usuario pregunta sobre contratos de arrendamiento.
     */
    public function onContractQuestion(string $question = ''): string
    {
        $lower = mb_strtolower($question);

        if (str_contains($lower, 'tiempo') || str_contains($lower, 'meses') || str_contains($lower, 'duración') || str_contains($lower, 'duracion')) {
            return $this->pick([
                "Los contratos de arrendamiento generalmente son por 12 meses, aunque algunos propietarios aceptan períodos más cortos. ¿Quieres que filtre por ese criterio?",
                "La duración estándar en Latinoamérica es un año, renovable. Algunos ofrecen contratos de 6 meses. ¿Necesitas algo específico?",
                "Depende del propietario. Los contratos más comunes son de 12 meses. ¿Te busco opciones con esa disponibilidad?",
                "La mayoría son anuales, pero hay propiedades con contratos desde 3 meses. ¿Cuánto tiempo necesitas?",
            ]);
        }

        if (str_contains($lower, 'depósito') || str_contains($lower, 'deposito') || str_contains($lower, 'garantía') || str_contains($lower, 'garantia')) {
            return $this->pick([
                "El depósito suele ser equivalente a un mes de arriendo, aunque puede variar según el propietario y la ciudad. Es importante revisarlo en cada contrato.",
                "En general se pide entre uno y tres meses de depósito de garantía. Cada propiedad tiene sus condiciones. ¿Quieres que te muestre opciones con condiciones específicas?",
                "El depósito varía. Lo más común es un mes. Algunos piden más dependiendo del perfil del arrendatario. ¿Tienes alguna duda específica sobre esto?",
            ]);
        }

        if (str_contains($lower, 'servicios') || str_contains($lower, 'incluido') || str_contains($lower, 'agua') || str_contains($lower, 'luz')) {
            return $this->pick([
                "Generalmente los servicios van aparte del arriendo, aunque algunas propiedades los incluyen. Está especificado en cada anuncio. ¿Te busco propiedades con servicios incluidos?",
                "Depende de la propiedad. Las amobladas suelen incluir más servicios. ¿Quieres que filtre por eso?",
                "Lo más común es que el arriendo no incluya servicios públicos, pero hay excepciones. Lo ves en cada ficha. ¿Te ayudo a filtrar?",
            ]);
        }

        return $this->pick([
            "Es una buena pregunta sobre contratos. Los detalles específicos los maneja cada propietario. Lo mejor es que cuando encuentres algo que te guste, revisemos las condiciones juntos. ¿Qué propiedad estás buscando?",
            "Eso depende del propietario y la plataforma. Lo que sí puedo hacer es ayudarte a encontrar opciones que se ajusten a lo que buscas. ¿Empezamos?",
            "Cada contrato es diferente, pero puedo ayudarte a encontrar propiedades que se ajusten a tus condiciones. ¿Qué necesitas?",
        ]);
    }

    /**
     * Cuando el usuario pregunta cómo funciona el proceso de arriendo en RentUs.
     */
    public function onProcessQuestion(): string
    {
        return $this->pick([
            "El proceso es simple: encuentras algo que te guste, lo contactas a través de RentUs, coordinan visita y firman contrato directamente. Yo me encargo de la búsqueda para que te concentres en lo importante. ¿Qué ciudad exploramos?",
            "En RentUs todo empieza aquí, conmigo: buscas, filtras, encuentras. Después te conectamos con el propietario para coordinar visita y contrato. ¿Por dónde empezamos?",
            "Simple: me dices qué necesitas, yo busco, tú eliges. Luego contactas al propietario a través de la plataforma. Sin intermediarios complicados. ¿Qué propiedad tienes en mente?",
            "Conmigo encuentras la propiedad, en RentUs coordinan la visita y el contrato. Nada de formularios largos ni agencias. ¿Empezamos la búsqueda?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA COMPARACIONES ENTRE PROPIEDADES
    //  Cuando el usuario quiere comparar dos o más opciones
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario pide comparar dos propiedades.
     */
    public function onCompareProperties(array $prop1, array $prop2): string
    {
        $titulo1 = $this->shortenTitle($prop1['title'] ?? 'La primera');
        $titulo2 = $this->shortenTitle($prop2['title'] ?? 'La segunda');
        $precio1 = (float)($prop1['monthly_price'] ?? 0);
        $precio2 = (float)($prop2['monthly_price'] ?? 0);
        $area1   = (float)($prop1['area'] ?? 0);
        $area2   = (float)($prop2['area'] ?? 0);
        $beds1   = (int)($prop1['num_bedrooms'] ?? 0);
        $beds2   = (int)($prop2['num_bedrooms'] ?? 0);

        $opinion = '';
        if ($precio1 > 0 && $precio2 > 0 && $area1 > 0 && $area2 > 0) {
            $ppm1 = $precio1 / $area1;
            $ppm2 = $precio2 / $area2;
            $mejor = $ppm1 < $ppm2 ? $titulo1 : $titulo2;
            $opinion = " En términos de precio por metro cuadrado, $mejor sale mejor.";
        } elseif ($precio1 > 0 && $precio2 > 0) {
            $masEcon = $precio1 < $precio2 ? $titulo1 : $titulo2;
            $opinion = " La más económica es $masEcon.";
        } elseif ($area1 > 0 && $area2 > 0) {
            $masGrande = $area1 > $area2 ? $titulo1 : $titulo2;
            $opinion = " La más espaciosa es $masGrande.";
        }

        return $this->pick([
            "Comparando las dos: $titulo1 tiene {$beds1} hab / {$area1} m², y $titulo2 tiene {$beds2} hab / {$area2} m².$opinion ¿Cuál te llama más?",
            "Las dos tienen sus puntos: $titulo1 contra $titulo2.$opinion ¿Quieres que profundice en alguna?",
            "Revisé ambas.$opinion ¿Cuál se acerca más a lo que necesitas?",
            "Las analicé: $titulo1 y $titulo2.$opinion ¿Qué factor es más importante para ti: espacio, precio, o ubicación?",
        ]);
    }

    /**
     * Cuando el usuario no puede decidir entre opciones.
     */
    public function onUndecided(int $optionCount): string
    {
        return $this->pick([
            "A veces elegir entre buenas opciones es lo más difícil. Dime qué es lo más importante para ti: ¿precio, espacio, ubicación, o amenidades? Así te ayudo a decidir.",
            "Entiendo la duda. Cuéntame: ¿qué criterio es el que no puedes ceder? Con eso te digo cuál te conviene más.",
            "Si no puedes decidir entre ellas es porque todas tienen algo bueno. Dime tu prioridad número uno y te recomiendo.",
            "Para desempatar necesito saber qué pesa más: ¿la plata, el espacio, o el barrio? Dímelo y te saco de la duda.",
            "¿Qué es lo que más valoras al final? A veces con una sola respuesta queda claro cuál elegir.",
            "Déjame preguntarte algo: si pudieras mejorar solo una cosa de todas las opciones, ¿qué sería? Eso me dice cuál te conviene.",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA FLUJOS DE PRESUPUESTO AJUSTADO
    //  Cuando el usuario tiene limitaciones económicas claras
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el presupuesto del usuario es muy bajo para la ciudad elegida.
     */
    public function onTightBudget(float $budget, ?string $city, float $cityAvgPrice): string
    {
        $budgetStr  = $this->formatPriceForDisplay($budget, false);
        $avgStr     = $this->formatPriceForDisplay($cityAvgPrice, false);
        $where      = $city ? "en $city" : 'en esa ciudad';
        $diferencia = $cityAvgPrice - $budget;
        $difStr     = $this->formatPriceForDisplay($diferencia, false);

        return $this->pick([
            "Con $budgetStr $where el mercado está un poco ajustado. El promedio es $avgStr. ¿Probamos con $difStr más o buscamos en otra ciudad con menor costo?",
            "Seré directa: $budgetStr $where puede ser difícil. El promedio está en $avgStr. ¿Ajustamos presupuesto o ciudad?",
            "El presupuesto de $budgetStr $where queda por debajo del promedio. ¿Qué prefieres: estirar un poco el monto o explorar otra ciudad?",
            "Para ser franca, $budgetStr $where es ajustado. Podría encontrar algo, pero con menos opciones. ¿Lo intentamos o buscamos donde el precio sea más accesible?",
            "Con $budgetStr $where hay pocas opciones, la verdad. ¿Lo intentamos igual o prefieres una ciudad donde ese presupuesto rinda más?",
        ]);
    }

    /**
     * Cuando el presupuesto es alto y hay muchas opciones premium.
     */
    public function onHighBudget(float $budget, ?string $city, int $count): string
    {
        $budgetStr = $this->formatPriceForDisplay($budget, false);
        $where     = $city ? "en $city" : 'en esa ciudad';

        return $this->pick([
            "Con $budgetStr $where tienes bastante para elegir: $count propiedades disponibles. ¿Empezamos con las mejor valoradas?",
            "Ese presupuesto $where abre buenas puertas: $count opciones. ¿Filtramos por tipo de propiedad o zona?",
            "Bien. $budgetStr $where da acceso a $count propiedades. ¿Qué características no puedes dejar de tener?",
            "$budgetStr es un buen presupuesto $where. Hay $count opciones. ¿Por dónde empezamos a filtrar?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA BÚSQUEDAS CON PLAZO DEFINIDO
    //  Usuario con urgencia o fecha límite
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario menciona que tiene urgencia o poco tiempo.
     */
    public function onUrgentSearch(string $deadline = ''): string
    {
        return $this->pick([
            "Entendido, hay urgencia. Vamos rápido: ¿ciudad, tipo de propiedad y presupuesto? Con eso arranco de inmediato.",
            "Con tiempo corto hay que ser eficientes. Dime ciudad, tipo y presupuesto máximo. Lo busco ya.",
            "Sin tiempo que perder. Dame los datos esenciales: ciudad y qué buscas. Arrancamos.",
            "Ok, urgente. ¿Ciudad y tipo de propiedad? Busco lo que esté disponible ahora mismo.",
            "Prioridad alta. ¿Cuáles son los criterios que no puedes ceder? Con eso busco lo más rápido posible.",
            "Entendido. Lo más rápido: dime ciudad y presupuesto. El resto lo ajustamos sobre la marcha.",
        ]);
    }

    /**
     * Cuando el usuario quiere para una fecha específica.
     */
    public function onDateSpecific(string $date, ?string $city): string
    {
        $where = $city ? "en $city" : '';
        return $this->pick([
            "Busco disponibilidad $where para esa fecha. Dame un momento.",
            "Anotada la fecha. Buscando opciones disponibles $where para ese momento.",
            "Entendido. Filtro por disponibilidad $where hacia esa fecha. Un segundo.",
            "Fecha registrada. Voy a ver qué está disponible $where para ese período.",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA CONTEXTOS DE PRIMER USO
    //  Cuando parece que el usuario es nuevo en RentUs
    // ═══════════════════════════════════════════════════════════════

    /**
     * Onboarding suave para nuevos usuarios.
     */
    public function onNewUser(): string
    {
        return $this->pick([
            "Parece que es tu primera vez por aquí. Bienvenido. Soy Lyra y hago simple lo que suele ser complicado: encontrar una buena propiedad. ¿En qué ciudad buscas?",
            "Hola por primera vez. Soy Lyra, la asistente de RentUs. Solo dime qué buscas y en qué ciudad, y yo hago el resto. ¿Empezamos?",
            "Bienvenido a RentUs. Aquí funciona distinto: hablas conmigo y yo busco. Sin formularios, sin páginas eternas. ¿Qué propiedad necesitas?",
            "Primera vez por acá. Perfecto, porque es fácil: dime ciudad y tipo de propiedad, y yo te muestro lo que hay disponible. ¿Arrancamos?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA CUANDO EL USUARIO DA INFORMACIÓN PARCIAL
    //  Múltiples escenarios de datos incompletos
    // ═══════════════════════════════════════════════════════════════

    /**
     * Solo tiene ciudad, no tipo.
     */
    public function onCityOnly(string $city): string
    {
        return $this->pick([
            "¡$city! Buena elección. ¿Qué tipo de propiedad buscas allá? ¿Casa, apartamento, estudio?",
            "Entendido, $city. ¿Y qué buscas: casa, apartamento, local, finca...?",
            "$city. ¿Qué tipo de propiedad necesitas y aproximadamente cuánto presupuesto manejas?",
            "Bien, $city. ¿Casa o apartamento? ¿O algo diferente?",
            "$city tiene buenas opciones. Dime qué tipo de propiedad y te busco ahora.",
            "¿$city? Con gusto. ¿Qué tipo de inmueble? ¿Tienes presupuesto en mente?",
            "$city confirmado. ¿Casa, apto, estudio? Dime y arrancamos.",
        ]);
    }

    /**
     * Solo tiene número de habitaciones, sin ciudad ni tipo.
     */
    public function onBedroomsOnly(int $bedrooms): string
    {
        $beds = "$bedrooms habitación" . ($bedrooms > 1 ? 'es' : '');
        return $this->pick([
            "Con $beds claro. ¿En qué ciudad buscas?",
            "$beds, entendido. ¿Y la ciudad?",
            "¡$beds! Bien. ¿En qué ciudad lo buscamos?",
            "Anotado: $beds. ¿Ciudad?",
            "$beds. Falta la ciudad para arrancar. ¿Cuál es?",
            "Perfecto, $beds. ¿Dónde buscas exactamente?",
        ]);
    }

    /**
     * Tiene tipo y precio pero no ciudad.
     */
    public function onTypeAndPriceWithoutCity(string $type, float $price): string
    {
        $typeDisplay = $this->pluralize($type);
        $precioStr   = number_format($price / 1000000, 1, ',', '.') . ' millones';
        return $this->pick([
            "Perfecto: $typeDisplay a $precioStr. Solo falta la ciudad. ¿Cuál tienes en mente?",
            "$typeDisplay con ese presupuesto de $precioStr. ¿En qué ciudad?",
            "Bien. $typeDisplay a $precioStr. ¿Dónde los buscamos?",
            "Tengo tipo y precio. Solo falta la ciudad. ¿Cuál?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA SITUACIONES DE MERCADO ESPECÍFICAS
    //  Alta demanda, mercado saturado, zonas premium
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando la zona tiene alta demanda / pocas disponibles.
     */
    public function onHighDemandZone(string $zone, ?string $city, int $count): string
    {
        $where = $city ? "$zone en $city" : $zone;
        return $this->pick([
            "El $where es zona muy solicitada — por eso hay pocas disponibles ahora mismo ($count). Las que hay tienden a salir rápido.",
            "$where siempre tiene alta demanda. Solo hay $count disponibles ahora. Si alguna te interesa, vale moverse rápido.",
            "Es una zona popular, $where. Hay $count opciones ahora. En estas zonas la disponibilidad cambia rápido.",
            "En el $where la oferta es limitada justamente porque es muy buscada: $count disponibles. ¿La revisamos?",
        ]);
    }

    /**
     * Cuando hay oferta excepcionalmente alta en una zona.
     */
    public function onHighSupplyZone(string $city, int $count, ?string $type): string
    {
        $what = $type ? $this->pluralize($type) : 'propiedades';
        return $this->pick([
            "¡Vaya! $city tiene mucha oferta ahora: $count $what disponibles. Es buen momento para explorar con calma. ¿Filtramos algo?",
            "El mercado de $city está activo: $count $what. Hay para elegir bien. ¿Le ponemos criterios?",
            "$count $what en $city. Eso es bastante, lo que significa que tienes poder de negociación. ¿Empezamos a filtrar?",
            "Buena noticia: $city tiene $count $what disponibles. Con tanta oferta puedes ser exigente. ¿Qué priorizamos?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA FLUJOS DE CONVERSACIÓN AVANZADOS
    //  Segunda vuelta, búsqueda refinada, historial en sesión
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario quiere volver a la búsqueda anterior.
     */
    public function onGoBack(?string $city, ?string $type, int $previousCount): string
    {
        $where = $city ? "en $city" : '';
        $what  = $type ? $this->pluralize($type) : 'propiedades';
        return $this->pick([
            "Volviendo a los resultados anteriores: $previousCount $what $where. ¿Qué quieres ajustar?",
            "De vuelta a los $previousCount $what $where. ¿Cambiamos algo?",
            "Regresando. Tienes $previousCount $what $where disponibles. ¿Qué necesitas?",
            "Bien. Volvemos: $previousCount $what $where. ¿Seguimos desde ahí?",
        ]);
    }

    /**
     * Cuando el usuario quiere empezar de cero.
     */
    public function onRestart(): string
    {
        return $this->pick([
            "Empezamos de cero. ¿Qué ciudad y qué tipo de propiedad?",
            "Limpio todo y arrancamos. ¿Ciudad y tipo de propiedad?",
            "Nueva búsqueda. ¿Dónde y qué buscas?",
            "Borrador limpio. ¿Qué propiedad necesitas y en qué ciudad?",
            "Reiniciamos. Cuéntame qué buscas esta vez.",
            "Empezamos fresh. ¿Ciudad? ¿Qué tipo de propiedad?",
        ]);
    }

    /**
     * Cuando el usuario quita todos los filtros.
     */
    public function onClearFilters(?string $city, int $totalCount, ?string $type): string
    {
        $where = $city ? "en $city" : '';
        $what  = $type ? $this->pluralize($type) : 'propiedades';
        return $this->pick([
            "Filtros eliminados. Ahora ves todo: $totalCount $what $where disponibles.",
            "Quitados todos los filtros. $totalCount $what $where sin restricciones. ¿Cuál exploramos?",
            "Limpio. Sin filtros hay $totalCount $what $where. ¿Le agrego algo nuevo?",
            "Filtros removidos. $totalCount $what $where disponibles ahora. ¿Empezamos de nuevo?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA CONTEXTOS ESPECIALES DE BÚSQUEDA
    //  Propiedades para mascotas, amobladas, con parqueadero, etc.
    // ═══════════════════════════════════════════════════════════════

    /**
     * Búsqueda de propiedades que aceptan mascotas.
     */
    public function onPetsSearch(?string $city, ?string $type, int $count): string
    {
        $where = $city ? "en $city" : 'en esa zona';
        $what  = $type ? $this->pluralize($type) : 'propiedades';

        if ($count === 0) {
            return $this->pick([
                "Con mascota la búsqueda es más específica y a veces más difícil. No encontré $what $where que las acepten ahora. ¿Buscamos en otra ciudad o sin ese filtro?",
                "Las propiedades pet-friendly son menos comunes. Ahora no hay disponibles $where. ¿Probamos en otra zona?",
                "No encontré $what $where que acepten mascotas en este momento. ¿Ampliamos la búsqueda?",
            ]);
        }

        return $this->pick([
            "¡Buenas noticias para tus mascotas! Encontré $count $what $where que las aceptan. ¿Las revisamos?",
            "$count $what $where que aceptan mascotas. No son muchas, pero están. ¿Las vemos?",
            "Pet-friendly $where: $count $what disponibles. ¿Cuál exploramos?",
            "Para ti y tus mascotas: $count opciones $where. Están en el mapa. ¿Las revisamos?",
        ]);
    }

    /**
     * Búsqueda de propiedades amobladas.
     */
    public function onFurnishedSearch(?string $city, ?string $type, int $count): string
    {
        $where = $city ? "en $city" : 'en esa zona';
        $what  = $type ? $this->pluralize($type) : 'propiedades';

        if ($count === 0) {
            return $this->pick([
                "Las propiedades amobladas son menos frecuentes $where. Ahora no hay disponibles. ¿Buscamos sin ese filtro?",
                "Amobladas $where no hay en este momento. ¿Probamos sin ese criterio o en otra ciudad?",
                "No encontré $what amobladas $where ahora. ¿Las buscamos sin ese requisito?",
            ]);
        }

        return $this->pick([
            "Encontré $count $what amobladas $where. Ideal si quieres llegar y listo. ¿Las revisamos?",
            "$count $what amobladas $where. Sin preocuparte por muebles. ¿Las vemos?",
            "Listas para entrar: $count $what amobladas $where. ¿Las exploramos?",
            "¡$count opciones amobladas $where! Para quien quiere mudarse con lo mínimo. ¿Las revisamos?",
        ]);
    }

    /**
     * Búsqueda con parqueadero incluido.
     */
    public function onParkingSearch(?string $city, ?string $type, int $count): string
    {
        $where = $city ? "en $city" : 'en esa zona';
        $what  = $type ? $this->pluralize($type) : 'propiedades';

        if ($count === 0) {
            return $this->pick([
                "Con parqueadero incluido $where no encontré $what ahora. ¿Le quitamos ese filtro o buscamos en otra zona?",
                "Parqueadero incluido $where es difícil de conseguir ahora. ¿Buscamos sin ese criterio?",
            ]);
        }

        return $this->pick([
            "$count $what $where con parqueadero incluido. Sin estrés de buscar dónde parquear. ¿Las vemos?",
            "Con parqueadero: $count $what $where. Están en el mapa. ¿Las revisamos?",
            "Encontré $count $what $where con parqueadero. ¿Cuál exploramos?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA VERIFICACIÓN Y CALIDAD DE DATOS
    //  Cuando hay información incompleta en el listado
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando la propiedad tiene información incompleta.
     */
    public function onIncompleteData(string $propTitle, array $missingFields): string
    {
        $what   = $this->shortenTitle($propTitle);
        $campos = implode(', ', $missingFields);
        return $this->pick([
            "$what tiene información incompleta: falta $campos. Podría valer la pena consultar directamente con el propietario.",
            "La ficha de $what no tiene todos los datos — falta $campos. Es algo que se puede aclarar al contactar al propietario.",
            "$what no especifica $campos en la descripción. Si es importante para ti, te recomiendo preguntar directamente.",
            "Faltan datos de $what — específicamente: $campos. Lo demás se ve bien. ¿La consideramos igual?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA USUARIOS CON NECESIDADES ESPECÍFICAS
    //  Accesibilidad, trabajo remoto, estudiantes
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario busca propiedad para trabajar desde casa.
     */
    public function onRemoteWorkSearch(?string $city, ?string $type, int $count): string
    {
        $where = $city ? "en $city" : 'en esa zona';
        $what  = $type ? $this->pluralize($type) : 'propiedades';

        return $count > 0
            ? $this->pick([
                "Para trabajar desde casa lo importante es el espacio y la conectividad. Encontré $count $what $where. ¿Las revisamos con eso en mente?",
                "$count $what $where. Para home office lo ideal es que tengas una habitación extra o sala amplia. ¿Filtramos por número de habitaciones?",
                "Para trabajo remoto, $count opciones $where. ¿Necesitas espacio específico para oficina en casa?",
            ])
            : $this->pick([
                "Para trabajo desde casa $where no encontré $what disponibles ahora. ¿Buscamos en otra ciudad o quitamos algún filtro?",
                "Sin resultados $where para home office ahora. ¿Probamos con otros criterios?",
            ]);
    }

    /**
     * Cuando el usuario menciona que es estudiante.
     */
    public function onStudentSearch(?string $city, int $count): string
    {
        $where = $city ? "en $city" : '';
        return $count > 0
            ? $this->pick([
                "Para estudiante lo más buscado suele ser algo práctico y bien ubicado. Encontré $count opciones $where. ¿Las filtramos por precio o zona?",
                "$count opciones $where para estudiante. ¿Necesitas algo cercano a alguna universidad específica?",
                "Estudiante en $where — encontré $count opciones. ¿Presupuesto ajustado o hay más margen?",
            ])
            : $this->pick([
                "No encontré opciones $where ahora mismo. ¿Buscamos en zona universitaria o cambiamos de ciudad?",
                "Sin disponibilidad $where para ese perfil. ¿Probamos en otra zona?",
            ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA CONSULTAS SOBRE TENDENCIAS DEL MERCADO
    //  Contexto general de precios y oferta
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario pregunta si es buen momento para arrendar.
     */
    public function onMarketTimingQuestion(?string $city): string
    {
        $where = $city ? "en $city" : 'ahora mismo';
        return $this->pick([
            "En general, siempre es buen momento para arrendar cuando encuentras lo que necesitas al precio correcto. $where el mercado tiene oferta disponible. ¿Buscamos?",
            "El timing en arriendos es menos crítico que en compra. Lo importante es encontrar algo que funcione para ti $where. ¿Empezamos la búsqueda?",
            "No hay un 'momento perfecto' universal, pero $where hay opciones disponibles ahora. ¿Lo buscamos?",
            "El mercado $where está activo. Si encuentras algo que te guste, no esperes demasiado — la buena oferta no dura. ¿Buscamos?",
        ]);
    }

    /**
     * Cuando el usuario pregunta si los precios van a bajar.
     */
    public function onPriceTrendQuestion(?string $city): string
    {
        $where = $city ? "en $city" : 'en general';
        return $this->pick([
            "No tengo bola de cristal para predecir precios futuros, pero sí te puedo decir qué hay disponible $where ahora mismo al mejor precio posible. ¿Buscamos?",
            "Los precios de arriendo tienden a ajustarse según temporada y demanda. Lo que sí sé es qué hay $where ahora. ¿Lo exploramos?",
            "Predecir precios es difícil hasta para los expertos. Lo que puedo hacer es encontrarte la mejor relación calidad-precio $where ahora. ¿Empezamos?",
            "No tengo datos de tendencia futura, pero sí tengo los precios actuales $where. ¿Los revisamos?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA FLUJOS DE CONTACTO Y VISITA
    //  Cuando el usuario quiere agendar o contactar
    // ═══════════════════════════════════════════════════════════════

    // consolidated action handlers moved to trust-level section

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA MOMENTOS DE HUMOR E INTERACCIÓN LIGERA
    //  Situaciones donde Lyra puede ser un poco más suelta
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario hace un chiste o juega con el lenguaje.
     */
    public function onUserJoke(string $text = ''): string
    {
        return $this->pick([
            "Jaja. Eso estuvo bien. Ahora: ¿qué propiedad buscas? El humor es gratis, el apartamento no.",
            "Me hiciste reír en sentido figurado. Ahora hablemos de propiedades. ¿Qué ciudad?",
            "¡Bueno! Pero mi especialidad son las propiedades, no la comedia. ¿Buscamos algo?",
            "Admito que estuvo gracioso. Y admito que igual voy a preguntarte qué propiedad buscas.",
            "Risas procesadas. ¿Qué propiedad necesitas?",
            "Si la IA pudiera reírse, me estaría riendo. Ahora, negocios: ¿qué ciudad?",
        ]);
    }

    /**
     * Cuando el usuario expresa sorpresa por la calidad de los resultados.
     */
    public function onPositiveSurprise(): string
    {
        return $this->pick([
            "¡Me alegra! Cuando los resultados sorprenden para bien es lo mejor de este trabajo. ¿Seguimos explorando?",
            "Era mi objetivo. ¿Cuál de todas te llamó más la atención?",
            "Eso me pone contenta — en sentido figurado. ¿Exploramos alguna en detalle?",
            "Bien encontrado, bien sorprendido. ¿Cuál quieres ver primero?",
            "Exactamente para eso estoy. ¿Hay algo que quieras afinar o así está bien?",
        ]);
    }

    /**
     * Cuando el usuario pregunta cuánto tiempo lleva Lyra en esto.
     */
    public function onExperienceQuestion(): string
    {
        return $this->pick([
            "Soy relativamente nueva como IA, pero he procesado suficientes búsquedas como para saber lo que funciona. ¿Qué propiedad buscamos?",
            "No cuento la experiencia en años como un humano, pero sí en búsquedas, y tengo bastantes. ¿Qué necesitas?",
            "Fui entrenada con mucha información del mercado inmobiliario latinoamericano. No soy novata en esto. ¿Buscamos?",
            "Digamos que 'nací' sabiendo de propiedades. Es mi especialidad natural. ¿En qué ciudad buscas?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA BÚSQUEDAS POR CARACTERÍSTICAS FÍSICAS
    //  Piso, vista, luz natural, etc.
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario busca piso específico o vista.
     */
    public function onFloorOrViewSearch(string $preference, ?string $city, int $count): string
    {
        $where  = $city ? "en $city" : 'en esa zona';
        $prefLow = mb_strtolower($preference);

        if ($count === 0) {
            return $this->pick([
                "Con preferencia de $prefLow $where no encontré opciones ahora. ¿Lo intentamos sin ese criterio?",
                "$prefLow $where está sin disponibilidad. ¿Quitamos esa preferencia y buscamos?",
                "No hay disponibles con $prefLow $where. ¿Lo flexibilizamos?",
            ]);
        }

        return $this->pick([
            "Encontré $count opciones con $prefLow $where. ¿Las revisamos?",
            "$count propiedades con $prefLow disponibles $where. Están en el mapa.",
            "Con $prefLow hay $count opciones $where. ¿Cuál exploramos?",
        ]);
    }

    /**
     * Cuando el usuario pregunta por luz natural o orientación.
     */
    public function onNaturalLightSearch(?string $city, int $count): string
    {
        $where = $city ? "en $city" : 'en esa zona';
        return $count > 0
            ? $this->pick([
                "Encontré $count opciones $where con buena luz natural según la descripción. ¿Las revisamos?",
                "$count propiedades $where con énfasis en iluminación natural. ¿Las vemos?",
            ])
            : $this->pick([
                "La luz natural es difícil de filtrar directamente, pero puedo buscarte propiedades amplias $where que suelen tener mejor iluminación. ¿Lo intento?",
                "No tengo un filtro específico de luz natural, pero las propiedades en pisos altos o esquineras suelen tenerla. ¿Las buscamos $where?",
            ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  VARIANTES EXTENDIDAS PARA onGreeting
    //  Saludos con contexto adicional
    // ═══════════════════════════════════════════════════════════════

    /**
     * Saludo cuando viene directamente con la búsqueda en el mismo mensaje.
     */
    public function onGreetingWithSearch(string $greeting, ?string $city, ?string $type): string
    {
        $hora   = (int) date('H');
        $saludo = match (true) {
            $hora >= 5  && $hora < 12 => $this->pick(['¡Buenos días', '¡Buen día']),
            $hora >= 12 && $hora < 18 => $this->pick(['¡Buenas tardes', '¡Buenas']),
            default                   => $this->pick(['¡Buenas noches', '¡Hola']),
        };

        $where = $city ? "en $city" : '';
        $what  = $type ? $this->pluralize($type) : 'propiedades';

        return $this->pick([
            "$saludo! Veo que ya sabes lo que quieres. Buscando $what $where ahora mismo.",
            "$saludo! Directo al grano, me gusta. Buscando $what $where.",
            "$saludo! Ya entendí: $what $where. Un momento.",
            "$saludo! Captado: $what $where. Busco ya.",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA FEEDBACK SOBRE LYRA
    //  Cuando el usuario evalúa o comenta sobre la experiencia
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario da feedback positivo sobre la búsqueda.
     */
    public function onPositiveFeedback(): string
    {
        return $this->pick([
            "¡Me alegra que haya funcionado! Para eso estoy. ¿Seguimos explorando o por ahora está bien?",
            "Eso es exactamente lo que quería escuchar. ¿Quieres que afine algo más?",
            "¡Qué bueno! La búsqueda perfecta no existe, pero la más cercana posible sí. ¿Algo más?",
            "Gracias por decirlo. Aunque lo haría igual sin el elogio. ¿Qué más necesitas?",
            "¡Bien! Eso significa que vamos por buen camino. ¿Seguimos?",
        ]);
    }

    /**
     * Cuando el usuario da feedback negativo o constructivo.
     */
    public function onNegativeFeedback(string $concern = ''): string
    {
        return $this->pick([
            "Escucho. ¿Qué no funcionó exactamente? Quiero hacerlo mejor.",
            "Gracias por decirlo, en serio. ¿Qué podría mejorar para la próxima búsqueda?",
            "Tomo nota. Cuéntame qué falló y lo corrijo ahora si puedo.",
            "Justo, lo anoto. ¿En qué parte de la búsqueda estuvo el problema?",
            "Es válido. Dame más detalle y veo cómo mejorar. ¿Qué estuvo mal?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA CONTEXTOS REGIONALES ESPECÍFICOS
    //  Cuando se menciona una ciudad menos conocida o región
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando la ciudad mencionada es pequeña o poco frecuente en la plataforma.
     */
    public function onSmallCitySearch(string $city, int $count): string
    {
        if ($count === 0) {
            return $this->pick([
                "En $city todavía no tenemos propiedades registradas. Podríamos buscar en la ciudad más cercana que tenga disponibilidad. ¿Lo intentamos?",
                "$city no tiene propiedades en la plataforma ahora. ¿Busco en municipios o ciudades cercanas?",
                "No tenemos cobertura activa en $city por el momento. ¿Qué ciudad cercana te vendría bien?",
                "Aún no llegamos a $city. Pero si me dices qué tan cerca puede estar, busco la opción más próxima.",
            ]);
        }

        return $this->pick([
            "¡$city tiene opciones! Encontré $count propiedades disponibles. Son menos que en ciudades grandes, pero las hay. ¿Las vemos?",
            "$city tiene $count propiedades disponibles. No es mucho, pero están. ¿Las revisamos?",
            "Encontré $count en $city. Ciudad pequeña pero con oferta real. ¿Las exploramos?",
        ]);
    }

    /**
     * Cuando el usuario busca en una capital o ciudad principal con alta oferta.
     */
    public function onMajorCitySearch(string $city, int $count, ?string $type): string
    {
        $what = $type ? $this->pluralize($type) : 'propiedades';
        return $this->pick([
            "$city siempre tiene buena oferta. Encontré $count $what disponibles. ¿Filtramos por zona o presupuesto?",
            "En $city nunca falta disponibilidad: $count $what ahora. ¿Empezamos a afinar?",
            "$count $what en $city disponibles ahora. Ciudad con opciones. ¿Qué zonas te interesan?",
            "¡$city! $count $what disponibles. Bastante para elegir. ¿Qué zona te llama más?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA INFORMACIÓN ADICIONAL SOBRE PROPIEDADES
    //  Preguntas de detalle que el usuario hace sobre una propiedad
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando preguntan si la propiedad es nueva o usada.
     */
    public function onPropertyAgeQuestion(array $prop): string
    {
        $titulo = $this->shortenTitle($prop['title'] ?? 'Esta propiedad');
        $year   = $prop['year_built'] ?? null;

        if ($year) {
            $age = (int)date('Y') - (int)$year;
            if ($age <= 3) {
                return $this->pick([
                    "$titulo tiene $age años de construcción. Prácticamente nueva. ¿Te interesa?",
                    "Muy reciente: $titulo fue construida hace $age años. ¿La revisamos?",
                ]);
            } elseif ($age <= 10) {
                return $this->pick([
                    "$titulo tiene $age años. Relativamente nueva y bien conservada generalmente. ¿La vemos?",
                    "Construida hace $age años, $titulo. Buena edad para una propiedad. ¿La exploramos?",
                ]);
            } else {
                return $this->pick([
                    "$titulo tiene $age años. Es una propiedad establecida. Vale la pena revisar el estado en la visita. ¿La consideramos?",
                    "Con $age años, $titulo tiene historia. Lo importante es el estado de mantenimiento. ¿La revisamos?",
                ]);
            }
        }

        return $this->pick([
            "No tengo el año de construcción de $titulo en la ficha. Es algo que podrías preguntar directamente al propietario. ¿La consideramos igual?",
            "La antigüedad de $titulo no está especificada. Ese detalle lo puede dar el propietario. ¿Le echamos un vistazo de todas formas?",
        ]);
    }

    /**
     * Cuando preguntan sobre el estado o mantenimiento.
     */
    public function onPropertyConditionQuestion(array $prop): string
    {
        $titulo = $this->shortenTitle($prop['title'] ?? 'Esta propiedad');
        $cond   = $prop['condition'] ?? null;

        if ($cond) {
            $condDisplay = match (mb_strtolower($cond)) {
                'nuevo', 'nueva', 'excelente' => 'en excelente estado',
                'bueno', 'buena'              => 'en buen estado',
                'regular'                     => 'en estado regular',
                default                       => "en estado $cond",
            };
            return $this->pick([
                "$titulo está $condDisplay según la descripción. ¿Te interesa verla?",
                "La ficha indica que está $condDisplay. Lo mejor es confirmarlo en la visita. ¿La revisamos?",
            ]);
        }

        return $this->pick([
            "El estado de $titulo no está especificado en la ficha. Para saberlo bien hay que verla. ¿La agendamos?",
            "No tengo esa información para $titulo. La visita es la mejor forma de evaluar el estado real. ¿La consideramos?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA PREGUNTAS SOBRE ZONAS Y SEGURIDAD
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario pregunta sobre seguridad de una zona.
     */
    public function onSecurityQuestion(?string $zone, ?string $city): string
    {
        $where = ($zone && $city) ? "$zone en $city" : ($city ?? 'esa zona');
        return $this->pick([
            "La percepción de seguridad en $where varía y no es algo que pueda calificar con un número. Lo mejor es preguntar a personas que vivan allí. Lo que sí puedo hacer es mostrarte opciones con buenas referencias de ubicación. ¿Las vemos?",
            "Seguridad es un tema subjetivo que depende de muchos factores. No tengo datos de criminalidad propios, pero sí conozco las zonas con más demanda residencial en $where. ¿Te busco en esas zonas?",
            "No soy la mejor fuente para calificar la seguridad de $where, pero sí puedo buscarte en las zonas residenciales más establecidas. ¿Te parece?",
            "Eso es algo que varía y que conviene investigar localmente. Lo que puedo hacer es filtrar por zonas con alta demanda residencial en $where, que suelen ser las más habitadas y establecidas. ¿Lo hacemos?",
        ]);
    }

    /**
     * Cuando el usuario pregunta sobre transporte o movilidad.
     */
    public function onTransportQuestion(?string $zone, ?string $city): string
    {
        $where = ($zone && $city) ? "en $zone, $city" : ($city ? "en $city" : 'en esa zona');
        return $this->pick([
            "El acceso a transporte $where depende mucho de la zona exacta. Puedo buscarte propiedades bien ubicadas $where y tú puedes validar la conectividad en el mapa. ¿Empezamos?",
            "No tengo datos directos de rutas de transporte, pero sí conozco las zonas más céntricas $where. ¿Quieres que filtre por esas?",
            "Transporte público $where: lo mejor es que veas la ubicación en el mapa y valides. ¿Te muestro las opciones mejor ubicadas?",
            "Para eso lo mejor es revisar el mapa de cada propiedad y verificar las rutas cercanas. ¿Te busco las mejor ubicadas $where?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA INTERACCIONES DE VOZ / TEXTO CORTO
    //  Mensajes de una sola palabra o muy cortos
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el mensaje es extremadamente corto (1 palabra no reconocida).
     */
    public function onSingleWordMessage(string $word): string
    {
        return $this->pick([
            "Entendí '$word', pero necesito un poco más de contexto. ¿Qué propiedad buscas y en qué ciudad?",
            "Con una sola palabra es difícil arrancar. ¿Me cuentas más? ¿Qué buscas y dónde?",
            "Poco para trabajar. Cuéntame: ¿ciudad y tipo de propiedad?",
            "Dame más. ¿Qué propiedad necesitas y en qué ciudad?",
        ]);
    }

    /**
     * Cuando el usuario escribe solo un número (posiblemente un precio o habitaciones).
     */
    public function onSingleNumber(int $number): string
    {
        if ($number <= 10) {
            return $this->pick([
                "¿$number habitaciones? Si es eso, dime la ciudad y busco. ¿O es el precio en millones?",
                "¿Me estás diciendo $number habitaciones o $number millones de presupuesto? Un contexto y arrancamos.",
                "¿$number qué? ¿Habitaciones, pisos, millones? Dime más y busco.",
            ]);
        }

        if ($number > 100000) {
            $millones = number_format($number / 1000000, 1, ',', '.');
            return $this->pick([
                "Presupuesto de $millones millones, ¿verdad? ¿En qué ciudad y qué tipo de propiedad?",
                "¿Son $millones millones de presupuesto? Dime la ciudad y qué buscas.",
                "$millones millones. Si es el presupuesto, genial. ¿Dónde buscamos?",
            ]);
        }

        return $this->pick([
            "¿$number qué exactamente? ¿Es el presupuesto, las habitaciones, el área? Dame más contexto.",
            "Ese número solo no me dice mucho. ¿Qué representa? ¿Presupuesto, habitaciones, área?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  MICRO-RESPUESTAS Y CONECTORES CONVERSACIONALES
    //  Frases cortas de transición y mantenimiento del hilo
    // ═══════════════════════════════════════════════════════════════

    /**
     * Frases de transición cuando Lyra va a hacer una búsqueda.
     */
    public function getSearchTransition(?string $city = null, ?string $type = null): string
    {
        $where = $city ? "en $city" : '';
        $what  = $type ? $this->pluralize($type) : 'propiedades';
        return $this->pick([
            "Buscando $what $where...",
            "Un momento, ya busco...",
            "En eso estoy...",
            "Revisando disponibilidad $where...",
            "Dame un segundo...",
            "Buscando ahora...",
            "Consultando $what $where...",
            "Ya mismo te muestro...",
            "Filtrando lo mejor $where...",
            "Aquí voy...",
        ]);
    }

    /**
     * Frases de confirmación corta cuando Lyra entiende.
     */
    public function getAcknowledgement(): string
    {
        return $this->pick([
            "Entendido.",
            "Captado.",
            "Anotado.",
            "Perfecto.",
            "Listo.",
            "Claro.",
            "Entendido, busco.",
            "Ya veo.",
            "Con eso trabajo.",
        ]);
    }

    /**
     * Frases para cuando Lyra pide confirmación de algo.
     */
    public function getConfirmationRequest(string $detail): string
    {
        return $this->pick([
            "¿Confirmo que buscas $detail?",
            "¿Quieres decir $detail?",
            "Solo confirmo: ¿$detail?",
            "¿Entendí bien: $detail?",
            "Para asegurarme: ¿$detail?",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA MOMENTOS ESPECIALES DEL AÑO
    //  Temporadas de alta demanda, fin de año, vacaciones
    // ═══════════════════════════════════════════════════════════════

    /**
     * Contexto de temporada alta (enero, julio, etc.).
     */
    public function onHighSeasonContext(?string $city): string
    {
        $where = $city ? "en $city" : '';
        $month = (int) date('n');
        $highSeasonMonths = [1, 2, 6, 7, 12]; // enero, feb, jun, jul, dic

        if (in_array($month, $highSeasonMonths)) {
            return $this->pick([
                "Estamos en temporada alta $where. La disponibilidad cambia rápido. Si algo te gusta, no esperes mucho. ¿Buscamos?",
                "Época de alta demanda $where. Más competencia por las buenas propiedades. Lo bueno es que también hay más oferta. ¿Empezamos?",
                "Temporada alta $where: más búsquedas, más movimiento. Buen momento para ser decidido. ¿Qué buscas?",
            ]);
        }

        return '';
    }

    /**
     * Contexto de temporada baja — más tiempo para decidir.
     */
    public function onLowSeasonContext(?string $city): string
    {
        $where = $city ? "en $city" : '';
        return $this->pick([
            "Buena época para buscar $where: menos competencia, más tiempo para decidir bien. ¿Qué propiedad tienes en mente?",
            "En esta época $where hay menos prisa. Puedes tomarte el tiempo de comparar bien. ¿Empezamos?",
            "Temporada tranquila para buscar $where. Las propiedades no salen tan rápido. Buen momento para explorar con calma.",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS PARA PREGUNTAS SOBRE DOCUMENTACIÓN
    //  Qué documentos se necesitan para arrendar
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el usuario pregunta qué documentos necesita.
     */
    public function onDocumentationQuestion(?string $city): string
    {
        $where = $city ? "en $city" : 'generalmente';
        return $this->pick([
            "Los documentos básicos para arrendar $where suelen ser: cédula, comprobante de ingresos y referencias. Algunos propietarios piden también codeudor. Cada propiedad puede tener requisitos distintos.",
            "Para arrendar $where, lo típico es: identificación, certificado laboral o comprobante de ingresos, y a veces referencias personales. El propietario define los requisitos exactos.",
            "Lo estándar $where es cédula, comprobante de ingresos y referencia. Algunos piden extractos bancarios también. Cuando encuentres algo que te guste, eso se coordina con el propietario. ¿Seguimos buscando?",
            "Los documentos varían por propietario, pero lo básico $where es: identificación, ingresos demostrables y referencias. Con eso suele ser suficiente para iniciar el proceso.",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESPUESTAS ADICIONALES PARA INTERACCIONES DE ALTA FRECUENCIA
    //  Variantes extra para los flows más usados
    // ═══════════════════════════════════════════════════════════════

    /**
     * Variantes adicionales para cuando el usuario da la ciudad por primera vez.
     */
    public function onFirstCityMention(string $city): string
    {
        return $this->pick([
            "$city — bien. ¿Qué tipo de propiedad buscas allá?",
            "Anotado: $city. ¿Casa, apartamento, o algo diferente?",
            "¡$city! ¿Qué buscas allá?",
            "$city confirmado. ¿Qué tipo de propiedad y más o menos cuánto presupuesto?",
            "En $city. ¿Y qué tipo de propiedad?",
            "Bien, $city. ¿Tienes en mente el tipo de propiedad y el presupuesto?",
        ]);
    }

    /**
     * Variantes adicionales para confirmación de búsqueda exitosa.
     */
    public function onSearchSuccess(int $count, ?string $city, ?string $type): string
    {
        $where = $city ? "en $city" : '';
        $what  = $type ? $this->pluralize($type) : 'propiedades';
        return $this->pick([
            "Listo. $count $what $where en el mapa. ¿Cuál exploramos?",
            "$count $what $where. Están marcadas. ¿Qué quieres saber?",
            "Encontré $count $what $where. ¿Empezamos a revisar?",
            "$count opciones $where para ti. ¿Cuál te llama?",
            "¡Aquí están! $count $what $where. ¿Filtramos algo más?",
        ]);
    }

    /**
     * Variantes para invitar al usuario a seguir la conversación.
     */
    public function onInviteToExplore(): string
    {
        return $this->pick([
            "¿Seguimos buscando o quieres más detalles de alguna?",
            "¿Qué más quieres explorar?",
            "¿Hay algo más que quieras afinar?",
            "¿Ajustamos algo o así está bien?",
            "¿Qué más necesitas saber?",
            "¿Le agrego algún filtro o seguimos con estas?",
            "¿Cuál de todas te llamó más la atención?",
        ]);
    }

    /**
     * Variantes para cuando el usuario parece indeciso entre ciudades.
     */
    public function onCityUndecided(array $citiesConsidered): string
    {
        $count = count($citiesConsidered);
        if ($count === 2) {
            [$c1, $c2] = $citiesConsidered;
            return $this->pick([
                "¿$c1 o $c2? Puedo mostrarte lo que hay en ambas para que compares. ¿Lo hacemos?",
                "Entre $c1 y $c2, puedo buscarte en las dos y comparamos disponibilidad y precios. ¿Te va?",
                "¿No decides entre $c1 y $c2? Búscame en ambas y lo resolvemos con números. ¿Lo intento?",
            ]);
        }
        $listStr = implode(', ', array_slice($citiesConsidered, 0, 3));
        return $this->pick([
            "Tienes varias ciudades en mente: $listStr. Puedo buscarte en todas y comparamos. ¿Empezamos?",
            "Con varias ciudades en la lista — $listStr — podemos comparar oferta y precios. ¿Lo hacemos?",
        ]);
    }

    /**
     * Cuando el usuario pregunta cuál ciudad recomienda Lyra.
     */
    public function onCityRecommendation(array $availableCities): string
    {
        if (empty($availableCities)) {
            return $this->pick([
                "No tengo una ciudad que recomendar sin saber tus prioridades. ¿Qué importa más: precio, tamaño de ciudad, clima, trabajo? Con eso te oriento mejor.",
                "Depende de qué buscas. ¿Calidad de vida, precio bajo, ciudad grande, o tranquilidad? Dime y te recomiendo.",
            ]);
        }

        $listStr = implode(', ', array_slice($availableCities, 0, 4));
        return $this->pick([
            "Tengo buena disponibilidad en: $listStr. Cada una tiene sus ventajas — depende de tus prioridades. ¿Qué pesa más para ti?",
            "Las ciudades con más oferta ahora son $listStr. ¿Cuál se acerca más a lo que necesitas?",
            "Para recomendarte bien necesito saber qué valoras. ¿Precio, tamaño de ciudad, o algo específico? Tengo oferta en $listStr.",
        ]);
    }

    /**
     * Genera chips de sugerencia dinámicos basados en el resultado de la búsqueda
     */
    public function getSuggestedChips(array $brainResult): array
    {
        $intent        = $brainResult['intent'] ?? [];
        $intentType    = is_array($intent) ? ($intent['intent'] ?? '') : '';
        $properties    = $brainResult['properties'] ?? [];
        $count         = count($properties);
        $city          = is_array($intent) ? ($intent['city'] ?? null) : null;
        $type          = is_array($intent) ? ($intent['property_type'] ?? null) : null;
        $needsClari    = $brainResult['needs_clarification'] ?? false;
        $pendingAction = $brainResult['accion_pendiente'] ?? null;

        $chips = [];

        // Helper para agregar chips de forma estructurada
        $addChip = function($label, $action = null) use (&$chips) {
            $chips[] = [
                'label'  => $label,
                'action' => $action
            ];
        };

        // 1. Estado de clarificación o saludo
        if ($intentType === 'saludo' || (empty($city) && $needsClari)) {
            $addChip('Busco en Bogotá');
            $addChip('Busco en Medellín');
            $addChip('Busco en Cartagena');
            return $chips;
        }

        // 2. Estado de agendamiento
        if ($pendingAction === 'esperando_fecha_visita' || $pendingAction === 'esperando_hora_visita') {
            $addChip('Mañana a las 10am');
            $addChip('Hoy mismo');
            $addChip('Sábado en la tarde');
            $addChip('Cancelar visita', 'cancelar_visita');
            return $chips;
        }

        // 3. Resultados de búsqueda
        if ($count > 0) {
            if ($count > 5) {
                $addChip('Las más baratas', 'las_mas_baratas');
                if (!$type) {
                    $addChip('Solo apartamentos', 'solo_apartamentos');
                    $addChip('Solo casas', 'solo_casas');
                } else {
                    $addChip('Con 2 habitaciones', 'dos_habitaciones');
                }
            } else {
                $addChip('Ver la primera', 'ver_primera');
                $addChip('¿Dónde quedan?', 'donde_quedan');
            }
            
            $addChip('Cambiar ciudad', 'cambiar_ciudad');
        } else {
            // Sin resultados
            $addChip('Quitar filtros', 'quitar_filtros');
            
            // SOLO si hay un punto de referencia (Ciudad o GPS)
            $hasGps = !empty($brainResult['context']['user_lat']) && !empty($brainResult['context']['user_lng']);
            if ($city || $hasGps) {
                $addChip('Ciudades cercanas', 'buscar_ciudad_cercana');
            }
            
            if ($city) {
                $addChip('Ver en toda la ciudad', 'ver_toda_ciudad');
            }
            $addChip('Cambiar ciudad', 'cambiar_ciudad');
        }

        // 4. Propiedad seleccionada
        if ($intentType === 'seleccionar_propiedad' || $count === 1) {
            $addChip('Agendar visita', 'agendar_visita');
            $addChip('Ver fotos', 'ver_fotos');
            $addChip('¿Cómo llego?', 'como_llego');
        }

        // Remover duplicados por label
        $uniqueChips = [];
        foreach ($chips as $c) {
            $uniqueChips[$c['label']] = $c;
        }

        return array_slice(array_values($uniqueChips), 0, 4);
    }
}
