<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Motor de interpretación local — cerebro NLP autónomo de Lyra.
 * Opera sin dependencias externas. Reemplaza completamente a GeminiInterpreterService.
 *
 * v2.1 — Correcciones:
 *  - Orden de detección reorganizado: patrones específicos SIEMPRE antes de saludo/fallback
 *  - VER_NUEVAS_PATTERNS ampliado y con prioridad sobre isGreeting
 *  - CONTRATO_PATTERNS ampliado (pagos, registro, login, soporte)
 *  - PROCESO_PATTERNS ampliado (registro, login, cuenta)
 *  - RECOMENDAR_CIUDAD_PATTERNS más robusto (frases cortas y variantes)
 *  - DOCUMENTOS_PATTERNS ampliado (requisitos arriendo)
 *  - SEGURIDAD_PATTERNS: funciona sin ciudad previa
 *  - COMPARAR_PATTERNS: no requiere resultados previos
 *  - NUEVO_USUARIO_PATTERNS ampliado
 *  - isGreeting() reforzado para NO capturar mensajes que son intents válidos
 */
class LocalInterpreterEngine
{
    private const CITIES = [
        'Bogotá'        => ['bogota', 'bogotá', 'bogot', 'bta'],
        'Medellín'      => ['medellin', 'medellín', 'mede'],
        'Cali'          => ['cali'],
        'Barranquilla'  => ['barranquilla', 'bquilla', 'barranq'],
        'Cartagena'     => ['cartagena'],
        'Cúcuta'        => ['cucuta', 'cúcuta'],
        'Bucaramanga'   => ['bucaramanga', 'buca'],
        'Pereira'       => ['pereira'],
        'Santa Marta'   => ['santa marta', 'santamarta'],
        'Ibagué'        => ['ibague', 'ibagué'],
        'Pasto'         => ['pasto'],
        'Villavicencio' => ['villavicencio', 'villavo', 'villao'],
        'Neiva'         => ['neiva'],
        'Armenia'       => ['armenia'],
        'Montería'      => ['monteria', 'montería'],
        'Valledupar'    => ['valledupar'],
        'Manizales'     => ['manizales'],
        'Popayán'       => ['popayan', 'popayán'],
        'Sincelejo'     => ['sincelejo'],
        'Tunja'         => ['tunja'],
        'Florencia'     => ['florencia'],
        'Riohacha'      => ['riohacha'],
        'Yopal'         => ['yopal'],
        'Soacha'        => ['soacha'],
        'Soledad'       => ['soledad'],
        'Bello'         => ['bello'],
        'Envigado'      => ['envigado'],
        'Itagüí'        => ['itagui', 'itagüí'],
        'Sabaneta'      => ['sabaneta'],
        'Dosquebradas'  => ['dosquebradas'],
        'Palmira'       => ['palmira'],
        'Buenaventura'  => ['buenaventura'],
        'Floridablanca' => ['floridablanca'],
        'Girardot'      => ['girardot'],
        'Duitama'       => ['duitama'],
        'Sogamoso'      => ['sogamoso'],
        'Facatativá'    => ['facatativa', 'facatativá'],
        'Zipaquirá'     => ['zipaquira', 'zipaquirá'],
        'Fusagasugá'    => ['fusagasuga', 'fusagasugá', 'fusa'],
        'Chía'          => ['chia', 'chía'],
        'Rionegro'      => ['rionegro'],
        'Apartadó'      => ['apartado', 'apartadó'],
        'Leticia'       => ['leticia'],
        'San Andrés'    => ['san andres', 'san andrés'],
        'Girón'         => ['giron', 'girón'],
        'Mosquera'      => ['mosquera'],
        'Tulúa'         => ['tulua', 'tulúa'],
    ];

    // Palabras que nunca son ciudades
    private const NOT_A_CITY = [
        'rentus',
        'rent us',
        'la plataforma',
        'el sistema',
        'esta plataforma',
        'este sistema',
        'acá',
        'aca',
        'aquí',
        'aqui',
        'allá',
        'alla',
        'allí',
        'alli',
        'armenia', // ambiguo — solo excluir si el contexto no es Colombia
    ];

    private const PROPERTY_TYPES = [
        'apartamento' => ['apartamento', 'apartamentos', 'apto', 'aptos', 'depto', 'departamento'],
        'casa'        => ['casa', 'casas', 'casita'],
        'local'       => ['local', 'locales', 'local comercial'],
        'lote'        => ['lote', 'lotes', 'terreno', 'terrenos'],
        'finca'       => ['finca', 'fincas'],
        'estudio'     => ['estudio', 'estudios', 'studio'],
        'oficina'     => ['oficina', 'oficinas'],
        'bodega'      => ['bodega', 'bodegas'],
    ];

    private const REFERENCE_PATTERNS = [
        'mar',
        'playa',
        'centro',
        'centro historico',
        'centro histórico',
        'universidad',
        'parque',
        'rio',
        'río',
        'lago',
        'montaña',
        'aeropuerto',
        'terminal',
        'estadio',
        'hospital',
        'iglesia',
        'mall',
        'centro comercial',
    ];

    private const KNOWN_NEIGHBORHOODS = [
        'El Poblado',
        'Laureles',
        'Envigado',
        'Belén',
        'El Rodadero',
        'Bocagrande',
        'Manga',
        'Chapinero',
        'Usaquén',
        'Cedritos',
        'La Candelaria',
        'Suba',
        'Kennedy',
        'El Laguito',
        'Castillogrande',
        'La Castellana',
        'El Cabrero',
        'Getsemaní',
        'El Centro',
        'La Flora',
        'Ciudad Jardín',
        'San Antonio',
        'Granada',
        'El Peñón',
        'Provenza',
        'Manila',
        'Conquistadores',
        'La América',
        'Robledo',
        'Calasanz',
        'Boston',
        'Prado',
        'Buenos Aires',
        'Taganga',
        'Pozos Colorados',
        'Bávaro',
        'La Esmeralda',
        'La Aurora',
        'San Fernando',
    ];

    private const AFFIRMATIONS = [
        'si',
        'sí',
        'claro',
        'dale',
        'ok',
        'bueno',
        'por supuesto',
        'de acuerdo',
        'hazlo',
        'muéstrame',
        'muestrame',
        'adelante',
        'quiero',
        'venga',
        'listo',
        'perfecto',
        'okay',
        'va',
    ];

    private const VALID_ZONES = [
        'norte'     => 'Norte',
        'sur'       => 'Sur',
        'centro'    => 'Centro',
        'este'      => 'Este',
        'oriente'   => 'Este',
        'occidente' => 'Oeste',
        'oeste'     => 'Oeste',
    ];

    private const ANALYTIC_PATTERNS = [
        'precio promedio',
        'promedio de precio',
        'cuánto cuesta en promedio',
        'cuántas propiedades hay',
        'cuantas propiedades hay',
        'qué tan caro es',
        'que tan caro',
        'estadísticas de',
        'estadisticas',
        'mercado inmobiliario',
        'tendencia de precios',
        'cuál es el promedio',
        'cual es el promedio',
        'y en general',
        'en general',
        'en total',
        'en toda la plataforma',
        'de toda la plataforma',
        'de todas las ciudades',
        'a nivel nacional',
        'de todo rentus',
        'de todas las propiedades',
        'cuántas propiedades tiene',
        'cuantas propiedades tiene',
        'cuántas propiedades hay en total',
        'cuántas propiedades tienen',
        'propiedades activas',
        'propiedades disponibles en total',
        'cuánto manejan',
        'cuanto manejan',
        'qué precios manejan',
        'que precios manejan',
        'promedio general',
        'promedio de toda',
        'promedio de todas',
        'cuántas tiene rentus',
        'cuantas tiene rentus',
        'cuántas propiedades tiene rentus',
        'cuantas propiedades tiene rentus',
        'cuántas hay en rentus',
        'cuantas hay en rentus',
        'cuánto tiene rentus',
        'cuanto tiene rentus',
        'total de rentus',
        'toda la plataforma',
        'en toda la plataforma',
        'rentus en total',
        'total rentus',
    ];

    private const CARDINAL_ZONES = [
        'norte',
        'el norte',
        'en el norte',
        'por el norte',
        'al norte',
        'hacia el norte',
        'zona norte',
        'parte norte',
        'sector norte',
        'noroccidental',
        'nororiental',
        'nor-occidental',
        'nor-oriental',
        'sur',
        'el sur',
        'en el sur',
        'por el sur',
        'al sur',
        'hacia el sur',
        'zona sur',
        'parte sur',
        'sector sur',
        'suroccidental',
        'suroriental',
        'sur-occidental',
        'sur-oriental',
        'centro',
        'el centro',
        'en el centro',
        'por el centro',
        'al centro',
        'zona centro',
        'parte central',
        'sector centro',
        'zona central',
        'del centro',
        'este',
        'el este',
        'en el este',
        'por el este',
        'al este',
        'zona este',
        'oriente',
        'el oriente',
        'en el oriente',
        'por el oriente',
        'zona oriental',
        'oeste',
        'el oeste',
        'en el oeste',
        'por el oeste',
        'al oeste',
        'zona oeste',
        'occidente',
        'el occidente',
        'en el occidente',
        'por el occidente',
        'zona occidental',
    ];

    private const AMENITY_REFINEMENT_PATTERNS = [
        'que tengan',
        'que tenga',
        'con parqueadero',
        'con piscina',
        'con gimnasio',
        'con balcón',
        'con balcon',
        'con jardín',
        'con jardin',
        'que incluya',
        'que incluyan',
        'con servicios',
        'amoblado',
        'amueblado',
        'que sea amoblado',
        'con garaje',
        'con ascensor',
        'pet friendly',
        'que admita mascotas',
        'con vigilancia',
        'conjunto cerrado',
    ];

