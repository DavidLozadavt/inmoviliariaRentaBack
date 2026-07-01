<?php

namespace Database\Factories;

use App\Models\Maintenance;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MaintenanceFactory extends Factory
{
    protected $model = Maintenance::class;

    public function definition()
    {
        // Seleccionar una propiedad existente aleatoriamente
        $property = Property::inRandomOrder()->first();

        if (! $property) {
            throw new \Exception("No hay propiedades para crear mantenimientos.");
        }

        // Usuario (inquilino) aleatorio de la BD
        $user = User::inRandomOrder()->first();

        if (! $user) {
            throw new \Exception("No hay usuarios registrados para crear mantenimientos.");
        }

        // Fechas realistas
        $requestDate = now()->subDays(rand(0, 120))->format('Y-m-d');
        $statusOptions = ['pending', 'in_progress', 'finished'];
        $status = $statusOptions[array_rand($statusOptions)];

        $priorityOptions = ['low', 'medium', 'high', 'emergency'];

        return [
            'title' => 'Problema con ' . $this->faker->word(),
            'description' => $this->faker->sentence(8), // texto genérico, pero válido
            'request_date' => $requestDate,
            'status' => $status,
            'priority' => $priorityOptions[array_rand($priorityOptions)],
            'resolution_date' => $status === 'finished' ? now()->subDays(rand(1, 20)) : null,
            'validated_by_tenant' => $status === 'finished' ? 'yes' : 'no',
            'property_id' => $property->id,
            'user_id' => $user->id,
        ];
    }
}
