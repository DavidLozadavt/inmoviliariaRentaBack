<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition()
    {
        // Seleccionar una propiedad existente
        $property = Property::inRandomOrder()->first();

        if (! $property) {
            throw new \Exception("No hay propiedades para crear contratos.");
        }

        $landlord = User::find($property->user_id);

        if (! $landlord) {
            throw new \Exception("La propiedad {$property->id} no tiene un propietario válido.");
        }

        // Inquilino distinto al dueño
        $tenant = User::where('id', '!=', $landlord->id)->inRandomOrder()->first();

        if (! $tenant) {
            throw new \Exception("No se puede generar contrato porque solo existe un usuario en la base.");
        }

        // Fechas realistas
        $start = now()->subDays(rand(0, 30))->startOfDay();
        $end   = $start->copy()->addMonths(rand(3, 12))->endOfDay();

        // Estado lógico
        $status = now()->greaterThan($end) ? 'expired' : 'active';

        return [
            'start_date' => $start,
            'end_date' => $end,
            'status' => $status,

            'document_path' => "contracts/contrato_{$property->id}.pdf",
            'deposit' => rand(500000, 3000000),

            // Validación de soporte e inquilino realista
            'validated_by_support' => 'yes',
            'support_validation_date' => now()->subDays(rand(0, 5)),

            'accepted_by_tenant' => 'yes',
            'tenant_acceptance_date' => now()->subDays(rand(0, 3)),

            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
        ];
    }
}
