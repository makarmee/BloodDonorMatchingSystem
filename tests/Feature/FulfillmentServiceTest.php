<?php

namespace Tests\Feature;

use App\Models\BloodCompatibilityRule;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodRequestStatusHistory;
use App\Models\BloodUnit;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\InventoryAllocation;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\RequestFulfillment;
use App\Models\Role;
use App\Models\User;
use App\Services\DonationService;
use App\Services\DonorEligibilityService;
use App\Services\FulfillmentService;
use App\Services\InventoryService;
use App\Services\ScreeningService;
use Carbon\CarbonImmutable;
use Database\Seeders\BloodCompatibilityRuleSeeder;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FulfillmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private FulfillmentService $fulfillmentService;

    private InventoryService $inventoryService;

    private DonorEligibilityService $eligibilityService;

    private DonationService $donationService;

    private ScreeningService $screeningService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fulfillmentService = app(FulfillmentService::class);
        $this->inventoryService = app(InventoryService::class);
        $this->eligibilityService = app(DonorEligibilityService::class);
        $this->donationService = app(DonationService::class);
        $this->screeningService = app(ScreeningService::class);
    }

    public function test_authorization_and_hospital_ownership_across_fulfillment_actions(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $donorUser = $this->createUserWithRole('donor');
        $inactiveStaff = $this->createUserWithRole('staff', isActive: false);

        $hospitalA = Hospital::factory()->create(['primary_contact_name' => 'Dr. Maya Rao']);
        $hospitalB = Hospital::factory()->create();
        $unverifiedHospital = Hospital::factory()->pendingVerification()->create();
        $suspendedHospital = Hospital::factory()->suspended()->create();
        $inactiveHospital = Hospital::factory()->inactive()->create();

        $coordinatorA = $this->createHospitalCoordinator($hospitalA);
        $coordinatorB = $this->createHospitalCoordinator($hospitalB);
        $inactiveCoordinatorUser = $this->createHospitalCoordinator($hospitalA, userActive: false);
        $inactiveCoordinatorLink = $this->createHospitalCoordinator($hospitalA, linkActive: false);

        $rbc = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();

        $validPayload = [
            'blood_group' => 'A+',
            'blood_component_id' => $rbc->id,
            'requested_units' => 2,
            'priority' => 'High',
            'request_date' => '2026-11-12',
            'required_date' => '2026-11-13',
            'request_reason' => 'Scheduled cardiac surgery support.',
        ];

        // Unauthenticated, non-authorized roles, inactive users/links, and cross-hospital attempts fail.
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->createRequest($hospitalA, null, $validPayload));
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->createRequest($hospitalA, $donorUser, $validPayload));
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->createRequest($hospitalA, $labOfficer, $validPayload));
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->createRequest($hospitalA, $inactiveStaff, $validPayload));
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->createRequest($hospitalA, $inactiveCoordinatorUser, $validPayload));
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->createRequest($hospitalA, $inactiveCoordinatorLink, $validPayload));
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->createRequest($hospitalB, $coordinatorA, $validPayload));

        // Unverified / suspended / inactive partner hospitals cannot receive requests even via staff.
        $this->assertValidationError('hospital_id', fn (): BloodRequest => $this->fulfillmentService->createRequest($unverifiedHospital, $staff, $validPayload));
        $this->assertValidationError('hospital_id', fn (): BloodRequest => $this->fulfillmentService->createRequest($suspendedHospital, $staff, $validPayload));
        $this->assertValidationError('hospital_id', fn (): BloodRequest => $this->fulfillmentService->createRequest($inactiveHospital, $staff, $validPayload));

        // Verified coordinator creates a request for their own hospital.
        $requestA = $this->fulfillmentService->createRequest($hospitalA, $coordinatorA, $validPayload);
        $this->assertSame('Pending', $requestA->status);
        $this->assertSame($hospitalA->id, $requestA->hospital_id);
        $this->assertSame($coordinatorA->id, $requestA->requested_by_user_id);

        // Other hospital coordinator, lab officer, and donor cannot update, approve, reject, or cancel Hospital A request; coordinator cannot update.
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->updateRequest($requestA, $coordinatorA, ['requested_units' => 3]));
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->updateRequest($requestA, $coordinatorB, ['requested_units' => 3]));
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->cancelRequest($requestA, $coordinatorB, 'Not needed anymore.'));
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->approveRequest($requestA, $coordinatorA));
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->rejectRequest($requestA, $coordinatorA, 'Rejecting from coordinator should fail.'));
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->approveRequest($requestA, $labOfficer));
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->rejectRequest($requestA, $donorUser, 'Donor cannot reject.'));

        // Staff (with requests.fulfill) can update, and coordinator can cancel their own Pending request (Option A).
        $updatedPending = $this->fulfillmentService->updateRequest($requestA, $staff, ['requested_units' => 3]);
        $this->assertSame(3, $updatedPending->requested_units);
        $cancelledPending = $this->fulfillmentService->cancelRequest($updatedPending, $coordinatorA, 'Surgery postponed by patient family.');
        $this->assertSame('Cancelled', $cancelledPending->status);

        // Second request approved by staff: coordinator can NO LONGER cancel Approved requests.
        $requestA2 = $this->fulfillmentService->createRequest($hospitalA, $coordinatorA, $validPayload);
        $approved = $this->fulfillmentService->approveRequest($requestA2, $staff, 'Reviewed and approved by blood bank.');
        $this->assertSame('Approved', $approved->status);

        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->cancelRequest($approved, $coordinatorA, 'Too late for coordinator.'));

        $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'A+', 'PRBC', 2);

        // Coordinator, lab officer, and donor cannot reserve, release, or dispatch inventory.
        $this->assertUnauthorized(fn (): EloquentCollection => $this->fulfillmentService->reserveUnits($approved, $coordinatorA, 1));
        $this->assertUnauthorized(fn (): EloquentCollection => $this->fulfillmentService->reserveUnits($approved, $labOfficer, 1));
        $this->assertUnauthorized(fn (): RequestFulfillment => $this->fulfillmentService->dispatchUnits($approved, $coordinatorA, ['quantity' => 1]));

        // Staff reserves 1 unit, partially dispatches 1 unit -> coordinator still blocked from cancelling Partially Fulfilled.
        $this->fulfillmentService->reserveUnits($approved, $staff, 1);
        $this->fulfillmentService->dispatchUnits($approved, $staff, ['quantity' => 1, 'dispatched_blood_group' => 'A+']);

        $partial = $approved->fresh();
        $this->assertSame('Partially Fulfilled', $partial->status);
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->cancelRequest($partial, $coordinatorA, 'Blocked on partial.'));

        // Staff can release holds on another request and cancel Approved / Partially Fulfilled requests.
        $staffCancelled = $this->fulfillmentService->cancelRequest($partial, $staff, 'Hospital cancelled remaining units.');
        $this->assertSame('Cancelled', $staffCancelled->status);
        $this->assertSame(1, $staffCancelled->fulfilled_units);
        $this->assertSame(0, $staffCancelled->reserved_units);
    }

    public function test_request_lifecycle_validation_and_state_guards(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        $hospital = Hospital::factory()->create();
        $coordinator = $this->createHospitalCoordinator($hospital);
        $rbc = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();
        $plasma = BloodComponent::query()->where('code', 'PLS')->firstOrFail();

        // Validation guards on createRequest.
        $this->assertValidationError('required_date', function () use ($hospital, $coordinator, $rbc): void {
            $this->fulfillmentService->createRequest($hospital, $coordinator, [
                'blood_group' => 'O+',
                'blood_component_id' => $rbc->id,
                'requested_units' => 2,
                'request_date' => '2026-11-15',
                'required_date' => '2026-11-14',
                'request_reason' => 'Invalid backward required date.',
            ]);
        });

        $this->assertValidationError('requested_units', function () use ($hospital, $coordinator, $rbc): void {
            $this->fulfillmentService->createRequest($hospital, $coordinator, [
                'blood_group' => 'O+',
                'blood_component_id' => $rbc->id,
                'requested_units' => 0,
                'request_reason' => 'Zero units should fail.',
            ]);
        });

        $this->assertValidationError('request_reason', function () use ($hospital, $coordinator, $rbc): void {
            $this->fulfillmentService->createRequest($hospital, $coordinator, [
                'blood_group' => 'O+',
                'blood_component_id' => $rbc->id,
                'requested_units' => 1,
                'request_reason' => '   ',
            ]);
        });

        $request = $this->fulfillmentService->createRequest($hospital, $coordinator, [
            'blood_group' => 'O+',
            'blood_component_id' => $rbc->id,
            'requested_units' => 3,
            'priority' => 'Normal',
            'request_date' => '2026-11-12',
            'required_date' => '2026-11-14',
            'request_reason' => 'Trauma ICU readiness.',
        ]);

        // Pre-reservation Pending updates can freely adjust blood_group, component, priority, and dates.
        $updatedPending = $this->fulfillmentService->updateRequest($request, $staff, [
            'blood_group' => 'A+',
            'blood_component_id' => $plasma->id,
            'priority' => 'Critical',
            'requested_units' => 4,
        ]);
        $this->assertSame('A+', $updatedPending->blood_group);
        $this->assertSame($plasma->id, $updatedPending->blood_component_id);
        $this->assertSame('Critical', $updatedPending->priority);

        // Cannot reserve or dispatch while Pending.
        $this->assertValidationError('status', fn (): EloquentCollection => $this->fulfillmentService->reserveUnits($updatedPending, $staff, 1));
        $this->assertValidationError('status', fn (): RequestFulfillment => $this->fulfillmentService->dispatchUnits($updatedPending, $staff, ['quantity' => 1]));

        // Approval only works from Pending; re-approving fails.
        $approved = $this->fulfillmentService->approveRequest($updatedPending, $staff, 'Cleared for allocation.');
        $this->assertSame($staff->id, $approved->reviewed_by_user_id);
        $this->assertNotNull($approved->reviewed_at);

        $this->assertValidationError('status', fn (): BloodRequest => $this->fulfillmentService->approveRequest($approved, $admin));

        // Release 3 A+ PLASMA units and reserve 2 of them.
        $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'A+', 'PLS', 3);
        $this->fulfillmentService->reserveUnits($approved, $staff, 2);

        $withHold = $approved->fresh();

        // Dispatch 1 unit -> Partially Fulfilled (fulfilled=1, reserved=1, requested=4).
        $this->fulfillmentService->dispatchUnits($withHold, $staff, ['quantity' => 1, 'dispatched_blood_group' => 'A+']);
        $partial = $withHold->fresh();
        $this->assertSame('Partially Fulfilled', $partial->status);
        $this->assertSame(1, $partial->fulfilled_units);
        $this->assertSame(1, $partial->reserved_units);

        // Cannot reject after dispatch has started.
        $this->assertValidationError('status', fn (): BloodRequest => $this->fulfillmentService->rejectRequest($partial, $staff, 'Cannot reject after partial dispatch.'));

        // Update guards while Partially Fulfilled with reserved=1, fulfilled=1:
        // - Lowering requested_units below fulfilled + reserved (2) rejected.
        // - Changing blood_group or blood_component_id rejected.
        // - Lowering requested_units to 2 succeeds.
        $this->assertValidationError('requested_units', fn (): BloodRequest => $this->fulfillmentService->updateRequest($partial, $staff, ['requested_units' => 1]));
        $this->assertValidationError('blood_group', fn (): BloodRequest => $this->fulfillmentService->updateRequest($partial, $staff, ['blood_group' => 'B+']));
        $this->assertValidationError('blood_component_id', fn (): BloodRequest => $this->fulfillmentService->updateRequest($partial, $staff, ['blood_component_id' => $rbc->id]));
        $this->assertValidationError('required_date', fn (): BloodRequest => $this->fulfillmentService->updateRequest($partial, $staff, ['required_date' => '2026-11-11']));

        $reduced = $this->fulfillmentService->updateRequest($partial, $staff, ['requested_units' => 2]);
        $this->assertSame(2, $reduced->requested_units);

        // Dispatch remaining 1 unit -> Fulfilled. Terminal status blocks updates, rejection, reservation, dispatch, and cancellation.
        $this->fulfillmentService->dispatchUnits($reduced, $staff, ['quantity' => 1, 'dispatched_blood_group' => 'A+']);
        $fulfilled = $reduced->fresh();
        $this->assertSame('Fulfilled', $fulfilled->status);

        $this->assertValidationError('status', fn (): BloodRequest => $this->fulfillmentService->updateRequest($fulfilled, $staff, ['requested_units' => 5]));
        $this->assertValidationError('status', fn (): BloodRequest => $this->fulfillmentService->cancelRequest($fulfilled, $admin, 'Already fulfilled.'));
        $this->assertValidationError('status', fn (): BloodRequest => $this->fulfillmentService->rejectRequest($fulfilled, $admin, 'Already fulfilled.'));
    }

    public function test_compatibility_matrix_and_medical_override_accountability(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $hospital = Hospital::factory()->create();

        $rbc = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();

        // Release O- RBC (3 units) and O+ RBC (2 units).
        $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O-', 'PRBC', 3);
        $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O+', 'PRBC', 2);

        $request = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'A+',
            'blood_component_id' => $rbc->id,
            'requested_units' => 4,
            'request_reason' => 'Cross-group emergency testing.',
        ]);
        $request = $this->fulfillmentService->approveRequest($request, $staff);

        // 1. Only 40 Exact Match rules exist initially -> O- substitution rejected for A+ request.
        $this->assertValidationError('source_blood_group', function () use ($request, $staff): void {
            $this->fulfillmentService->reserveUnits($request, $staff, 1, [
                'source_blood_group' => 'O-',
            ]);
        });
        $this->assertValidationError('dispatched_blood_group', function () use ($request, $staff): void {
            $this->fulfillmentService->dispatchUnits($request, $staff, [
                'quantity' => 1,
                'dispatched_blood_group' => 'O-',
            ]);
        });

        // 2. Compatible Substitution WITHOUT medical override -> staff reserves & dispatches cleanly with null override audit fields.
        BloodCompatibilityRule::query()->create([
            'recipient_blood_group' => 'A+',
            'donor_blood_group' => 'O-',
            'blood_component_id' => $rbc->id,
            'compatibility_level' => 'Compatible Substitution',
            'requires_medical_override' => false,
            'is_active' => true,
            'notes' => 'Universal RBC substitution cleared without override.',
        ]);

        $compatibleAllocations = $this->fulfillmentService->reserveUnits($request, $staff, 1, [
            'source_blood_group' => 'O-',
        ]);
        $this->assertCount(1, $compatibleAllocations);

        $compatibleDispatch = $this->fulfillmentService->dispatchUnits($request->fresh(), $staff, [
            'quantity' => 1,
            'dispatched_blood_group' => 'O-',
        ]);
        $this->assertFalse($compatibleDispatch->is_compatibility_override);
        $this->assertNull($compatibleDispatch->override_approved_by_user_id);
        $this->assertNull($compatibleDispatch->override_reason);
        $this->assertSame($staff->id, $compatibleDispatch->dispatched_by_user_id);

        // 3. Emergency Only / requires_medical_override=true rule -> enforced on BOTH reserveUnits and dispatchUnits.
        BloodCompatibilityRule::query()->create([
            'recipient_blood_group' => 'A+',
            'donor_blood_group' => 'O+',
            'blood_component_id' => $rbc->id,
            'compatibility_level' => 'Emergency Only',
            'requires_medical_override' => true,
            'is_active' => true,
            'notes' => 'Emergency life-saving RBC substitution requiring medical officer approval.',
        ]);

        // Staff alone lacks requests.override_compatibility on reserve & dispatch.
        $this->assertUnauthorized(fn (): EloquentCollection => $this->fulfillmentService->reserveUnits($request->fresh(), $staff, 1, [
            'source_blood_group' => 'O+',
            'override_reason' => 'Urgent hemorrhage protocol.',
        ]));
        $this->assertUnauthorized(fn (): RequestFulfillment => $this->fulfillmentService->dispatchUnits($request->fresh(), $staff, [
            'quantity' => 1,
            'dispatched_blood_group' => 'O+',
            'override_reason' => 'Urgent hemorrhage protocol.',
        ]));

        // Passing non-authorized user (staff) as override_approver is also rejected.
        $this->assertUnauthorized(fn (): EloquentCollection => $this->fulfillmentService->reserveUnits($request->fresh(), $staff, 1, [
            'source_blood_group' => 'O+',
            'override_approver' => $staff,
            'override_reason' => 'Attempted non-admin approver.',
        ]));

        // Authorized override actor without override_reason rejected on BOTH reserve and dispatch.
        $this->assertValidationError('override_reason', fn (): EloquentCollection => $this->fulfillmentService->reserveUnits($request->fresh(), $admin, 1, [
            'source_blood_group' => 'O+',
            'override_reason' => '   ',
        ]));
        $this->assertValidationError('override_reason', fn (): RequestFulfillment => $this->fulfillmentService->dispatchUnits($request->fresh(), $admin, [
            'quantity' => 1,
            'dispatched_blood_group' => 'O+',
        ]));

        // Staff executes reserve & co-signed dispatch with admin override_approver -> stores full override accountability.
        $this->fulfillmentService->reserveUnits($request->fresh(), $staff, 1, [
            'source_blood_group' => 'O+',
            'override_approver' => $admin,
            'override_reason' => 'Mass casualty ER escalation signed off by CMO.',
        ]);

        $cosignedDispatch = $this->fulfillmentService->dispatchUnits($request->fresh(), $staff, [
            'quantity' => 1,
            'dispatched_blood_group' => 'O+',
            'override_approver' => $admin,
            'override_reason' => 'Mass casualty ER escalation signed off by CMO.',
        ]);

        $this->assertTrue($cosignedDispatch->is_compatibility_override);
        $this->assertSame($admin->id, $cosignedDispatch->override_approved_by_user_id);
        $this->assertSame('Mass casualty ER escalation signed off by CMO.', $cosignedDispatch->override_reason);
        $this->assertSame($staff->id, $cosignedDispatch->dispatched_by_user_id);

        // Admin self-authorized override without separate override_approver also succeeds.
        $adminSelfDispatch = $this->fulfillmentService->dispatchUnits($request->fresh(), $admin, [
            'quantity' => 1,
            'dispatched_blood_group' => 'O+',
            'override_reason' => 'Night-shift medical director direct emergency release.',
        ]);

        $this->assertTrue($adminSelfDispatch->is_compatibility_override);
        $this->assertSame($admin->id, $adminSelfDispatch->override_approved_by_user_id);
        $this->assertSame($admin->id, $adminSelfDispatch->dispatched_by_user_id);
    }

    public function test_component_specific_and_component_unspecified_requests(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $hospital = Hospital::factory()->create();

        $rbc = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();
        $plasma = BloodComponent::query()->where('code', 'PLS')->firstOrFail();

        $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'B+', 'PRBC', 2);
        $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'B+', 'PLS', 2);

        // Component-specific request enforces exact component match.
        $specificRequest = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'B+',
            'blood_component_id' => $rbc->id,
            'requested_units' => 2,
            'request_reason' => 'Packed red cell transfusion.',
        ]);
        $specificRequest = $this->fulfillmentService->approveRequest($specificRequest, $staff);

        $this->assertValidationError('blood_component_id', fn (): EloquentCollection => $this->fulfillmentService->reserveUnits($specificRequest, $staff, 1, [
            'blood_component_id' => $plasma->id,
        ]));
        $this->assertValidationError('blood_component_id', fn (): RequestFulfillment => $this->fulfillmentService->dispatchUnits($specificRequest, $staff, [
            'quantity' => 1,
            'blood_component_id' => $plasma->id,
        ]));

        // Component-unspecified request (blood_component_id = null) requires explicit target component and permits multi-component batches.
        $flexibleRequest = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'B+',
            'blood_component_id' => null,
            'requested_units' => 2,
            'request_reason' => 'Flexible surgical component support.',
        ]);
        $flexibleRequest = $this->fulfillmentService->approveRequest($flexibleRequest, $staff);

        $this->assertValidationError('blood_component_id', fn (): EloquentCollection => $this->fulfillmentService->reserveUnits($flexibleRequest, $staff, 1));
        $this->assertValidationError('blood_component_id', fn (): RequestFulfillment => $this->fulfillmentService->dispatchUnits($flexibleRequest, $staff, ['quantity' => 1]));

        $batch1 = $this->fulfillmentService->dispatchUnits($flexibleRequest, $staff, [
            'quantity' => 1,
            'blood_component_id' => $rbc->id,
            'dispatched_blood_group' => 'B+',
        ]);
        $batch2 = $this->fulfillmentService->dispatchUnits($flexibleRequest->fresh(), $staff, [
            'quantity' => 1,
            'blood_component_code' => 'PLS',
            'dispatched_blood_group' => 'B+',
        ]);

        $this->assertNotSame($batch1->id, $batch2->id);
        $this->assertSame($rbc->id, $batch1->blood_component_id);
        $this->assertSame($plasma->id, $batch2->blood_component_id);
        $this->assertSame('Fulfilled', $flexibleRequest->fresh()->status);
    }

    public function test_fifo_reservation_expiry_guard_and_per_unit_dispatch_ledger(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $hospital = Hospital::factory()->create();
        $rbc = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();

        $bucket = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O-', 'PRBC', 4);
        $units = $bucket['units'];
        $stock = $bucket['stock'];

        $asOf = CarbonImmutable::parse('2026-11-12 10:00:00');

        // Stagger expiry dates so FIFO order is: $units[2] -> $units[0] -> $units[3], while $units[1] is expired.
        $units[1]->update(['expires_at' => $asOf->subDay()]);
        $units[2]->update(['expires_at' => $asOf->addDays(2)]);
        $units[0]->update(['expires_at' => $asOf->addDays(5)]);
        $units[3]->update(['expires_at' => $asOf->addDays(10)]);

        $request = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'O-',
            'blood_component_id' => $rbc->id,
            'requested_units' => 3,
            'request_reason' => 'FIFO order and dispatch ledger check.',
        ]);
        $request = $this->fulfillmentService->approveRequest($request, $staff);

        // Reserve 1 unit -> must select non-expired earliest-expiring unit ($units[2]), skipping expired $units[1].
        $allocations = $this->fulfillmentService->reserveUnits($request, $staff, 1, ['as_of' => $asOf]);
        $this->assertCount(1, $allocations);
        $this->assertSame($units[2]->id, $allocations->first()->blood_unit_id);

        // If reserved unit expires before dispatch, dispatch fails cleanly with expires_at.
        $this->assertValidationError('expires_at', function () use ($request, $staff, $asOf): void {
            $this->fulfillmentService->dispatchUnits($request->fresh(), $staff, [
                'quantity' => 1,
                'dispatched_blood_group' => 'O-',
                'as_of' => $asOf->addDays(3),
            ]);
        });

        // Dispatch 2 units as of $asOf -> consumes held $units[2] first + next FIFO available ($units[0]).
        $fulfillment = $this->fulfillmentService->dispatchUnits($request->fresh(), $staff, [
            'quantity' => 2,
            'dispatched_blood_group' => 'O-',
            'courier_reference' => 'CR-2026-881',
            'as_of' => $asOf,
        ]);

        $this->assertSame(2, $fulfillment->units_dispatched);
        $this->assertSame('CR-2026-881', $fulfillment->courier_reference);
        $this->assertSame('Dispatched', $units[2]->fresh()->unit_status);
        $this->assertSame('Dispatched', $units[0]->fresh()->unit_status);
        $this->assertSame('Available', $units[3]->fresh()->unit_status);

        $dispatchedAllocations = InventoryAllocation::query()
            ->where('fulfillment_id', $fulfillment->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $dispatchedAllocations);
        $this->assertTrue($dispatchedAllocations->every(static fn (InventoryAllocation $a): bool => $a->allocation_status === 'Dispatched'));

        // Option A: one Dispatch transaction per dispatched unit, each with units_delta = -1 and fulfillment reference.
        $dispatchTransactions = InventoryTransaction::query()
            ->where('operation_type', 'Dispatch')
            ->where('reference_type', 'fulfillment')
            ->where('reference_id', $fulfillment->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $dispatchTransactions);
        $this->assertSame([$units[2]->id, $units[0]->id], $dispatchTransactions->pluck('blood_unit_id')->all());
        foreach ($dispatchTransactions as $txn) {
            $this->assertSame(-1, $txn->units_delta);
        }

        $report = $this->inventoryService->reconcileStock($stock->fresh());
        $this->assertTrue($report['is_reconciled']);
    }

    public function test_multi_bucket_reservations_selective_consumption_and_oversell_prevention(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $hospital = Hospital::factory()->create();
        $rbc = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();

        BloodCompatibilityRule::query()->create([
            'recipient_blood_group' => 'A+',
            'donor_blood_group' => 'O-',
            'blood_component_id' => $rbc->id,
            'compatibility_level' => 'Compatible Substitution',
            'requires_medical_override' => false,
            'is_active' => true,
        ]);

        // Bucket A (A+ RBC): 4 units; Bucket B (O- RBC): 3 units.
        $bucketA = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'A+', 'PRBC', 4);
        $bucketB = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O-', 'PRBC', 3);

        $request = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'A+',
            'blood_component_id' => $rbc->id,
            'requested_units' => 5,
            'request_reason' => 'Multi-bucket selective dispatch test.',
        ]);
        $request = $this->fulfillmentService->approveRequest($request, $staff);

        // Reserve 2 from Bucket A and 2 from Bucket B -> reserved_units = 4, unallocatedRemaining = 1.
        $allocationsA = $this->fulfillmentService->reserveUnits($request, $staff, 2, ['source_blood_group' => 'A+']);
        $allocationsB = $this->fulfillmentService->reserveUnits($request->fresh(), $staff, 2, ['source_blood_group' => 'O-']);

        $afterHolds = $request->fresh();
        $this->assertSame(4, $afterHolds->reserved_units);
        $this->assertSame(0, $afterHolds->fulfilled_units);

        // Oversell guard: Bucket A has 2 holds + 2 Available, and unallocatedRemaining = 1.
        // Requesting 4 from Bucket A needs 2 extra Available units -> 2 > 1 -> rejected without mutating Bucket B!
        $this->assertValidationError('quantity', fn (): RequestFulfillment => $this->fulfillmentService->dispatchUnits($afterHolds, $staff, [
            'quantity' => 4,
            'dispatched_blood_group' => 'A+',
        ]));

        // Dispatching 3 from Bucket A consumes its 2 Reserved holds + 1 Available unit (within unallocatedRemaining=1).
        $batchA = $this->fulfillmentService->dispatchUnits($afterHolds, $staff, [
            'quantity' => 3,
            'dispatched_blood_group' => 'A+',
        ]);
        $this->assertSame(3, $batchA->units_dispatched);

        $midState = $request->fresh();
        $this->assertSame('Partially Fulfilled', $midState->status);
        $this->assertSame(3, $midState->fulfilled_units);
        $this->assertSame(2, $midState->reserved_units);
        $this->assertLessThanOrEqual($midState->requested_units, $midState->fulfilled_units + $midState->reserved_units);

        // Verify Bucket B Reserved holds are still intact.
        foreach ($allocationsB as $allocationB) {
            $this->assertSame('Reserved', $allocationB->fresh()->allocation_status);
        }
        foreach ($allocationsA as $allocationA) {
            $freshAllocA = $allocationA->fresh();
            $this->assertSame('Dispatched', $freshAllocA->allocation_status);
            $this->assertSame($batchA->id, $freshAllocA->fulfillment_id);
        }

        // Complete request from Bucket B (2 Reserved units) -> separate RequestFulfillment batch.
        $batchB = $this->fulfillmentService->dispatchUnits($midState, $staff, [
            'quantity' => 2,
            'dispatched_blood_group' => 'O-',
        ]);

        $finalState = $request->fresh();
        $this->assertNotSame($batchA->id, $batchB->id);
        $this->assertSame('O-', $batchB->dispatched_blood_group);
        $this->assertSame('Fulfilled', $finalState->status);
        $this->assertSame(5, $finalState->fulfilled_units);
        $this->assertSame(0, $finalState->reserved_units);

        $this->assertTrue($this->inventoryService->reconcileStock($bucketA['stock']->fresh())['is_reconciled']);
        $this->assertTrue($this->inventoryService->reconcileStock($bucketB['stock']->fresh())['is_reconciled']);
    }

    public function test_multi_bucket_cancellation_and_pre_dispatch_rejection_cleanup(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $hospital = Hospital::factory()->create();
        $rbc = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();

        BloodCompatibilityRule::query()->create([
            'recipient_blood_group' => 'A+',
            'donor_blood_group' => 'O-',
            'blood_component_id' => $rbc->id,
            'compatibility_level' => 'Compatible Substitution',
            'requires_medical_override' => false,
            'is_active' => true,
        ]);

        $bucketA = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'A+', 'PRBC', 3);
        $bucketB = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O-', 'PRBC', 2);

        // Scenario A: Pre-dispatch rejection of an Approved request holding units across 2 buckets.
        $rejectedCandidate = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'A+',
            'blood_component_id' => $rbc->id,
            'requested_units' => 2,
            'request_reason' => 'Pre-dispatch hold release verification.',
        ]);
        $rejectedCandidate = $this->fulfillmentService->approveRequest($rejectedCandidate, $staff);
        $holdA = $this->fulfillmentService->reserveUnits($rejectedCandidate, $staff, 1, ['source_blood_group' => 'A+']);
        $holdB = $this->fulfillmentService->reserveUnits($rejectedCandidate->fresh(), $staff, 1, ['source_blood_group' => 'O-']);

        $rejected = $this->fulfillmentService->rejectRequest($rejectedCandidate->fresh(), $staff, 'Hospital withdrew request before dispatch.');
        $this->assertSame('Rejected', $rejected->status);
        $this->assertSame(0, $rejected->reserved_units);
        $this->assertSame('Released', $holdA->first()->fresh()->allocation_status);
        $this->assertSame('Released', $holdB->first()->fresh()->allocation_status);
        $this->assertSame('Available', BloodUnit::query()->findOrFail($holdA->first()->blood_unit_id)->unit_status);
        $this->assertSame('Available', BloodUnit::query()->findOrFail($holdB->first()->blood_unit_id)->unit_status);

        // Scenario B: Partially Fulfilled cancellation across multiple held buckets.
        $mainRequest = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'A+',
            'blood_component_id' => $rbc->id,
            'requested_units' => 4,
            'request_reason' => 'Partial dispatch + multi-bucket cancel verification.',
        ]);
        $mainRequest = $this->fulfillmentService->approveRequest($mainRequest, $staff);

        // Reserve 2 A+ and 1 O-. Dispatch 2 A+ (consumes both A+ holds, then reserve 1 remaining A+ and keep 1 O- held).
        $this->fulfillmentService->reserveUnits($mainRequest, $staff, 2, ['source_blood_group' => 'A+']);
        $this->fulfillmentService->reserveUnits($mainRequest->fresh(), $staff, 1, ['source_blood_group' => 'O-']);
        $batch = $this->fulfillmentService->dispatchUnits($mainRequest->fresh(), $staff, [
            'quantity' => 2,
            'dispatched_blood_group' => 'A+',
        ]);
        $this->fulfillmentService->reserveUnits($mainRequest->fresh(), $staff, 1, ['source_blood_group' => 'A+']);

        $beforeCancel = $mainRequest->fresh();
        $this->assertSame('Partially Fulfilled', $beforeCancel->status);
        $this->assertSame(2, $beforeCancel->fulfilled_units);
        $this->assertSame(2, $beforeCancel->reserved_units);

        $cancelled = $this->fulfillmentService->cancelRequest($beforeCancel, $staff, 'Patient transferred out before remaining units needed.');

        $this->assertSame('Cancelled', $cancelled->status);
        $this->assertSame(2, $cancelled->fulfilled_units);
        $this->assertSame(0, $cancelled->reserved_units);
        $this->assertSame($staff->id, $cancelled->cancelled_by_user_id);

        // Already Dispatched allocations and BloodUnits remain Dispatched; remaining holds across both buckets are Released & Available.
        $dispatchedAllocations = InventoryAllocation::query()
            ->where('blood_request_id', $cancelled->id)
            ->where('fulfillment_id', $batch->id)
            ->get();
        $this->assertCount(2, $dispatchedAllocations);
        foreach ($dispatchedAllocations as $dispatchedAllocation) {
            $this->assertSame('Dispatched', $dispatchedAllocation->allocation_status);
            $this->assertSame('Dispatched', $dispatchedAllocation->bloodUnit->unit_status);
        }

        $releasedAllocations = InventoryAllocation::query()
            ->where('blood_request_id', $cancelled->id)
            ->where('allocation_status', 'Released')
            ->get();
        $this->assertCount(2, $releasedAllocations);
        foreach ($releasedAllocations as $releasedAllocation) {
            $this->assertSame('Available', $releasedAllocation->bloodUnit->unit_status);
        }

        // Released BloodUnits can immediately be reserved & dispatched for a subsequent BloodRequest.
        $nextRequest = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'A+',
            'blood_component_id' => $rbc->id,
            'requested_units' => 1,
            'request_reason' => 'Re-allocation of released unit.',
        ]);
        $nextRequest = $this->fulfillmentService->approveRequest($nextRequest, $staff);
        $reallocated = $this->fulfillmentService->reserveUnits($nextRequest, $staff, 1, [
            'blood_unit_ids' => [$releasedAllocations->firstWhere('inventory_stock_id', $bucketA['stock']->id)->blood_unit_id],
        ]);
        $this->assertCount(1, $reallocated);

        // Verify complete BloodRequestStatusHistory trail for $cancelled.
        $events = BloodRequestStatusHistory::query()
            ->where('blood_request_id', $cancelled->id)
            ->orderBy('id')
            ->pluck('event_name')
            ->all();
        $this->assertSame([
            'Request Submitted',
            'Request Approved',
            'Units Reserved',
            'Units Reserved',
            'Partial Dispatch Recorded',
            'Units Reserved',
            'Request Cancelled',
        ], $events);

        // Both inventory buckets pass full ledger reconciliation.
        $this->assertTrue($this->inventoryService->reconcileStock($bucketA['stock']->fresh())['is_reconciled']);
        $this->assertTrue($this->inventoryService->reconcileStock($bucketB['stock']->fresh())['is_reconciled']);
    }

    public function test_stale_model_guards_and_rollback_atomicity_on_failure(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $hospital = Hospital::factory()->create();
        $coordinator = $this->createHospitalCoordinator($hospital);
        $rbc = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();

        $bucket = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'AB+', 'PRBC', 2);

        $staleRequest = $this->fulfillmentService->createRequest($hospital, $coordinator, [
            'blood_group' => 'AB+',
            'blood_component_id' => $rbc->id,
            'requested_units' => 2,
            'request_reason' => 'Stale concurrency check.',
        ]);

        // Staff approves concurrently while coordinator still holds stale 'Pending' model instance.
        $approved = $this->fulfillmentService->approveRequest($staleRequest->fresh(), $staff);
        $this->assertSame('Approved', $approved->status);

        // Coordinator attempts cancelRequest using stale Pending instance -> post-lock re-authorization rejects with AuthorizationException.
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->cancelRequest($staleRequest, $coordinator, 'Coordinator cancelling stale copy.'));

        // Staff holding stale Pending instance fails approveRequest cleanly because locked status is Approved.
        $this->assertValidationError('status', fn (): BloodRequest => $this->fulfillmentService->approveRequest($staleRequest, $staff));

        // Mid-transaction rollback check on dispatch failure when held unit expires before dispatch.
        $this->fulfillmentService->reserveUnits($approved, $staff, 1);

        $heldAllocation = InventoryAllocation::query()
            ->where('blood_request_id', $approved->id)
            ->where('allocation_status', 'Reserved')
            ->firstOrFail();

        BloodUnit::query()
            ->whereKey($heldAllocation->blood_unit_id)
            ->update(['expires_at' => CarbonImmutable::parse('2026-11-12 08:00:00')]);

        $stockBefore = $bucket['stock']->fresh();
        $requestBefore = $approved->fresh();
        $txnCountBefore = InventoryTransaction::query()->count();
        $fulfillmentCountBefore = RequestFulfillment::query()->count();
        $historyCountBefore = BloodRequestStatusHistory::query()->count();

        $this->assertValidationError('expires_at', function () use ($approved, $staff): void {
            $this->fulfillmentService->dispatchUnits($approved->fresh(), $staff, [
                'quantity' => 2,
                'dispatched_blood_group' => 'AB+',
                'as_of' => CarbonImmutable::parse('2026-11-12 12:00:00'),
            ]);
        });

        $this->assertSame($stockBefore->available_units, $bucket['stock']->fresh()->available_units);
        $this->assertSame($stockBefore->reserved_units, $bucket['stock']->fresh()->reserved_units);
        $this->assertSame($requestBefore->fulfilled_units, $approved->fresh()->fulfilled_units);
        $this->assertSame($requestBefore->reserved_units, $approved->fresh()->reserved_units);
        $this->assertSame($requestBefore->status, $approved->fresh()->status);
        $this->assertSame($txnCountBefore, InventoryTransaction::query()->count());
        $this->assertSame($fulfillmentCountBefore, RequestFulfillment::query()->count());
        $this->assertSame($historyCountBefore, BloodRequestStatusHistory::query()->count());
    }

    public function test_partial_and_full_release_of_reserved_allocations(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $hospital = Hospital::factory()->create();
        $coordinator = $this->createHospitalCoordinator($hospital);
        $rbc = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();

        $bucket = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O+', 'PRBC', 3);

        $request = $this->fulfillmentService->createRequest($hospital, $coordinator, [
            'blood_group' => 'O+',
            'blood_component_id' => $rbc->id,
            'requested_units' => 3,
            'request_reason' => 'Selective reservation release test.',
        ]);
        $request = $this->fulfillmentService->approveRequest($request, $staff);
        $allocations = $this->fulfillmentService->reserveUnits($request, $staff, 3);

        // Unauthorized coordinator cannot release holds.
        $this->assertUnauthorized(fn (): BloodRequest => $this->fulfillmentService->releaseReservedAllocations($request->fresh(), $coordinator));

        // Empty array [] is rejected explicitly rather than silently releasing everything.
        $this->assertValidationError('allocation_ids', fn (): BloodRequest => $this->fulfillmentService->releaseReservedAllocations($request->fresh(), $staff, []));

        // Release only the first allocation -> remaining 2 stay Reserved.
        $firstAllocation = $allocations->first();
        $afterPartialRelease = $this->fulfillmentService->releaseReservedAllocations(
            $request->fresh(),
            $staff,
            [$firstAllocation->id],
            'Clinical dose reduced by 1 unit.'
        );

        $this->assertSame(2, $afterPartialRelease->reserved_units);
        $this->assertSame('Released', $firstAllocation->fresh()->allocation_status);
        $this->assertSame('Available', BloodUnit::query()->findOrFail($firstAllocation->blood_unit_id)->unit_status);
        $this->assertSame(1, $bucket['stock']->fresh()->available_units);
        $this->assertSame(2, $bucket['stock']->fresh()->reserved_units);

        // Release all remaining holds via null -> reserved_units becomes 0.
        $afterFullRelease = $this->fulfillmentService->releaseReservedAllocations($afterPartialRelease, $staff, null, 'Remaining units cleared.');
        $this->assertSame(0, $afterFullRelease->reserved_units);
        $this->assertSame(3, $bucket['stock']->fresh()->available_units);
        $this->assertSame(0, $bucket['stock']->fresh()->reserved_units);

        // Releasing again when no active holds remain fails cleanly.
        $this->assertValidationError('allocation_status', fn (): BloodRequest => $this->fulfillmentService->releaseReservedAllocations($afterFullRelease, $staff));

        $this->assertTrue($this->inventoryService->reconcileStock($bucket['stock']->fresh())['is_reconciled']);
    }

    private function seedBaselineCatalogs(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            BloodComponentSeeder::class,
            ScreeningTestTypeSeeder::class,
            ComponentScreeningRequirementSeeder::class,
            BloodCompatibilityRuleSeeder::class,
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
            reason: 'Cleared for fulfillment integration test.',
            clearanceSource: 'Medical Officer Clearance',
        );

        return $donor->fresh();
    }

    private function createHospitalCoordinator(
        Hospital $hospital,
        bool $userActive = true,
        bool $linkActive = true
    ): User {
        $user = $this->createUserWithRole('hospital', isActive: $userActive);

        HospitalUser::factory()->create([
            'user_id' => $user->id,
            'hospital_id' => $hospital->id,
            'is_active' => $linkActive,
        ]);

        return $user;
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
