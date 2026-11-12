<?php

namespace Database\Factories;

use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BloodUnit>
 */
class BloodUnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_code' => 'UNIT-'.now()->format('Y').'-'.fake()->unique()->numerify('######'),
            'donation_id' => Donation::factory()->screeningPassed(),
            'parent_unit_id' => null,
            'blood_group' => fn (array $attributes): string => Donation::query()
                ->whereKey($attributes['donation_id'] ?? null)
                ->value('blood_group')
                ?? fake()->randomElement(BloodUnit::BLOOD_GROUPS),
            'blood_component_id' => fn (array $attributes): mixed => Donation::query()
                ->whereKey($attributes['donation_id'] ?? null)
                ->value('blood_component_id')
                ?? BloodComponent::factory(),
            'volume_ml' => 300,
            'storage_location' => 'Cold Room A - Rack '.fake()->numberBetween(1, 8),
            'unit_status' => 'Quarantined',
            'collected_at' => now()->subHours(6),
            'expires_at' => now()->addDays(35),
            'released_at' => null,
            'released_by_user_id' => null,
        ];
    }

    /**
     * Indicate that the serialized blood unit is released and available for allocation.
     */
    public function available(): static
    {
        return $this->state(fn (array $attributes): array => [
            'unit_status' => 'Available',
            'released_at' => now()->subHour(),
            'released_by_user_id' => User::factory(),
        ]);
    }

    /**
     * Indicate that the unit is currently reserved for an active blood request.
     */
    public function reserved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'unit_status' => 'Reserved',
            'released_at' => now()->subHours(2),
            'released_by_user_id' => User::factory(),
        ]);
    }

    /**
     * Indicate that the unit has been dispatched to a hospital facility.
     */
    public function dispatched(): static
    {
        return $this->state(fn (array $attributes): array => [
            'unit_status' => 'Dispatched',
            'released_at' => now()->subDay(),
            'released_by_user_id' => User::factory(),
        ]);
    }

    /**
     * Indicate that the available unit is nearing shelf-life expiry.
     */
    public function expiringSoon(int $daysRemaining = 3): static
    {
        return $this->state(fn (array $attributes): array => [
            'unit_status' => 'Available',
            'collected_at' => now()->subDays(32),
            'expires_at' => now()->addDays($daysRemaining),
            'released_at' => now()->subDays(31),
            'released_by_user_id' => User::factory(),
        ]);
    }

    /**
     * Indicate that the blood unit is past expiry.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'unit_status' => 'Expired',
            'collected_at' => now()->subDays(45),
            'expires_at' => now()->subDays(2),
        ]);
    }

    /**
     * Indicate that the unit has been discarded due to screening failure or wastage.
     */
    public function discarded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'unit_status' => 'Discarded',
        ]);
    }
}
