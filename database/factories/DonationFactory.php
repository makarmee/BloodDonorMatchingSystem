<?php

namespace Database\Factories;

use App\Models\BloodComponent;
use App\Models\Donation;
use App\Models\DonationCamp;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Donation>
 */
class DonationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'donation_code' => 'DON-'.fake()->unique()->numerify('######'),
            'donor_id' => Donor::factory()->eligible(),
            'appointment_id' => null,
            'camp_id' => null,
            'center_id' => fn (array $attributes): mixed => ($attributes['camp_id'] ?? null) === null
                ? DonationCenter::factory()
                : null,
            'collection_location_name' => fn (array $attributes): string => DonationCenter::query()
                ->whereKey($attributes['center_id'] ?? null)
                ->value('name')
                ?? DonationCamp::query()
                    ->whereKey($attributes['camp_id'] ?? null)
                    ->value('venue_name')
                ?? 'Central Collection Center',
            'recorded_by_user_id' => null,
            'staff_member_name' => fake()->name(),
            'blood_group' => fn (array $attributes): string => Donor::query()
                ->whereKey($attributes['donor_id'] ?? null)
                ->value('blood_group')
                ?? fake()->randomElement(Donation::BLOOD_GROUPS),
            'blood_component_id' => BloodComponent::factory(),
            'donation_date' => now()->toDateString(),
            'collected_at' => now(),
            'quantity_ml' => fake()->numberBetween(350, 500),
            'collection_status' => 'Scheduled',
            'screening_status' => 'Pending',
            'collection_notes' => null,
            'screening_notes' => null,
            'released_to_inventory_at' => null,
            'approved_by_user_id' => null,
        ];
    }

    /**
     * Indicate that the physical blood collection has completed and awaits lab screening.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'collection_status' => 'Completed',
            'collected_at' => now(),
        ]);
    }

    /**
     * Indicate that the donation was cancelled prior to collection.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'collection_status' => 'Cancelled',
            'collected_at' => null,
        ]);
    }

    /**
     * Indicate that the donation passed all mandatory screening panels and was released.
     */
    public function screeningPassed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'collection_status' => 'Completed',
            'screening_status' => 'Passed',
            'collected_at' => now()->subHours(4),
            'released_to_inventory_at' => now(),
            'approved_by_user_id' => User::factory(),
        ]);
    }

    /**
     * Indicate that the donation failed laboratory screening and stays quarantined/discarded.
     */
    public function screeningFailed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'collection_status' => 'Completed',
            'screening_status' => 'Failed',
            'collected_at' => now()->subHours(4),
            'screening_notes' => 'Reactive assay identified during mandatory infectious screening.',
            'released_to_inventory_at' => null,
        ]);
    }

    /**
     * Associate the donation with a mobile donation camp instead of a fixed center.
     */
    public function atCamp(?DonationCamp $camp = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'camp_id' => $camp?->getKey() ?? DonationCamp::factory()->ongoing(),
            'center_id' => null,
            'collection_location_name' => $camp?->venue_name ?? 'Mobile Donation Camp',
        ]);
    }
}
