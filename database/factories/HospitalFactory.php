<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hospital>
 */
class HospitalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hospital_code' => 'HSP-'.fake()->unique()->numerify('#####'),
            'name' => fake()->company().' Medical Center',
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'primary_contact_name' => fake()->name(),
            'primary_phone' => fake()->numerify('+1-555-###-####'),
            'primary_email' => fake()->unique()->companyEmail(),
            'verification_status' => 'Verified',
            'verified_by_user_id' => User::factory(),
            'verified_at' => now(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the hospital is awaiting onboarding verification.
     */
    public function pendingVerification(): static
    {
        return $this->state(fn (array $attributes): array => [
            'verification_status' => 'Pending',
            'verified_by_user_id' => null,
            'verified_at' => null,
        ]);
    }

    /**
     * Indicate that the partner hospital account has been suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'verification_status' => 'Suspended',
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the hospital record is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
