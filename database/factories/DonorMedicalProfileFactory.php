<?php

namespace Database\Factories;

use App\Models\Donor;
use App\Models\DonorMedicalProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonorMedicalProfile>
 */
class DonorMedicalProfileFactory extends Factory
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
            'medical_notes' => 'Routine vitals within normal donor screening range.',
            'last_hemoglobin_g_dl' => fake()->randomFloat(2, 12.60, 16.50),
            'weight_kg' => fake()->randomFloat(2, 54.00, 92.00),
            'deferral_notes' => null,
            'updated_by_user_id' => null,
        ];
    }

    /**
     * Indicate that the medical profile documents an active clinical deferral note.
     */
    public function deferred(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_hemoglobin_g_dl' => 11.40,
            'deferral_notes' => 'Temporarily deferred due to sub-threshold hemoglobin.',
        ]);
    }
}