    private const REFINE_PATTERNS = [
        'que tenga',
        'que tengan',
        'con parqueadero',
        'con piscina',
        'con gimnasio',
        'con balcón',
        'con balcon',
        'con ascensor',
        'con vigilancia',
        'amueblado',
        'amoblado',
        'amueblada',
        'solo las de',
        'filtra por',
        'agrega el filtro',
        'también que',
        'tambien que',
        'además que',
        'ademas que',
        'y que tenga',
        'pero que',
    ];

    private const USER_LOCATION_PATTERNS = [
        'cerca a mí',
        'cerca de mí',
        'cerca a mi',
        'cerca de mi',
        'en mi zona',
        'donde estoy',
        'aquí cerca',
        'aqui cerca',
        'mi ubicación',
        'mi ubicacion',
        'por aquí',
        'por aqui',
        'cerca a donde estoy',
        'qué hay cerca',
        'que hay cerca',
        'por acá',
        'por aca',
        'cerca de donde estoy',
        'en mi sector',
        'en mi barrio',
        'qué hay por aquí',
        'que hay por aqui',
        'qué hay por acá',
        'que hay por aca',
        'propiedades aquí',
        'propiedades aqui',
        'algo cerca',
        'algo por aquí',
        'algo por acá',
        'lo que haya cerca',
        'lo que hay por aquí',
        'propiedades cercanas',
        'cerca de mi ubicación',
        'cerca de mi ubicacion',
        'en mi area',
        'en mi área',
    ];

    private const SECURITY_PATTERNS = [
        'base de datos',
        'base datos',
        'database',
        'endpoints',
        'endpoint',
        'api key',
        'apikey',
        'servidor',
        'server',
        'código fuente',
        'codigo fuente',
        'contraseña',
        'password',
        'token',
        'secret',
        'arquitectura',
        'framework',
        'laravel',
        'mysql',
        'tabla',
        'columnas',
        'esquema',
        'migrations',
    ];

    private const OFF_TOPIC_PATTERNS = [
        'partido',
        'fútbol',
        'futbol',
        'deporte',
        'chiste',
        'broma',
        'película',
        'pelicula',
        'música',
        'musica',
        'canción',
        'cancion',
        'receta',
        'cocina',
        'clima',
        'tiempo',
        'política',
        'politica',
        'noticias',
        'quién ganó',
        'quien gano',
        'cómo se hace',
        'como se hace',
    ];

    // ── PATRONES DE INTENTS ───────────────────────────────────────────────────

    private const RECOMENDAR_CIUDAD_PATTERNS = [
        // Frases largas
        'recomiendas ciudad',
        'recomienda ciudad',
        'qué ciudad recomiendas',
        'que ciudad recomiendas',
        'cuál ciudad recomiendas',
        'cual ciudad recomiendas',
        'qué ciudad me recomiendas',
        'que ciudad me recomiendas',
        'cuál ciudad me recomiendas',
        'cual ciudad me recomiendas',
        'recomiéndame una ciudad',
        'recomendame una ciudad',
        'en qué ciudad debería buscar',
        'en que ciudad deberia buscar',
        'qué ciudad es mejor para',
        'que ciudad es mejor para',
        'cuál ciudad es mejor',
        'cual ciudad es mejor',
        'a qué ciudad me voy',
        'a que ciudad me voy',
        'dónde es mejor vivir',
        'donde es mejor vivir',
        'qué ciudad elegir',
        'que ciudad elegir',
        'dime una ciudad',
        'cuál me recomiendas para vivir',
        'cual me recomiendas para vivir',
        'mejores ciudades para vivir',
        'cuáles ciudades tienen más',
        'cuales ciudades tienen mas',
        'en qué ciudad buscar',
        'en que ciudad buscar',
        'ciudad recomendada',
        'mejores ciudades',
        // Frases cortas que fallaban
        'que ciudad me recomiendas',
        'qué ciudad',
        'que ciudad',
        'cual ciudad',
        'cuál ciudad',
        'recomiendas ciudad',
        'recomiendan ciudad',
        'mejor ciudad',
        'qué ciudad recomiendas',
        'que ciudades hay',
        'qué ciudades hay',
        'ciudades disponibles',
        'en que ciudad',
        'me voy a mudar',
        'quiero mudarme',
        'a que ciudad',
        'a qué ciudad',
        'recomienda una ciudad',
        'recomiéndame ciudad',
        // Variantes adicionales
        'recomendar ciudad',
        'recomiendes ciudad',
        'recomiendame ciudad',
        'recomiendacion de ciudad',
        'recomendacion de ciudad',
        'recomendación de ciudad',
        'recomendación ciudad',
        'recomendación',
        'recomiendacion ciudad',
        'recomendacion ciudad',
        'podrias recomendar',
        'puedes recomendar',
        'recomendar alguna ciudad',
        'para irme a vivir',
        'para vivir en',
        'donde vivir',
        'para mudarme',
        'me recomiendas para vivir',
        'recomendame ciudad',
        'cambiemos de ciudad',
        'cambiamos de ciudad',
        'probemos otra ciudad',
        'probamos otra ciudad',
        'busquemos en otra ciudad',
        'otra ciudad',
        'cambia de ciudad',
        'cambia la ciudad',
    ];

    private const VER_NUEVAS_PATTERNS = [
        // Triggers simples
        'nueva',
        'nuevas',
        'novedad',
        'novedades',
        // Originales
        'nuevas propiedades',
        'propiedades nuevas',
        'qué hay nuevo',
        'que hay nuevo',
        'qué hay de nuevo',
        'que hay de nuevo',
        'últimas propiedades',
        'ultimas propiedades',
        'recién agregadas',
        'recien agregadas',
        'agregadas recientemente',
        'publicadas recientemente',
        'más recientes',
        'mas recientes',
        'las nuevas',
        'las más nuevas',
        'las mas nuevas',
        'novedades',
        'qué novedades hay',
        'que novedades hay',
        'acabaron de publicar',
        'acaban de publicar',
        'propiedades recientes',
        'lo más nuevo',
        'lo mas nuevo',
        'agregaron recientemente',
        // Variantes adicionales que fallaban
        'nuevas casas',
        'nuevos apartamentos',
        'nuevas fincas',
        'nuevos locales',
        'nuevas oficinas',
        'nuevos estudios',
        'nuevas bodegas',
        'nuevos lotes',
        'nuevas opciones',
        'nueva casa',
        'nuevo apartamento',
        'nueva finca',
        'nuevo local',
        'nueva oficina',
        'nuevo estudio',
        'hay nuevas',
        'hay nuevos',
        'existen nuevas',
        'existen nuevos',
        'muestrame nuevas',
        'muéstrame nuevas',
        'ver nuevas',
        'ver nuevos',
        // Con "en rentus"
        'hay nuevas propiedades en rentus',
        'hay nuevos en rentus',
        'nuevas en rentus',
        'nuevas de rentus',
        // Sin "propiedad" explícita
        'qué hay nuevo en rentus',
        'que hay nuevo en rentus',
        'novedades en rentus',
        'lo nuevo de rentus',
    ];

    private const SUGERIR_CIUDADES_CERCANAS_PATTERNS = [
        'ciudades cercanas',
        'que hay cerca',
        'qué hay cerca',
        'opciones cercanas',
        'ciudades nearby',
        'ciudades mas cercanas',
        'ciudades más cercanas',
        'municipios cercanos',
        'buscar cerca',
        'en otra ciudad cercana',
    ];

    private const CONTRATO_PATTERNS = [
        // Triggers simples
        'contrato',
        'contratos',
        'legal',
        'legalidad',
        // Contratos
        'contrato de arriendo',
        'contrato de arrendamiento',
        'cómo funciona el contrato',
        'como funciona el contrato',
        'duración del contrato',
        'duracion del contrato',
        'tiempo del contrato',
        'meses del contrato',
        'renovación del contrato',
        'renovacion del contrato',
        'términos del contrato',
        'terminos del contrato',
        'cláusulas',
        'clausulas',
        'firma del contrato',
        'firmar contrato',
        'qué dice el contrato',
        'que dice el contrato',
        'contrato de arrendamiento colombia',
        'cómo son los contratos',
        'como son los contratos',
        // Pagos
        'los pagos',
        'el pago',
        'cómo se paga',
        'como se paga',
        'forma de pago',
        'formas de pago',
        'métodos de pago',
        'metodos de pago',
        'cuándo se paga',
        'cuando se paga',
        'pago mensual',
        'pago del arriendo',
        'cómo pago',
        'como pago',
        'por dónde pago',
        'por donde pago',
        'transferencia',
        'consignación',
        'consignacion',
        // Sistema de pagos / plataforma de pago
        'sistema de pagos',
        'sistema de pago',
        'pasarela de pago',
        'pagar el arriendo',
        'pagar arriendo',
        'realizar el pago',
        'hacer el pago',
        'efectuar el pago',
        'medios de pago',
        'se puede pagar con',
        'aceptan tarjeta',
        'pago en línea',
        'pago online',
        'pago digital',
    ];

    private const DOCUMENTOS_PATTERNS = [
        // Triggers simples
        'documento',
        'documentos',
        'papel',
        'papeles',
        'requisito',
        'requisitos',
        // Originales
        'qué documentos',
        'que documentos',
        'cuáles documentos',
        'cuales documentos',
        'qué papeles',
        'que papeles',
        'documentos necesito',
        'papeles necesito',
        'qué necesito para arrendar',
        'que necesito para arrendar',
        'qué piden para',
        'que piden para',
        'requisitos para arrendar',
        'requisitos del arriendo',
        'qué requisitos',
        'que requisitos',
        'codeudor',
        'fiador',
        'referencias personales',
        'comprobante de ingresos',
        'certificado laboral',
        'extractos bancarios',
        // Variantes adicionales
        'requisitos de arrendamiento',
        'documentos de arrendamiento',
        'que se necesita para arrendar',
        'qué se necesita para arrendar',
        'que piden',
        'qué piden',
        'que solicitan',
        'qué solicitan',
        'que exigen',
        'qué exigen',
        'exigen documentos',
        'que debo presentar',
        'qué debo presentar',
        'documentos requeridos',
        'papeles requeridos',
        'qué documentación',
        'que documentacion',
        'para hacer el arriendo',
        'para alquilar',
        'qué necesito',
        'que necesito',
    ];

