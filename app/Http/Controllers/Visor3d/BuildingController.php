<?php

namespace App\Http\Controllers\Visor3d;

use App\Http\Controllers\Controller;
use App\Http\Requests\Visor3d\BuildingRequest;
use App\Models\Building;
use App\Services\Visor3d\BuildingConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BuildingController extends Controller
{
    public function __construct(private BuildingConfigService $configs)
    {
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Building::class);

        $buildings = Building::visibleTo(Auth::user())
            ->withCount([
                'units',
                'units as available_count' => fn ($q) => $q->where(fn ($q) => $q
                    ->where('commercial_status', 'available')
                    ->orWhere(fn ($q) => $q->whereNull('commercial_status')->where('status', 'available'))),
            ])
            ->orderByDesc('created_at')
            ->get(['id', 'user_id', 'name', 'address', 'city', 'config', 'cover_path', 'preview_path', 'created_at', 'updated_at'])
            ->map(fn (Building $b) => [
                'id'              => $b->id,
                'name'            => $b->name,
                'address'         => $b->address,
                'city'            => $b->city,
                'units_count'     => $b->units_count,
                'available_count' => $b->available_count,
                'towers'          => count($b->config['towers'] ?? []),
                'max_floors'      => collect($b->config['towers'] ?? [])->max('floors') ?? 0,
                'cover_url'       => $b->cover_url,
                'preview_url'     => $b->preview_url,
                'created_at'      => $b->created_at,
                'updated_at'      => $b->updated_at,
            ]);

        return $this->ok($buildings);
    }

    public function store(BuildingRequest $request): JsonResponse
    {
        Gate::authorize('create', Building::class);

        $building = DB::transaction(function () use ($request) {
            $building = Building::create($request->safe()->only(['name', 'address', 'city', 'config']) + [
                'user_id' => Auth::id(),
            ]);
            $this->syncUnitTypes($building, $request->input('unit_types', []));
            return $building;
        });

        return $this->ok($this->configs->payload($building->fresh()), 201);
    }

    /** Configuración completa para el visor (modo privado: incluye cartera, inquilino y PQRS). */
    public function config(Building $building): JsonResponse
    {
        Gate::authorize('view', $building);

        return $this->ok($this->configs->payload($building))
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(BuildingRequest $request, Building $building): JsonResponse
    {
        Gate::authorize('update', $building);

        DB::transaction(function () use ($request, $building) {
            $building->fill($request->safe()->only(['name', 'address', 'city', 'config']));
            if ($request->has('config')) {
                $original = $building->getOriginal('config');
                $merged = $this->keepTowerMedia($original, $building->config);
                $orig = is_string($original) ? json_decode($original, true) : ($original ?? []);
                unset($merged['unit_plans']);
                if (! empty($orig['unit_plans'])) {
                    $merged['unit_plans'] = $orig['unit_plans'];
                }
                $building->config = $merged;
            }
            $building->config_version++;
            $building->save();

            if ($request->has('unit_types')) {
                $this->syncUnitTypes($building, $request->input('unit_types'));
            }
        });

        return $this->ok($this->configs->payload($building->fresh()));
    }

    public function destroy(Building $building): JsonResponse
    {
        Gate::authorize('delete', $building);

        // Las unidades (properties) se conservan: solo pierden el vínculo al edificio.
        DB::transaction(function () use ($building) {
            $building->units()->update(['building_id' => null, 'unit_type_id' => null]);
            $building->delete();
        });

        return $this->ok(null);
    }

    /** Las rutas de imágenes de cada torre solo las escribe TowerMediaController: se conservan por código de torre. */
    private function keepTowerMedia(mixed $old, array $new): array
    {
        $old  = is_string($old) ? json_decode($old, true) : ($old ?? []);
        $keys = array_map(fn ($k) => "{$k}_path", \App\Http\Controllers\Visor3d\TowerMediaController::KINDS);
        $byCode = collect($old['towers'] ?? [])->keyBy(fn ($t) => (string) $t['code']);

        foreach ($new['towers'] ?? [] as $i => $t) {
            foreach ($keys as $k) {
                unset($new['towers'][$i][$k], $new['towers'][$i][str_replace('_path', '_url', $k)]);
                if ($v = $byCode[(string) $t['code']][$k] ?? null) {
                    $new['towers'][$i][$k] = $v;
                }
            }
        }

        return $new;
    }

    /** Crea/actualiza por código y borra los tipos que ya no vienen. */
    private function syncUnitTypes(Building $building, array $types): void
    {
        $codes = [];
        foreach ($types as $t) {
            $building->unitTypes()->updateOrCreate(['code' => $t['code']], $t);
            $codes[] = $t['code'];
        }
        $building->unitTypes()->whereNotIn('code', $codes)->delete();
    }

    private function ok($data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }
}
