<?php

namespace App\Services\Visor3d;

use App\Models\Building;
use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\Property;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Arma (y guarda en caché) la configuración del visor 3D de un edificio.
 * La llave lleva config_version: editar el edificio o cualquier dato de una
 * unidad incrementa la versión y la caché vieja simplemente deja de leerse.
 */
class BuildingConfigService
{
    public const TTL_SECONDS = 600;

    public function payload(Building $building, bool $public = false): array
    {
        $key = sprintf('visor3d:building:%d:v%d:%s', $building->id, $building->config_version, $public ? 'public' : 'private');

        return Cache::remember($key, self::TTL_SECONDS, fn () => $this->build($building, $public));
    }

    public static function bump(?int $buildingId): void
    {
        if ($buildingId) {
            Building::withTrashed()->whereKey($buildingId)->increment('config_version');
        }
    }

    private function build(Building $building, bool $public): array
    {
        $building->loadMissing('unitTypes');

        $units = Property::query()
            ->where('building_id', $building->id)
            ->with([
                'contracts' => fn ($q) => $q->where('status', 'active')
                    ->with(['tenant:id,name', 'payments']),
            ])
            ->withCount(['maintenances as open_requests' => fn ($q) => $q->whereIn('status', ['pending', 'in_progress'])])
            ->orderBy('tower')->orderBy('floor')->orderBy('unit_code')
            ->get();

        return [
            'building' => [
                'id'      => $building->id,
                'name'    => $building->name,
                'address' => $building->address,
                'city'    => $building->city,
                'version' => $building->config_version,
                'cover_url'   => $building->cover_url,
                'preview_url' => $building->preview_url,
                'facade_front_url' => $building->facade_front_url,
                'facade_side_url'  => $building->facade_side_url,
                'facade_left_url'  => $building->facade_left_url,
                'facade_back_url'  => $building->facade_back_url,
                'roof_url'         => $building->roof_url,
                'site_plan_url'    => $building->site_plan_url,
            ],
            'config'     => $this->presentConfig($building->config),
            'unit_types' => $building->unitTypes->map(fn ($t) => [
                'id'        => $t->id,
                'code'      => $t->code,
                'name'      => $t->name,
                'area_m2'   => $t->area_m2 !== null ? (float) $t->area_m2 : null,
                'bedrooms'  => $t->bedrooms,
                'bathrooms' => $t->bathrooms,
                'color'     => $t->color,
            ])->values(),
            'units' => $units->map(fn (Property $p) => $this->unit($p, $building, $public))->values(),
        ];
    }

    /** Cambia las rutas de imágenes de las torres por URLs públicas. */
    private function presentConfig(array $config): array
    {
        foreach ($config['towers'] ?? [] as $i => $t) {
            foreach (\App\Http\Controllers\Visor3d\TowerMediaController::KINDS as $k) {
                $path = $t["{$k}_path"] ?? null;
                unset($config['towers'][$i]["{$k}_path"]);
                $config['towers'][$i]["{$k}_url"] = $path ? \Illuminate\Support\Facades\Storage::disk('public')->url($path) : null;
            }
        }

        return $config;
    }

    private function unit(Property $p, Building $building, bool $public): array
    {
        $type     = $building->unitTypes->firstWhere('id', $p->unit_type_id);
        $contract = $p->contracts->first();

        $data = [
            'id'                => $p->id,
            'tower'             => $p->tower,
            'floor'             => $p->floor,
            'unit_code'         => $p->unit_code,
            'unit_type'         => $type?->code,
            'title'             => $p->title,
            'area_m2'           => (float) ($p->area_m2 ?: $type?->area_m2),
            'bedrooms'          => (int) ($p->num_bedrooms ?: $type?->bedrooms),
            'bathrooms'         => (int) ($p->num_bathrooms ?: $type?->bathrooms),
            'price'             => (float) $p->monthly_price,
            'commercial_status' => $this->commercialStatus($p, $contract),
        ];

        if ($public) {
            return $data; // solo datos comerciales: sin inquilino, cartera ni PQRS
        }

        $arrearsDays = $this->arrearsDays($contract);

        return $data + [
            'tenant'         => $contract?->tenant ? ['id' => $contract->tenant->id, 'name' => $contract->tenant->name] : null,
            'contract_id'    => $contract?->id,
            'arrears_days'   => $arrearsDays,
            'arrears_bucket' => $arrearsDays === 0 ? 'ok' : ($arrearsDays <= 60 ? 'lt60' : 'gt60'),
            'open_requests'  => (int) $p->open_requests,
        ];
    }

    private function commercialStatus(Property $p, ?Contract $contract): string
    {
        if ($p->commercial_status) {
            return $p->commercial_status;
        }
        return $contract ? 'rented' : ($p->status === 'available' ? 'available' : 'reserved');
    }

    /** Días desde el pago pendiente más viejo del contrato activo. */
    private function arrearsDays(?Contract $contract): int
    {
        if (! $contract) {
            return 0;
        }

        $oldest = $contract->payments
            ->filter(fn ($pay) => $pay->status !== 'paid' && $pay->payment_date)
            ->map(function ($pay) {
                try {
                    return Carbon::parse($pay->payment_date)->startOfDay();
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter()
            ->min();

        if (! $oldest || $oldest->isFuture()) {
            return 0;
        }

        return (int) $oldest->diffInDays(now()->startOfDay());
    }
}
