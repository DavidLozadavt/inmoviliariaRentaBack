<?php

namespace App\Http\Controllers\Visor3d;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Property;
use App\Services\Visor3d\BuildingConfigService;
use App\Services\Visor3d\PanoramaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Enlace público del visor (modo inmobiliaria, sin login).
 * Es un Signed URL relativo de Laravel sobre la ruta pública + un token que se puede revocar.
 */
class PublicLinkController extends Controller
{
    public const ROUTE = 'visor3d.public';

    public function show(Building $building): JsonResponse
    {
        Gate::authorize('update', $building);

        return response()->json(['success' => true, 'data' => $this->linkData($building, null)]);
    }

    /** Crea (o rota) el enlace. Rotar invalida los enlaces anteriores. */
    public function store(Request $request, Building $building): JsonResponse
    {
        Gate::authorize('update', $building);

        $data = $request->validate(['expires_in_days' => ['nullable', 'integer', 'between:1,365']]);

        $building->public_token      = Str::random(40);
        $building->public_expires_at = isset($data['expires_in_days']) ? now()->addDays($data['expires_in_days']) : null;
        $building->save();

        return response()->json(['success' => true, 'data' => $this->linkData($building, $this->signedQuery($building))], 201);
    }

    public function destroy(Building $building): JsonResponse
    {
        Gate::authorize('update', $building);

        $building->forceFill(['public_token' => null, 'public_expires_at' => null])->save();

        return response()->json(['success' => true, 'data' => null]);
    }

    /** GET /api/public/buildings/{token}?expires=&signature=[&tour=<unitId>] */
    public function publicView(Request $request, string $token, BuildingConfigService $configs, PanoramaService $panoramas): JsonResponse
    {
        if (! URL::hasCorrectSignature($request, false, ['tour'])) {
            return $this->fail('Enlace inválido.', 403);
        }
        if (! URL::signatureHasNotExpired($request)) {
            return $this->fail('Este enlace ya venció.', 410);
        }

        $building = Building::where('public_token', $token)->first();
        if (! $building || ($building->public_expires_at && $building->public_expires_at->isPast())) {
            return $this->fail('Este enlace ya no está disponible.', 404);
        }

        if ($request->filled('tour')) {
            $unit = Property::where('building_id', $building->id)->find((int) $request->query('tour'));
            if (! $unit) {
                return $this->fail('Unidad no encontrada.', 404);
            }
            return response()->json(['success' => true, 'data' => $panoramas->tourFor($unit)]);
        }

        return response()->json(['success' => true, 'data' => $configs->payload($building, public: true)]);
    }

    private function signedQuery(Building $building): string
    {
        $params = ['token' => $building->public_token];
        $url = $building->public_expires_at
            ? URL::temporarySignedRoute(self::ROUTE, $building->public_expires_at, $params, false)
            : URL::signedRoute(self::ROUTE, $params, null, false);

        return (string) parse_url($url, PHP_URL_QUERY);
    }

    private function linkData(Building $building, ?string $query): array
    {
        if (! $building->public_token) {
            return ['active' => false];
        }

        // La firma solo se conoce al crear el enlace: después se regenera igual (mismo token y vencimiento).
        $query ??= $this->signedQuery($building);
        $url = rtrim(config('app.frontend_url'), '/') . '/visor/' . $building->public_token . '?' . $query;

        return [
            'active'     => true,
            'url'        => $url,
            'embed_url'  => $url . '&embed=1',
            'iframe'     => '<iframe src="' . e($url . '&embed=1') . '" width="100%" height="600" style="border:0" allow="fullscreen; xr-spatial-tracking" loading="lazy" title="' . e($building->name) . '"></iframe>',
            'expires_at' => $building->public_expires_at?->toIso8601String(),
        ];
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
