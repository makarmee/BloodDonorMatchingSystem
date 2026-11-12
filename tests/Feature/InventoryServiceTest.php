<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\Hospital;
use App\Models\InventoryAllocation;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\Role;
use App\Models\User;
use App\Policies\BloodUnitPolicy;
use App\Policies\InventoryStockPolicy;
use App\Services\DonationService;
use App\Services\DonorEligibilityService;
use App\Services\InventoryService;
use App\Services\ScreeningService;
use Carbon\CarbonImmutable;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventoryService;

    private DonationService $donationService;

    private ScreeningService $screeningService;

    private DonorEligibilityService $eligibilityService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryService = $this->app->make(InventoryService::class);
        $this->donationService = $this->app->make(DonationService::class);
        $this->screeningService = $this->app->make(ScreeningService::class);
        $this->eligibilityService = $this->app->make(DonorEligibilityService::class);
    }

    public function test_policies_are_auto_discovered_and_enforce_inventory_adjust_permission(): void
    {
        $this->seedBaselineCatalogs();

        $this->assertInstanceOf(InventoryStockPolicy::class, Gate::getPolicyFor(InventoryStock::class));
        $this->assertInstanceOf(BloodUnitPolicy::class, Gate::getPolicyFor(BloodUnit::class));

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $inactiveStaff = $this->createUserWithRole('staff', false);
        $labOfficer = $this->createUserWithRole('lab_officer');
        $donorUser = $this->createUserWithRole('donor');
        $hospitalUser = $this->createUserWithRole('hospital');

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O+', 'WB', 1);
        $unit = $units->firstOrFail();

        $this->assertTrue(Gate::forUser($admin)->allows('adjust', $stock));
        $this->assertTrue(Gate::forUser($staff)->allows('adjust', $stock));
        $this->assertFalse(Gate::forUser($inactiveStaff)->allows('adjust', $stock));
        $this->assertFalse(Gate::forUser($labOfficer)->allows('adjust', $stock));
        $this->assertFalse(Gate::forUser($donorUser)->allows('adjust', $stock));
        $this->assertFalse(Gate::forUser($hospitalUser)->allows('adjust', $stock));

        foreach ([null, $inactiveStaff, $labOfficer, $donorUser, $hospitalUser] as $unauthorizedActor) {
            $this->assertUnauthorized(fn (): InventoryStock => $this->inventoryService->adjustStock($stock, $unauthorizedActor, [
                'units_delta' => -1,
                'description' => 'Unauthorized adjustment attempt.',
            ]));
            $this->assertUnauthorized(fn (): mixed => $this->inventoryService->reserveUnits($stock, $unauthorizedActor, 1));
            $this->assertUnauthorized(fn (): BloodUnit => $this->inventoryService->reserveUnit($unit, $unauthorizedActor));
            $this->assertUnauthorized(fn (): BloodUnit => $this->inventoryService->releaseReservation($unit, $unauthorizedActor));
            $this->assertUnauthorized(fn (): BloodUnit => $this->inventoryService->discardUnit($unit, $unauthorizedActor, [
                'description' => 'Unauthorized discard attempt.',
            ]));
            $this->assertUnauthorized(fn (): BloodUnit => $this->inventoryService->markUnitExpired($unit, $unauthorizedActor));
        }
    }

    public function test_authorized_manual_stock_adjustment_decrements_unit_backed_stock_and_updates_thresholds(): void
    {
        $this->travelTo('2026-11-12 09:00:00');
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'A+', 'PRBC', 2);
        $targetUnit = $units->last();

        // Update stock thresholds without changing unit count.
        $thresholdUpdated = $this->inventoryService->adjustStock($stock, $staff, [
            'minimum_level' => 10,
            'target_capacity' => 60,
            'description' => 'Quarterly safety threshold calibration.',
        ]);

        $this->assertSame(10, $thresholdUpdated->minimum_level);
        $this->assertSame(60, $thresholdUpdated->target_capacity);
        $this->assertSame(2, $thresholdUpdated->available_units);
        $this->assertSame('critical', $this->inventoryService->evaluateStockStatus($thresholdUpdated));

        // Perform unit-backed negative manual adjustment targeting a specific unit.
        $adjusted = $this->inventoryService->adjustStock($thresholdUpdated, $staff, [
            'units_delta' => -1,
            'blood_unit_ids' => [$targetUnit->id],
            'description' => 'Damaged storage seal observed during morning freezer check.',
            'reference_type' => 'manual',
            'reference_id' => 501,
        ]);

        $this->assertSame(1, $adjusted->available_units);
        $this->assertSame(0, $adjusted->reserved_units);
        $this->assertSame('Discarded', $targetUnit->fresh()->unit_status);

        $manualTxn = InventoryTransaction::query()
            ->where('inventory_stock_id', $adjusted->id)
            ->ofOperation('Manual Adjustment')
            ->first();

        $this->assertNotNull($manualTxn);
        $this->assertSame(-1, $manualTxn->units_delta);
        $this->assertSame(2, $manualTxn->available_before);
        $this->assertSame(1, $manualTxn->available_after);
        $this->assertSame($targetUnit->id, $manualTxn->blood_unit_id);
        $this->assertSame($staff->id, $manualTxn->performed_by_user_id);
        $this->assertSame($staff->name, $manualTxn->actor_name);
        $this->assertSame('manual', $manualTxn->reference_type);
        $this->assertSame(501, $manualTxn->reference_id);

        $reconciliation = $this->inventoryService->reconcileStock($adjusted);
        $this->assertTrue($reconciliation['is_reconciled']);
    }

    public function test_manual_adjustment_rejects_positive_counter_inflation_mismatched_dimensions_and_bad_descriptions(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $apStock] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'A+', 'WB', 1);
        ['units' => $bpUnits] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'B+', 'WB', 1);
        $bpUnit = $bpUnits->firstOrFail();

        $this->assertValidationError('units_delta', fn (): InventoryStock => $this->inventoryService->adjustStock($apStock, $staff, [
            'units_delta' => 2,
            'description' => 'Attempting unbacked counter inflation.',
        ]));

        $this->assertValidationError('description', fn (): InventoryStock => $this->inventoryService->adjustStock($apStock, $staff, [
            'units_delta' => -1,
            'description' => 'ok',
        ]));

        $this->assertValidationError('blood_unit_id', fn (): InventoryStock => $this->inventoryService->adjustStock($apStock, $staff, [
            'units_delta' => -1,
            'blood_unit_ids' => [$bpUnit->id],
            'description' => 'Wrong blood group unit referenced.',
        ]));

        $this->assertValidationError('blood_unit_ids', fn (): InventoryStock => $this->inventoryService->adjustStock($apStock, $staff, [
            'blood_unit_ids' => [$bpUnit->id, $bpUnit->id],
            'description' => 'Duplicate blood unit IDs passed.',
        ]));

        $this->assertSame(1, $apStock->fresh()->available_units);
    }

    public function test_fifo_reservation_and_targeted_reservation_prevent_insufficient_and_duplicate_reservations(): void
    {
        $this->travelTo('2026-11-12 10:00:00');
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O-', 'PLT', 3);

        $oldestUnit = $units[0];
        $middleUnit = $units[1];
        $newestUnit = $units[2];

        // Force distinct expiries so FIFO order is unambiguous; make newestUnit already expired to test exclusion.
        $oldestUnit->forceFill(['expires_at' => CarbonImmutable::parse('2026-11-14 10:00:00')])->save();
        $middleUnit->forceFill(['expires_at' => CarbonImmutable::parse('2026-11-16 10:00:00')])->save();
        $newestUnit->forceFill(['expires_at' => CarbonImmutable::parse('2026-11-11 10:00:00')])->save();

        // Attempting to reserve 3 fails because newestUnit is expired (only 2 non-expired Available units).
        $this->assertValidationError('available_units', fn (): mixed => $this->inventoryService->reserveUnits($stock, $staff, 3));
        $this->assertSame(3, $stock->fresh()->available_units);
        $this->assertSame(0, $stock->fresh()->reserved_units);

        // FIFO reserves oldest non-expired unit first.
        $reservedBatch = $this->inventoryService->reserveUnits($stock, $staff, 1, [
            'description' => 'Holding earliest expiry unit for crossmatch.',
        ]);

        $this->assertCount(1, $reservedBatch);
        $this->assertSame($oldestUnit->id, $reservedBatch->first()->id);
        $this->assertSame('Reserved', $oldestUnit->fresh()->unit_status);
        $this->assertSame(2, $stock->fresh()->available_units);
        $this->assertSame(1, $stock->fresh()->reserved_units);

        // Reserving same unit via stale instance is blocked (prevents double-reservation race).
        $this->assertValidationError('unit_status', fn (): BloodUnit => $this->inventoryService->reserveUnit($oldestUnit, $staff));

        // Reserving expired Available unit directly is blocked.
        $this->assertValidationError('expires_at', fn (): BloodUnit => $this->inventoryService->reserveUnit($newestUnit, $staff));

        // Targeted reservation succeeds on remaining valid unit.
        $staleCopyA = $middleUnit->fresh();
        $staleCopyB = $middleUnit->fresh();

        $reservedMiddle = $this->inventoryService->reserveUnit($staleCopyA, $staff, [
            'description' => 'Targeted crossmatch hold.',
        ]);
        $this->assertSame('Reserved', $reservedMiddle->unit_status);
        $this->assertSame(1, $stock->fresh()->available_units);
        $this->assertSame(2, $stock->fresh()->reserved_units);

        // Concurrent caller holding staleCopyB sees Reserved status under lock and is blocked.
        $this->assertValidationError('unit_status', fn (): BloodUnit => $this->inventoryService->reserveUnit($staleCopyB, $staff));

        $reconciliation = $this->inventoryService->reconcileStock($stock->fresh());
        $this->assertTrue($reconciliation['is_reconciled']);
    }

    public function test_reservation_release_returns_unit_to_available_and_blocks_duplicate_release(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'B+', 'WB', 1);
        $unit = $units->firstOrFail();

        $reserved = $this->inventoryService->reserveUnit($unit, $staff);
        $this->assertSame(0, $stock->fresh()->available_units);
        $this->assertSame(1, $stock->fresh()->reserved_units);

        $staleReservedCopy = $reserved->fresh();
        $released = $this->inventoryService->releaseReservation($reserved, $staff, [
            'description' => 'Surgery postponed; releasing unit hold.',
        ]);

        $this->assertSame('Available', $released->unit_status);
        $this->assertSame(1, $stock->fresh()->available_units);
        $this->assertSame(0, $stock->fresh()->reserved_units);

        $releaseTxn = InventoryTransaction::query()
            ->where('inventory_stock_id', $stock->id)
            ->ofOperation('Release Reservation')
            ->first();

        $this->assertNotNull($releaseTxn);
        $this->assertSame(1, $releaseTxn->units_delta);
        $this->assertSame(0, $releaseTxn->available_before);
        $this->assertSame(1, $releaseTxn->available_after);
        $this->assertSame(1, $releaseTxn->reserved_before);
        $this->assertSame(0, $releaseTxn->reserved_after);

        // Duplicate release via stale Reserved copy fails cleanly.
        $this->assertValidationError('unit_status', fn (): BloodUnit => $this->inventoryService->releaseReservation($staleReservedCopy, $staff));

        $this->assertTrue($this->inventoryService->reconcileStock($stock->fresh())['is_reconciled']);
    }

    public function test_discarding_available_reserved_and_quarantined_units_and_blocking_allocated_units(): void
    {
        $this->travelTo('2026-11-12 11:00:00');
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'AB+', 'WB', 3);

        // 1. Discard Available unit -> decrements available_units, units_delta = -1.
        $availableUnit = $units[0];
        $discardedAvailable = $this->inventoryService->discardUnit($availableUnit, $staff, [
            'description' => 'Bag cracked during shelf transfer.',
        ]);
        $this->assertSame('Discarded', $discardedAvailable->unit_status);
        $this->assertSame(2, $stock->fresh()->available_units);
        $this->assertSame(0, $stock->fresh()->reserved_units);

        // Duplicate discard blocked.
        $this->assertValidationError('unit_status', fn (): BloodUnit => $this->inventoryService->discardUnit($availableUnit, $staff, [
            'description' => 'Duplicate discard attempt.',
        ]));

        // 2. Discard standalone Reserved unit -> decrements reserved_units, units_delta = -1.
        $reservedUnit = $this->inventoryService->reserveUnit($units[1], $staff);
        $discardedReserved = $this->inventoryService->discardUnit($reservedUnit, $staff, [
            'description' => 'Visible hemolysis detected prior to dispatch.',
        ]);
        $this->assertSame('Discarded', $discardedReserved->unit_status);
        $this->assertSame(1, $stock->fresh()->available_units);
        $this->assertSame(0, $stock->fresh()->reserved_units);

        // 3. Reserved unit linked to an active request allocation cannot be discarded or released directly.
        $allocatedUnit = $this->inventoryService->reserveUnit($units[2], $staff);
        $hospital = Hospital::factory()->create();
        $bloodRequest = BloodRequest::factory()->approved()->create([
            'hospital_id' => $hospital->id,
            'blood_group' => 'AB+',
            'blood_component_id' => $stock->blood_component_id,
            'requested_units' => 1,
            'reserved_units' => 1,
        ]);
        InventoryAllocation::factory()->create([
            'blood_request_id' => $bloodRequest->id,
            'inventory_stock_id' => $stock->id,
            'blood_unit_id' => $allocatedUnit->id,
            'allocation_status' => 'Reserved',
            'allocated_by_user_id' => $staff->id,
        ]);

        $this->assertValidationError('allocation_status', fn (): BloodUnit => $this->inventoryService->discardUnit($allocatedUnit, $staff, [
            'description' => 'Trying to discard unit allocated to hospital request.',
        ]));
        $this->assertValidationError('allocation_status', fn (): BloodUnit => $this->inventoryService->releaseReservation($allocatedUnit, $staff));

        // 4. Discard pre-release Quarantined unit -> zero-delta audit log, balances unchanged, ScreeningService untouched.
        $donor = $this->createEligibleDonor($admin, 'AB+');
        $quarantinedDonation = $this->donationService->registerDonation($donor, $staff, [
            'blood_component_id' => $stock->blood_component_id,
            'quantity_ml' => 450,
            'center_id' => DonationCenter::factory()->create()->id,
        ]);
        $quarantinedUnit = $quarantinedDonation->primaryBloodUnit;

        $discardedQuarantined = $this->inventoryService->discardUnit($quarantinedUnit, $staff, [
            'description' => 'Clotted bag observed in quarantine before lab panel entry.',
        ]);
        $this->assertSame('Discarded', $discardedQuarantined->unit_status);
        $this->assertSame(0, $stock->fresh()->available_units);
        $this->assertSame(1, $stock->fresh()->reserved_units);
        $this->assertSame('Pending', $quarantinedDonation->fresh()->screening_status);

        $reconciliation = $this->inventoryService->reconcileStock($stock->fresh());
        $this->assertTrue($reconciliation['is_reconciled']);
        $this->assertSame(1, $reconciliation['quarantined_zero_delta_discards_count']);
        $this->assertSame(1, $reconciliation['stock_total_units']);

        // 5. Direct reserveUnit() on an Available unit linked to an active request allocation is blocked.
        ['units' => $allocatedCheckUnits] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'AB+', 'WB', 1);
        $allocatedAvailableUnit = $allocatedCheckUnits[0];
        InventoryAllocation::factory()->create([
            'blood_request_id' => $bloodRequest->id,
            'inventory_stock_id' => $stock->id,
            'blood_unit_id' => $allocatedAvailableUnit->id,
            'allocation_status' => 'Reserved',
            'allocated_by_user_id' => $staff->id,
        ]);

        $this->assertValidationError('unit_status', fn (): BloodUnit => $this->inventoryService->reserveUnit($allocatedAvailableUnit, $staff));
    }

    public function test_marking_expired_units_and_detecting_reconciliation_discrepancies_without_silent_repair(): void
    {
        $this->travelTo('2026-11-12 12:00:00');
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O+', 'WB', 2);
        $nonExpired = $units[0];
        $expired = $units[1];
        $expired->forceFill(['expires_at' => CarbonImmutable::parse('2026-11-12 11:59:00')])->save();

        // Non-expired unit cannot be marked Expired.
        $this->assertValidationError('expires_at', fn (): BloodUnit => $this->inventoryService->markUnitExpired($nonExpired, $staff));

        $markedExpired = $this->inventoryService->markUnitExpired($expired, $staff);
        $this->assertSame('Expired', $markedExpired->unit_status);
        $this->assertSame(1, $stock->fresh()->available_units);

        // Quarantined, Dispatched, or Expired units cannot be marked Expired again.
        $this->assertValidationError('unit_status', fn (): BloodUnit => $this->inventoryService->markUnitExpired($markedExpired, $staff));

        // Simulate a failed screening zero-delta discard in the same stock bucket (handled by ScreeningService).
        $donorFail = $this->createEligibleDonor($admin, 'O+');
        $failedDonation = $this->donationService->registerDonation($donorFail, $staff, [
            'blood_component_id' => $stock->blood_component_id,
            'quantity_ml' => 450,
            'center_id' => DonationCenter::factory()->create()->id,
        ]);
        $this->screeningService->recordScreeningResult($failedDonation, $labOfficer, [
            'test_type_code' => 'HIV',
            'screening_result' => 'Failed',
        ]);

        $cleanReport = $this->inventoryService->reconcileStock($stock->fresh());
        $this->assertTrue($cleanReport['is_reconciled']);
        $this->assertSame(1, $cleanReport['quarantined_zero_delta_discards_count']);

        // Tamper stock counter directly in DB -> reconcileStock must detect inconsistency and NOT silently repair it.
        InventoryStock::query()->whereKey($stock->id)->update(['available_units' => 9]);

        $dirtyReport = $this->inventoryService->reconcileStock($stock->fresh());
        $this->assertFalse($dirtyReport['is_reconciled']);
        $this->assertNotEmpty($dirtyReport['discrepancies']);
        $this->assertSame(9, $stock->fresh()->available_units);
    }

    public function test_failed_ledger_insert_rolls_back_unit_and_stock_mutations_atomically(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'A-', 'WB', 1);
        $unit = $units->firstOrFail();

        Event::listen('eloquent.creating: '.InventoryTransaction::class, static function (): void {
            throw new RuntimeException('Simulated ledger persistence failure.');
        });

        try {
            $this->inventoryService->reserveUnit($unit, $staff);
            $this->fail('Expected RuntimeException from simulated ledger failure.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            Event::forget('eloquent.creating: '.InventoryTransaction::class);
        }

        $this->assertSame('Available', $unit->fresh()->unit_status);
        $this->assertSame(1, $stock->fresh()->available_units);
        $this->assertSame(0, $stock->fresh()->reserved_units);
        $this->assertTrue($this->inventoryService->reconcileStock($stock->fresh())['is_reconciled']);
    }

    public function test_invalid_unit_state_transitions_fifo_manual_decrement_and_unit_count_mismatch_detection(): void
    {
        $this->travelTo('2026-11-12 13:00:00');
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'B-', 'WB', 2);
        $earlierUnit = $units[0];
        $laterUnit = $units[1];
        $earlierUnit->forceFill(['expires_at' => CarbonImmutable::parse('2026-11-20 13:00:00')])->save();
        $laterUnit->forceFill(['expires_at' => CarbonImmutable::parse('2026-11-28 13:00:00')])->save();

        // Negative manual adjustment without explicit blood_unit_ids picks FIFO earliest-expiry unit.
        $adjusted = $this->inventoryService->adjustStock($stock, $staff, [
            'units_delta' => -1,
            'description' => 'FIFO spoilage write-off after temperature excursion.',
        ]);
        $this->assertSame('Discarded', $earlierUnit->fresh()->unit_status);
        $this->assertSame('Available', $laterUnit->fresh()->unit_status);
        $this->assertSame(1, $adjusted->available_units);

        // Quarantined, Dispatched, and Discarded units reject invalid inventory state transitions.
        $donor = $this->createEligibleDonor($admin, 'B-');
        $quarantinedDonation = $this->donationService->registerDonation($donor, $staff, [
            'blood_component_id' => $stock->blood_component_id,
            'quantity_ml' => 450,
            'center_id' => DonationCenter::factory()->create()->id,
        ]);
        $quarantinedUnit = $quarantinedDonation->primaryBloodUnit;

        $this->assertValidationError('unit_status', fn (): BloodUnit => $this->inventoryService->reserveUnit($quarantinedUnit, $staff));
        $this->assertValidationError('unit_status', fn (): BloodUnit => $this->inventoryService->releaseReservation($quarantinedUnit, $staff));
        $this->assertValidationError('unit_status', fn (): BloodUnit => $this->inventoryService->markUnitExpired($quarantinedUnit, $staff));

        $dispatchedUnit = BloodUnit::factory()->dispatched()->create([
            'blood_group' => 'B-',
            'blood_component_id' => $stock->blood_component_id,
        ]);
        $this->assertValidationError('unit_status', fn (): BloodUnit => $this->inventoryService->discardUnit($dispatchedUnit, $staff, [
            'description' => 'Cannot discard dispatched unit.',
        ]));
        $this->assertValidationError('unit_status', fn (): BloodUnit => $this->inventoryService->releaseReservation($dispatchedUnit, $staff));

        // Corrupt serialized BloodUnit status directly in DB -> reconcileStock catches Available unit count drift.
        BloodUnit::query()->whereKey($laterUnit->id)->update(['unit_status' => 'Dispatched']);
        $mismatchReport = $this->inventoryService->reconcileStock($stock->fresh());
        $this->assertFalse($mismatchReport['is_reconciled']);
        $this->assertStringContainsString('Serialized Available blood_units count', implode(' ', $mismatchReport['discrepancies']));
    }

    public function test_reconcile_stock_validates_operation_signs_and_detects_duplicates_or_cross_dimension_units(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $wbComponent = BloodComponent::query()->where('code', 'WB')->firstOrFail();

        // 1. Valid complex historical lifecycle including Reserve -> Release -> Reserve -> Dispatch (reserved) -> Stock In -> Quarantined zero-delta Discard.
        ['stock' => $wbStock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'A+', 'WB', 2);
        $unitDispatched = $units[0];
        $unitAvailable = $units[1];

        $this->inventoryService->reserveUnit($unitDispatched, $staff);
        $this->inventoryService->releaseReservation($unitDispatched, $staff);
        $this->inventoryService->reserveUnit($unitDispatched, $staff);

        $unitDispatched->forceFill(['unit_status' => 'Dispatched'])->save();
        InventoryStock::query()->whereKey($wbStock->id)->update(['reserved_units' => 0]);
        InventoryTransaction::query()->create([
            'transaction_code' => 'TXN-HIST-DISP',
            'inventory_stock_id' => $wbStock->id,
            'blood_unit_id' => $unitDispatched->id,
            'operation_type' => 'Dispatch',
            'units_delta' => -1,
            'available_before' => 1,
            'available_after' => 1,
            'reserved_before' => 1,
            'reserved_after' => 0,
            'reference_type' => 'fulfillment',
            'reference_id' => 99,
            'performed_by_user_id' => $staff->id,
            'actor_name' => $staff->name,
            'description' => 'Valid historical reserved unit dispatch.',
            'occurred_at' => now(),
        ]);

        $cleanReport = $this->inventoryService->reconcileStock($wbStock->fresh());
        $this->assertTrue($cleanReport['is_reconciled'], implode(' | ', $cleanReport['discrepancies']));

        // 2. Inverted Reserve sign (+1 instead of -1) is caught even when net physical delta stays zero.
        $invertedStock = InventoryStock::factory()->create([
            'blood_group' => 'AB-',
            'blood_component_id' => $wbComponent->id,
            'available_units' => 1,
            'reserved_units' => 0,
        ]);
        $abUnit = BloodUnit::factory()->available()->create([
            'blood_group' => 'AB-',
            'blood_component_id' => $wbComponent->id,
        ]);
        InventoryTransaction::query()->create([
            'transaction_code' => 'TXN-INV-1',
            'inventory_stock_id' => $invertedStock->id,
            'blood_unit_id' => $abUnit->id,
            'operation_type' => 'Reserve',
            'units_delta' => 1,
            'available_before' => 0,
            'available_after' => 1,
            'reserved_before' => 0,
            'reserved_after' => 0,
            'reference_type' => 'manual',
            'reference_id' => 1,
            'performed_by_user_id' => $staff->id,
            'actor_name' => $staff->name,
            'description' => 'Corrupted positive Reserve delta.',
            'occurred_at' => now(),
        ]);
        $invertedReport = $this->inventoryService->reconcileStock($invertedStock);
        $this->assertFalse($invertedReport['is_reconciled']);
        $this->assertStringContainsString('Reserve transaction TXN-INV-1 has invalid delta', implode(' ', $invertedReport['discrepancies']));

        // 3. Duplicate Stock In, duplicate terminal Discarded, and cross-dimension BloodUnit reference are all detected.
        InventoryTransaction::query()->create([
            'transaction_code' => 'TXN-DUP-IN',
            'inventory_stock_id' => $wbStock->id,
            'blood_unit_id' => $unitAvailable->id,
            'operation_type' => 'Stock In',
            'units_delta' => 1,
            'available_before' => 1,
            'available_after' => 2,
            'reserved_before' => 0,
            'reserved_after' => 0,
            'reference_type' => 'manual',
            'reference_id' => 10,
            'performed_by_user_id' => $staff->id,
            'actor_name' => $staff->name,
            'description' => 'Duplicate Stock In on same unit.',
            'occurred_at' => now(),
        ]);
        InventoryTransaction::query()->create([
            'transaction_code' => 'TXN-DUP-TERM',
            'inventory_stock_id' => $wbStock->id,
            'blood_unit_id' => $unitDispatched->id,
            'operation_type' => 'Discarded',
            'units_delta' => -1,
            'available_before' => 2,
            'available_after' => 1,
            'reserved_before' => 0,
            'reserved_after' => 0,
            'reference_type' => 'manual',
            'reference_id' => 11,
            'performed_by_user_id' => $staff->id,
            'actor_name' => $staff->name,
            'description' => 'Duplicate terminal event on already-dispatched unit.',
            'occurred_at' => now(),
        ]);
        InventoryTransaction::query()->create([
            'transaction_code' => 'TXN-XDIM',
            'inventory_stock_id' => $wbStock->id,
            'blood_unit_id' => $abUnit->id,
            'operation_type' => 'Discarded',
            'units_delta' => 0,
            'available_before' => 1,
            'available_after' => 1,
            'reserved_before' => 0,
            'reserved_after' => 0,
            'reference_type' => 'manual',
            'reference_id' => 12,
            'performed_by_user_id' => $staff->id,
            'actor_name' => $staff->name,
            'description' => 'Cross-dimension BloodUnit reference.',
            'occurred_at' => now(),
        ]);

        $auditReport = $this->inventoryService->reconcileStock($wbStock->fresh());
        $joined = implode(' ', $auditReport['discrepancies']);
        $this->assertFalse($auditReport['is_reconciled']);
        $this->assertStringContainsString('Duplicate Stock In transaction TXN-DUP-IN', $joined);
        $this->assertStringContainsString('Duplicate terminal transaction TXN-DUP-TERM', $joined);
        $this->assertStringContainsString('from dimension (AB-/', $joined);
    }

    public function test_discard_unit_does_not_trust_stale_in_memory_unit_status_or_dimensions(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $component = BloodComponent::query()->where('code', 'PLS')->firstOrFail();

        // Case 1: Quarantined unit with NO stock bucket yet, mutated in memory to look like Available AB-.
        $donorA = $this->createEligibleDonor($admin, 'O-');
        $donationA = $this->donationService->registerDonation($donorA, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 600,
            'center_id' => DonationCenter::factory()->create()->id,
        ]);
        $tamperedUnit = $donationA->primaryBloodUnit;
        $tamperedUnit->unit_status = 'Available';
        $tamperedUnit->blood_group = 'AB-';

        $discardedA = $this->inventoryService->discardUnit($tamperedUnit, $staff, [
            'description' => 'Discarding quarantined plasma unit despite tampered model attributes.',
        ]);
        $this->assertSame('Discarded', $discardedA->unit_status);
        $this->assertSame('O-', $discardedA->blood_group);

        $omStock = InventoryStock::query()->forBloodGroup('O-')->forComponent($component->id)->firstOrFail();
        $this->assertSame(0, $omStock->available_units);
        $this->assertTrue($this->inventoryService->reconcileStock($omStock)['is_reconciled']);

        // Case 2: Stale Quarantined copy held by caller while ScreeningService releases the unit to Available.
        $donorB = $this->createEligibleDonor($admin, 'O-');
        $donationB = $this->donationService->registerDonation($donorB, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 600,
            'center_id' => DonationCenter::factory()->create()->id,
        ]);
        $staleQuarantinedCopy = $donationB->primaryBloodUnit;

        foreach (['HIV', 'HBV', 'HCV', 'SYPH', 'COMPAT'] as $code) {
            $this->screeningService->recordScreeningResult($donationB, $labOfficer, [
                'test_type_code' => $code,
                'screening_result' => 'Passed',
            ]);
        }
        $this->screeningService->releaseToInventory($donationB, $labOfficer);
        $this->assertSame(1, $omStock->fresh()->available_units);

        // Passing stale Quarantined copy MUST reload DB state ('Available'), decrement stock (-1), and reconcile cleanly.
        $discardedB = $this->inventoryService->discardUnit($staleQuarantinedCopy, $staff, [
            'description' => 'Stale model discard after ScreeningService release.',
        ]);
        $this->assertSame('Discarded', $discardedB->unit_status);
        $this->assertSame(0, $omStock->fresh()->available_units);
        $this->assertTrue($this->inventoryService->reconcileStock($omStock->fresh())['is_reconciled']);
    }

    private function seedBaselineCatalogs(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            BloodComponentSeeder::class,
            ScreeningTestTypeSeeder::class,
            ComponentScreeningRequirementSeeder::class,
        ]);
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
            $donor = $this->createEligibleDonor($admin, $bloodGroup);
            $donation = $this->donationService->registerDonation($donor, $staff, [
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

            $releasedDonation = $this->screeningService->releaseToInventory($donation, $labOfficer);
            $units->push($releasedDonation->primaryBloodUnit->fresh());
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

    private function createEligibleDonor(User $admin, string $bloodGroup): Donor
    {
        $donor = Donor::factory()->create([
            'blood_group' => $bloodGroup,
            'profile_completed' => true,
            'account_status' => 'Active',
        ]);

        $this->eligibilityService->approveEligibility(
            donor: $donor,
            actor: $admin,
            reason: 'Cleared for inventory integration test.',
            clearanceSource: 'Medical Officer Clearance',
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

    private function assertUnauthorized(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertValidationError(string $expectedKey, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected ValidationException containing key '{$expectedKey}'.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($expectedKey, $exception->errors());
        }
    }
}
