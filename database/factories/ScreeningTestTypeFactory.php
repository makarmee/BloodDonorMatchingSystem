<?php

namespace Database\Factories;

use App\Models\ScreeningTestType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScreeningTestType>
 */
class ScreeningTestTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $suffix = fake()->unique()->numerify('#####');

        return [
            'code' => 'TST-'.$suffix,
            'name' => 'Screening Assay '.$suffix,
            'description' => 'Mandatory laboratory donor blood screening assay.',
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the screening test type is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
