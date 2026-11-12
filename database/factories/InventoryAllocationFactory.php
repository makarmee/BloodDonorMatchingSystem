<?php

namespace Database\Factories;

use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\InventoryAllocation;
use App\Models\InventoryStock;
use App\Models\RequestFulfillment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryAllocation>
 */
class InventoryAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'allocation_code' => 'ALC-'.fake()->unique()->numerify('######'),
            'blood_request_id' => BloodRequest::factory()->approved(),
            'inventory_stock_id' => InventoryStock::factory(),
            'blood_unit_id' => BloodUnit::factory()->reserved(),
            'fulfillment_id' => null,
            'units_allocated' => 1,
            'allocation_status' => 'Reserved',
            'allocated_by_user_id' => User::factory(),
            'allocated_at' => now(),
            'released_at' => null,
            'dispatched_at' => null,
            'notes' => null,
        ];
    }

    /**
     * Indicate that the allocated unit has been dispatched with a fulfillment record.
     */
    public function dispatched(?RequestFulfillment $fulfillment = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'allocation_status' => 'Dispatched',
            'fulfillment_id' => $fulfillment?->getKey() ?? RequestFulfillment::factory(),
            'dispatched_at' => now(),
        ]);
    }

    /**
     * Indicate that the reservation was released so active_unit_guard remains null.
     */
    public function released(): static
    {
        return $this->state(fn (array $attributes): array => [
            'allocation_status' => 'Released',
            'released_at' => now(),
        ]);
    }

    /**
     * Indicate that the allocation was cancelled so active_unit_guard remains null.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'allocation_status' => 'Cancelled',
            'released_at' => now(),
        ]);
    }
}
