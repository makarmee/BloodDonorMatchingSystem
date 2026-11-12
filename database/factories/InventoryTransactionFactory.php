<?php

namespace Database\Factories;

use App\Models\BloodUnit;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryTransaction>
 */
class InventoryTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_code' => 'TXN-'.fake()->unique()->numerify('#######'),
            'inventory_stock_id' => InventoryStock::factory(),
            'blood_unit_id' => BloodUnit::factory()->available(),
            'operation_type' => 'Stock In',
            'units_delta' => 1,
            'available_before' => 10,
            'available_after' => 11,
            'reserved_before' => 0,
            'reserved_after' => 0,
            'reference_type' => null,
            'reference_id' => null,
            'performed_by_user_id' => User::factory(),
            'actor_name' => fake()->name(),
            'description' => 'Released blood unit added to available inventory.',
            'occurred_at' => now(),
        ];
    }

    /**
     * Record a reservation ledger transaction.
     */
    public function reserve(): static
    {
        return $this->state(fn (array $attributes): array => [
            'operation_type' => 'Reserve',
            'units_delta' => -1,
            'available_before' => 11,
            'available_after' => 10,
            'reserved_before' => 0,
            'reserved_after' => 1,
            'description' => 'Unit reserved for approved hospital blood request.',
        ]);
    }

    /**
     * Record a release-reservation ledger transaction.
     */
    public function releaseReservation(): static
    {
        return $this->state(fn (array $attributes): array => [
            'operation_type' => 'Release Reservation',
            'units_delta' => 1,
            'available_before' => 10,
            'available_after' => 11,
            'reserved_before' => 1,
            'reserved_after' => 0,
            'description' => 'Reserved unit returned to available inventory pool.',
        ]);
    }

    /**
     * Record a hospital dispatch ledger transaction.
     */
    public function dispatch(): static
    {
        return $this->state(fn (array $attributes): array => [
            'operation_type' => 'Dispatch',
            'units_delta' => -1,
            'available_before' => 10,
            'available_after' => 10,
            'reserved_before' => 1,
            'reserved_after' => 0,
            'description' => 'Reserved unit dispatched for request fulfillment.',
        ]);
    }
}
