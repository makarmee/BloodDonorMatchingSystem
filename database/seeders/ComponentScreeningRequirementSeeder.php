<?php

namespace Database\Seeders;

use App\Models\BloodComponent;
use App\Models\ComponentScreeningRequirement;
use App\Models\ScreeningTestType;
use Illuminate\Database\Seeder;

class ComponentScreeningRequirementSeeder extends Seeder
{
    /**
     * Seed mandatory five-panel screening requirements for every approved blood component (5 x 5 = 25).
     */
    public function run(): void
    {
        $componentCodes = array_column(BloodComponentSeeder::COMPONENTS, 'code');
        $testTypeCodes = array_column(ScreeningTestTypeSeeder::TEST_TYPES, 'code');

        $components = BloodComponent::query()->whereIn('code', $componentCodes)->get();

        if ($components->count() < count($componentCodes)) {
            $this->call(BloodComponentSeeder::class);
            $components = BloodComponent::query()->whereIn('code', $componentCodes)->get();
        }

        $testTypes = ScreeningTestType::query()->whereIn('code', $testTypeCodes)->get();

        if ($testTypes->count() < count($testTypeCodes)) {
            $this->call(ScreeningTestTypeSeeder::class);
            $testTypes = ScreeningTestType::query()->whereIn('code', $testTypeCodes)->get();
        }

        foreach ($components as $component) {
            foreach ($testTypes as $testType) {
                ComponentScreeningRequirement::query()->updateOrCreate(
                    [
                        'blood_component_id' => $component->id,
                        'test_type_id' => $testType->id,
                    ],
                    [
                        'is_required' => true,
                        'is_active' => true,
                        'notes' => 'Mandatory baseline five-panel screening requirement prior to inventory release.',
                    ]
                );
            }
        }
    }
}
