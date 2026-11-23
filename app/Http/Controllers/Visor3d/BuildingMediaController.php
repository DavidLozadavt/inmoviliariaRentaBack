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
 * Imágenes del edificio:
 * - cover: foto del conjunto o la torre que envía el cliente (referencia para armar el 3D y portada de la tarjeta).
 * - preview: imagen del modelo 3D que el editor toma al guardar (portada cuando no hay foto).
 * Llegan ya comprimidas a WebP desde el navegador.
 */
class BuildingMediaController extends Controller
{
    private const FIELDS = [
        'cover'        => 'cover_path',
        'preview'      => 'preview_path',
        'facade_front' => 'facade_front_path',   // texturas del modelo 3D
        'facade_side'  => 'facade_side_path',
        'facade_left'  => 'facade_left_path',
        'facade_back'  => 'facade_back_path',
        'roof'         => 'roof_path',
        'site_plan'    => 'site_plan_path',      // plano del conjunto (suelo)
    ];

    public function store(Request $request, Building $building, string $kind): JsonResponse
    {
        Gate::authorize('update', $building);
        abort_unless(isset(self::FIELDS[$kind]), 404);

        $request->validate(['image' => ['required', 'file', 'mimetypes:image/webp,image/jpeg,image/png', 'max:8192']]);

        $field = self::FIELDS[$kind];
        $disk  = Storage::disk(PanoramaService::DISK);
        $ext   = match ($request->file('image')->getMimeType()) {
            'image/webp' => 'webp', 'image/png' => 'png', default => 'jpg',
        };
        $path = $request->file('image')->storeAs(
            PanoramaService::DIR . '/' . $building->id, "$kind-" . Str::random(20) . ".$ext", PanoramaService::DISK
        );

        if ($building->$field) {
            $disk->delete($building->$field);
        }
        $building->forceFill([$field => $path])->save();
        BuildingConfigService::bump($building->id);

        return response()->json(['success' => true, 'data' => ['url' => $disk->url($path)]], 201);
    }

    public function destroy(Building $building, string $kind): JsonResponse
    {
        Gate::authorize('update', $building);
        abort_unless(isset(self::FIELDS[$kind]), 404);

        $field = self::FIELDS[$kind];
        if ($building->$field) {
            Storage::disk(PanoramaService::DISK)->delete($building->$field);
            $building->forceFill([$field => null])->save();
            BuildingConfigService::bump($building->id);
        }

        return response()->json(['success' => true, 'data' => null]);
    }
}
