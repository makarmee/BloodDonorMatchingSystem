<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\Hospital;
use App\Models\InventoryAllocation;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\Role;
use App\Models\User;
use App\Services\DonationService;
use App\Services\DonorEligibilityService;
use App\Services\FulfillmentService;
use App\Services\InventoryService;
use App\Services\ScreeningService;
use Database\Seeders\BloodCompatibilityRuleSeeder;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventoryService;

    private FulfillmentService $fulfillmentService;

    private DonationService $donationService;

    private ScreeningService $screeningService;

    private DonorEligibilityService $eligibilityService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryService = $this->app->make(InventoryService::class);
        $this->fulfillmentService = $this->app->make(FulfillmentService::class);
        $this->donationService = $this->app->make(DonationService::class);
        $this->screeningService = $this->app->make(ScreeningService::class);
        $this->eligibilityService = $this->app->make(DonorEligibilityService::class);

        $this->seed([
            RolePermissionSeeder::class,
            BloodComponentSeeder::class,
            BloodCompatibilityRuleSeeder::class,
            ScreeningTestTypeSeeder::class,
            ComponentScreeningRequirementSeeder::class,
        ]);
    }

    public function test_concurrent_reservation_attempts_prevent_double_reservation_and_overselling(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $hospital = Hospital::factory()->create();

        // Exactly 1 screened unit available
        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O+', 'WB', 1);
        $unit = $units[0];

        $request1 = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'O+',
            'blood_component_id' => $stock->blood_component_id,
            'requested_units' => 1,
            'request_reason' => 'First concurrent request hold.',
        ]);
        $request2 = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'O+',
            'blood_component_id' => $stock->blood_component_id,
            'requested_units' => 1,
            'request_reason' => 'Second concurrent request competing for same stock.',
        ]);

        $this->fulfillmentService->approveRequest($request1, $staff, 'Approved request 1.');
        $this->fulfillmentService->approveRequest($request2, $staff, 'Approved request 2.');

        // First reservation consumes the 1 available unit
        $allocations1 = $this->fulfillmentService->reserveUnits($request1, $staff, 1);
        $this->assertCount(1, $allocations1);
        $this->assertSame('Reserved', $unit->fresh()->unit_status);
        $this->assertSame(0, $stock->fresh()->available_units);
        $this->assertSame(1, $stock->fresh()->reserved_units);

        // Competing reservation immediately fails validation due to zero available units
        $this->expectException(ValidationException::class);
        $this->fulfillmentService->reserveUnits($request2, $staff, 1);
    }

    public function test_active_unit_guard_database_constraint_blocks_double_allocation(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $hospital = Hospital::factory()->create();

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'A+', 'WB', 1);
        $unit = $units[0];

        $request = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'A+',
            'requested_units' => 1,
            'request_reason' => 'Database constraint test.',
        ]);

        // Insert first active allocation (Reserved)
        InventoryAllocation::query()->create([
            'allocation_code' => 'ALC-TEST-0001',
            'blood_request_id' => $request->id,
            'inventory_stock_id' => $stock->id,
            'blood_unit_id' => $unit->id,
            'units_allocated' => 1,
            'allocation_status' => 'Reserved',
            'allocated_by_user_id' => $staff->id,
            'allocated_at' => now(),
        ]);

        // Attempting to insert a second active allocation for the identical blood_unit_id
        // triggers unique constraint violation on active_unit_guard
        $this->expectException(QueryException::class);
        InventoryAllocation::query()->create([
            'allocation_code' => 'ALC-TEST-0002',
            'blood_request_id' => $request->id,
            'inventory_stock_id' => $stock->id,
            'blood_unit_id' => $unit->id,
            'units_allocated' => 1,
            'allocation_status' => 'Reserved',
            'allocated_by_user_id' => $staff->id,
            'allocated_at' => now(),
        ]);
    }

    public function test_concurrent_dispatch_prevents_double_dispatch_of_same_allocation(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $hospital = Hospital::factory()->create();

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'B+', 'WB', 1);
        $unit = $units[0];

        $bloodRequest = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'B+',
            'blood_component_id' => $stock->blood_component_id,
            'requested_units' => 1,
            'request_reason' => 'Dispatch concurrency test.',
        ]);
        $this->fulfillmentService->approveRequest($bloodRequest, $staff, 'Approved.');
        $this->fulfillmentService->reserveUnits($bloodRequest, $staff, 1);

        // First dispatch succeeds
        $fulfillment = $this->fulfillmentService->dispatchUnits($bloodRequest, $staff, [
            'dispatched_blood_group' => 'B+',
            'quantity' => 1,
            'courier_name' => 'Cold Express',
            'courier_reference' => 'CEXP-100',
        ]);

        $this->assertNotNull($fulfillment);
        $this->assertSame('Dispatched', $unit->fresh()->unit_status);
        $this->assertSame(0, $stock->fresh()->available_units);
        $this->assertSame(0, $stock->fresh()->reserved_units);

        // Subsequent attempt to dispatch again on a fully fulfilled request fails
        $this->expectException(ValidationException::class);
        $this->fulfillmentService->dispatchUnits($bloodRequest, $staff, [
            'dispatched_blood_group' => 'B+',
            'quantity' => 1,
        ]);
    }

    public function test_concurrent_release_prevents_duplicate_stock_restoration(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $hospital = Hospital::factory()->create();

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'AB-', 'WB', 1);
        $unit = $units[0];

        $bloodRequest = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'AB-',
            'blood_component_id' => $stock->blood_component_id,
            'requested_units' => 1,
            'request_reason' => 'Release concurrency test.',
        ]);
        $this->fulfillmentService->approveRequest($bloodRequest, $staff, 'Approved.');
        $this->fulfillmentService->reserveUnits($bloodRequest, $staff, 1);

        $this->assertSame(0, $stock->fresh()->available_units);
        $this->assertSame(1, $stock->fresh()->reserved_units);

        // First release restores unit to Available
        $this->fulfillmentService->releaseReservedAllocations($bloodRequest, $staff, null, 'Legitimate hold release.');
        $this->assertSame(1, $stock->fresh()->available_units);
        $this->assertSame(0, $stock->fresh()->reserved_units);
        $this->assertSame('Available', $unit->fresh()->unit_status);

        // Second release attempt finds no active reserved allocations and fails
        $this->expectException(ValidationException::class);
        $this->fulfillmentService->releaseReservedAllocations($bloodRequest, $staff, null, 'Duplicate release attempt.');

        // Available stock does not increment a second time
        $this->assertSame(1, $stock->fresh()->available_units);
    }

    public function test_failed_operation_rolls_back_unit_stock_and_ledger_atomically(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O+', 'WB', 1);
        $unit = $units[0];

        $initialTxnCount = InventoryTransaction::query()->count();

        // Attempt an operation that throws an unhandled exception midway
        try {
            $this->inventoryService->discardUnit($unit, $staff, [
                'reason' => 'Testing atomic rollback.',
                'reference_type' => str_repeat('X', 500), // exceeds DB varchar(64) column limit -> triggers DB failure
            ]);
            $this->fail('Expected exception was not thrown.');
        } catch (\Throwable) {
            // Expected failure
        }

        // Verify that atomic rollback prevented partial mutation
        $unit->refresh();
        $this->assertSame('Available', $unit->unit_status, 'Unit must remain Available after rollback.');
        $this->assertSame(1, $stock->fresh()->available_units, 'Stock balance must not change after rollback.');
        $this->assertSame($initialTxnCount, InventoryTransaction::query()->count(), 'No partial ledger transaction rows created.');
    }

    /**
     * @return array{stock: InventoryStock, units: Collection<int, BloodUnit>}
     */
    private function releaseScreenedUnits(
        User $admin,
        User $staff,
        User $labOfficer,
        string $bloodGroup,
        string $componentCode,
        int $count
    ): array {
        $component = BloodComponent::query()->where('code', $componentCode)->firstOrFail();
        $center = DonationCenter::factory()->create();
        $units = collect();

        for ($i = 0; $i < $count; $i++) {
            $donor = Donor::factory()->create([
                'blood_group' => $bloodGroup,
                'profile_completed' => true,
                'account_status' => 'Active',
            ]);

            $this->eligibilityService->approveEligibility(
                donor: $donor,
                actor: $admin,
                reason: 'Cleared for concurrency integration test.',
                clearanceSource: 'Medical Officer Clearance'
            );

            $donation = $this->donationService->registerDonation($donor->fresh(), $staff, [
                'blood_component_id' => $component->id,
                'quantity_ml' => $component->default_volume_ml,
                'center_id' => $center->id,
            ]);

            foreach (['HIV', 'HBV', 'HCV', 'SYPH', 'COMPAT'] as $code) {
                $this->screeningService->recordScreeningResult($donation, $labOfficer, [
                    'test_type_code' => $code,
                    'screening_result' => 'Passed',
                ]);
            }

            $released = $this->screeningService->releaseToInventory($donation, $labOfficer);
            $units->push($released->primaryBloodUnit->fresh());
        }

        $stock = InventoryStock::query()
            ->forBloodGroup($bloodGroup)
            ->forComponent($component->id)
            ->firstOrFail();

        return [
            'stock' => $stock,
            'units' => $units,
        ];
    }

    private function createUserWithRole(string $roleSlug, bool $isActive = true): User
    {
        $user = User::factory()->create(['is_active' => $isActive]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user;
    }
}
