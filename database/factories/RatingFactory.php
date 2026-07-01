<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class RatingFactory extends Factory
{
    public function definition()
    {
        return [
            'recipient_role' => null, // lo asignaremos en el seeder según si califica dueño o inquilino
            'score' => fake()->numberBetween(3, 5),
            'comment' => fake()->randomElement([
                'Excelente comunicación y cumplimiento del contrato.',
                'Todo muy claro durante la estadía.',
                'Pagos puntuales y conducta impecable.',
                'El trato fue respetuoso y responsable.',
                'Seriedad total durante toda la relación contractual.',
            ]),
            'date' => now()->format('Y-m-d'),
            'contract_id' => null, // lo define el seeder
            'user_id' => null, // lo define el seeder
        ];
    }
}
