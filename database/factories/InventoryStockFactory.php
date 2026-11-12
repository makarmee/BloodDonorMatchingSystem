<?php

namespace Database\Factories;

use App\Models\BloodComponent;
use App\Models\InventoryStock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryStock>
 */
class InventoryStockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'blood_group' => fake()->randomElement(InventoryStock::BLOOD_GROUPS),
            'blood_component_id' => BloodComponent::factory(),
            'available_units' => 25,
            'reserved_units' => 0,
            'minimum_level' => 15,
            'target_capacity' => 100,
            'last_adjusted_at' => now(),
        ];
    }

    /**
     * Indicate healthy stock safely above minimum threshold.
     */
    public function healthy(): static
    {
        return $this->state(fn (array $attributes): array => [
            'available_units' => 45,
            'minimum_level' => 15,
        ]);
    }

    /**
     * Indicate low stock below the minimum threshold.
     */
    public function lowStock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'available_units' => 5,
            'minimum_level' => 15,
        ]);
    }

    /**
     * Indicate critically low stock (at or below critical level).
     */
    public function critical(): static
    {
        return $this->state(fn (array $attributes): array => [
            'available_units' => 2,
            'minimum_level' => 15,
        ]);
    }

    /**
     * Indicate zero available stock.
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'available_units' => 0,
            'reserved_units' => 0,
        ]);
    }
}
