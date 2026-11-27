<?php

namespace App\Http\Controllers\Visor3d;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Property;
use App\Models\UnitType;
use App\Services\Visor3d\BuildingConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Plano de la unidad: cómo se unen sus espacios (sala, cocina, baño, habitaciones…).
 * Cada espacio es un rectángulo en una cuadrícula; los que se tocan quedan conectados en el recorrido.
 * Se guarda en config.unit_plans["type-<id>"] o ["unit-<id>"] (no hace falta migración).
 */
class UnitPlanController extends Controller
{
    public const COLS = 12;
    public const ROWS = 8;

    public static function key(string $kind, int $id): string
    {
        return "{$kind}-{$id}";
    }

    public function update(Request $request, Building $building): JsonResponse
    {
        Gate::authorize('update', $building);

        $data = $request->validate([
            'owner_kind'          => ['required', 'in:type,unit'],
            'owner_id'            => ['required', 'integer'],
            'rooms'               => ['present', 'array', 'max:30'],
            'rooms.*.space'       => ['required', 'string', 'max:60', 'distinct'],
            'rooms.*.kind'        => ['nullable', 'string', 'max:30'],
            'rooms.*.x'           => ['required', 'integer', 'between:0,' . (self::COLS - 1)],
            'rooms.*.y'           => ['required', 'integer', 'between:0,' . (self::ROWS - 1)],
            'rooms.*.w'           => ['required', 'integer', 'between:1,' . self::COLS],
            'rooms.*.h'           => ['required', 'integer', 'between:1,' . self::ROWS],
            'doors'               => ['sometimes', 'array', 'max:60'],   // conexiones entre espacios
            'doors.*.a'           => ['required', 'string', 'max:60'],
            'doors.*.b'           => ['required', 'string', 'max:60', 'different:doors.*.a'],
        ]);

        $owner = $data['owner_kind'] === 'type'
            ? UnitType::where('building_id', $building->id)->find($data['owner_id'])
            : Property::where('building_id', $building->id)->find($data['owner_id']);
        abort_unless($owner, 422, 'El tipo o la unidad no pertenece a este edificio.');

        $rooms = collect($data['rooms'])->map(fn ($r) => [
            'space' => $r['space'],
            'kind'  => $r['kind'] ?? null,
            'x'     => (int) $r['x'],
            'y'     => (int) $r['y'],
            'w'     => min((int) $r['w'], self::COLS - (int) $r['x']),
            'h'     => min((int) $r['h'], self::ROWS - (int) $r['y']),
        ])->values()->all();

        // Solo puertas entre espacios del plano, sin repetir (a-b = b-a)
        $names = collect($rooms)->pluck('space')->all();
        $doors = collect($data['doors'] ?? [])
            ->filter(fn ($d) => in_array($d['a'], $names, true) && in_array($d['b'], $names, true) && $d['a'] !== $d['b'])
            ->map(fn ($d) => strcmp($d['a'], $d['b']) < 0 ? ['a' => $d['a'], 'b' => $d['b']] : ['a' => $d['b'], 'b' => $d['a']])
            ->unique(fn ($d) => $d['a'] . '|' . $d['b'])->values()->all();

        $config = $building->config;
        $config['unit_plans'][self::key($data['owner_kind'], (int) $data['owner_id'])] = ['rooms' => $rooms, 'doors' => $doors];
        $building->config = $config;
        $building->save();
        BuildingConfigService::bump($building->id);

        return response()->json(['success' => true, 'data' => ['rooms' => $rooms, 'doors' => $doors]]);
    }
}
