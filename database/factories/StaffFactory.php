<?php

namespace Database\Factories;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => 'EMP-'.fake()->unique()->numerify('####'),
            'user_id' => null,
            'full_name' => fake()->name(),
            'professional_role' => fake()->randomElement(Staff::PROFESSIONAL_ROLES),
            'department' => fake()->randomElement(Staff::DEPARTMENTS),
            'phone' => '+1-555-'.fake()->numerify('###-####'),
            'email' => fake()->unique()->safeEmail(),
            'status' => 'Active',
            'notes' => null,
        ];
    }

    /**
     * Indicate that the staff member is active.
     */
    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => 'Active',
        ]);
    }

    /**
     * Indicate that the staff member is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => 'Inactive',
        ]);
    }
}
