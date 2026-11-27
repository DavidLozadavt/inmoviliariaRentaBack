<?php

namespace App\Observers;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\Payment;
use App\Models\Property;
use App\Services\Visor3d\BuildingConfigService;
use Illuminate\Database\Eloquent\Model;

/** Invalida la caché del visor 3D cuando cambia algo de una unidad. */
class Visor3dCacheObserver
{
    public function saved(Model $model): void
    {
        $this->bump($model);
    }

    public function deleted(Model $model): void
    {
        $this->bump($model);
    }

    private function bump(Model $model): void
    {
        $ids = match (true) {
            $model instanceof Property    => [$model->building_id, $model->getOriginal('building_id')],
            $model instanceof Contract,
            $model instanceof Maintenance => [Property::withTrashed()->whereKey($model->property_id)->value('building_id')],
            $model instanceof Payment     => [Property::withTrashed()->whereKey(
                Contract::whereKey($model->contract_id)->value('property_id')
            )->value('building_id')],
            default => [],
        };

        foreach (array_unique(array_filter($ids)) as $id) {
            BuildingConfigService::bump((int) $id);
        }
    }
}