    private const PROCESO_PATTERNS = [
        // Proceso de arriendo
        'cómo funciona',
        'como funciona',
        'cómo es el proceso',
        'como es el proceso',
        'cómo arrendar',
        'como arrendar',
        'cómo se arrienda',
        'como se arrienda',
        'pasos para arrendar',
        'proceso de arriendo',
        'proceso de arrendamiento',
        'cómo se hace en rentus',
        'como se hace en rentus',
        'cómo usar rentus',
        'como usar rentus',
        'qué pasos',
        'que pasos',
        'qué hago para arrendar',
        'que hago para arrendar',
        // Registro / cuenta
        'cómo me registro',
        'como me registro',
        'como me puedo registrar',
        'cómo me puedo registrar',
        'registrarme',
        'crear cuenta',
        'crear mi cuenta',
        'cómo crear cuenta',
        'como crear cuenta',
        'registrar usuario',
        'crear usuario',
        'como registrarme',
        'quiero registrarme',
        'quiero crear cuenta',
        'nuevo usuario',
        'crear perfil',
        'abrir cuenta',
        // Login / acceso
        'cómo me logueo',
        'como me logueo',
        'como me logeo',
        'cómo iniciar sesión',
        'como iniciar sesion',
        'iniciar sesión',
        'iniciar sesion',
        'cómo entrar',
        'como entrar a rentus',
        'cómo accedo',
        'como accedo',
        'cómo me logueo',
        'olvidé mi contraseña',
        'olvide mi contraseña',
        'recuperar contraseña',
        'cambiar contraseña',
        'problema con mi cuenta',
        'no puedo entrar',
        'login',
        'loguearme',
        'logearme',
        'logearme en rentus',
        // Post-search flow signals
        'como sigo el proceso',
        'cómo sigo el proceso',
        'qué sigue ahora',
        'que sigue ahora',
        'qué sigue',
        'que sigue',
        'cuál es el siguiente paso',
        'cual es el siguiente paso',
        'qué hago después',
        'que hago despues',
        'qué hago ahora',
        'que hago ahora',
        'cómo avanzo',
        'como avanzo',
        'cómo continúo',
        'como continuo',
        'cómo procedo',
        'como procedo',
        'qué paso sigue',
        'que paso sigue',
        'después de encontrar',
        'despues de encontrar',
        'ya encontré algo',
        'ya encontre algo',
        'qué hago con esta propiedad',
        'que hago con esta propiedad',
        'cómo es el siguiente paso',
        'como es el siguiente paso',
        'y ahora qué',
        'y ahora que',
        'qué hago si me gusta',
        'que hago si me gusta',
        // Soporte general
        'cómo funciona la plataforma',
        'como funciona la plataforma',
        'explicame cómo funciona',
        'explicame como funciona',
        'guía de uso',
        'tutorial',
        'ayuda con rentus',
        'cómo publico',
        'como publico',
        'publicar propiedad',
        'cómo pongo en arriendo',
        'como pongo en arriendo',
        'soy propietario',
        'tengo una propiedad para arrendar',
    ];

    private const URGENTE_PATTERNS = [
        'urgente',
        'es urgente',
        'lo necesito ya',
        'necesito algo ya',
        'para ya',
        'inmediatamente',
        'lo antes posible',
        'cuanto antes',
        'cuánto antes',
        'tengo prisa',
        'tengo urgencia',
        'rápido',
        'rapido',
        'me urge',
        'urge',
        'muy rápido',
        'muy rapido',
        'para hoy',
        'para mañana',
        'esta semana',
        'lo más pronto',
        'lo mas pronto',
    ];

    private const DISPONIBILIDAD_INMEDIATA_PATTERNS = [
        'disponible ahora',
        'disponibles ahora',
        'disponible ya',
        'disponibles ya',
        'para entrar ya',
        'para entrar ahora',
        'entrega inmediata',
        'disponibilidad inmediata',
        'que esté disponible ya',
        'que esten disponibles ya',
        'para ocupar ya',
        'para ocupar ahora',
        'libre ahora',
        'libre ya',
        'disponible de inmediato',
    ];

    private const REINICIAR_PATTERNS = [
        'empezar de cero',
        'empezar de nuevo',
        'nueva búsqueda',
        'nueva busqueda',
        'reiniciar',
        'reiniciemos',
        'borrar todo',
        'limpiar todo',
        'comenzar de nuevo',
        'comenzar de cero',
        'olvidemos lo anterior',
        'otra búsqueda',
        'otra busqueda',
        'cambiar todo',
        'empecemos de nuevo',
        'empecemos de cero',
    ];

    private const LIMPIAR_FILTROS_PATTERNS = [
        'quitar filtros',
        'quitar los filtros',
        'limpiar filtros',
        'limpiar los filtros',
        'borrar filtros',
        'borrar los filtros',
        'sin filtros',
        'eliminar filtros',
        'eliminar los filtros',
        'remover filtros',
        'quita los filtros',
        'quita el filtro',
        'sin restricciones',
        'ver todos sin filtro',
    ];

    private const SEGURIDAD_ZONA_PATTERNS = [
        'qué tan seguro',
        'que tan seguro',
        'qué tan segura',
        'que tan segura',
        'es seguro',
        'es segura',
        'seguridad del barrio',
        'seguridad de la zona',
        'zona segura',
        'barrio seguro',
        'qué tal la seguridad',
        'que tal la seguridad',
        'peligroso',
        'peligrosa',
        'delincuencia',
        'robo',
        'robos',
        'tranquilo el barrio',
        'tranquila la zona',
        // Variantes adicionales
        'seguridad',
        'tan seguro',
        'es peligroso',
        'como es la seguridad',
        'cómo es la seguridad',
        'hay inseguridad',
        'zona peligrosa',
        'me puedo sentir seguro',
        'nivel de seguridad',
        'cómo está la seguridad',
        'como esta la seguridad',
    ];

    private const TRANSPORTE_PATTERNS = [
        'transporte',
        'transporte público',
        'transporte publico',
        'bus',
        'buses',
        'metro',
        'metrocable',
        'mio',
        'transmilenio',
        'rutas de bus',
        'ruta de bus',
        'cómo se mueve',
        'como se mueve',
        'movilidad',
        'cerca a estación',
        'cerca a estacion',
        'cerca al metro',
        'cerca al bus',
        'cerca a la vía',
        'cerca a la via',
        'accesibilidad',
        'bien comunicado',
    ];

    private const CONTACTAR_PATTERNS = [
        'contactar al propietario',
        'contactar propietario',
        'hablar con el dueño',
        'hablar con el propietario',
        'cómo contacto',
        'como contacto',
        'número del propietario',
        'whatsapp del propietario',
        'teléfono del propietario',
        'cómo me comunico',
        'como me comunico',
        'quiero contactar',
        'escribirle al propietario',
        'llamar al propietario',
        'como me contacto',
        'cómo me contacto',
        'me contacto con',
        'contacto con el',
        'cómo me contacto con el cliente',
        'cómo me contacto con el propietario',
        'como me contacto con el propietario',
        'quiero contactarme',
        'me puedo contactar',
        'contactarme con',
        'datos de contacto',
        'información del propietario',
        'info del propietario',
        'dame el contacto',
        'me pasa el contacto',
        'número de contacto',
        'cómo contacto al dueño',
        'como contacto al dueño',
        'cómo hablo con',
        'como hablo con',
        'quiero hablar con el',
        'necesito contactar',
        'escribirle al dueño',
        'cómo le escribo',
        'como le escribo',
        'cómo le llamo',
        'como le llamo',
        'cómo avanzo con esta',
        'como avanzo con esta',
        'qué hago para contactar',
        'que hago para contactar',
    ];

    private const SELECCIONAR_PROPIEDAD_PATTERNS = [
        'quiero seguir con',
        'me quedo con',
        'elijo esta',
        'elijo esa',
        'me interesa esta',
        'me interesa esa',
        'voy con la primera',
        'voy con la segunda',
        'voy con la tercera',
        'quiero esa',
        'me convence',
        'la quiero',
        'procedo con',
        'avanzo con',
        'me quedo con esta',
        'me quedo con esa',
        'esta me gustó',
        'esa me gustó',
        'esta me gusta',
        'esa me gusta',
        'me decido por esta',
        'me decido por esa',
        'escojo esta',
        'escojo esa',
        'elijo la primera',
        'elijo la segunda',
        'me llevo esta',
        'quiero arrendar esta',
        'quiero arrendar esa',
        'quiero esta propiedad',
        'quiero esa propiedad',
        'la recomendada',
        'la que recomendaste',
        'la que me recomendaste',
        'seguir con esta',
        'seguir con esa',
        'avanzar con esta',
        'avanzar con esa',
        'me quedo con la recomendada',
        'la escojo',
        'me convence esta',
        'me convence esa',
        'voy con esa',
        'voy con esta',
        'esa es la que quiero',
        'esta es la que quiero',
        'me gusta esa',
        'me gusta esta',
    ];

    private const AGENDAR_VISITA_PATTERNS = [
        'agendar visita',
        'agendar una visita',
        'programar visita',
        'programar una visita',
        'cómo agendo',
        'como agendo',
        'cómo programo',
        'como programo',
        'agendar una cita',
        'agendar cita',
        'pedir cita',
        'solicitar visita',
        'hacer una cita',
        'pedir una visita',
        'pedir visita',
        'quiero una cita',
        'quiero agendar',
        'quiero ir a verla',
        'quiero ir a verlo',
    ];

