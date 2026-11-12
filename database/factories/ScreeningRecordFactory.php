<?php

namespace Database\Factories;

use App\Models\Donation;
use App\Models\ScreeningRecord;
use App\Models\ScreeningTestType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScreeningRecord>
 */
class ScreeningRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'screening_code' => 'SCR-'.fake()->unique()->numerify('######'),
            'donation_id' => Donation::factory()->completed(),
            'test_type_id' => ScreeningTestType::factory(),
            'test_date' => now()->toDateString(),
            'screening_result' => 'Pending',
            'is_confirmatory' => false,
            'performed_by_user_id' => null,
            'lab_staff_name' => fake()->name(),
            'assay_method' => 'ELISA / Serology',
            'specimen_tube_code' => 'TUBE-'.fake()->unique()->numerify('######'),
            'test_notes' => null,
            'collected_at' => now()->subHours(2),
            'logged_at' => now()->subHour(),
            'result_recorded_at' => null,
        ];
    }

    /**
     * Indicate a non-reactive/negative passing laboratory test result.
     */
    public function passed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'screening_result' => 'Passed',
            'performed_by_user_id' => User::factory(),
            'result_recorded_at' => now(),
            'test_notes' => 'Negative / non-reactive within validated control bounds.',
        ]);
    }

    /**
     * Indicate a reactive/failed laboratory test result.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'screening_result' => 'Failed',
            'performed_by_user_id' => User::factory(),
            'result_recorded_at' => now(),
            'test_notes' => 'Reactive screen; unit permanently barred from release.',
        ]);
    }

    /**
     * Mark the record as a confirmatory repeat assay.
     */
    public function confirmatory(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_confirmatory' => true,
        ]);
    }
}
