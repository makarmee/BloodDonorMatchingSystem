<?php

namespace Database\Factories;

use App\Models\BloodComponent;
use App\Models\ComponentScreeningRequirement;
use App\Models\ScreeningTestType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ComponentScreeningRequirement>
 */
class ComponentScreeningRequirementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'blood_component_id' => BloodComponent::factory(),
            'test_type_id' => ScreeningTestType::factory(),
            'is_required' => true,
            'is_active' => true,
            'notes' => 'Mandatory baseline screening panel requirement.',
        ];
    }

    /**
     * Mark the requirement rule as optional rather than blocking release.
     */
    public function optional(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_required' => false,
        ]);
    }

    /**
     * Mark the requirement rule as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
