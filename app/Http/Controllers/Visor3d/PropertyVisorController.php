<?php

namespace App\Http\Controllers\Visor3d;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Property;
use App\Services\Visor3d\BuildingConfigService;
use App\Services\Visor3d\PanoramaService;
use Illuminate\Http\JsonResponse;

/**
 * Visor 3D desde el detalle público de una propiedad.
 * Solo para unidades publicadas y aprobadas que pertenecen a un edificio.
 * Devuelve el edificio en modo público (solo datos comerciales) y el recorrido 360° de la unidad.
 */
class PropertyVisorController extends Controller
{
    public function show(Property $property, BuildingConfigService $configs, PanoramaService $panoramas): JsonResponse
    {
        $building = $property->building_id ? Building::find($property->building_id) : null;

        if (! $building || ! $property->isApproved() || ! $property->isPublished()) {
            return response()->json(['success' => false, 'message' => 'Esta propiedad no tiene visor 3D.'], 404);
        }

        return response()->json(['success' => true, 'data' => [
            'unit_id'  => $property->id,
            'building' => $configs->payload($building, public: true),
            'tour'     => $panoramas->tourFor($property),
        ]]);
    }
}
