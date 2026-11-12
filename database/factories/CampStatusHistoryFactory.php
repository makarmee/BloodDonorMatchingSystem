<?php

namespace Database\Factories;

use App\Models\CampStatusHistory;
use App\Models\DonationCamp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampStatusHistory>
 */
class CampStatusHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'camp_id' => DonationCamp::factory(),
            'event_name' => 'Camp Created',
            'previous_status' => null,
            'new_status' => 'Draft',
            'actor_user_id' => null,
            'actor_name' => fake()->name(),
            'notes' => 'Camp record initialized.',
            'occurred_at' => now(),
        ];
    }
}
