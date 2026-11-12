<?php

namespace Database\Factories;

use App\Models\CampRegistration;
use App\Models\DonationCamp;
use App\Models\Donor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampRegistration>
 */
class CampRegistrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_code' => 'REG-'.fake()->unique()->numerify('######'),
            'camp_id' => DonationCamp::factory()->upcoming(),
            'donor_id' => Donor::factory()->eligible(),
            'appointment_id' => null,
            'registered_by_user_id' => null,
            'registration_date' => now()->toDateString(),
            'is_walk_in' => false,
            'attendance_status' => 'Registered',
            'registered_at' => now(),
            'checked_in_at' => null,
            'cancelled_at' => null,
        ];
    }

    /**
     * Indicate that the registration is confirmed.
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'attendance_status' => 'Confirmed',
        ]);
    }

    /**
     * Indicate that the donor checked in and attended the camp.
     */
    public function attended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'attendance_status' => 'Attended',
            'checked_in_at' => now(),
        ]);
    }

    /**
     * Indicate that the donor did not attend the camp.
     */
    public function noShow(): static
    {
        return $this->state(fn (array $attributes): array => [
            'attendance_status' => 'No-show',
        ]);
    }

    /**
     * Indicate that the registration was cancelled so active_registration_guard remains null.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'attendance_status' => 'Cancelled',
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Indicate a walk-in camp check-in registration.
     */
    public function walkIn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_walk_in' => true,
            'attendance_status' => 'Attended',
            'checked_in_at' => now(),
        ]);
    }
}
