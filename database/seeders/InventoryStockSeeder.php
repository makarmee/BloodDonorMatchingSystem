<?php

namespace Database\Seeders;

use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\InventoryStock;
use Illuminate\Database\Seeder;

class InventoryStockSeeder extends Seeder
{
    /**
     * Seed baseline inventory stock buckets for all active blood components and ABO/Rh blood groups.
     *
     * Preserves existing live stock counts and adjustment timestamps if buckets already exist.
     */
    public function run(): void
    {
        $components = BloodComponent::query()->where('is_active', true)->get();

        if ($components->isEmpty()) {
            $this->call(BloodComponentSeeder::class);
            $components = BloodComponent::query()->where('is_active', true)->get();
        }

        foreach ($components as $component) {
            foreach (BloodUnit::BLOOD_GROUPS as $group) {
                InventoryStock::query()->firstOrCreate(
                    [
                        'blood_group' => $group,
                        'blood_component_id' => $component->id,
                    ],
                    [
                        'available_units' => 0,
                        'reserved_units' => 0,
                        'minimum_level' => 20,
                        'target_capacity' => 100,
                    ]
                );
            }
        }
    }
}
