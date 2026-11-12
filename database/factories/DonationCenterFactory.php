<?php

namespace Database\Factories;

use App\Models\DonationCenter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonationCenter>
 */
class DonationCenterFactory extends Factory
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
            'center_code' => 'CTR-'.$suffix,
            'name' => fake()->city().' Fixed Blood Center '.$suffix,
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'operating_hours' => '08:00 - 17:00',
            'default_slot_capacity' => 3,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the donation center is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