    private const DESPEDIDA_PATTERNS = [
        'chao', 'ciao', 'adiós', 'adios', 'hasta luego', 'nos vemos',
        'gracias', 'muchas gracias', 'grax', 'thank you',
        'listo', 'perfecto', 'todo bien', 'era todo',
        'chaito', 'bye', 'goodbye', 'hasta pronto'
    ];

    private const COMPARAR_PATTERNS = [
        'compara',
        'comparar',
        'comparame',
        'compárame',
        'comparar propiedades',
        'diferencia entre',
        'cuál es la diferencia',
        'cual es la diferencia',
        'versus',
        ' vs ',
        'cuál es mejor entre',
        'cual es mejor entre',
        'entre la primera y',
        'entre la segunda y',
        'cuál es mejor la primera',
        'cual es mejor la primera',
        'compara la primera con la segunda',
        'la primera vs',
        'la segunda vs',
        'cuál tienen más',
        'cual tienen mas',
        'cuál conviene más',
        'cual conviene mas',
        'en cuál me quedo',
        'en cual me quedo',
        // Variantes adicionales
        'comparar las propiedades',
        'compare',
        'hacer una comparación',
        'hacer una comparacion',
        'cuál es mejor',
        'cual es mejor',
        'diferencias entre propiedades',
        'comparativo',
    ];

    private const INDECISO_CIUDAD_PATTERNS = [
        'no sé a qué ciudad',
        'no se a que ciudad',
        'no sé en qué ciudad',
        'no se en que ciudad',
        'no sé cuál ciudad',
        'no se cual ciudad',
        'no sé dónde',
        'no se donde',
        'estoy entre',
        'me debate entre',
        'no me decido entre',
        'tanto bogotá como',
        'tanto bogota como',
        'tanto medellín como',
        'tanto medellin como',
        'o bogotá o',
        'o bogota o',
        'o medellín o',
        'o medellin o',
        'no sé si bogotá',
        'no se si bogota',
        'no sé si medellín',
        'no se si medellin',
        'tengo dudas entre ciudades',
        // Variantes
        'no sé a dónde irme',
        'no se a donde irme',
        'no sé a dónde mudarme',
        'no se a donde mudarme',
        'me debato entre',
        'tengo duda entre',
    ];

    private const NUEVO_USUARIO_PATTERNS = [
        'es mi primera vez',
        'primera vez aquí',
        'primera vez aqui',
        'acabo de llegar',
        'no sé cómo funciona',
        'no se como funciona esto',
        'cómo empiezo',
        'como empiezo',
        'por dónde empiezo',
        'por donde empiezo',
        'soy nuevo',
        'soy nueva',
        'nunca he usado rentus',
        'primera vez en rentus',
        // Variantes adicionales
        'primera vez',
        'es mi primera',
        'nunca he estado aquí',
        'nunca he estado aqui',
        'acabo de conocer rentus',
        'me acaban de recomendar',
        'me recomendaron rentus',
        'no conozco rentus',
        'no sé qué es rentus',
        'no se que es rentus',
        'llegué nuevo',
        'llegue nuevo',
        'llegué nueva',
        'llegue nueva',
        'estoy empezando',
        'recién llegué',
        'recien llegue',
    ];

    private const VER_TODAS_PATTERNS = [
        'ver todas',
        'mostrar todas',
        'quiero ver todas',
        'ver todo',
        'mostrar todo',
        'sin filtro',
        'todas las propiedades',
        'todo lo que hay',
        'ver todo lo disponible',
        'mostrar todo lo disponible',
        'qué hay disponible',
        'que hay disponible',
        'qué hay en total',
        'que hay en total',
    ];

    private const VOLVER_RESULTADOS_PATTERNS = [
        'volver a los resultados',
        'volver a las propiedades',
        'ver los resultados anteriores',
        'ver las propiedades anteriores',
        'regresar a los resultados',
        'volver atrás',
        'volver atras',
        'los anteriores',
        'las anteriores',
        'los de antes',
        'las de antes',
        'mostrar los anteriores',
        'mostrar las anteriores',
        'qué tenías antes',
        'que tenias antes',
        'los resultados anteriores',
        'las propiedades anteriores',
    ];

    // Palabras que indican claramente que no es un saludo aunque el mensaje sea corto
    private const NOT_GREETING_SIGNALS = [
        // de PROCESO_PATTERNS
        'registro',
        'registrar',
        'logueo',
        'logeo',
        'login',
        'contraseña',
        'iniciar sesion',
        'iniciar sesión',
        'cuenta',
        // de CONTRATO_PATTERNS
        'contrato',
        'pago',
        'pagos',
        'arriendo',
        'arrendamiento',
        // de DOCUMENTOS_PATTERNS
        'documentos',
        'requisitos',
        'papeles',
        // de VER_NUEVAS_PATTERNS
        'nueva',
        'nuevas',
        'nuevos',
        'nuevo',
        'reciente',
        'recientes',
        'novedades',
        'ultimas',
        'últimas',
        // de RECOMENDAR_CIUDAD_PATTERNS
        'ciudad',
        'ciudades',
        'mudar',
        'mudanza',
        // de SEGURIDAD_ZONA_PATTERNS
        'seguro',
        'segura',
        'seguridad',
        'peligros',
        // de COMPARAR_PATTERNS
        'compara',
        'comparar',
        'versus',
        ' vs ',
        // de TRANSPORTE_PATTERNS
        'transporte',
        'bus',
        'metro',
        // generales
        'propiedad',
        'propiedades',
        'casa',
        'casas',
        'apartamento',
        'apartamentos',
        'busco',
        'buscar',
        'necesito',
        'quiero',
        'precio',
        'precios',
    ];

    // ─────────────────────────────────────────────────────────────────────────

    public function interpret(string $text, array $context = []): array
    {
        $normalized   = $this->normalize($text);
        $ultimaCiudad = $context['ultima_ciudad'] ?? null;

        // ══ 0. Seguridad técnica (inyección, endpoints, passwords) ══
        foreach (self::SECURITY_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('fuera_de_contexto', [
                    'security_violation' => true,
                    'response_text'      => '',
                ]);
            }
        }

