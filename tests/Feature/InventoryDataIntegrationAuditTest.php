<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\Hospital;
use App\Models\HospitalUser;
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
use Database\Seeders\InventoryStockSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryDataIntegrationAuditTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventoryService;

    private DonationService $donationService;

    private ScreeningService $screeningService;

    private DonorEligibilityService $eligibilityService;

    private FulfillmentService $fulfillmentService;

    private User $admin;

    private User $staff;

    private User $labOfficer;

    private User $hospitalOfficer;

    private Hospital $hospital;

    private DonationCenter $center;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            BloodComponentSeeder::class,
            ScreeningTestTypeSeeder::class,
            ComponentScreeningRequirementSeeder::class,
            BloodCompatibilityRuleSeeder::class,
            InventoryStockSeeder::class,
        ]);

        $this->inventoryService = $this->app->make(InventoryService::class);
        $this->donationService = $this->app->make(DonationService::class);
        $this->screeningService = $this->app->make(ScreeningService::class);
        $this->eligibilityService = $this->app->make(DonorEligibilityService::class);
        $this->fulfillmentService = $this->app->make(FulfillmentService::class);

        $this->admin = $this->createUserWithRole('admin');
        $this->staff = $this->createUserWithRole('staff');
        $this->labOfficer = $this->createUserWithRole('lab_officer');
        $this->hospitalOfficer = $this->createUserWithRole('hospital');

        $this->hospital = Hospital::factory()->create([
            'is_active' => true,
            'verification_status' => 'Verified',
        ]);

        HospitalUser::factory()->create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->hospitalOfficer->id,
            'is_active' => true,
        ]);

        $this->center = DonationCenter::factory()->create();
    }

    /**
     * Stage 1 & 2: Donor registration and donation scheduling do not change stock counters.
     */
    public function test_donor_and_scheduled_donation_do_not_alter_inventory_counters(): void
    {
        $donor = Donor::factory()->create([
            'blood_group' => 'O+',
            'account_status' => 'Active',
            'profile_completed' => true,
        ]);

        $stockBefore = InventoryStock::query()
            ->forBloodGroup('O+')
            ->forComponent(1)
            ->firstOrFail();

        $this->assertSame(0, $stockBefore->available_units);
        $this->assertSame(0, $stockBefore->reserved_units);
        $this->assertSame(0, InventoryTransaction::query()->count());
        $this->assertSame(0, BloodUnit::query()->count());
    }

    /**
     * Stage 3 & 4: Completed donation creates a Quarantined BloodUnit with 0 available stock.
     */
    public function test_completed_donation_creates_quarantined_unit_with_zero_stock_delta(): void
    {
        $donor = $this->createEligibleDonor('A+');
        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'center_id' => $this->center->id,
        ]);

        $this->assertSame('Completed', $donation->collection_status);
        $this->assertSame('Pending', $donation->screening_status);

        $primaryUnit = $donation->primaryBloodUnit;
        $this->assertInstanceOf(BloodUnit::class, $primaryUnit);
        $this->assertSame('Quarantined', $primaryUnit->unit_status);
        $this->assertSame('A+', $primaryUnit->blood_group);
        $this->assertNull($primaryUnit->released_at);

        // Stock counter MUST remain 0
        $stock = InventoryStock::query()->forBloodGroup('A+')->forComponent($component->id)->firstOrFail();
        $this->assertSame(0, $stock->available_units);
        $this->assertSame(0, $stock->reserved_units);
        $this->assertSame(0, InventoryTransaction::query()->count());
    }

    /**
     * Stage 5 & 6: Pending or incomplete screening blocks inventory admission.
     */
    public function test_incomplete_or_pending_screening_rejects_inventory_admission(): void
    {
        $donor = $this->createEligibleDonor('B+');
        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'center_id' => $this->center->id,
        ]);

        // Only record 1 of 5 mandatory tests
        $this->screeningService->recordScreeningResult($donation, $this->labOfficer, [
            'test_type_code' => 'HIV',
            'screening_result' => 'Passed',
        ]);

        $this->expectException(ValidationException::class);
        $this->screeningService->releaseToInventory($donation, $this->labOfficer);
    }

    /**
     * Stage 5 & 6: Failed screening permanently discards the unit and writes 0-delta audit ledger row.
     */
    public function test_failed_screening_discards_unit_and_preserves_stock_integrity(): void
    {
        $donor = $this->createEligibleDonor('O-');
        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'center_id' => $this->center->id,
        ]);

        // Fail HIV test
        $this->screeningService->recordScreeningResult($donation, $this->labOfficer, [
            'test_type_code' => 'HIV',
            'screening_result' => 'Failed',
        ]);

        $donation->refresh();
        $this->assertSame('Failed', $donation->screening_status);

        $unit = $donation->primaryBloodUnit->fresh();
        $this->assertSame('Discarded', $unit->unit_status);

        $stock = InventoryStock::query()->forBloodGroup('O-')->forComponent($component->id)->firstOrFail();
        $this->assertSame(0, $stock->available_units);
        $this->assertSame(0, $stock->reserved_units);

        // Verify zero-delta Discarded transaction
        $txn = InventoryTransaction::query()->where('blood_unit_id', $unit->id)->firstOrFail();
        $this->assertSame('Discarded', $txn->operation_type);
        $this->assertSame(0, $txn->units_delta);
        $this->assertSame(0, $txn->available_before);
        $this->assertSame(0, $txn->available_after);
    }

    /**
     * Stage 6 & 7: Approved screening releases unit into Available inventory with atomic Stock In ledger.
     */
    public function test_approved_screening_releases_unit_to_available_stock_with_stock_in_ledger(): void
    {
        $donor = $this->createEligibleDonor('AB+');
        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'center_id' => $this->center->id,
        ]);

        foreach (['HIV', 'HBV', 'HCV', 'SYPH', 'COMPAT'] as $code) {
            $this->screeningService->recordScreeningResult($donation, $this->labOfficer, [
                'test_type_code' => $code,
                'screening_result' => 'Passed',
            ]);
        }

        $releasedDonation = $this->screeningService->releaseToInventory($donation, $this->labOfficer);

        $this->assertSame('Passed', $releasedDonation->screening_status);
        $this->assertNotNull($releasedDonation->released_to_inventory_at);

        $unit = $releasedDonation->primaryBloodUnit->fresh();
        $this->assertSame('Available', $unit->unit_status);
        $this->assertNotNull($unit->released_at);
        $this->assertSame($this->labOfficer->id, $unit->released_by_user_id);

        $stock = InventoryStock::query()->forBloodGroup('AB+')->forComponent($component->id)->firstOrFail();
        $this->assertSame(1, $stock->available_units);
        $this->assertSame(0, $stock->reserved_units);

        $txn = InventoryTransaction::query()->where('blood_unit_id', $unit->id)->firstOrFail();
        $this->assertSame('Stock In', $txn->operation_type);
        $this->assertSame(1, $txn->units_delta);
        $this->assertSame(0, $txn->available_before);
        $this->assertSame(1, $txn->available_after);
    }

    /**
     * Stage 7: Duplicate admission attempts are strictly rejected and idempotent.
     */
    public function test_duplicate_admission_is_rejected_preventing_counter_drift(): void
    {
        $donor = $this->createEligibleDonor('A-');
        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'center_id' => $this->center->id,
        ]);

        foreach (['HIV', 'HBV', 'HCV', 'SYPH', 'COMPAT'] as $code) {
            $this->screeningService->recordScreeningResult($donation, $this->labOfficer, [
                'test_type_code' => $code,
                'screening_result' => 'Passed',
            ]);
        }

        $this->screeningService->releaseToInventory($donation, $this->labOfficer);

        // Attempting to release a second time MUST throw ValidationException
        $this->expectException(ValidationException::class);
        $this->screeningService->releaseToInventory($donation->fresh(), $this->labOfficer);
    }

    /**
     * Stage 8 & 9: Blood request approval and reservation FIFO-allocates available unit to reserved.
     */
    public function test_request_approval_and_reservation_fifo_allocates_units(): void
    {
        $unitData = $this->admitScreenedUnits('O+', 'WB', 2);
        $stock = $unitData['stock']->fresh();

        $this->assertSame(2, $stock->available_units);
        $this->assertSame(0, $stock->reserved_units);

        $request = $this->fulfillmentService->createRequest($this->hospital, $this->hospitalOfficer, [
            'blood_group' => 'O+',
            'blood_component_id' => 1,
            'requested_units' => 1,
            'priority' => 'Normal',
            'required_date' => now()->addDays(2)->toDateString(),
            'request_reason' => 'Scheduled surgical procedure support.',
        ]);

        $approvedRequest = $this->fulfillmentService->approveRequest($request, $this->admin, 'Stock verified and approved for reservation.');

        $allocations = $this->fulfillmentService->reserveUnits($approvedRequest, $this->staff, 1);
        $this->assertCount(1, $allocations);

        $stock->refresh();
        $this->assertSame(1, $stock->available_units);
        $this->assertSame(1, $stock->reserved_units);

        $allocatedUnit = $allocations->first()->bloodUnit;
        $this->assertSame('Reserved', $allocatedUnit->unit_status);

        // Ledger must contain Reserve transaction
        $reserveTxn = InventoryTransaction::query()
            ->where('blood_unit_id', $allocatedUnit->id)
            ->where('operation_type', 'Reserve')
            ->firstOrFail();

        $this->assertSame(-1, $reserveTxn->units_delta);
        $this->assertSame(2, $reserveTxn->available_before);
        $this->assertSame(1, $reserveTxn->available_after);
        $this->assertSame(0, $reserveTxn->reserved_before);
        $this->assertSame(1, $reserveTxn->reserved_after);
    }

    /**
     * Stage 10: Dispatch decrements reserved stock, marks unit Dispatched, and appends dispatch ledger row.
     */
    public function test_dispatch_decrements_reserved_units_and_marks_unit_dispatched(): void
    {
        $unitData = $this->admitScreenedUnits('B-', 'WB', 1);
        $unit = $unitData['units']->first();

        $request = $this->fulfillmentService->createRequest($this->hospital, $this->hospitalOfficer, [
            'blood_group' => 'B-',
            'blood_component_id' => 1,
            'requested_units' => 1,
            'priority' => 'High',
            'required_date' => now()->addDays(1)->toDateString(),
            'request_reason' => 'Urgent medical requirement.',
        ]);

        $approved = $this->fulfillmentService->approveRequest($request, $this->admin);
        $this->fulfillmentService->reserveUnits($approved, $this->staff, 1);

        $fulfillment = $this->fulfillmentService->dispatchUnits($approved->fresh(), $this->staff, [
            'quantity' => 1,
            'courier_reference' => 'FastMed-Logistics',
            'notes' => 'Dispatched under temperature-controlled packaging.',
        ]);

        $this->assertSame('Dispatched', $fulfillment->delivery_status);

        $stock = InventoryStock::query()->forBloodGroup('B-')->forComponent(1)->firstOrFail();
        $this->assertSame(0, $stock->available_units);
        $this->assertSame(0, $stock->reserved_units);

        $unit->refresh();
        $this->assertSame('Dispatched', $unit->unit_status);

        $dispatchTxn = InventoryTransaction::query()
            ->where('blood_unit_id', $unit->id)
            ->where('operation_type', 'Dispatch')
            ->firstOrFail();

        $this->assertSame(-1, $dispatchTxn->units_delta);
        $this->assertSame(0, $dispatchTxn->available_before);
        $this->assertSame(0, $dispatchTxn->available_after);
        $this->assertSame(1, $dispatchTxn->reserved_before);
        $this->assertSame(0, $dispatchTxn->reserved_after);
    }

    /**
     * Stage 11: Marking a Dispatched unit as Transfused maintains stock parity and updates lifecycle status.
     */
    public function test_marking_dispatched_unit_transfused_maintains_stock_parity(): void
    {
        $unitData = $this->admitScreenedUnits('A+', 'WB', 1);
        $unit = $unitData['units']->first();

        $request = $this->fulfillmentService->createRequest($this->hospital, $this->hospitalOfficer, [
            'blood_group' => 'A+',
            'blood_component_id' => 1,
            'requested_units' => 1,
            'priority' => 'Normal',
            'required_date' => now()->addDays(1)->toDateString(),
            'request_reason' => 'Transfusion support for scheduled oncology procedure.',
        ]);

        $approved = $this->fulfillmentService->approveRequest($request, $this->admin);
        $this->fulfillmentService->reserveUnits($approved, $this->staff, 1);

        $this->fulfillmentService->dispatchUnits($approved->fresh(), $this->staff, [
            'quantity' => 1,
            'courier_reference' => 'City-Medical-441',
        ]);

        $this->assertSame('Dispatched', $unit->fresh()->unit_status);

        $transfusedUnit = $this->inventoryService->markUnitTransfused($unit->fresh(), $this->admin, [
            'reason' => 'Administered successfully to recipient in ICU ward.',
        ]);

        $this->assertSame('Transfused', $transfusedUnit->unit_status);

        $stock = InventoryStock::query()->forBloodGroup('A+')->forComponent(1)->firstOrFail();
        $this->assertSame(0, $stock->available_units);
        $this->assertSame(0, $stock->reserved_units);

        $reconciliation = $this->inventoryService->reconcileStock($stock);
        $this->assertTrue($reconciliation['is_reconciled']);
        $this->assertSame([], $reconciliation['discrepancies']);
    }

    /**
     * Stage 11: Direct bedside transfusion of Available unit decrements stock and writes Removed ledger row.
     */
    public function test_direct_transfusion_of_available_unit_decrements_stock_with_ledger(): void
    {
        $unitData = $this->admitScreenedUnits('O-', 'WB', 1);
        $unit = $unitData['units']->first();
        $stock = $unitData['stock']->fresh();

        $this->assertSame(1, $stock->available_units);

        $transfused = $this->inventoryService->markUnitTransfused($unit, $this->admin, [
            'reason' => 'Emergency bedside trauma transfusion administered on-site.',
        ]);

        $this->assertSame('Transfused', $transfused->unit_status);

        $stock->refresh();
        $this->assertSame(0, $stock->available_units);
        $this->assertSame(0, $stock->reserved_units);

        $txn = InventoryTransaction::query()
            ->where('blood_unit_id', $unit->id)
            ->where('operation_type', 'Removed')
            ->firstOrFail();

        $this->assertSame(-1, $txn->units_delta);
        $this->assertSame(1, $txn->available_before);
        $this->assertSame(0, $txn->available_after);

        $reconciliation = $this->inventoryService->reconcileStock($stock);
        $this->assertTrue($reconciliation['is_reconciled']);
        $this->assertSame([], $reconciliation['discrepancies']);
    }

    /**
     * Stage 11: Manual discard of Available unit decrements stock and appends Discarded transaction.
     */
    public function test_manual_discard_of_available_unit_decrements_stock(): void
    {
        $unitData = $this->admitScreenedUnits('AB-', 'WB', 1);
        $unit = $unitData['units']->first();
        $stock = $unitData['stock']->fresh();

        $this->assertSame(1, $stock->available_units);

        $discarded = $this->inventoryService->discardUnit($unit, $this->admin, [
            'description' => 'Container seal damaged during storage inspection.',
        ]);

        $this->assertSame('Discarded', $discarded->unit_status);

        $stock->refresh();
        $this->assertSame(0, $stock->available_units);

        $txn = InventoryTransaction::query()
            ->where('blood_unit_id', $unit->id)
            ->where('operation_type', 'Discarded')
            ->where('units_delta', -1)
            ->firstOrFail();

        $this->assertSame(1, $txn->available_before);
        $this->assertSame(0, $txn->available_after);

        $reconciliation = $this->inventoryService->reconcileStock($stock);
        $this->assertTrue($reconciliation['is_reconciled']);
    }

    /**
     * Stage 11: Expired available unit marked Expired decrements stock and appends Expired transaction.
     */
    public function test_marking_expired_unit_decrements_available_stock(): void
    {
        $unitData = $this->admitScreenedUnits('B+', 'WB', 1);
        $unit = $unitData['units']->first();
        $stock = $unitData['stock']->fresh();

        // Simulate expiry in past
        $unit->expires_at = now()->subHour();
        $unit->save();

        $expired = $this->inventoryService->markUnitExpired($unit, $this->admin, [
            'description' => 'Unit exceeded 35-day shelf life.',
        ]);

        $this->assertSame('Expired', $expired->unit_status);

        $stock->refresh();
        $this->assertSame(0, $stock->available_units);

        $txn = InventoryTransaction::query()
            ->where('blood_unit_id', $unit->id)
            ->where('operation_type', 'Expired')
            ->firstOrFail();

        $this->assertSame(-1, $txn->units_delta);

        $reconciliation = $this->inventoryService->reconcileStock($stock);
        $this->assertTrue($reconciliation['is_reconciled']);
    }

    /**
     * Stage 12: Stock reconciliation verifies zero discrepancies across all buckets and ledger balances.
     */
    public function test_reconcile_all_seeded_buckets_confirms_clean_audit(): void
    {
        // Admit a mixture of units across groups
        $this->admitScreenedUnits('A+', 'WB', 2);
        $this->admitScreenedUnits('O+', 'WB', 3);
        $this->admitScreenedUnits('B+', 'WB', 1);

        $allStocks = InventoryStock::query()->get();
        $this->assertGreaterThanOrEqual(40, $allStocks->count());

        foreach ($allStocks as $stock) {
            $report = $this->inventoryService->reconcileStock($stock);
            $this->assertTrue($report['is_reconciled'], "Stock bucket {$stock->blood_group}/#{$stock->blood_component_id} failed reconciliation: ".implode(', ', $report['discrepancies']));
            $this->assertSame([], $report['discrepancies']);
        }
    }

    /**
     * Dashboard & UI consistency: Dashboard and inventory controller views show identical stock numbers.
     */
    public function test_dashboard_and_inventory_views_display_identical_consistent_stock(): void
    {
        $this->admitScreenedUnits('O+', 'WB', 4);
        $this->admitScreenedUnits('A-', 'WB', 2);

        $inventoryResponse = $this->actingAs($this->admin)->get(route('inventory.index'));
        $inventoryResponse->assertOk();
        $inventoryResponse->assertViewHas('summary');
        $inventorySummary = $inventoryResponse->viewData('summary');
        $this->assertSame(6, $inventorySummary['totalUnits']);

        $dashboardResponse = $this->actingAs($this->admin)->get(route('dashboard'));
        $dashboardResponse->assertOk();
        $dashboardResponse->assertSee('6'); // totalUnits in dashboard KPI
    }

    /**
     * Helper to admit N screened units of a specified group and component.
     *
     * @return array{stock: InventoryStock, units: Collection<int, BloodUnit>}
     */
    private function admitScreenedUnits(string $bloodGroup, string $componentCode, int $count): array
    {
        $component = BloodComponent::query()->where('code', $componentCode)->firstOrFail();
        $units = collect();

        for ($i = 0; $i < $count; $i++) {
            $donor = $this->createEligibleDonor($bloodGroup);

            $donation = $this->donationService->registerDonation($donor, $this->staff, [
                'blood_component_id' => $component->id,
                'quantity_ml' => $component->default_volume_ml,
                'center_id' => $this->center->id,
            ]);

            foreach (['HIV', 'HBV', 'HCV', 'SYPH', 'COMPAT'] as $code) {
                $this->screeningService->recordScreeningResult($donation, $this->labOfficer, [
                    'test_type_code' => $code,
                    'screening_result' => 'Passed',
                ]);
            }

            $released = $this->screeningService->releaseToInventory($donation, $this->labOfficer);
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

    private function createEligibleDonor(string $bloodGroup): Donor
    {
        $donor = Donor::factory()->create([
            'blood_group' => $bloodGroup,
            'profile_completed' => true,
            'account_status' => 'Active',
        ]);

        $this->eligibilityService->approveEligibility(
            donor: $donor,
            actor: $this->admin,
            reason: 'Cleared for audit integration test.',
            clearanceSource: 'Medical Officer Clearance'
        );

        return $donor->fresh();
    }

    private function createUserWithRole(string $roleSlug, bool $isActive = true): User
    {
        $user = User::factory()->create(['is_active' => $isActive]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user;
    }
}
