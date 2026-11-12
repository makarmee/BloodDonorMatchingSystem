<?php

namespace Database\Factories;

use App\Models\BloodComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BloodComponent>
 */
class BloodComponentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $suffix = fake()->unique()->numerify('#####');

        return [
            'code' => 'CMP-'.$suffix,
            'name' => 'Blood Component '.$suffix,
            'default_volume_ml' => fake()->randomElement([250, 300, 360, 450, 600]),
            'shelf_life_days' => fake()->randomElement([5, 35, 42, 365]),
            'storage_requirements' => '2°C to 6°C controlled cold-chain storage',
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the blood component catalog entry is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
