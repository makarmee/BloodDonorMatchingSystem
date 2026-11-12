<?php

namespace Database\Seeders;

use App\Models\BloodCompatibilityRule;
use App\Models\BloodComponent;
use Illuminate\Database\Seeder;

class BloodCompatibilityRuleSeeder extends Seeder
{
    /**
     * Seed the 40 identical-group Exact Match compatibility baseline rules across all 5 components.
     *
     * Cross-group Compatible Substitution and Emergency Only rules remain unseeded
     * pending formal Medical Director / Transfusion Committee approval per DESIGN-DECISIONS.md.
     */
    public function run(): void
    {
        $componentCodes = array_column(BloodComponentSeeder::COMPONENTS, 'code');
        $components = BloodComponent::query()->whereIn('code', $componentCodes)->get();

        if ($components->count() < count($componentCodes)) {
            $this->call(BloodComponentSeeder::class);
            $components = BloodComponent::query()->whereIn('code', $componentCodes)->get();
        }

        foreach ($components as $component) {
            foreach (BloodCompatibilityRule::BLOOD_GROUPS as $bloodGroup) {
                BloodCompatibilityRule::query()->updateOrCreate(
                    [
                        'recipient_blood_group' => $bloodGroup,
                        'donor_blood_group' => $bloodGroup,
                        'blood_component_id' => $component->id,
                    ],
                    [
                        'compatibility_level' => 'Exact Match',
                        'requires_medical_override' => false,
                        'is_active' => true,
                        'notes' => 'Approved identical ABO/Rh baseline match rule; cross-group substitution and emergency rules await Medical Director approval.',
                    ]
                );
            }
        }
    }
}
