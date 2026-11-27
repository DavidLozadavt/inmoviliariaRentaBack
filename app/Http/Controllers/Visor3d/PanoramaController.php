<?php

namespace App\Http\Controllers\Visor3d;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Panorama;
use App\Models\Property;
use App\Models\UnitType;
use App\Services\Visor3d\BuildingConfigService;
use App\Services\Visor3d\PanoramaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Fotos 360°. Llegan ya redimensionadas y en WebP desde el navegador
 * (máx. 6000 px + miniatura), así PHP no tiene que abrir la imagen.
 */
class PanoramaController extends Controller
{
    public function __construct(private PanoramaService $service)
    {
    }

    public function index(Building $building): JsonResponse
    {
        Gate::authorize('update', $building);

        return response()->json(['success' => true, 'data' => $this->service->forBuilding($building)]);
    }

    public function store(Request $request, Building $building): JsonResponse
    {
        Gate::authorize('update', $building);

        $data = $request->validate([
            'owner_kind' => ['required', 'in:type,unit'],
            'owner_id'   => ['required', 'integer'],
            'space'      => ['required', 'string', 'max:60'],
            'kind'       => ['nullable', 'in:' . implode(',', PanoramaService::KINDS)],
            'projection' => ['nullable', 'in:equirect,flat'],   // flat = foto normal
            'surface'    => ['nullable', 'in:' . implode(',', PanoramaService::SURFACES)],
            'image'      => ['required', 'file', 'mimetypes:image/webp,image/jpeg', 'max:15360'], // 15 MB
            'thumb'      => ['nullable', 'file', 'mimetypes:image/webp,image/jpeg', 'max:512'],
            'width'      => ['nullable', 'integer', 'max:6000'],
        ]);

        $owner = $this->owner($building, $data['owner_kind'], (int) $data['owner_id']);

        [$w, $h] = @getimagesize($request->file('image')->getRealPath()) ?: [0, 0];
        if ($w > 6000) {
            return response()->json(['success' => false, 'message' => 'La imagen supera 6000 px de ancho.'], 422);
        }
        $projection = $data['projection'] ?? 'equirect';
        if ($projection === 'equirect' && $w && $h && abs($w / $h - 2) > 0.1) {
            return response()->json(['success' => false, 'message' => 'La foto debe ser 360° equirectangular (proporción 2:1).'], 422);
        }

        $dir   = PanoramaService::DIR . '/' . $building->id;
        $name  = Str::random(32);
        $ext   = $request->file('image')->getMimeType() === 'image/webp' ? 'webp' : 'jpg';
        $path  = $request->file('image')->storeAs($dir, "$name.$ext", PanoramaService::DISK);
        $thumb = $request->hasFile('thumb')
            ? $request->file('thumb')->storeAs($dir, "$name-t.$ext", PanoramaService::DISK)
            : null;

        $first = ! $owner->panoramas()->exists();

        $panorama = $owner->panoramas()->create([
            'space'      => $data['space'],
            'kind'       => $data['kind'] ?? null,
            'projection' => $projection,
            'surface'    => $projection === 'flat' ? ($data['surface'] ?? null) : null,
            'path'       => $path,
            'thumb_path' => $thumb,
            'width'      => $w ?: ($data['width'] ?? null),
            'size_kb'    => (int) ceil($request->file('image')->getSize() / 1024),
            'is_start'   => $first,
            'order'      => (int) $owner->panoramas()->max('order') + 1,
        ]);

        BuildingConfigService::bump($building->id);

        return response()->json(['success' => true, 'data' => $this->service->present($panorama->load('hotspots'))], 201);
    }

    public function update(Request $request, Building $building, Panorama $panorama): JsonResponse
    {
        Gate::authorize('update', $building);
        $this->ensureBelongs($building, $panorama);

        $data = $request->validate([
            'space'    => ['sometimes', 'string', 'max:60'],
            'kind'     => ['sometimes', 'nullable', 'in:' . implode(',', PanoramaService::KINDS)],
            'surface'  => ['sometimes', 'nullable', 'in:' . implode(',', PanoramaService::SURFACES)],
            'is_start' => ['sometimes', 'boolean'],
            'order'    => ['sometimes', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($panorama, $data) {
            if (! empty($data['is_start'])) {
                Panorama::where('owner_type', $panorama->owner_type)->where('owner_id', $panorama->owner_id)
                    ->update(['is_start' => false]);
            }
            $panorama->update($data);
        });

        return response()->json(['success' => true, 'data' => $this->service->present($panorama->fresh('hotspots'))]);
    }

    /** Reemplaza los puntos de paso de una foto. Solo hacia fotos del mismo dueño. */
    public function hotspots(Request $request, Building $building, Panorama $panorama): JsonResponse
    {
        Gate::authorize('update', $building);
        $this->ensureBelongs($building, $panorama);

        $data = $request->validate([
            'links'          => ['present', 'array', 'max:20'],
            'links.*.target' => ['required', 'integer', 'different:' . $panorama->id],
            'links.*.yaw'    => ['required', 'numeric', 'between:-7,7'],
            'links.*.pitch'  => ['required', 'numeric', 'between:-1.6,1.6'],
            'links.*.label'  => ['nullable', 'string', 'max:60'],
        ]);

        if ($panorama->projection === 'flat' && count($data['links'])) {
            return response()->json(['success' => false, 'message' => 'Los puntos de paso solo van en fotos 360°.'], 422);
        }

        $siblings = Panorama::where('owner_type', $panorama->owner_type)->where('owner_id', $panorama->owner_id)->pluck('id');
        foreach ($data['links'] as $i => $l) {
            if ((int) $l['target'] === $panorama->id || ! $siblings->contains((int) $l['target'])) {
                return response()->json(['success' => false, 'message' => "El destino del punto " . ($i + 1) . " no es un espacio de esta unidad."], 422);
            }
        }

        DB::transaction(function () use ($panorama, $data) {
            $panorama->hotspots()->delete();
            foreach ($data['links'] as $l) {
                $panorama->hotspots()->create([
                    'target_panorama_id' => $l['target'],
                    'yaw'   => $l['yaw'],
                    'pitch' => $l['pitch'],
                    'label' => $l['label'] ?? null,
                ]);
            }
        });

        return response()->json(['success' => true, 'data' => $this->service->present($panorama->fresh('hotspots'))]);
    }

    public function destroy(Building $building, Panorama $panorama): JsonResponse
    {
        Gate::authorize('update', $building);
        $this->ensureBelongs($building, $panorama);

        DB::transaction(function () use ($panorama) {
            $wasStart = $panorama->is_start;
            $this->service->deleteFiles($panorama);
            $panorama->delete();
            if ($wasStart) {
                Panorama::where('owner_type', $panorama->owner_type)->where('owner_id', $panorama->owner_id)
                    ->orderBy('order')->limit(1)->update(['is_start' => true]);
            }
        });

        return response()->json(['success' => true, 'data' => null]);
    }

    private function owner(Building $building, string $kind, int $id): UnitType|Property
    {
        $owner = $kind === 'type'
            ? UnitType::where('building_id', $building->id)->find($id)
            : Property::where('building_id', $building->id)->find($id);

        abort_unless($owner, 422, 'El tipo o la unidad no pertenece a este edificio.');

        return $owner;
    }

    private function ensureBelongs(Building $building, Panorama $panorama): void
    {
        $ok = $panorama->owner_type === UnitType::class
            ? UnitType::where('building_id', $building->id)->whereKey($panorama->owner_id)->exists()
            : Property::where('building_id', $building->id)->whereKey($panorama->owner_id)->exists();

        abort_unless($ok, 404);
    }
}
