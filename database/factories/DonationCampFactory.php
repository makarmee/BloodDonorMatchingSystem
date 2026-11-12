<?php

namespace Database\Factories;

use App\Models\DonationCamp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonationCamp>
 */
class DonationCampFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = now()->addDays(14);
        $suffix = fake()->unique()->numerify('#####');

        return [
            'camp_code' => 'CMP-'.$suffix,
            'name' => 'Community Blood Drive '.$suffix,
            'description' => 'Organized mobile community blood collection camp.',
            'organizer_name' => fake()->company(),
            'organizer_contact' => fake()->numerify('+1-555-###-####'),
            'organizer_email' => fake()->unique()->companyEmail(),
            'created_by_user_id' => null,
            'venue_name' => fake()->company().' Civic Hall',
            'full_address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'location_notes' => 'Ground floor assembly hall with donor parking.',
            'start_date' => $startDate->toDateString(),
            'end_date' => $startDate->copy()->addDay()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '16:00:00',
            'capacity' => 60,
            'registration_deadline' => $startDate->copy()->subDays(2)->toDateString(),
            'allow_walk_in' => true,
            'donor_requirements' => 'Age 18-65, minimum weight 50 kg, valid photo ID.',
            'additional_notes' => null,
            'status' => 'Draft',
        ];
    }

    /**
     * Indicate that the camp is published and upcoming.
     */
    public function upcoming(): static
    {
        $startDate = now()->addDays(10);

        return $this->state(fn (array $attributes): array => [
            'start_date' => $startDate->toDateString(),
            'end_date' => $startDate->copy()->addDay()->toDateString(),
            'registration_deadline' => $startDate->copy()->subDays(2)->toDateString(),
            'status' => 'Upcoming',
        ]);
    }

    /**
     * Indicate that the camp is currently ongoing.
     */
    public function ongoing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'registration_deadline' => now()->subDay()->toDateString(),
            'status' => 'Ongoing',
        ]);
    }

    /**
     * Indicate that the camp has already completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDays(9)->toDateString(),
            'registration_deadline' => now()->subDays(12)->toDateString(),
            'status' => 'Completed',
        ]);
    }

    /**
     * Indicate that the camp was cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'Cancelled',
        ]);
    }
}
