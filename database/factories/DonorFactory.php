<?php

namespace Database\Factories;

use App\Models\Donor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Donor>
 */
class DonorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'donor_code' => 'DNR-'.fake()->unique()->numerify('######'),
            'user_id' => null,
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('+1-555-###-####'),
            'gender' => fake()->randomElement(Donor::GENDERS),
            'date_of_birth' => fake()->dateTimeBetween('-55 years', '-19 years')->format('Y-m-d'),
            'blood_group' => fake()->randomElement(Donor::BLOOD_GROUPS),
            'address' => fake()->streetAddress().', '.fake()->city(),
            'emergency_contact' => fake()->name().' ('.fake()->numerify('+1-555-###-####').')',
            'availability_status' => 'Available',
            'eligibility_status' => 'Pending Review',
            'account_status' => 'Active',
            'last_donation_date' => null,
            'next_eligible_date' => null,
            'total_completed_donations' => 0,
            'profile_completed' => true,
            'registered_at' => now()->toDateString(),
        ];
    }

    /**
     * Indicate that the donor has verified active eligibility and a completed profile.
     */
    public function eligible(): static
    {
        return $this->state(fn (array $attributes): array => [
            'availability_status' => 'Available',
            'eligibility_status' => 'Eligible',
            'account_status' => 'Active',
            'next_eligible_date' => now()->subDay()->toDateString(),
            'profile_completed' => true,
        ]);
    }

    /**
     * Indicate that the donor is temporarily or medically deferred from donating.
     */
    public function notEligible(?string $nextEligibleDate = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'availability_status' => 'Temporarily Unavailable',
            'eligibility_status' => 'Not Eligible',
            'next_eligible_date' => $nextEligibleDate ?? now()->addDays(42)->toDateString(),
        ]);
    }

    /**
     * Indicate that the donor eligibility evaluation is pending review.
     */
    public function pendingReview(): static
    {
        return $this->state(fn (array $attributes): array => [
            'eligibility_status' => 'Pending Review',
            'next_eligible_date' => null,
        ]);
    }

    /**
     * Indicate that required donor profile fields are incomplete and block booking.
     */
    public function incompleteProfile(): static
    {
        return $this->state(fn (array $attributes): array => [
            'phone' => null,
            'gender' => null,
            'date_of_birth' => null,
            'blood_group' => null,
            'address' => null,
            'emergency_contact' => null,
            'profile_completed' => false,
        ]);
    }

    /**
     * Indicate that the donor account is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'availability_status' => 'Inactive',
            'account_status' => 'Inactive',
        ]);
    }

    /**
     * Associate the donor record with a portal login user account.
     */
    public function withUser(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => User::factory(),
        ]);
    }
}
