<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class RentalRequestFactory extends Factory
{
    public function definition()
    {
        return [
            'requested_date' => now()->addDays(fake()->numberBetween(1, 15))->format('Y-m-d'),
            'requested_time' => fake()->randomElement(['09:00', '10:00', '11:30', '14:00', '16:30']),
            'counter_date' => null,
            'counter_time' => null,
            'status' => fake()->randomElement([
                'pending',
                'accepted',
                'rejected',
                'counter_proposed',
                'visit_completed',
                'contract_sent'
            ]),
            'visit_end_time' => null,
            'property_id' => null,
            'user_id' => null,
            'owner_id' => null,
        ];
    }
}
