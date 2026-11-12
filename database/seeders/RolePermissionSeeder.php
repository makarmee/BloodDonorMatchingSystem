<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Canonical BDMS role definitions mapped to documented permission slugs.
     *
     * @var array<string, array{name: string, description: string, permissions: list<string>}>
     */
    private const array ROLES = [
        'admin' => [
            'name' => 'Administrator',
            'description' => 'Full BDMS administrative governance, role management, hospital verification, and compatibility overrides.',
            'permissions' => [
                'inventory.adjust',
                'donors.view_medical',
                'screening.record',
                'screening.manage',
                'screening.release_inventory',
                'requests.fulfill',
                'requests.override_compatibility',
                'hospitals.verify',
            ],
        ],
        'staff' => [
            'name' => 'Blood Bank Staff',
            'description' => 'Manages donor intake, inventory adjustments, collection operations, camps, appointments, and blood request fulfillment.',
            'permissions' => [
                'inventory.adjust',
                'donors.view_medical',
                'requests.fulfill',
            ],
        ],
        'lab_officer' => [
            'name' => 'Lab Officer',
            'description' => 'Performs laboratory screening assays, manages screening test workflows, and releases screened units to inventory.',
            'permissions' => [
                'donors.view_medical',
                'screening.record',
                'screening.manage',
                'screening.release_inventory',
            ],
        ],
        'hospital' => [
            'name' => 'Hospital Partner',
            'description' => 'Partner hospital portal user authorized to submit and track requests scoped to verified partner hospitals.',
            'permissions' => [],
        ],
        'donor' => [
            'name' => 'Donor',
            'description' => 'Registered donor portal user managing personal profile completion, eligibility status, appointments, and camp registrations.',
            'permissions' => [],
        ],
    ];

    /**
     * Documented BDMS authorization permissions grouped by functional module.
     *
     * @var array<string, array{name: string, module: string, description: string}>
     */
    private const array PERMISSIONS = [
        'inventory.adjust' => [
            'name' => 'Adjust Inventory Stock',
            'module' => 'inventory',
            'description' => 'Record manual inventory adjustments, quarantine disposals, and auditable stock ledger updates.',
        ],
        'donors.view_medical' => [
            'name' => 'View Donor Medical Profile',
            'module' => 'donors',
            'description' => 'View protected donor clinical measurements, hemoglobin levels, weight, and deferral notes.',
        ],
        'screening.record' => [
            'name' => 'Record Screening Results',
            'module' => 'screening',
            'description' => 'Record infectious and compatibility laboratory assay results and confirmatory tests.',
        ],
        'screening.manage' => [
            'name' => 'Manage Screening Workflows',
            'module' => 'screening',
            'description' => 'Manage screening test workflows and component screening requirements.',
        ],
        'screening.release_inventory' => [
            'name' => 'Release Screened Units to Inventory',
            'module' => 'screening',
            'description' => 'Approve completed mandatory negative screening panels and release units into available inventory.',
        ],
        'requests.fulfill' => [
            'name' => 'Fulfill Blood Requests',
            'module' => 'requests',
            'description' => 'Allocate compatible blood units, dispatch shipments, and update hospital fulfillment statuses.',
        ],
        'requests.override_compatibility' => [
            'name' => 'Approve Compatibility Override',
            'module' => 'requests',
            'description' => 'Authorize emergency non-identical blood compatibility fulfillment overrides with documented audit rationale.',
        ],
        'hospitals.verify' => [
            'name' => 'Verify Partner Hospitals',
            'module' => 'hospitals',
            'description' => 'Verify, approve, or suspend partner hospital facility accounts.',
        ],
    ];

    /**
     * Seed the baseline RBAC roles, documented permissions, and default mappings.
     */
    public function run(): void
    {
        $permissionsBySlug = [];

        foreach (self::PERMISSIONS as $slug => $attributes) {
            $permissionsBySlug[$slug] = Permission::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $attributes['name'],
                    'module' => $attributes['module'],
                    'description' => $attributes['description'],
                ]
            );
        }

        $now = now();

        foreach (self::ROLES as $slug => $attributes) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $attributes['name'],
                    'description' => $attributes['description'],
                ]
            );

            $targetPermissionIds = array_map(
                static fn (string $permissionSlug): int => $permissionsBySlug[$permissionSlug]->id,
                $attributes['permissions']
            );

            if ($targetPermissionIds === []) {
                continue;
            }

            $existingPermissionIds = $role->permissions()
                ->pluck('permissions.id')
                ->all();

            $missingPermissionIds = array_diff($targetPermissionIds, $existingPermissionIds);

            if ($missingPermissionIds === []) {
                continue;
            }

            $pivotPayloads = [];

            foreach ($missingPermissionIds as $permissionId) {
                $pivotPayloads[$permissionId] = ['created_at' => $now];
            }

            $role->permissions()->attach($pivotPayloads);
        }
    }
}
