<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\User;
use App\Services\DonationService;
use App\Services\DonorEligibilityService;
use App\Services\FulfillmentService;
use App\Services\ScreeningService;
use Database\Seeders\BloodCompatibilityRuleSeeder;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BloodRequestsUiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private FulfillmentService $fulfillmentService;

    private DonorEligibilityService $eligibilityService;

    private DonationService $donationService;

    private ScreeningService $screeningService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fulfillmentService = app(FulfillmentService::class);
        $this->eligibilityService = app(DonorEligibilityService::class);
        $this->donationService = app(DonationService::class);
        $this->screeningService = app(ScreeningService::class);

        $this->seed([
            RolePermissionSeeder::class,
            BloodComponentSeeder::class,
            BloodCompatibilityRuleSeeder::class,
            ScreeningTestTypeSeeder::class,
            ComponentScreeningRequirementSeeder::class,
        ]);
    }

    public function test_unauthenticated_users_are_redirected_to_login_for_index_and_show(): void
    {
        $hospital = Hospital::factory()->create();
        $staff = $this->createUserWithRole('staff');

        $bloodRequest = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'O+',
            'requested_units' => 2,
            'request_reason' => 'Emergency inventory triage order.',
        ]);

        $this->get(route('requests.index'))->assertRedirect(route('login'));
        $this->get(route('requests.show', $bloodRequest->id))->assertRedirect(route('login'));
        $this->get(route('requests.show', $bloodRequest->request_code))->assertRedirect(route('login'));
    }

    public function test_unauthorized_users_receive_403_forbidden_on_index_and_show(): void
    {
        $hospital = Hospital::factory()->create();
        $staff = $this->createUserWithRole('staff');

        $bloodRequest = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'A+',
            'requested_units' => 2,
            'request_reason' => 'Surgical crossmatch reserve.',
        ]);

        $donor = $this->createUserWithRole('donor');
        $this->actingAs($donor)->get(route('requests.index'))->assertForbidden();
        $this->actingAs($donor)->get(route('requests.show', $bloodRequest->id))->assertForbidden();

        $labOfficer = $this->createUserWithRole('lab_officer');
        $this->actingAs($labOfficer)->get(route('requests.index'))->assertForbidden();
        $this->actingAs($labOfficer)->get(route('requests.show', $bloodRequest->id))->assertForbidden();

        $inactiveStaff = $this->createUserWithRole('staff', false);
        $this->actingAs($inactiveStaff)->get(route('requests.index'))->assertForbidden();
        $this->actingAs($inactiveStaff)->get(route('requests.show', $bloodRequest->id))->assertForbidden();
    }

    public function test_hospital_coordinators_see_only_their_own_facility_requests_in_index(): void
    {
        $hospitalA = Hospital::factory()->create(['name' => 'Metro General Hospital']);
        $hospitalB = Hospital::factory()->create(['name' => 'St. Jude Medical Center']);

        $coordinatorA = $this->createHospitalCoordinator($hospitalA);
        $coordinatorB = $this->createHospitalCoordinator($hospitalB);

        $requestA = $this->fulfillmentService->createRequest($hospitalA, $coordinatorA, [
            'blood_group' => 'A+',
            'requested_units' => 3,
            'request_reason' => 'Trauma wing replenishment for Metro General.',
        ]);

        $requestB = $this->fulfillmentService->createRequest($hospitalB, $coordinatorB, [
            'blood_group' => 'B-',
            'requested_units' => 5,
            'request_reason' => 'Cardiothoracic bypass standby for St. Jude.',
        ]);

        $responseA = $this->actingAs($coordinatorA)->get(route('requests.index'));
        $responseA->assertOk();
        $responseA->assertSee($requestA->request_code);
        $responseA->assertSee('Metro General Hospital');
        $responseA->assertDontSee($requestB->request_code);
        $responseA->assertDontSee('St. Jude Medical Center');

        $responseB = $this->actingAs($coordinatorB)->get(route('requests.index'));
        $responseB->assertOk();
        $responseB->assertSee($requestB->request_code);
        $responseB->assertSee('St. Jude Medical Center');
        $responseB->assertDontSee($requestA->request_code);
        $responseB->assertDontSee('Metro General Hospital');
    }

    public function test_staff_with_fulfill_permission_can_see_all_hospital_requests_in_index(): void
    {
        $hospitalA = Hospital::factory()->create(['name' => 'Northside Health Center']);
        $hospitalB = Hospital::factory()->create(['name' => 'Valley Children Hospital']);

        $staff = $this->createUserWithRole('staff');

        $requestA = $this->fulfillmentService->createRequest($hospitalA, $staff, [
            'blood_group' => 'O-',
            'requested_units' => 4,
            'request_reason' => 'Emergency protocol standby.',
        ]);

        $requestB = $this->fulfillmentService->createRequest($hospitalB, $staff, [
            'blood_group' => 'AB+',
            'requested_units' => 2,
            'request_reason' => 'Pediatric oncology transfusion.',
        ]);

        $response = $this->actingAs($staff)->get(route('requests.index'));
        $response->assertOk();
        $response->assertSee($requestA->request_code);
        $response->assertSee('Northside Health Center');
        $response->assertSee($requestB->request_code);
        $response->assertSee('Valley Children Hospital');
    }

    public function test_summary_counters_are_accurate_and_properly_scoped(): void
    {
        $hospitalA = Hospital::factory()->create(['name' => 'Hospital Alpha']);
        $hospitalB = Hospital::factory()->create(['name' => 'Hospital Beta']);

        $coordinatorA = $this->createHospitalCoordinator($hospitalA);
        $staff = $this->createUserWithRole('staff');

        $reqA1 = $this->fulfillmentService->createRequest($hospitalA, $coordinatorA, [
            'blood_group' => 'A+',
            'requested_units' => 4,
            'priority' => 'High',
            'request_reason' => 'Alpha High Priority order.',
        ]);

        $reqA2 = $this->fulfillmentService->createRequest($hospitalA, $coordinatorA, [
            'blood_group' => 'O+',
            'requested_units' => 6,
            'priority' => 'Normal',
            'request_reason' => 'Alpha Normal order.',
        ]);

        $reqB1 = $this->fulfillmentService->createRequest($hospitalB, $staff, [
            'blood_group' => 'B+',
            'requested_units' => 10,
            'priority' => 'Critical',
            'request_reason' => 'Beta Critical order.',
        ]);

        $responseCoordinator = $this->actingAs($coordinatorA)->get(route('requests.index'));
        $responseCoordinator->assertOk();
        $summaryCoordinator = $responseCoordinator->viewData('summary');

        $this->assertSame(2, $summaryCoordinator['totalRequests']);
        $this->assertSame(2, $summaryCoordinator['pendingRequests']);
        $this->assertSame(1, $summaryCoordinator['urgentRequests']);
        $this->assertSame(0, $summaryCoordinator['fulfilledRequests']);
        $this->assertSame(10, $summaryCoordinator['totalUnitsRequested']);
        $this->assertSame(0, $summaryCoordinator['totalUnitsFulfilled']);

        $responseStaff = $this->actingAs($staff)->get(route('requests.index'));
        $responseStaff->assertOk();
        $summaryStaff = $responseStaff->viewData('summary');

        $this->assertSame(3, $summaryStaff['totalRequests']);
        $this->assertSame(3, $summaryStaff['pendingRequests']);
        $this->assertSame(2, $summaryStaff['urgentRequests']); // High + Critical
        $this->assertSame(0, $summaryStaff['fulfilledRequests']);
        $this->assertSame(20, $summaryStaff['totalUnitsRequested']);
    }

    public function test_index_filtering_by_status_priority_blood_group_and_search(): void
    {
        $hospital = Hospital::factory()->create(['name' => 'Mercy Care Pavilion']);
        $staff = $this->createUserWithRole('staff');

        $reqPending = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'O-',
            'requested_units' => 2,
            'priority' => 'Critical',
            'request_reason' => 'Trauma intake.',
        ]);

        $reqApproved = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'A+',
            'requested_units' => 4,
            'priority' => 'Normal',
            'request_reason' => 'Scheduled surgical order.',
        ]);
        $this->fulfillmentService->approveRequest($reqApproved, $staff, 'Cleared by staff.');

        // Filter by status=Approved
        $responseStatus = $this->actingAs($staff)->get(route('requests.index', ['status' => 'Approved']));
        $responseStatus->assertOk();
        $responseStatus->assertSee($reqApproved->request_code);
        $responseStatus->assertDontSee($reqPending->request_code);

        // Filter by priority=Critical
        $responsePriority = $this->actingAs($staff)->get(route('requests.index', ['priority' => 'Critical']));
        $responsePriority->assertOk();
        $responsePriority->assertSee($reqPending->request_code);
        $responsePriority->assertDontSee($reqApproved->request_code);

        // Filter by blood_group=O-
        $responseGroup = $this->actingAs($staff)->get(route('requests.index', ['blood_group' => 'O-']));
        $responseGroup->assertOk();
        $responseGroup->assertSee($reqPending->request_code);
        $responseGroup->assertDontSee($reqApproved->request_code);

        // Filter by search=Mercy
        $responseSearch = $this->actingAs($staff)->get(route('requests.index', ['search' => 'Mercy Care']));
        $responseSearch->assertOk();
        $responseSearch->assertSee($reqPending->request_code);
        $responseSearch->assertSee($reqApproved->request_code);
    }

    public function test_hospital_coordinator_cannot_view_another_hospitals_request_details_returns_403(): void
    {
        $hospitalA = Hospital::factory()->create(['name' => 'Hospital One']);
        $hospitalB = Hospital::factory()->create(['name' => 'Hospital Two']);

        $coordinatorA = $this->createHospitalCoordinator($hospitalA);
        $coordinatorB = $this->createHospitalCoordinator($hospitalB);

        $requestB = $this->fulfillmentService->createRequest($hospitalB, $coordinatorB, [
            'blood_group' => 'B+',
            'requested_units' => 3,
            'request_reason' => 'Private clinical request for Hospital Two.',
        ]);

        $this->actingAs($coordinatorA)
            ->get(route('requests.show', $requestB->id))
            ->assertForbidden();

        $this->actingAs($coordinatorA)
            ->get(route('requests.show', $requestB->request_code))
            ->assertForbidden();
    }

    public function test_authorized_actors_can_view_persisted_request_details_with_timeline_history(): void
    {
        $hospital = Hospital::factory()->create([
            'name' => 'City Hospital Center',
            'primary_phone' => '+1-555-010-9900',
            'primary_email' => 'transfusion@cityhospital.org',
            'address' => '500 Health Blvd, Wing 3A',
        ]);
        $coordinator = $this->createHospitalCoordinator($hospital);
        $staff = $this->createUserWithRole('staff');

        $bloodRequest = $this->fulfillmentService->createRequest($hospital, $coordinator, [
            'blood_group' => 'AB-',
            'requested_units' => 4,
            'priority' => 'High',
            'request_reason' => 'Urgent orthopedic trauma replacement.',
            'additional_notes' => 'Cold box delivery required before 08:00.',
        ]);

        $responseCoordinator = $this->actingAs($coordinator)->get(route('requests.show', $bloodRequest->request_code));
        $responseCoordinator->assertOk();
        $responseCoordinator->assertSee($bloodRequest->request_code);
        $responseCoordinator->assertSee('City Hospital Center');
        $responseCoordinator->assertSee('AB-');
        $responseCoordinator->assertSee('4 units');
        $responseCoordinator->assertSee('Urgent orthopedic trauma replacement.');
        $responseCoordinator->assertSee('Request Submitted');

        $responseStaff = $this->actingAs($staff)->get(route('requests.show', $bloodRequest->id));
        $responseStaff->assertOk();
        $responseStaff->assertSee($bloodRequest->request_code);
        $responseStaff->assertSee('Approve Request');
        $responseStaff->assertSee('Reject Request');
    }

    public function test_staff_can_approve_pending_request_and_timeline_updates(): void
    {
        $hospital = Hospital::factory()->create();
        $staff = $this->createUserWithRole('staff');

        $bloodRequest = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'A+',
            'requested_units' => 2,
            'request_reason' => 'Standby surgical reserve.',
        ]);

        $this->assertSame('Pending', $bloodRequest->status);

        $response = $this->actingAs($staff)->post(route('requests.approve', $bloodRequest->id), [
            'notes' => 'Crossmatching cleared by senior technician.',
        ]);

        $response->assertRedirect(route('requests.index'));
        $response->assertSessionHas('success');

        $bloodRequest->refresh();
        $this->assertSame('Approved', $bloodRequest->status);
        $this->assertSame($staff->id, $bloodRequest->reviewed_by_user_id);
        $this->assertNotNull($bloodRequest->reviewed_at);

        $latestHistory = $bloodRequest->statusHistories()->latest('id')->first();
        $this->assertNotNull($latestHistory);
        $this->assertSame('Approved', $latestHistory->new_status);
        $this->assertStringContainsString('Crossmatching cleared', $latestHistory->notes);
    }

    public function test_staff_can_reject_pending_request_with_mandatory_rationale(): void
    {
        $hospital = Hospital::factory()->create();
        $staff = $this->createUserWithRole('staff');

        $bloodRequest = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'B+',
            'requested_units' => 3,
            'request_reason' => 'Emergency elective order.',
        ]);

        // Attempt rejection without rationale -> validation fails
        $failResponse = $this->actingAs($staff)->post(route('requests.reject', $bloodRequest->id), []);
        $failResponse->assertSessionHasErrors('rejection_reason');
        $bloodRequest->refresh();
        $this->assertSame('Pending', $bloodRequest->status);

        // Submit valid rejection
        $successResponse = $this->actingAs($staff)->post(route('requests.reject', $bloodRequest->id), [
            'rejection_reason' => 'Duplicate order submitted under clinical protocol.',
        ]);

        $successResponse->assertRedirect(route('requests.index'));
        $successResponse->assertSessionHas('success');

        $bloodRequest->refresh();
        $this->assertSame('Rejected', $bloodRequest->status);
        $this->assertSame('Duplicate order submitted under clinical protocol.', $bloodRequest->rejection_reason);
    }

    public function test_hospital_coordinator_can_cancel_their_own_pending_request_but_not_others(): void
    {
        $hospitalA = Hospital::factory()->create();
        $hospitalB = Hospital::factory()->create();

        $coordinatorA = $this->createHospitalCoordinator($hospitalA);
        $coordinatorB = $this->createHospitalCoordinator($hospitalB);

        $requestA = $this->fulfillmentService->createRequest($hospitalA, $coordinatorA, [
            'blood_group' => 'O+',
            'requested_units' => 2,
            'request_reason' => 'Hospital A order to be cancelled.',
        ]);

        $requestB = $this->fulfillmentService->createRequest($hospitalB, $coordinatorB, [
            'blood_group' => 'O-',
            'requested_units' => 2,
            'request_reason' => 'Hospital B order.',
        ]);

        // Coordinator A cannot cancel Hospital B request
        $this->actingAs($coordinatorA)
            ->post(route('requests.cancel', $requestB->id), [
                'cancellation_reason' => 'Unauthorized cancellation attempt.',
            ])
            ->assertForbidden();

        // Coordinator A cancels their own request with valid rationale
        $response = $this->actingAs($coordinatorA)->post(route('requests.cancel', $requestA->id), [
            'cancellation_reason' => 'Patient transferred to regional burn facility.',
        ]);

        $response->assertRedirect(route('requests.index'));
        $response->assertSessionHas('success');

        $requestA->refresh();
        $this->assertSame('Cancelled', $requestA->status);
        $this->assertSame($coordinatorA->id, $requestA->cancelled_by_user_id);
        $this->assertNotNull($requestA->cancelled_at);
        $this->assertSame('Patient transferred to regional burn facility.', $requestA->cancellation_reason);
    }

    public function test_staff_can_dispatch_units_against_approved_request(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();

        // Seed inventory unit
        $this->seedScreenedUnits($admin, $staff, $labOfficer, 'A+', 'WB', 2);

        $bloodRequest = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'A+',
            'blood_component_id' => $component->id,
            'requested_units' => 2,
            'request_reason' => 'Urgent orthopedic procedure.',
        ]);

        $this->fulfillmentService->approveRequest($bloodRequest, $staff, 'Cleared for fulfillment.');

        $dispatchResponse = $this->actingAs($staff)->post(route('requests.dispatch', $bloodRequest->id), [
            'quantity' => 1,
            'dispatched_blood_group' => 'A+',
            'courier_reference' => 'COURIER-TR101',
            'notes' => 'Cold chain verified at 4C.',
        ]);

        $dispatchResponse->assertRedirect(route('requests.index'));
        $dispatchResponse->assertSessionHas('success');

        $bloodRequest->refresh();
        $this->assertSame('Partially Fulfilled', $bloodRequest->status);
        $this->assertSame(1, $bloodRequest->fulfilled_units);
        $this->assertCount(1, $bloodRequest->fulfillments);
        $this->assertSame('COURIER-TR101', $bloodRequest->fulfillments->first()->courier_reference);
    }

    public function test_state_aware_restrictions_prevent_modifying_terminal_requests(): void
    {
        $hospital = Hospital::factory()->create();
        $staff = $this->createUserWithRole('staff');

        $bloodRequest = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'O+',
            'requested_units' => 2,
            'request_reason' => 'Test terminal request constraints.',
        ]);

        $this->fulfillmentService->rejectRequest($bloodRequest, $staff, 'Clinical rejection.');
        $bloodRequest->refresh();
        $this->assertSame('Rejected', $bloodRequest->status);

        // Attempting to approve rejected request fails
        $response = $this->actingAs($staff)->post(route('requests.approve', $bloodRequest->id), [
            'notes' => 'Invalid attempt to approve rejected request.',
        ]);
        $response->assertSessionHasErrors('status');

        // Attempting to edit rejected request redirects back with error
        $editResponse = $this->actingAs($staff)->get(route('requests.edit', $bloodRequest->id));
        $editResponse->assertRedirect(route('requests.show', $bloodRequest->request_code));
        $editResponse->assertSessionHas('error');
    }

    public function test_request_details_page_displays_inventory_allocations_and_empty_state(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();

        $bloodRequest = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'A+',
            'blood_component_id' => $component->id,
            'requested_units' => 2,
            'request_reason' => 'Emergency surgical allocation display test.',
        ]);

        // 1. Initial empty state: no allocations
        $emptyResponse = $this->actingAs($staff)->get(route('requests.show', $bloodRequest->id));
        $emptyResponse->assertOk();
        $emptyResponse->assertSee('Inventory Allocations');
        $emptyResponse->assertSee('0 allocations');
        $emptyResponse->assertSee('No inventory unit allocations recorded yet.');

        // 2. Approve and reserve units
        $this->seedScreenedUnits($admin, $staff, $labOfficer, 'A+', 'WB', 2);
        $this->fulfillmentService->approveRequest($bloodRequest, $staff, 'Approved for reservation.');

        $allocations = $this->fulfillmentService->reserveUnits($bloodRequest, $staff, 1);
        $allocation = $allocations->first();
        $allocationCode = $allocation->allocation_code;
        $unitNumber = $allocation->bloodUnit->unit_number;

        $allocatedResponse = $this->actingAs($staff)->get(route('requests.show', $bloodRequest->id));
        $allocatedResponse->assertOk();
        $allocatedResponse->assertSee('Inventory Allocations');
        $allocatedResponse->assertSee('1 allocations');
        $allocatedResponse->assertSee($allocationCode);
        $allocatedResponse->assertSee('Reserved');
        $allocatedResponse->assertSee($unitNumber);
        $allocatedResponse->assertSee('A+');
        $allocatedResponse->assertSee('1 unit(s) allocated');

        // 3. Release reserved allocations and verify Released state rendering
        $this->fulfillmentService->releaseReservedAllocations($bloodRequest, $staff, null, 'Released for UI test.');

        $releasedResponse = $this->actingAs($staff)->get(route('requests.show', $bloodRequest->id));
        $releasedResponse->assertOk();
        $releasedResponse->assertSee('Released');
    }

    public function test_fulfillment_blood_component_is_eager_loaded_without_n_plus_one_queries(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();

        $this->seedScreenedUnits($admin, $staff, $labOfficer, 'O+', 'WB', 3);

        $bloodRequest = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'O+',
            'blood_component_id' => $component->id,
            'requested_units' => 3,
            'request_reason' => 'Multiple fulfillments N+1 verification test.',
        ]);

        $this->fulfillmentService->approveRequest($bloodRequest, $staff, 'Approved for dispatch.');

        // Dispatch 2 separate fulfillments
        $this->actingAs($staff)->post(route('requests.dispatch', $bloodRequest->id), [
            'quantity' => 1,
            'dispatched_blood_group' => 'O+',
            'courier_reference' => 'BATCH-ALPHA',
            'notes' => 'First dispatch batch.',
        ]);

        $this->actingAs($staff)->post(route('requests.dispatch', $bloodRequest->id), [
            'quantity' => 1,
            'dispatched_blood_group' => 'O+',
            'courier_reference' => 'BATCH-BETA',
            'notes' => 'Second dispatch batch.',
        ]);

        $bloodRequest->refresh();
        $this->assertCount(2, $bloodRequest->fulfillments);

        // Record query log during show invocation
        DB::flushQueryLog();
        DB::enableQueryLog();

        $showResponse = $this->actingAs($staff)->get(route('requests.show', $bloodRequest->id));
        $showResponse->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // In SQLite/MySQL, queries selecting from blood_components should be at most 2:
        // 1 for the BloodRequest's bloodComponent, and 1 for fulfillments.bloodComponent in a batch IN (...) query.
        // If an N+1 query occurred, each fulfillment would trigger a distinct SELECT on blood_components (total >= 3).
        $componentQueries = collect($queries)->filter(function (array $query): bool {
            return str_contains(strtolower($query['query']), 'blood_components');
        });

        $this->assertLessThanOrEqual(2, $componentQueries->count(), 'Expected fulfillments.bloodComponent to be eager-loaded without N+1 queries.');
    }

    /**
     * @return array{stock: InventoryStock, units: Collection}
     */
    private function seedScreenedUnits(
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
                reason: 'Cleared for UI integration fulfillment test.',
                clearanceSource: 'Medical Officer Clearance',
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

        return ['stock' => $stock, 'units' => $units];
    }

    private function createHospitalCoordinator(Hospital $hospital, bool $isActive = true): User
    {
        $user = $this->createUserWithRole('hospital', $isActive);

        HospitalUser::factory()->create([
            'user_id' => $user->id,
            'hospital_id' => $hospital->id,
            'is_active' => $isActive,
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
}
