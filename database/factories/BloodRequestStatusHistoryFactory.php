<?php

namespace Database\Factories;

use App\Models\BloodRequest;
use App\Models\BloodRequestStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BloodRequestStatusHistory>
 */
class BloodRequestStatusHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'blood_request_id' => BloodRequest::factory(),
            'event_name' => 'Request Submitted',
            'previous_status' => null,
            'new_status' => 'Pending',
            'units_affected' => 0,
            'actor_user_id' => null,
            'actor_name' => fake()->name(),
            'notes' => 'Hospital request submitted for review.',
            'occurred_at' => now(),
        ];
    }
}
