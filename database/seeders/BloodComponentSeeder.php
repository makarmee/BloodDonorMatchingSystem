<?php

namespace Database\Seeders;

use App\Models\BloodComponent;
use Illuminate\Database\Seeder;

class BloodComponentSeeder extends Seeder
{
    /**
     * Canonical BDMS blood components from DATA-DICTIONARY.md and SCHEMA.md.
     *
     * @var list<array{code: string, name: string, default_volume_ml: int, shelf_life_days: int, storage_requirements: string, is_active: bool}>
     */
    public const array COMPONENTS = [
        [
            'code' => 'WB',
            'name' => 'Whole Blood',
            'default_volume_ml' => 450,
            'shelf_life_days' => 35,
            'storage_requirements' => '2°C to 6°C monitored refrigerated storage',
            'is_active' => true,
        ],
        [
            'code' => 'PRBC',
            'name' => 'Packed RBC',
            'default_volume_ml' => 300,
            'shelf_life_days' => 42,
            'storage_requirements' => '2°C to 6°C refrigerated storage in additive solution',
            'is_active' => true,
        ],
        [
            'code' => 'PLT',
            'name' => 'Platelets',
            'default_volume_ml' => 250,
            'shelf_life_days' => 5,
            'storage_requirements' => '20°C to 24°C continuous gentle agitation',
            'is_active' => true,
        ],
        [
            'code' => 'PLS',
            'name' => 'Plasma',
            'default_volume_ml' => 600,
            'shelf_life_days' => 365,
            'storage_requirements' => '-18°C or colder frozen plasma storage',
            'is_active' => true,
        ],
        [
            'code' => 'DRBC',
            'name' => 'Double Red Cells',
            'default_volume_ml' => 360,
            'shelf_life_days' => 42,
            'storage_requirements' => '2°C to 6°C refrigerated storage in additive solution',
            'is_active' => true,
        ],
    ];

    /**
     * Seed all canonical BDMS blood components idempotently.
     */
    public function run(): void
    {
        foreach (self::COMPONENTS as $component) {
            BloodComponent::query()->updateOrCreate(
                ['code' => $component['code']],
                [
                    'name' => $component['name'],
                    'default_volume_ml' => $component['default_volume_ml'],
                    'shelf_life_days' => $component['shelf_life_days'],
                    'storage_requirements' => $component['storage_requirements'],
                    'is_active' => $component['is_active'],
                ]
            );
        }
    }
}
