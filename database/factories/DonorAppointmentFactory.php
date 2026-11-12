<?php

namespace Database\Factories;

use App\Models\BloodComponent;
use App\Models\DonationCamp;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\DonorAppointment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonorAppointment>
 */
class DonorAppointmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hour = fake()->numberBetween(8, 16);
        $minute = fake()->randomElement([0, 15, 30, 45]);

        return [
            'appointment_code' => 'APT-'.fake()->unique()->numerify('######'),
            'donor_id' => Donor::factory()->eligible(),
            'donation_component_id' => BloodComponent::factory(),
            'donation_camp_id' => null,
            'donation_center_id' => fn (array $attributes): mixed => ($attributes['donation_camp_id'] ?? null) === null
                ? DonationCenter::factory()
                : null,
            'appointment_date' => now()->addDays(5)->toDateString(),
            'slot_time' => sprintf('%02d:%02d:00', $hour, $minute),
            'status' => 'Pending',
            'notes' => null,
            'prep_summary' => 'Hydrate well and eat an iron-rich meal prior to donation.',
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ];
    }

    /**
     * Target a fixed donation center for the appointment.
     */
    public function atCenter(?DonationCenter $center = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'donation_center_id' => $center?->getKey() ?? DonationCenter::factory(),
            'donation_camp_id' => null,
        ]);
    }

    /**
     * Target a mobile donation camp for the appointment.
     */
    public function atCamp(?DonationCamp $camp = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'donation_center_id' => null,
            'donation_camp_id' => $camp?->getKey() ?? DonationCamp::factory()->upcoming(),
        ]);
    }

    /**
     * Indicate that the appointment has been confirmed.
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'Confirmed',
        ]);
    }

    /**
     * Indicate that the appointment has completed collection.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'appointment_date' => now()->subDay()->toDateString(),
            'status' => 'Completed',
        ]);
    }

    /**
     * Indicate that the appointment was cancelled so active_slot_guard remains null.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'Cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => 'Donor requested schedule cancellation.',
        ]);
    }
}
