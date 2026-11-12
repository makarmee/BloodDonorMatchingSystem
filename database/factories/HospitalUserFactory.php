<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HospitalUser>
 */
class HospitalUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hospital_id' => Hospital::factory(),
            'user_id' => User::factory(),
            'contact_name' => fake()->name(),
            'department' => 'Transfusion Medicine',
            'job_title' => 'Blood Bank Coordinator',
            'work_phone' => fake()->numerify('+1-555-###-####'),
            'work_email' => fake()->unique()->safeEmail(),
            'is_primary_contact' => false,
            'is_active' => true,
        ];
    }

    /**
     * Mark this hospital user as the facility's primary contact.
     */
    public function primaryContact(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_primary_contact' => true,
        ]);
    }

    /**
     * Mark this hospital user link as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
