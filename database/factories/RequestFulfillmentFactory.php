<?php

namespace Database\Factories;

use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\RequestFulfillment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequestFulfillment>
 */
class RequestFulfillmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fulfillment_code' => 'FUL-'.fake()->unique()->numerify('######'),
            'blood_request_id' => BloodRequest::factory()->approved(),
            'dispatched_by_user_id' => User::factory(),
            'dispatched_blood_group' => fn (array $attributes): string => BloodRequest::query()
                ->whereKey($attributes['blood_request_id'] ?? null)
                ->value('blood_group')
                ?? fake()->randomElement(RequestFulfillment::BLOOD_GROUPS),
            'blood_component_id' => fn (array $attributes): mixed => BloodRequest::query()
                ->whereKey($attributes['blood_request_id'] ?? null)
                ->value('blood_component_id')
                ?? BloodComponent::factory(),
            'units_dispatched' => 2,
            'dispatch_date' => now()->toDateString(),
            'dispatched_at' => now(),
            'courier_reference' => 'COU-'.fake()->unique()->numerify('#####'),
            'delivery_status' => 'Dispatched',
            'delivered_at' => null,
            'received_by_name' => null,
            'notes' => null,
            'is_compatibility_override' => false,
            'override_approved_by_user_id' => null,
            'override_approved_at' => null,
            'override_reason' => null,
        ];
    }

    /**
     * Indicate an authorized emergency compatibility override dispatch.
     */
    public function withOverride(?string $reason = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'dispatched_blood_group' => 'O-',
            'is_compatibility_override' => true,
            'override_approved_by_user_id' => User::factory(),
            'override_approved_at' => now(),
            'override_reason' => $reason ?? 'Emergency universal donor override authorized by medical director.',
        ]);
    }

    /**
     * Indicate that the fulfillment shipment is in transit.
     */
    public function inTransit(): static
    {
        return $this->state(fn (array $attributes): array => [
            'delivery_status' => 'In Transit',
        ]);
    }

    /**
     * Indicate that the fulfillment shipment has been delivered and signed off.
     */
    public function delivered(): static
    {
        return $this->state(fn (array $attributes): array => [
            'delivery_status' => 'Delivered',
            'delivered_at' => now(),
            'received_by_name' => fake()->name(),
        ]);
    }

    /**
     * Indicate that the dispatched shipment was returned.
     */
    public function returned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'delivery_status' => 'Returned',
        ]);
    }
}
