<?php

namespace Database\Factories;

use App\Models\Donor;
use App\Models\DonorEligibilityRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonorEligibilityRecord>
 */
class DonorEligibilityRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'donor_id' => Donor::factory(),
            'eligibility_status' => 'Pending Review',
            'reason' => 'Initial clinical eligibility assessment pending verification.',
            'clearance_source' => 'Clinical Intake Desk',
            'reviewed_at' => now()->toDateString(),
            'next_eligible_date' => null,
            'reviewed_by_user_id' => null,
            'is_current' => true,
        ];
    }

    /**
     * Indicate a cleared eligible determination.
     */
    public function eligible(): static
    {
        return $this->state(fn (array $attributes): array => [
            'eligibility_status' => 'Eligible',
            'reason' => 'Donor cleared clinical questionnaire and hemoglobin criteria.',
            'clearance_source' => 'Staff Medical Review',
            'reviewed_at' => now()->toDateString(),
            'next_eligible_date' => now()->toDateString(),
            'reviewed_by_user_id' => User::factory(),
            'is_current' => true,
        ]);
    }

    /**
     * Indicate a deferred not-eligible determination.
     */
    public function notEligible(?string $nextEligibleDate = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'eligibility_status' => 'Not Eligible',
            'reason' => 'Deferred for mandatory post-donation recovery interval.',
            'clearance_source' => 'Medical Officer Deferral',
            'reviewed_at' => now()->toDateString(),
            'next_eligible_date' => $nextEligibleDate ?? now()->addDays(56)->toDateString(),
            'reviewed_by_user_id' => User::factory(),
            'is_current' => true,
        ]);
    }

    /**
     * Indicate a historical superseded eligibility record so current_record_guard remains null.
     */
    public function historical(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_current' => false,
        ]);
    }
}
