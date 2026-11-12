<?php

namespace Database\Factories;

use App\Models\BloodCompatibilityRule;
use App\Models\BloodComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BloodCompatibilityRule>
 */
class BloodCompatibilityRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bloodGroup = fake()->randomElement(BloodCompatibilityRule::BLOOD_GROUPS);

        return [
            'recipient_blood_group' => $bloodGroup,
            'donor_blood_group' => $bloodGroup,
            'blood_component_id' => BloodComponent::factory(),
            'compatibility_level' => 'Exact Match',
            'requires_medical_override' => false,
            'is_active' => true,
            'notes' => 'Exact group match rule.',
        ];
    }

    /**
     * Indicate an authorized non-identical compatible substitution rule.
     */
    public function compatibleSubstitution(): static
    {
        return $this->state(fn (array $attributes): array => [
            'compatibility_level' => 'Compatible Substitution',
            'requires_medical_override' => false,
        ]);
    }

    /**
     * Indicate an emergency-only rule requiring documented medical officer override.
     */
    public function emergencyOnly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'compatibility_level' => 'Emergency Only',
            'requires_medical_override' => true,
        ]);
    }

    /**
     * Indicate that the compatibility rule is disabled.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