        // ══ 1. SELECCIONAR_PROPIEDAD (máxima prioridad de acción) ══
        foreach (self::SELECCIONAR_PROPIEDAD_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('seleccionar_propiedad', ['response_text' => '']);
            }
        }

        // ══ 1.5 DESPEDIDA / GRACIAS — Prioridad alta para cerrar flujos ══
        foreach (self::DESPEDIDA_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('despedida', ['response_text' => '']);
            }
        }

        // ══ 2. CONTACTAR_PROPIETARIO ══
        foreach (self::CONTACTAR_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('contactar_propietario', ['response_text' => '']);
            }
        }

        // ══ 3. AGENDAR_VISITA ══
        foreach (self::AGENDAR_VISITA_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('agendar_visita', ['response_text' => '']);
            }
        }

        // ══ 4. CONSULTA_CONTRATO ══
        foreach (self::CONTRATO_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('consulta_contrato', ['response_text' => '']);
            }
        }

        // ══ 5. CONSULTA_DOCUMENTOS ══
        foreach (self::DOCUMENTOS_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('consulta_documentos', [
                    'city'          => $this->extractCity($normalized) ?? $ultimaCiudad,
                    'response_text' => '',
                ]);
            }
        }

        // ══ 6. CONSULTA_PROCESO ══
        foreach (self::PROCESO_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('consulta_proceso', ['response_text' => '']);
            }
        }

        // ══ 7. VER_NUEVAS (solo si NO hay property_type en el mensaje) ══
        $tipo = $this->extractPropertyType($normalized);
        if (!$tipo) {
            foreach (self::VER_NUEVAS_PATTERNS as $p) {
                if (str_contains($normalized, $p)) {
                    return $this->buildIntent('ver_nuevas', ['response_text' => '']);
                }
            }
        }

        // ══ 8. Acción pendiente + afirmación ══
        if ($this->isAffirmation($normalized) && ($context['pending_action'] ?? null)) {
            return $this->buildIntent('afirmacion_accion_pendiente', ['response_text' => '']);
        }

        // ══ 9. Consulta analítica ══
        if ($this->isConsultaAnalitica($normalized)) {
            return $this->buildIntent('consulta_analitica', ['response_text' => '']);
        }

        // ══ 10. Preguntar RentUs ══
        if ($this->isRentUsQuestion($normalized)) {
            return $this->buildIntent('preguntar_rentus', ['response_text' => '']);
        }

        // ══ 11. Nuevo usuario ══
        foreach (self::NUEVO_USUARIO_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('nuevo_usuario', ['response_text' => '']);
            }
        }

        // ══ 12. Seguridad zona / transporte ══
        foreach (self::SEGURIDAD_ZONA_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('consulta_seguridad', ['city' => $ultimaCiudad, 'response_text' => '']);
            }
        }
        foreach (self::TRANSPORTE_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('consulta_transporte', ['city' => $ultimaCiudad, 'response_text' => '']);
            }
        }

        // ══ 13. Urgente / disponibilidad inmediata ══
        foreach (self::URGENTE_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('urgente', ['response_text' => '']);
            }
        }
        foreach (self::DISPONIBILIDAD_INMEDIATA_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('disponibilidad_inmediata', ['response_text' => '']);
            }
        }

        // ══ 14. Flujos de sesión (reiniciar, limpiar, ver todas) ══
        foreach (self::REINICIAR_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('reiniciar', ['response_text' => '']);
            }
        }
        foreach (self::LIMPIAR_FILTROS_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('limpiar_filtros', ['response_text' => '']);
            }
        }
        foreach (self::VER_TODAS_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('ver_todas', ['response_text' => '']);
            }
        }

        // ══ 15. Recomendar ciudad / indeciso ciudades ══
        foreach (self::RECOMENDAR_CIUDAD_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('recomendar_ciudad', ['response_text' => '']);
            }
        }

        foreach (self::SUGERIR_CIUDADES_CERCANAS_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('buscar_ciudad_cercana', ['response_text' => '']);
            }
        }
        
        foreach (self::SUGERIR_CIUDADES_CERCANAS_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('buscar_ciudad_cercana', ['response_text' => '']);
            }
        }
        foreach (self::INDECISO_CIUDAD_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('indeciso_ciudades', ['response_text' => '']);
            }
        }

        // ══ 16. Recomendación de propiedad ══
        if ($this->isRecomendacion($normalized)) {
            return $this->buildIntent('recomendacion', ['response_text' => '']);
        }

        // ══ 17. Zona cardinal ══
        foreach (self::CARDINAL_ZONES as $p) {
            if (str_contains($normalized, $p)) {
                $zone = $this->extractZone($normalized);
                return $this->buildIntent('buscar_propiedad', ['city' => $ultimaCiudad, 'zone' => $zone, 'response_text' => '']);
            }
        }

        // ══ 18. Consulta resultados previos ══
        if ($this->isConsultaResultados($normalized, $context)) {
            return $this->buildIntent('consulta_resultados', [
                'pregunta_sobre_resultado' => $this->detectTipoConsulta($normalized),
                'propiedad_referenciada'   => $this->extractPropertyReference($normalized),
                'response_text' => '',
            ]);
        }

        // ══ 19. Comparar propiedades ══
        foreach (self::COMPARAR_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('comparar_propiedades', ['response_text' => '']);
            }
        }

        // ══ 20. Ubicación GPS ══
        foreach (self::USER_LOCATION_PATTERNS as $p) {
            if (str_contains($normalized, $p)) {
                return $this->buildIntent('ubicacion_usuario', ['response_text' => '']);
            }
        }

        // ══ 20.5. Detección de regiones geográficas ══
        $regionMap = [
            'eje cafetero'    => ['Manizales', 'Pereira', 'Armenia'],
            'costa caribe'    => ['Barranquilla', 'Cartagena', 'Santa Marta'],
            'caribe'          => ['Barranquilla', 'Cartagena', 'Santa Marta'],
            'sabana'          => ['Bogotá', 'Chía', 'Zipaquirá', 'Mosquera'],
            'llanos'          => ['Villavicencio', 'Yopal'],
            'antioquia'       => ['Medellín', 'Bello', 'Envigado', 'Itagüí'],
            'santanderes'     => ['Bucaramanga', 'Cúcuta'],
            'valle del cauca' => ['Cali', 'Palmira', 'Buga'],
        ];
        foreach ($regionMap as $regionKey => $regionCities) {
            if (str_contains($normalized, $regionKey)) {
                // Usar la primera ciudad de la región como ciudad principal
                return $this->buildIntent('buscar_propiedad', [
                    'city'          => $regionCities[0],
                    'region_cities' => $regionCities,
                    'response_text' => '',
                ]);
            }
        }

        // ══ 21. Extracción de filtros → buscar_propiedad ══
        $normalizedNoWords = $this->normalizeNumberWords($normalized);
        $city              = $this->extractCity($normalizedNoWords);
        $tipo              = $this->extractPropertyType($normalizedNoWords);
        $maxPrice          = $this->extractMaxPrice($normalizedNoWords);
        $minPrice          = $this->extractMinPrice($normalizedNoWords);
        $bedrooms          = $this->extractBedrooms($normalizedNoWords);
        $bathrooms         = $this->extractBathrooms($normalizedNoWords);
        $referencePlace    = $this->extractReferencePlace($normalizedNoWords);
        $neighborhood      = $this->extractNeighborhood($normalizedNoWords, $city ?? $ultimaCiudad);
        $amenities         = $this->extractAmenities($normalizedNoWords);

        if ($city || $tipo || $maxPrice || $minPrice || $bedrooms || $neighborhood || $referencePlace || !empty($amenities)) {
            return $this->buildIntent('buscar_propiedad', [
                'city'            => $city ?: $ultimaCiudad,
                'property_type'   => $tipo,
                'max_price'       => $maxPrice,
                'min_price'       => $minPrice,
                'bedrooms'        => $bedrooms,
                'bathrooms'       => $bathrooms,
                'amenities'       => $amenities,
                'neighborhood'    => $neighborhood,
                'reference_place' => $referencePlace,
                'radius_km'       => $this->calculateSmartRadius($neighborhood, $referencePlace, $city ?: $ultimaCiudad),
                'is_new_search'   => (bool)$city,
                'response_text'   => '',
            ]);
        }

        // ══ 22. Cambio simple de ciudad ══
        if ($city) {
            return $this->buildIntent('buscar_propiedad', ['city' => $city, 'response_text' => '']);
        }

        // ══ 23. Saludo (siempre al final) ══
        if ($this->isGreeting($normalized)) {
            return $this->buildIntent('saludo', ['response_text' => '']);
        }

        // ══ 24. FALLBACK INTELIGENTE ══
        $procesoSeñales  = ['sigo', 'siguiente', 'ahora', 'después', 'avanzo', 'continúo', 'procedo', 'qué hago'];
        $contactoSeñales = ['contacto', 'contactar', 'comunico', 'escribo', 'llamo', 'hablo'];
        $seleccionSeñales = ['esta', 'esa', 'quiero', 'elijo', 'quedo', 'recomendada', 'verla', 'verlo'];

        foreach ($procesoSeñales as $señal) {
            if (preg_match("/\b" . preg_quote($señal, '/') . "\b/u", $normalized)) {
                return $this->buildIntent('consulta_proceso', ['response_text' => '']);
            }
        }
        foreach ($contactoSeñales as $señal) {
            if (preg_match("/\b" . preg_quote($señal, '/') . "\b/u", $normalized)) {
                return $this->buildIntent('contactar_propietario', ['response_text' => '']);
            }
        }
        foreach ($seleccionSeñales as $señal) {
            if (preg_match("/\b" . preg_quote($señal, '/') . "\b/u", $normalized)) {
                return $this->buildIntent('seleccionar_propiedad', ['response_text' => '']);
            }
        }

        // ══ 22.5. Validación de input inválido (gibberish/nonsense) ══
        $cleanText = preg_replace('/\s+/', '', $normalized);
        $isGibberish = false;

        // Sin vocales (excluyendo números puros y siglas comunes)
        if (!preg_match('/[aeiouáéíóúü]/ui', $cleanText) && !preg_match('/^\d+$/', $cleanText)) {
            $isGibberish = true;
        }
        // 4+ caracteres repetidos seguidos (ej. "aaaaa")
        if (preg_match('/(.)\1{3,}/u', $cleanText)) {
            $isGibberish = true;
        }
        // Muy corto y no es un número ni afirmación válida
        $shortAffirmations = ['si', 'sí', 'no', 'ok', 'ya', 'va', 'bta'];
        if (mb_strlen($cleanText) <= 2 && !preg_match('/^\d+$/', $cleanText) && !in_array($cleanText, $shortAffirmations)) {
            $isGibberish = true;
        }
        // Palabras sin sentido específicas reportadas
        $nonsenseWords = ['chalera', 'chao chao', 'chau chau'];
        foreach ($nonsenseWords as $nw) {
            if (str_contains($normalized, $nw)) {
                $isGibberish = true;
                break;
            }
        }

        if ($isGibberish) {
            return $this->buildIntent('fuera_de_contexto', [
                'confidence' => 0.1,
                'response_text' => ''
            ]);
        }

        // ══ 23. Saludo (siempre al final) ══
        if ($this->isGreeting($normalized)) {
            return $this->buildIntent('saludo', ['confidence' => 0.9, 'response_text' => '']);
        }

        // ══ 24. FALLBACK INTELIGENTE ══
        $procesoSeñales  = ['sigo', 'siguiente', 'ahora', 'después', 'avanzo', 'continúo', 'procedo', 'qué hago'];
        $contactoSeñales = ['contacto', 'contactar', 'comunico', 'escribo', 'llamo', 'hablo', 'propietario', 'dueño'];
        $seleccionSeñales = ['esta', 'esa', 'quiero', 'elijo', 'quedo', 'recomendada'];

        foreach ($procesoSeñales as $señal) {
            if (preg_match("/\b" . preg_quote($señal, '/') . "\b/u", $normalized)) {
                return $this->buildIntent('consulta_proceso', ['confidence' => 0.8, 'response_text' => '']);
            }
        }
        foreach ($contactoSeñales as $señal) {
            if (preg_match("/\b" . preg_quote($señal, '/') . "\b/u", $normalized)) {
                return $this->buildIntent('contactar_propietario', ['confidence' => 0.8, 'response_text' => '']);
            }
        }
        foreach ($seleccionSeñales as $señal) {
            if (preg_match("/\b" . preg_quote($señal, '/') . "\b/u", $normalized)) {
                return $this->buildIntent('seleccionar_propiedad', ['confidence' => 0.8, 'response_text' => '']);
            }
        }

        // Fallback real — solo si hay alguna entidad mínima. Si no, fuera de contexto.
        if ($city || $tipo || $maxPrice || $minPrice || $bedrooms || $neighborhood || $referencePlace) {
            return $this->buildIntent('buscar_propiedad', [
                'intent' => 'buscar_propiedad',
                'confidence' => 0.75,
                'city'          => $city ?: $ultimaCiudad,
                'property_type' => $tipo,
                'max_price'     => $maxPrice,
                'min_price'     => $minPrice,
                'bedrooms'      => $bedrooms,
                'bathrooms'     => $bathrooms,
                'amenities'     => $amenities,
                'neighborhood'    => $neighborhood,
                'reference_place' => $referencePlace,
                'response_text' => '',
            ]);
        }

        return $this->buildIntent('fuera_de_contexto', [
            'confidence'    => 0.1,
            'response_text' => '',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  HELPERS DE DETECCIÓN
    // ─────────────────────────────────────────────────────────────────────────

    private function matchesPatterns(string $text, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (str_contains($text, $p)) return true;
        }
        return false;
    }

    private function isZonaGeografica(string $text): bool
    {
        foreach (self::CARDINAL_ZONES as $zone) {
            if (str_contains($text, $zone)) return true;
        }
        return false;
    }

    private function extractZone(string $text): string
    {
        foreach (self::VALID_ZONES as $key => $value) {
            if (str_contains(mb_strtolower($text), $key)) return $value;
        }
        return 'Centro';
    }

    private function isRecomendacion(string $text): bool
    {
        $patterns = [
            'recomiendas',
            'recomienda',
            'recomendacion',
            'recomendación',
            'me recomiendas',
            'cuál recomiendas',
            'cual recomiendas',
            'qué recomiendas',
            'que recomiendas',
            'cuál es la mejor',
            'cual es la mejor',
            'la mejor opción',
            'la mejor opcion',
            'la mejor alternativa',
            'la mejor propiedad',
            'cuál tomarías',
            'cual tomarias',
            'cuál escoges',
            'cual escoges',
            'cuál elegirías',
            'cual elegirias',
            'ayúdame a elegir',
            'ayudame a elegir',
            'cuál te gusta',
            'cual te gusta',
            'con cuál me quedo',
            'con cual me quedo',
            'calidad precio',
            'calidad-precio',
            'mejor relación calidad',
            'mejor valor',
            'qué me conviene',
            'que me conviene',
            'cuál me conviene',
            'cual me conviene',
            'dime cuál',
            'dime cual',
            'sugiéreme',
            'sugiereme',
            'recomiéndame',
            'recomendame',
            'alguna que me recomiendes',
            'cual me recomeindas',
        ];
        // "la mejor" / "el mejor" solo si hay tipo de propiedad o ciudad (evita colisión con saludo)
        $propPatterns = ['la mejor', 'el mejor', 'lo mejor'];
        foreach ($propPatterns as $pp) {
            if (str_contains($text, $pp) && ($this->extractPropertyType($text) || $this->extractCity($text))) {
                return true;
            }
        }
        foreach ($patterns as $p) {
            if (str_contains($text, $p)) return true;
        }
        return false;
    }

    private function isAffirmation(string $text): bool
    {
        if (in_array($text, self::AFFIRMATIONS)) return true;
        if (str_word_count($text) <= 3) {
            foreach (self::AFFIRMATIONS as $a) {
                if (str_contains($text, $a)) return true;
            }
        }
        return false;
    }

    private function isConsultaResultados(string $text, array $context): bool
    {
        if (empty($context['ultimos_resultados'])) return false;

        $lower = mb_strtolower($text);
        $hasCity  = $this->extractCity($lower) !== null;
        $hasType  = $this->extractPropertyType($lower) !== null;
        $hasPrice = $this->extractMaxPrice($lower) !== null || $this->extractMinPrice($lower) !== null;

        $searchSignals = [
            'quiero ver',
            'quiero una',
            'quiero un',
            'busco',
            'busca',
            'muéstrame',
            'muestrame',
            'necesito ver',
            'necesito una',
        ];
        $hasSearchSignal = false;
        foreach ($searchSignals as $signal) {
            if (str_contains($lower, $signal)) {
                $hasSearchSignal = true;
                break;
            }
        }
        if ($hasCity && ($hasType || $hasPrice || $hasSearchSignal)) return false;
        if ($hasSearchSignal && ($hasPrice || $hasType)) return false;

        $patterns = [
            'en qué barrio',
            'en que barrio',
            'qué barrio',
            'que barrio',
            'dónde queda',
            'donde queda',
            'dónde está',
            'donde esta',
            'cuánto cuesta',
            'cuanto cuesta',
            'de cuánto es',
            'de cuanto es',
            'qué precio tiene',
            'que precio tiene',
            'el precio de',
            'a cuánto',
            'a cuanto',
            'la primera',
            'la segunda',
            'la tercera',
            'la cuarta',
            'la quinta',
            'la más barata',
            'la mas barata',
            'las mas barata',
            'más barata',
            'mas barata',
            'la más cara',
            'la mas cara',
            'las mas cara',
            'más cara',
            'mas cara',
            'la más grande',
            'la mas grande',
            'las mas grande',
            'más grande',
            'mas grande',
            'la más pequeña',
            'la mas pequeña',
            'las mas pequeña',
            'más pequeña',
            'mas pequeña',
            'qué características tiene',
            'que caracteristicas tiene',
            'qué incluye',
            'que incluye',
            'qué servicios',
            'que servicios',
            'esa propiedad',
            'ese apartamento',
            'esa casa',
            'cuéntame más',
            'cuentame mas',
            'más detalles',
            'mas detalles',
            'dame info',
            'dame detalles',
            'más información',
            'mas informacion',
            'la que me mostraste',
            'la del mapa',
            'la de',
            'de qué precio',
            'de que precio',
            'a qué precio',
            'a que precio',
            'cuánto valen',
            'cuanto valen',
            'cuánto cuestan',
            'cuanto cuestan',
            'qué precio tienen',
            'que precio tienen',
            'precios son',
            'cuál es su precio',
            'cual es su precio',
            'cuánto son',
            'cuanto son',
            'valores de',
            'cuánto miden',
            'cuanto miden',
            'medida en precio',
            'medida de',
            'cuánto es la medida',
            'en precio',
        ];

        foreach ($patterns as $p) {
            if (in_array($p, ['si', 'sí', 'ok', 'claro']) && $lower === $p) return true;
            if (!in_array($p, ['si', 'sí', 'ok', 'claro']) && str_contains($lower, $p)) return true;
        }

        // Señales de "la barata", "la cara" sin el "más"
        if (str_contains($lower, 'la barata') || str_contains($lower, 'la cara') || str_contains($lower, 'la economica') || str_contains($lower, 'la más económica')) return true;

        if (!$hasSearchSignal && !$hasCity) {
            if (preg_match('/(?:la\s+)?del?\s+\d+(?:[.,]\d+)?(?:\s*m(?:illones?)?)?/u', $lower)) return true;
        }
        if (str_word_count($lower) <= 4 && !$hasCity && !$hasType && !$hasSearchSignal) {
            if (preg_match('/\d+(?:[.,]\d+)?/u', $lower)) return true;
        }

        return false;
    }

    private function isConsultaAnalitica(string $text): bool
    {
        return $this->matchesPatterns($text, self::ANALYTIC_PATTERNS);
    }

    private function isUbicacionUsuario(string $text): bool
    {
        return $this->matchesPatterns($text, self::USER_LOCATION_PATTERNS);
    }

    private function hasRefinePattern(string $text): bool
    {
        $allPatterns = array_merge(self::REFINE_PATTERNS, self::AMENITY_REFINEMENT_PATTERNS);
        foreach ($allPatterns as $pattern) {
            if (str_contains($text, $pattern)) return true;
        }
        return false;
    }

    private function detectTipoConsulta(string $text): string
    {
        $lower = mb_strtolower($text);

        if (
            str_contains($text, 'recomiendas') || str_contains($text, 'recomienda')
            || str_contains($text, 'calidad precio') || str_contains($text, 'calidad-precio')
            || str_contains($text, 'conviene') || str_contains($text, 'mejor opción')
            || str_contains($text, 'mejor') || str_contains($text, 'la más completa')
        ) {
            return 'recomendacion';
        }
        if (
            str_contains($lower, 'grande') || str_contains($lower, 'pequeñ')
            || str_contains($lower, 'más espacio') || str_contains($lower, 'metros')
            || str_contains($lower, 'm²') || str_contains($lower, 'área')
            || str_contains($lower, 'la mas grande') || str_contains($lower, 'la más grande')
        ) {
            return 'tamaño';
        }
        if (str_contains($lower, 'barrio') || str_contains($lower, 'queda') || str_contains($lower, 'dirección') || str_contains($lower, 'ubica')) {
            return 'barrio';
        }
        if (
            str_contains($lower, 'precio') || str_contains($lower, 'cuesta') || str_contains($lower, 'val')
            || str_contains($lower, 'cuánto son') || str_contains($lower, 'cuanto son')
            || str_contains($lower, 'cuánto valen') || str_contains($lower, 'cuanto valen')
            || str_contains($lower, 'barat') || str_contains($lower, 'económ')
            || str_contains($lower, 'car') || str_contains($lower, 'costos')
            || str_contains($lower, 'de qué precio') || str_contains($lower, 'de que precio')
            || str_contains($lower, 'a qué precio') || str_contains($lower, 'a que precio')
            || str_contains($lower, 'en precio') || str_contains($lower, 'medida en precio')
        ) {
            return 'precio';
        }
        if (str_contains($text, 'promedio')) return 'promedio';
        if (str_contains($text, 'características') || str_contains($text, 'caracteristicas') || str_contains($text, 'incluye')) {
            return 'caracteristicas';
        }
        return 'general';
    }

    private function extractPropertyReference(string $text): ?string
    {
        $lower = mb_strtolower($text);
        if (preg_match('/(la primera|la segunda|la tercera|la cuarta|la quinta)/u', $lower, $m)) return $m[1];
        if (preg_match('/(?:la|las|el)?\s*(?:más|mas|la)?\s+(barata|cara|económica|economica|grande|pequeña|pequena|nueva|vieja|barato|caro|pequeño)/u', $lower, $m)) {
            $ref = $m[1];
            // Normalizar a "la más [adjetivo]" para el Brain
            $map = [
                'barata' => 'barata', 'barato' => 'barata',
                'cara' => 'cara', 'caro' => 'cara',
                'económica' => 'barata', 'economica' => 'barata',
                'grande' => 'grande',
                'pequeña' => 'pequeña', 'pequena' => 'pequeña', 'pequeño' => 'pequeña',
            ];
            $key = $map[$ref] ?? $ref;
            return 'la más ' . $key;
        }
        if (preg_match('/la de (\d+(?:[.,]\d+)?)\s*(?:millones?|millon(?:es)?|m\b)?/u', $lower, $m)) {
            $price = $this->parseNumber($m[1]);
            return $price < 1000 ? 'la de ' . $price . ' millones' : 'la de ' . number_format($price, 0, ',', '.');
        }
        if (preg_match('/del?\s+(\d+(?:[.,]\d+)?)\s*(?:millones?|millon(?:es)?|m\b)?/u', $lower, $m)) {
            $price = (float) str_replace(',', '.', $m[1]);
            return $price < 1000 ? 'la de ' . $price . ' millones' : 'la de ' . number_format($price, 0, ',', '.');
        }
        $wordPrices = [
            'millon cuatrocientos' => '1.4 millones',
            'millón cuatrocientos' => '1.4 millones',
            'un millon' => '1 millón',
            'dos millones' => '2 millones',
            'tres millones' => '3 millones',
        ];
        foreach ($wordPrices as $phrase => $canonical) {
            if (str_contains($lower, $phrase)) return 'la de ' . $canonical;
        }
        if (str_word_count($lower) <= 3 && preg_match('/(\d+(?:[.,]\d+)?)/u', $lower, $m)) {
            return 'referencia: ' . $m[1];
        }
        return null;
    }

    private function extractMultipleCities(string $text): array
    {
        $found = [];
        foreach (self::CITIES as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (preg_match('/\b' . preg_quote($alias, '/') . '\b/ui', $text)) {
                    $found[] = $canonical;
                    break;
                }
            }
        }
        return array_unique($found);
    }

    private function extractDeadline(string $text): string
    {
        if (str_contains($text, 'hoy'))         return 'hoy';
        if (str_contains($text, 'mañana'))      return 'mañana';
        if (str_contains($text, 'esta semana')) return 'esta semana';
        if (preg_match('/en\s+(\d+)\s+días/ui', $text, $m)) return "en {$m[1]} días";
        return '';
    }

    private function isRentUsQuestion(string $text): bool
    {
        if (trim($text) === 'rentus') return true;
        if (preg_match('/^\s*rentus[?!.]?\s*$/u', $text)) return true;

        if (str_contains($text, 'rentus')) {
            $triggers = [
                'que es',
                'qué es',
                'qué hace',
                'que hace',
                'como funciona',
                'cómo funciona',
                'para que',
                'para qué',
                'qué ofrece',
                'que ofrece',
                'qué tiene',
                'que tiene',
                'quien es',
                'quién es',
                'cuéntame',
                'cuentame',
                'dime',
                'explica',
                'háblame',
                'hablame',
                'sobre',
                'acerca',
                'información',
                'informacion',
                'presentame',
                'preséntame',
                'quiero saber',
                'saber mas',
                'saber más',
            ];
            // NO detectar como rentus si tiene patrones de contrato/proceso
            $contratoTriggers = array_merge(self::CONTRATO_PATTERNS, self::PROCESO_PATTERNS);
            foreach ($contratoTriggers as $ct) {
                if (str_contains($text, $ct)) return false;
            }
            foreach ($triggers as $t) {
                if (str_contains($text, $t)) return true;
            }
        }
        return false;
    }

    /**
     * isGreeting — SOLO detecta saludos reales.
     * No captura mensajes que contienen señales de intents específicos.
     */
    private function isGreeting(string $text): bool
    {
        // Primero verificar que no haya señales de otro intent
        foreach (self::NOT_GREETING_SIGNALS as $signal) {
            if (str_contains($text, $signal)) return false;
        }

        $greetings = [
            'hola',
            'buenas',
            'hey',
            'buen día',
            'buen dia',
            'buenos días',
            'buenos dias',
            'buenas tardes',
            'buenas noches',
            'saludos',
            'qué tal',
            'que tal',
            'como estas',
            'cómo estás',
            'como te va',
            'cómo te va',
            'que haces',
            'qué haces',
            'que hay',
            'qué hay',
            'todo bien',
            'todo ok',
            'cómo andas',
            'como andas',
            'hola lyra',
            'buenos',
            'hi lyra',
        ];
        foreach ($greetings as $g) {
            if (str_contains($text, $g)) return true;
        }

        // Mensajes muy cortos sin señal de intent → posible saludo
        $wordCount = str_word_count($text);
        if ($wordCount <= 3) {
            if ($this->isAffirmation($text)) return false;
            // No asumir que cualquier texto corto es saludo si no coincide explícitamente con un patrón (Bug 3).
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  EXTRACTORES DE ENTIDADES
    // ─────────────────────────────────────────────────────────────────────────

    private function extractCity(string $text): ?string
    {
        $normalized = mb_strtolower($text);
        foreach (self::NOT_A_CITY as $blocked) {
            if ($blocked === 'armenia') continue; // Armenia sí es una ciudad
            if (preg_match('/\b' . preg_quote($blocked, '/') . '\b/ui', $normalized)) return null;
        }
        $citiesByLength = self::CITIES;
        uasort($citiesByLength, fn($a, $b) => max(array_map('strlen', $b)) - max(array_map('strlen', $a)));
        foreach ($citiesByLength as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (preg_match('/\b' . preg_quote($alias, '/') . '\b/ui', $text)) return $canonical;
            }
        }
        return null;
    }

    private function extractPropertyType(string $text): ?string
    {
        // text ya viene normalizado (minúsculas, sin tildes)
        foreach (self::PROPERTY_TYPES as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                // normalizar también el alias antes de comparar
                $normalizedAlias = $this->normalize($alias);
                if (preg_match('/\b' . preg_quote($normalizedAlias, '/') . '\b/ui', $text)) {
                    return $canonical;
                }
            }
        }
        return null;
    }

    private function extractMaxPrice(string $text): ?float
    {
        $prefixes = '(?:menos de|hasta|maximo|máximo|por|precio(?: de)?|menores(?: a)?)';

        // Ej. "millón 300", "un millón 300", "2 millones 500"
        if (preg_match('/' . $prefixes . '?\s*(?:(un|uno|\d+(?:[.,]\d+)?)\s+)?(?:millones|millon|millón)\s+(\d{3})\b/ui', $text, $m)) {
            $baseStr = mb_strtolower(trim($m[1] ?? ''));
            $base = ($baseStr === 'un' || $baseStr === 'uno' || $baseStr === '') ? 1000000 : $this->parseNumber($baseStr) * 1000000;
            return $base + ((float)$m[2] * 1000);
        }

        // Ej. "menores a 2 millones", "precio de un millón"
        if (preg_match('/' . $prefixes . '\s+(un|uno|\d+(?:[.,]\d+)?)\s*(?:millones|millon|millón)/ui', $text, $m)) {
            $baseStr = mb_strtolower($m[1]);
            $val = ($baseStr === 'un' || $baseStr === 'uno') ? 1 : $this->parseNumber($baseStr);
            return $val * 1000000;
        }

        // Ej. "de 1 a 2 millones"
        if (preg_match('/de\s+(?:un|uno|\d+(?:[.,]\d+)?)\s+a\s+(un|uno|\d+(?:[.,]\d+)?)\s*(?:millones|millon|millón)/ui', $text, $m)) {
            $baseStr = mb_strtolower($m[1]);
            $val = ($baseStr === 'un' || $baseStr === 'uno') ? 1 : $this->parseNumber($baseStr);
            return $val * 1000000;
        }

        // Ej. "hasta 800 mil"
        if (preg_match('/' . $prefixes . '\s+(\d+(?:[.,]\d+)?)\s*(?:mil|k)\b/ui', $text, $m)) {
            return $this->parseNumber($m[1]) * 1000;
        }

        // Ej. "hasta 1500000"
        if (preg_match('/' . $prefixes . '\s+(\d{6,})/ui', $text, $m)) {
            return (float) $m[1];
        }

        // Sin prefijo: "2 millones"
        if (preg_match('/(un|uno|\d+(?:[.,]\d+)?)\s*(?:millones|millon|millón)/ui', $text, $m)) {
            $baseStr = mb_strtolower($m[1]);
            $val = ($baseStr === 'un' || $baseStr === 'uno') ? 1 : $this->parseNumber($baseStr);
            return $val * 1000000;
        }

        return null;
    }

    private function extractMinPrice(string $text): ?float
    {
        if (preg_match('/(?:desde|minimo|mínimo)\s+(\d+(?:[.,]\d+)?)\s*(?:millones|millon|millón)/ui', $text, $m)) {
            return $this->parseNumber($m[1]) * 1000000;
        }
        if (preg_match('/(?:desde|minimo|mínimo)\s+(\d+(?:[.,]\d+)?)\s*(?:mil|k)/ui', $text, $m)) {
            return $this->parseNumber($m[1]) * 1000;
        }
        return null;
    }

    private function extractBedrooms(string $text): ?int
    {
        if (preg_match('/(\d+)\s*(?:habitacion|habitaciones|cuartos?|alcobas?|recamaras?|piezas?|hab)\b/ui', $text, $m)) {
            return (int) $m[1];
        }
        $words = ['una' => 1, 'un' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5];
        foreach ($words as $word => $num) {
            if (preg_match('/' . $word . '\s*(?:habitacion|habitaciones|cuartos?|alcobas?)/ui', $text)) return $num;
        }
        return null;
    }

    private function extractBathrooms(string $text): ?int
    {
        if (preg_match('/(\d+)\s*(?:baño|baños|bano|banos)\b/ui', $text, $m)) return (int) $m[1];
        return null;
    }

    private function extractReferencePlace(string $text): ?string
    {
        if (preg_match('/cerca\s+(?:al?|del?)\s+(\w+(?:\s+\w+)?)/ui', $text, $m)) {
            $candidate = mb_strtolower(trim($m[1]));
            foreach (self::REFERENCE_PATTERNS as $ref) {
                if (str_contains($candidate, $ref) || str_contains($ref, $candidate)) return $candidate;
            }
            return $candidate;
        }
        if (preg_match('/(?:vista|frente|lado)\s+(?:al?|del?)\s+(\w+(?:\s+\w+)?)/ui', $text, $m)) {
            return mb_strtolower(trim($m[1]));
        }
        return null;
    }

    private function isReference(string $str): bool
    {
        $lower = mb_strtolower($str);
        foreach (self::REFERENCE_PATTERNS as $ref) {
            if (str_contains($lower, $ref) || str_contains($ref, $lower)) return true;
        }
        return false;
    }

    private function extractNeighborhood(string $text, ?string $city): ?string
    {
        foreach (self::KNOWN_NEIGHBORHOODS as $nb) {
            if (str_contains($text, mb_strtolower($nb))) return $nb;
        }
        if (preg_match('/(?:en el barrio|barrio|sector)\s+(?:de\s+)?([A-Za-záéíóúñÁÉÍÓÚÑ\s]{3,25})/ui', $text, $m)) {
            $candidate = trim($m[1]);
            if ($city && mb_strtolower($candidate) === mb_strtolower($city)) return null;
            if ($this->isReference($candidate)) return null;
            return ucwords($candidate);
        }
        return null;
    }

    private function extractAmenities(string $text): ?array
    {
        $amenityMap = [
            'amueblado'   => ['amueblado', 'amueblada', 'amoblado', 'amoblada'],
            'piscina'     => ['piscina'],
            'gimnasio'    => ['gimnasio', 'gym'],
            'parqueadero' => ['parqueadero', 'garaje', 'parking', 'estacionamiento'],
            'balcón'      => ['balcon', 'balcón', 'terraza'],
            'ascensor'    => ['ascensor', 'elevador'],
            'vigilancia'  => ['vigilancia', 'seguridad', 'portería', 'porteria'],
        ];
        $found = [];
        foreach ($amenityMap as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($text, $alias)) {
                    $found[] = $canonical;
                    break;
                }
            }
        }
        return !empty($found) ? $found : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  HELPERS INTERNOS
    // ─────────────────────────────────────────────────────────────────────────

    private function calculateSmartRadius(?string $neighborhood, ?string $referencePlace, ?string $city): float
    {
        if ($neighborhood) return 3.0;
        if ($referencePlace) return 5.0;
        if ($city) {
            $bigCities = ['Bogotá', 'Medellín', 'Cali', 'Barranquilla', 'Cartagena', 'Bucaramanga'];
            return in_array($city, $bigCities) ? 25.0 : 15.0;
        }
        return 10.0;
    }

    private function buildSearchResponse(
        ?string $city,
        ?string $type,
        ?string $nb,
        ?float $price,
        ?int $bed,
        string $originalText = ''
    ): string {
        $where = '';
        if ($nb && $city) $where = "en $nb, $city";
        elseif ($city)    $where = "en $city";
        elseif ($nb)      $where = "en $nb";
        else              $where = 'en esa zona';

        $what    = $type ? $this->pluralizeType($type) : 'propiedades';
        $extras  = [];
        if ($bed)   $extras[] = "con $bed habitaciones";
        if ($price) $extras[] = "hasta " . number_format($price / 1000000, 1) . " millones";
        $extraStr = !empty($extras) ? ' ' . implode(' y ', $extras) : '';

        return $this->randomFrom([
            "Buscando $what $where$extraStr. Dame un momento...",
            "Déjame revisar $what disponibles $where$extraStr.",
            "¡Vamos! Revisando $what $where$extraStr ahora mismo.",
            "Buscando las mejores opciones $where$extraStr.",
        ]);
    }

    private function pluralizeType(string $type): string
    {
        $map = [
            'casa' => 'casas',
            'apartamento' => 'apartamentos',
            'lote' => 'lotes',
            'local' => 'locales',
            'estudio' => 'estudios',
            'finca' => 'fincas',
            'bodega' => 'bodegas',
            'oficina' => 'oficinas',
            'propiedad' => 'propiedades',
        ];
        return $map[$type] ?? $type . 's';
    }

    private function randomFrom(array $options): string
    {
        return $options[array_rand($options)];
    }

    private function normalizeNumberWords(string $text): string
    {
        $text = str_ireplace(
            [
                ' millón y medio ',
                ' millon y medio ',
                ' un millón y medio ',
                ' un millon y medio ',
                ' medio millón ',
                ' medio millon ',
                ' dos millones y medio ',
                ' tres millones y medio ',
                ' un millón ',
                ' un millon ',
                ' dos millones ',
                ' tres millones ',
                ' cuatro millones ',
                ' cinco millones ',
                ' cien ',
                ' doscientos ',
                ' trescientos ',
                ' cuatrocientos ',
                ' quinientos ',
                ' seiscientos ',
                ' setecientos ',
                ' ochocientos ',
                ' novecientos '
            ],
            [
                ' 1.5 millones ',
                ' 1.5 millones ',
                ' 1.5 millones ',
                ' 1.5 millones ',
                ' 0.5 millones ',
                ' 0.5 millones ',
                ' 2.5 millones ',
                ' 3.5 millones ',
                ' 1 millón ',
                ' 1 millón ',
                ' 2 millones ',
                ' 3 millones ',
                ' 4 millones ',
                ' 5 millones ',
                ' 100 ',
                ' 200 ',
                ' 300 ',
                ' 400 ',
                ' 500 ',
                ' 600 ',
                ' 700 ',
                ' 800 ',
                ' 900 '
            ],
            ' ' . $text . ' '
        );
        return trim($text);
    }

    private function buildIntent(string $intent, array $data = []): array
    {
        return array_merge([
            'intent'                 => $intent,
            'confidence'             => 1.0,
            'city'                   => null,
            'neighborhood'           => null,
            'street'                 => null,
            'reference_place'        => null,
            'property_type'          => null,
            'max_price'              => null,
            'min_price'              => null,
            'bedrooms'               => null,
            'bathrooms'              => null,
            'amenities'              => null,
            'radius_km'              => 5,
            'is_new_search'          => false,
            'needs_clarification'    => false,
            'clarification_question' => null,
            'map_lat'                => null,
            'map_lng'                => null,
            'security_violation'     => false,
            'response_text'          => '',
        ], $data);
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(trim($this->normalizeTypos($text)));
    }

    private function normalizeTypos(string $text): string
    {
        $corrections = [
            'apartementos' => 'apartamentos',
            'apartamnetos' => 'apartamentos',
            'apartametos' => 'apartamentos',
            'apartamientos' => 'apartamentos',
            'aprtamento' => 'apartamento',
            'aprtamentos' => 'apartamentos',
            'apatamento' => 'apartamento',
            'apatamentos' => 'apartamentos',
            'apto' => 'apartamento',
            'aptos' => 'apartamentos',
            'caza' => 'casa',
            'cazas' => 'casas',
            'estidio' => 'estudio',
            'estuio' => 'estudio',
            'medellin' => 'medellín',
            'medelin' => 'medellín',
            'bogota' => 'bogotá',
            'barranquila' => 'barranquilla',
            'baranquilla' => 'barranquilla',
            'manizalez' => 'manizales',
            'calli' => 'cali',
            'kali' => 'cali',
            'bucaramaga' => 'bucaramanga',
            'habitasiones' => 'habitaciones',
            'abitaciones' => 'habitaciones',
            'cuartos' => 'habitaciones',
            'cuarto' => 'habitación',
            'piezas' => 'habitaciones',
            'pieza' => 'habitación',
            'recamaras' => 'habitaciones',
            'banos' => 'baños',
            'bano' => 'baño',
            'pisina' => 'piscina',
            'picina' => 'piscina',
            'pizcina' => 'piscina',
            'gim' => 'gimnasio',
            'gimnacio' => 'gimnasio',
            'jimnasio' => 'gimnasio',
            'parqeadero' => 'parqueadero',
            'parking' => 'parqueadero',
            'balcon' => 'balcón',
            'valcon' => 'balcón',
            'terasa' => 'terraza',
            'terrasa' => 'terraza',
            'asensor' => 'ascensor',
            'elevador' => 'ascensor',
            'varato' => 'barato',
            'economico' => 'económico',
            'economica' => 'económica',
            'pequeno' => 'pequeño',
            'chico' => 'pequeño',
            'amlio' => 'amplio',
            'nesesito' => 'necesito',
            'nesecito' => 'necesito',
            'kiero' => 'quiero',
            'arrendr' => 'arrendar',
            'alquilar' => 'arrendar',
            'rentar' => 'arrendar',
            'arrendamiento' => 'arriendo',
            'alquiler' => 'arriendo',
            'recomendacion' => 'recomendación',
            'recomendame' => 'recomiéndame',
            'recomiendacion' => 'recomendación',
            'recomiendame' => 'recomiéndame',
            'recomendame ciudad' => 'recomiéndame ciudad',
            'sugiereme' => 'sugiéreme',
            // Nuevas correcciones para variantes de "nuevas"
            'nuevas cassa' => 'nuevas casas',
            'nuevas apartamentos' => 'nuevos apartamentos',
            // Login / registro
            'loguearme' => 'logearme',
            'logearme' => 'iniciar sesion',
            'logearme en rentus' => 'iniciar sesion en rentus',
        ];

        foreach ($corrections as $typo => $correct) {
            $text = preg_replace('/\b' . preg_quote($typo, '/') . '\b/ui', $correct, $text);
        }
        return $text;
    }

    private function parseNumber(string $num): float
    {
        // Si tiene un punto y es un número pequeño (ej. 1.2), el punto es decimal.
        // Si tiene un punto y luego 3 dígitos (ej. 1.200), el punto es de miles.
        if (str_contains($num, '.') && !str_contains($num, ',')) {
            if (preg_match('/^\d+\.\d{1,2}$/', $num)) {
                return (float) $num;
            }
        }
        
        return (float) str_replace(['.', ','], ['', '.'], $num);
    }
}
