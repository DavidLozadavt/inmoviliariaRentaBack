<?php

namespace App\Http\Controllers\Visor3d;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Services\Visor3d\BuildingConfigService;
use App\Services\Visor3d\PanoramaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imágenes por torre: foto de la torre y texturas de su fachada (frente y costado).
 * Las rutas se guardan dentro de config.towers[] (photo_path, facade_front_path, facade_side_path)
 * y solo este controlador las escribe: BuildingController::update conserva las que ya existen.
 */
class TowerMediaController extends Controller
{
    public const KINDS = ['photo', 'facade_front', 'facade_side', 'facade_left', 'facade_back', 'roof'];

    public function store(Request $request, Building $building, string $tower, string $kind): JsonResponse
    {
        Gate::authorize('update', $building);
        $request->validate(['image' => ['required', 'file', 'mimetypes:image/webp,image/jpeg,image/png', 'max:8192']]);

        $config = $building->config;
        $i = $this->towerIndex($config, $tower);

        $ext  = match ($request->file('image')->getMimeType()) { 'image/webp' => 'webp', 'image/png' => 'png', default => 'jpg' };
        $path = $request->file('image')->storeAs(
            PanoramaService::DIR . '/' . $building->id,
            'tower-' . Str::slug($tower) . "-$kind-" . Str::random(16) . ".$ext",
            PanoramaService::DISK
        );

        $key = "{$kind}_path";
        if (! empty($config['towers'][$i][$key])) {
            Storage::disk(PanoramaService::DISK)->delete($config['towers'][$i][$key]);
        }
        $config['towers'][$i][$key] = $path;
        $building->config = $config;
        $building->save();
        BuildingConfigService::bump($building->id);

        return response()->json(['success' => true, 'data' => ['url' => Storage::disk(PanoramaService::DISK)->url($path)]], 201);
    }

    public function destroy(Building $building, string $tower, string $kind): JsonResponse
    {
        Gate::authorize('update', $building);

        $config = $building->config;
        $i = $this->towerIndex($config, $tower);
        $key = "{$kind}_path";

        if (! empty($config['towers'][$i][$key])) {
            Storage::disk(PanoramaService::DISK)->delete($config['towers'][$i][$key]);
            unset($config['towers'][$i][$key]);
            $building->config = $config;
            $building->save();
            BuildingConfigService::bump($building->id);
        }

        return response()->json(['success' => true, 'data' => null]);
    }

    private function towerIndex(array $config, string $code): int
    {
        foreach ($config['towers'] ?? [] as $i => $t) {
            if ((string) $t['code'] === $code) {
                return $i;
            }
        }
        abort(404, 'Torre no encontrada. Guarda el edificio primero.');
    }
}
