<?php

namespace Database\Factories;

use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BloodRequest>
 */
class BloodRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_code' => 'REQ-'.fake()->unique()->numerify('######'),
            'hospital_id' => Hospital::factory(),
            'requested_by_user_id' => null,
            'department_or_ward' => 'Emergency & Trauma Ward',
            'contact_person_snapshot' => fake()->name(),
            'contact_phone_snapshot' => fake()->numerify('+1-555-###-####'),
            'contact_email_snapshot' => fake()->safeEmail(),
            'blood_group' => fake()->randomElement(BloodRequest::BLOOD_GROUPS),
            'blood_component_id' => BloodComponent::factory(),
            'requested_units' => 4,
            'reserved_units' => 0,
            'fulfilled_units' => 0,
            'priority' => 'Normal',
            'status' => 'Pending',
            'request_date' => now()->toDateString(),
            'required_date' => now()->addDays(2)->toDateString(),
            'request_reason' => 'Scheduled perioperative transfusion readiness.',
            'additional_notes' => null,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
            'cancelled_by_user_id' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ];
    }

    /**
     * Indicate that the blood request has been approved for allocation and fulfillment.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'Approved',
            'reviewed_by_user_id' => User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Indicate that the blood request has partial unit dispatch and active reservation.
     */
    public function partiallyFulfilled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'requested_units' => 4,
            'reserved_units' => 1,
            'fulfilled_units' => 2,
            'status' => 'Partially Fulfilled',
            'reviewed_by_user_id' => User::factory(),
            'reviewed_at' => now()->subHours(2),
        ]);
    }

    /**
     * Indicate that the blood request has been completely fulfilled.
     */
    public function fulfilled(): static
    {
        return $this->state(function (array $attributes): array {
            $requested = (int) ($attributes['requested_units'] ?? 4);

            return [
                'requested_units' => $requested,
                'reserved_units' => 0,
                'fulfilled_units' => $requested,
                'status' => 'Fulfilled',
                'reviewed_by_user_id' => User::factory(),
                'reviewed_at' => now()->subHours(4),
            ];
        });
    }

    /**
     * Indicate that the blood request was rejected upon clinical review.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'Rejected',
            'reviewed_by_user_id' => User::factory(),
            'reviewed_at' => now(),
            'rejection_reason' => 'Insufficient clinical indication details provided.',
        ]);
    }

    /**
     * Indicate that the blood request was cancelled prior to completion.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'Cancelled',
            'cancelled_by_user_id' => User::factory(),
            'cancelled_at' => now(),
            'cancellation_reason' => 'Surgical procedure postponed by requesting ward.',
        ]);
    }

    /**
     * Mark the request with Critical triage priority and same-day requirement.
     */
    public function critical(): static
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => 'Critical',
            'required_date' => now()->toDateString(),
        ]);
    }
}
