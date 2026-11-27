<?php

namespace App\Http\Controllers\Visor3d;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Property;
use App\Services\Visor3d\UnitGenerator;
use App\Services\Visor3d\PanoramaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public const COMMERCIAL = ['available', 'reserved', 'rented', 'sold'];

    /** Crea las unidades que falten según el layout (ver UnitGenerator). */
    public function generate(Request $request, Building $building): JsonResponse
    {
        Gate::authorize('update', $building);

        $data = $request->validate([
            'monthly_price' => ['nullable', 'numeric', 'min:0'],
            'prices'        => ['nullable', 'array'],          // precio por código de tipo
            'prices.*'      => ['numeric', 'min:0'],
        ]);

        $result = app(UnitGenerator::class)->generate($building, $data['prices'] ?? [], $data['monthly_price'] ?? null);

        return response()->json(['success' => true, 'data' => $result]);
    }

    /** Vincula una propiedad existente del mismo dueño a una posición del edificio. */
    public function link(Request $request, Building $building): JsonResponse
    {
        Gate::authorize('update', $building);

        $data = $request->validate([
            'property_id' => ['required', 'integer', Rule::exists('properties', 'id')->where('user_id', $building->user_id)->whereNull('deleted_at')],
            'tower'       => ['required', 'string', 'max:20'],
            'floor'       => ['required', 'integer'],
            'unit_code'   => ['required', 'string', 'max:20'],
            'unit_type'   => ['nullable', 'string', 'max:10'],
        ]);

        $taken = $building->units()->where('tower', $data['tower'])->where('unit_code', $data['unit_code'])
            ->where('id', '!=', $data['property_id'])->exists();
        if ($taken) {
            return response()->json(['success' => false, 'message' => 'Esa posición ya tiene una unidad.'], 422);
        }

        $unit = Property::findOrFail($data['property_id']);
        $unit->update([
            'building_id'  => $building->id,
            'tower'        => $data['tower'],
            'floor'        => $data['floor'],
            'unit_code'    => $data['unit_code'],
            'unit_type_id' => $this->typeId($building, $data['unit_type'] ?? null) ?? $unit->unit_type_id,
        ]);

        return response()->json(['success' => true, 'data' => ['id' => $unit->id]]);
    }

    /** Datos comerciales de la unidad desde el visor. */
    public function update(Request $request, Building $building, Property $unit): JsonResponse
    {
        Gate::authorize('update', $building);
        abort_unless($unit->building_id === $building->id, 404);

        $data = $request->validate([
            'monthly_price'     => ['sometimes', 'numeric', 'min:0'],
            'commercial_status' => ['sometimes', 'nullable', Rule::in(self::COMMERCIAL)],
            'area_m2'           => ['sometimes', 'numeric', 'min:0'],
            'num_bedrooms'      => ['sometimes', 'integer', 'between:0,20'],
            'num_bathrooms'     => ['sometimes', 'integer', 'between:0,20'],
            'unit_type'         => ['sometimes', 'nullable', 'string', 'max:10'],
        ]);

        if (array_key_exists('unit_type', $data)) {
            $data['unit_type_id'] = $this->typeId($building, $data['unit_type']);
            unset($data['unit_type']);
        }

        $unit->update($data);

        return response()->json(['success' => true, 'data' => ['id' => $unit->id]]);
    }

    /**
     * Acomodar: mover la unidad a otra posición del edificio (torre, piso, código).
     * Si la posición está ocupada, las dos unidades se intercambian.
     */
    public function move(Request $request, Building $building, Property $unit): JsonResponse
    {
        Gate::authorize('update', $building);
        abort_unless($unit->building_id === $building->id, 404);

        $data = $request->validate([
            'tower'     => ['required', 'string', 'max:20'],
            'floor'     => ['required', 'integer', 'between:-10,80'],
            'unit_code' => ['required', 'string', 'max:20'],
            'unit_type' => ['nullable', 'string', 'max:10'],
        ]);

        DB::transaction(function () use ($building, $unit, $data) {
            $other = $building->units()->where('tower', $data['tower'])->where('unit_code', $data['unit_code'])
                ->where('id', '!=', $unit->id)->lockForUpdate()->first();
            $from = ['tower' => $unit->tower, 'floor' => $unit->floor, 'unit_code' => $unit->unit_code];

            // Liberar la llave única (edificio, torre, código) antes del intercambio
            if ($other) {
                $other->update(['unit_code' => '__swap_' . $other->id]);
            }
            $unit->update([
                'tower' => $data['tower'], 'floor' => $data['floor'], 'unit_code' => $data['unit_code'],
                'unit_type_id' => $this->typeId($building, $data['unit_type'] ?? null) ?? $unit->unit_type_id,
            ]);
            if ($other) {
                $other->update($from);
            }
        });

        return response()->json(['success' => true, 'data' => ['id' => $unit->id]]);
    }

    /** Eliminar la unidad (la propiedad). No se permite si tiene un contrato activo. */
    public function destroy(Building $building, Property $unit): JsonResponse
    {
        Gate::authorize('update', $building);
        abort_unless($unit->building_id === $building->id, 404);

        if ($unit->contracts()->where('status', 'active')->exists()) {
            return response()->json(['success' => false, 'message' => 'La unidad tiene un contrato activo: no se puede eliminar.'], 422);
        }
        $unit->delete(); // borrado suave, como el resto de propiedades

        return response()->json(['success' => true, 'data' => null]);
    }

    public function unlink(Building $building, Property $unit): JsonResponse
    {
        Gate::authorize('update', $building);
        abort_unless($unit->building_id === $building->id, 404);

        $unit->update(['building_id' => null, 'unit_type_id' => null, 'tower' => null, 'floor' => null, 'unit_code' => null]);

        return response()->json(['success' => true, 'data' => null]);
    }

    public function tour(Building $building, Property $unit, PanoramaService $panoramas): JsonResponse
    {
        Gate::authorize('view', $building);
        abort_unless($unit->building_id === $building->id, 404);

        return response()->json(['success' => true, 'data' => $panoramas->tourFor($unit)]);
    }

    /** Propiedades del dueño que aún no están en ningún edificio (para vincular). */
    public function available(Building $building): JsonResponse
    {
        Gate::authorize('update', $building);

        $list = Property::where('user_id', $building->user_id)->whereNull('building_id')
            ->orderBy('title')->get(['id', 'title', 'city', 'monthly_price']);

        return response()->json(['success' => true, 'data' => $list]);
    }

    private function typeId(Building $building, ?string $code): ?int
    {
        return $code ? $building->unitTypes()->where('code', $code)->value('id') : null;
    }
}
