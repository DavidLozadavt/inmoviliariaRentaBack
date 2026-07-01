<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ReportFactory extends Factory
{
    public function definition()
    {
        return [
            'type' => fake()->randomElement([
                'financial',
                'activity',
                'properties',
                'payments',
                'contracts',
                'maintenance'
            ]),
            'description' => fake()->sentence(),
            'applied_filter' => fake()->randomElement([
                'last_30_days',
                'current_year',
                'pending_only',
                'completed_only',
                'high_value_contracts',
                'active_properties'
            ]),
            'generation_date' => now()->format('Y-m-d'),
            'user_id' => null,
        ];
    }
}
