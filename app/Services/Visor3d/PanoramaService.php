<?php

namespace App\Services\Visor3d;

use App\Models\Building;
use App\Models\Panorama;
use App\Models\Property;
use App\Models\UnitType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class PanoramaService
{
    public const DISK = 'public';
    public const DIR  = 'visor3d';

    /** Tipos de espacio que se pueden marcar en una unidad. */
    /** Superficie que muestra una foto normal dentro de su espacio. */
    public const SURFACES = ['front', 'right', 'back', 'left', 'floor', 'ceiling', 'detail'];

    public const KINDS = ['sala', 'comedor', 'cocina', 'habitacion', 'bano', 'balcon', 'estudio', 'lavanderia', 'patio', 'fachada', 'otro'];

    /**
     * Recorrido de una unidad: los espacios de su tipo más los propios de la unidad.
     * Si un espacio propio se llama igual que uno del tipo, gana el de la unidad.
     */
    public function tourFor(Property $unit): array
    {
        $own = $unit->panoramas()->with('hotspots')->get();
        $type = $unit->unit_type_id
            ? Panorama::where('owner_type', UnitType::class)->where('owner_id', $unit->unit_type_id)
                ->with('hotspots')->orderBy('order')->get()
            : collect();

        $ownNames  = $own->pluck('space')->map(fn ($s) => mb_strtolower($s))->all();
        $inherited = $type->reject(fn ($p) => in_array(mb_strtolower($p->space), $ownNames, true));
        $panoramas = $inherited->concat($own)->values();

        $source = match (true) {
            $panoramas->isEmpty() => null,
            $own->isEmpty()       => 'type',
            $inherited->isEmpty() => 'unit',
            default               => 'mixed',
        };

        $start = $own->firstWhere('is_start', true) ?? $inherited->firstWhere('is_start', true) ?? $panoramas->first();

        $plans = optional(\App\Models\Building::find($unit->building_id))->config['unit_plans'] ?? [];
        $plan  = $plans["unit-{$unit->id}"] ?? ($unit->unit_type_id ? ($plans["type-{$unit->unit_type_id}"] ?? null) : null);

        return [
            'unit_id' => $unit->id,
            'plan'    => $plan,
            'source'  => $source,
            'start'   => $start?->id,
            'nodes'   => $panoramas->map(fn (Panorama $p) => $this->present($p))->values(),
        ];
    }

    /** Todas las fotos del edificio (por tipo y por unidad) para el panel de admin. */
    public function forBuilding(Building $building): Collection
    {
        $typeIds = $building->unitTypes()->pluck('id');
        $unitIds = $building->units()->pluck('id');

        return Panorama::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('owner_type', UnitType::class)->whereIn('owner_id', $typeIds))
                ->orWhere(fn ($q) => $q->where('owner_type', Property::class)->whereIn('owner_id', $unitIds)))
            ->with('hotspots')
            ->orderBy('owner_type')->orderBy('owner_id')->orderBy('order')
            ->get()
            ->map(fn (Panorama $p) => $this->present($p) + [
                'owner' => ['kind' => $p->owner_type === UnitType::class ? 'type' : 'unit', 'id' => $p->owner_id],
            ]);
    }

    public function present(Panorama $p): array
    {
        return [
            'id'      => $p->id,
            'space'   => $p->space,
            'kind'    => $p->kind,
            'projection' => $p->projection ?? 'equirect',
            'surface' => $p->surface,
            'url'     => Storage::disk(self::DISK)->url($p->path),
            'thumb'   => $p->thumb_path ? Storage::disk(self::DISK)->url($p->thumb_path) : null,
            'width'   => $p->width,
            'size_kb' => $p->size_kb,
            'is_start'=> $p->is_start,
            'order'   => $p->order,
            'links'   => $p->hotspots->map(fn ($h) => [
                'id'     => $h->id,
                'target' => $h->target_panorama_id,
                'yaw'    => (float) $h->yaw,
                'pitch'  => (float) $h->pitch,
                'label'  => $h->label,
            ])->values(),
        ];
    }

    public function deleteFiles(Panorama $p): void
    {
        Storage::disk(self::DISK)->delete(array_filter([$p->path, $p->thumb_path]));
    }

    /** Archivos en storage/app/public/visor3d que ninguna fila usa. */
    public function orphanFiles(): Collection
    {
        $used = Panorama::query()->get(['path', 'thumb_path'])
            ->flatMap(fn ($p) => [$p->path, $p->thumb_path])
            ->merge(\App\Models\Building::withTrashed()->get(['cover_path', 'preview_path', 'facade_front_path', 'facade_side_path', 'facade_left_path', 'facade_back_path', 'roof_path', 'site_plan_path'])
                ->flatMap(fn ($b) => [$b->cover_path, $b->preview_path, $b->facade_front_path, $b->facade_side_path,
                    $b->facade_left_path, $b->facade_back_path, $b->roof_path, $b->site_plan_path]))
            ->merge(\App\Models\Building::withTrashed()->get(['config'])->flatMap(fn ($b) => collect($b->config['towers'] ?? [])
                ->flatMap(fn ($t) => array_map(fn ($k) => $t["{$k}_path"] ?? null, \App\Http\Controllers\Visor3d\TowerMediaController::KINDS))))
            ->filter()->flip();

        return collect(Storage::disk(self::DISK)->allFiles(self::DIR))
            ->reject(fn ($f) => isset($used[$f]))->values();
    }
}
