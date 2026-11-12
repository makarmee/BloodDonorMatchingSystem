<?php

namespace Tests\Feature;

use App\Models\BloodCompatibilityRule;
use App\Models\BloodComponent;
use App\Models\ComponentScreeningRequirement;
use App\Models\Donor;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ScreeningTestType;
use App\Models\User;
use Database\Seeders\BloodCompatibilityRuleSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EssentialSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_populates_essential_rbac_and_catalog_reference_data_without_demo_users(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Donor::query()->count());

        $this->assertSame(5, Role::query()->count());
        $this->assertSame(8, Permission::query()->count());

        $rolePermissions = Role::query()
            ->with('permissions:id,slug')
            ->get()
            ->mapWithKeys(static fn (Role $role): array => [
                $role->slug => $role->permissions->pluck('slug')->sort()->values()->all(),
            ]);

        $this->assertEqualsCanonicalizing([
            'donors.view_medical',
            'hospitals.verify',
            'inventory.adjust',
            'requests.fulfill',
            'requests.override_compatibility',
            'screening.manage',
            'screening.record',
            'screening.release_inventory',
        ], $rolePermissions['admin']);

        $this->assertEqualsCanonicalizing([
            'donors.view_medical',
            'inventory.adjust',
            'requests.fulfill',
        ], $rolePermissions['staff']);

        $this->assertEqualsCanonicalizing([
            'donors.view_medical',
            'screening.manage',
            'screening.record',
            'screening.release_inventory',
        ], $rolePermissions['lab_officer']);

        $this->assertSame([], $rolePermissions['hospital']);
        $this->assertSame([], $rolePermissions['donor']);

        $this->assertSame(5, BloodComponent::query()->count());
        $components = BloodComponent::query()->get()->keyBy('code');

        $this->assertSame(['WB', 'PRBC', 'PLT', 'PLS', 'DRBC'], ['WB', 'PRBC', 'PLT', 'PLS', 'DRBC']);
        $this->assertSame(450, $components['WB']->default_volume_ml);
        $this->assertSame(35, $components['WB']->shelf_life_days);
        $this->assertSame(300, $components['PRBC']->default_volume_ml);
        $this->assertSame(42, $components['PRBC']->shelf_life_days);
        $this->assertSame(250, $components['PLT']->default_volume_ml);
        $this->assertSame(5, $components['PLT']->shelf_life_days);
        $this->assertSame(600, $components['PLS']->default_volume_ml);
        $this->assertSame(365, $components['PLS']->shelf_life_days);
        $this->assertSame(360, $components['DRBC']->default_volume_ml);
        $this->assertSame(42, $components['DRBC']->shelf_life_days);

        $this->assertTrue(BloodComponent::query()->where('is_active', false)->doesntExist());

        $this->assertSame(5, ScreeningTestType::query()->count());
        $this->assertEqualsCanonicalizing(
            ['HIV', 'HBV', 'HCV', 'SYPH', 'COMPAT'],
            ScreeningTestType::query()->active()->pluck('code')->all()
        );

        $this->assertSame(25, ComponentScreeningRequirement::query()->count());
        $this->assertSame(25, ComponentScreeningRequirement::query()->mandatory()->count());

        foreach ($components as $component) {
            $this->assertSame(
                5,
                $component->screeningRequirements()->mandatory()->count()
            );
        }

        $this->assertSame(40, BloodCompatibilityRule::query()->count());
        $this->assertSame(40, BloodCompatibilityRule::query()->active()->count());
        $this->assertSame(
            0,
            BloodCompatibilityRule::query()
                ->whereIn('compatibility_level', ['Compatible Substitution', 'Emergency Only'])
                ->count()
        );
        $this->assertTrue(
            BloodCompatibilityRule::query()->where('requires_medical_override', true)->doesntExist()
        );

        foreach (BloodCompatibilityRule::BLOOD_GROUPS as $bloodGroup) {
            $this->assertSame(
                5,
                BloodCompatibilityRule::query()
                    ->where('recipient_blood_group', $bloodGroup)
                    ->where('donor_blood_group', $bloodGroup)
                    ->where('compatibility_level', 'Exact Match')
                    ->count()
            );
        }
    }

    public function test_essential_seeders_are_idempotent_across_repeated_executions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $initialPivotCreatedAt = DB::table('permission_role')
            ->orderBy('role_id')
            ->orderBy('permission_id')
            ->pluck('created_at')
            ->all();

        $this->travel(5)->minutes();
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(5, Role::query()->count());
        $this->assertSame(8, Permission::query()->count());
        $this->assertSame(15, DB::table('permission_role')->count());
        $this->assertSame(5, BloodComponent::query()->count());
        $this->assertSame(5, ScreeningTestType::query()->count());
        $this->assertSame(25, ComponentScreeningRequirement::query()->count());
        $this->assertSame(40, BloodCompatibilityRule::query()->count());
        $this->assertSame(
            $initialPivotCreatedAt,
            DB::table('permission_role')
                ->orderBy('role_id')
                ->orderBy('permission_id')
                ->pluck('created_at')
                ->all()
        );
    }

    public function test_component_screening_requirement_seeder_runs_cleanly_standalone(): void
    {
        $this->seed(ComponentScreeningRequirementSeeder::class);

        $this->assertSame(5, BloodComponent::query()->count());
        $this->assertSame(5, ScreeningTestType::query()->count());
        $this->assertSame(25, ComponentScreeningRequirement::query()->count());
    }

    public function test_blood_compatibility_rule_seeder_runs_cleanly_standalone(): void
    {
        $this->seed(BloodCompatibilityRuleSeeder::class);

        $this->assertSame(5, BloodComponent::query()->count());
        $this->assertSame(40, BloodCompatibilityRule::query()->count());
    }
}
