<?php

namespace Tests\Feature;

use App\Models\BloodCompatibilityRule;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
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
use App\Services\ScreeningService;
use Database\Seeders\BloodCompatibilityRuleSeeder;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BloodRequestControllerTest extends TestCase
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

        $this->seedBaselineCatalogs();
    }

    public function test_routes_are_registered_with_expected_names_http_methods_and_auth_middleware(): void
    {
        $expectedRoutes = [
            'requests.store' => ['methods' => ['POST'], 'uri' => 'requests'],
            'requests.update' => ['methods' => ['PUT', 'PATCH'], 'uri' => 'requests/{bloodRequest}'],
            'requests.approve' => ['methods' => ['POST'], 'uri' => 'requests/{bloodRequest}/approve'],
            'requests.reject' => ['methods' => ['POST'], 'uri' => 'requests/{bloodRequest}/reject'],
            'requests.reserve' => ['methods' => ['POST'], 'uri' => 'requests/{bloodRequest}/reserve'],
            'requests.release' => ['methods' => ['POST'], 'uri' => 'requests/{bloodRequest}/release'],
            'requests.dispatch' => ['methods' => ['POST'], 'uri' => 'requests/{bloodRequest}/dispatch'],
            'requests.cancel' => ['methods' => ['POST'], 'uri' => 'requests/{bloodRequest}/cancel'],
        ];

        foreach ($expectedRoutes as $routeName => $spec) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Missing named route '{$routeName}'.");
            $this->assertSame($spec['uri'], $route->uri());
            $this->assertSame($spec['methods'], $route->methods());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertNotContains('GET', $route->methods());
        }

        $staff = $this->createUserWithRole('staff');
        $hospital = Hospital::factory()->create();
        $bloodRequest = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'A+',
            'requested_units' => 1,
            'request_reason' => 'HTTP method verification request.',
        ]);

        foreach (['approve', 'reject', 'reserve', 'release', 'dispatch', 'cancel'] as $action) {
            $this->actingAs($staff)
                ->get("/requests/{$bloodRequest->id}/{$action}")
                ->assertMethodNotAllowed();
        }
    }

    public function test_unauthenticated_users_cannot_access_any_protected_blood_request_operation(): void
    {
        $staff = $this->createUserWithRole('staff');
        $hospital = Hospital::factory()->create();
        $bloodRequest = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'O+',
            'requested_units' => 2,
            'request_reason' => 'Unauthenticated barrier check.',
        ]);

        $this->post(route('requests.store'), [])->assertRedirect(route('login'));
        $this->put(route('requests.update', $bloodRequest), [])->assertRedirect(route('login'));
        $this->patch(route('requests.update', $bloodRequest), [])->assertRedirect(route('login'));
        $this->post(route('requests.approve', $bloodRequest), [])->assertRedirect(route('login'));
        $this->post(route('requests.reject', $bloodRequest), [])->assertRedirect(route('login'));
        $this->post(route('requests.reserve', $bloodRequest), [])->assertRedirect(route('login'));
        $this->post(route('requests.release', $bloodRequest), [])->assertRedirect(route('login'));
        $this->post(route('requests.dispatch', $bloodRequest), [])->assertRedirect(route('login'));
        $this->post(route('requests.cancel', $bloodRequest), [])->assertRedirect(route('login'));

        $this->postJson(route('requests.approve', $bloodRequest), [])->assertUnauthorized();
        $this->assertSame('Pending', $bloodRequest->fresh()->status);
    }

    public function test_hospital_coordinator_creates_request_for_own_verified_hospital_and_ignores_unexpected_fields(): void
    {
        $hospital = Hospital::factory()->create(['primary_contact_name' => 'Dr. Maya Rao']);
        $coordinator = $this->createHospitalCoordinator($hospital);
        $prbc = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();

        $response = $this->actingAs($coordinator)->post(route('requests.store'), [
            'blood_group' => 'A+',
            'blood_component_id' => $prbc->id,
            'requested_units' => 3,
            'priority' => 'High',
            'request_date' => '2026-11-12',
            'required_date' => '2026-11-14',
            'request_reason' => 'Scheduled cardiac surgery crossmatch.',
            'additional_notes' => 'Deliver before 08:00 AM.',
            'status' => 'Approved',
            'fulfilled_units' => 3,
        ]);

        $response->assertRedirect(route('requests.index'));
        $response->assertSessionHas('success');

        $created = BloodRequest::query()->firstOrFail();
        $this->assertSame($hospital->id, $created->hospital_id);
        $this->assertSame($coordinator->id, $created->requested_by_user_id);
        $this->assertSame('Pending', $created->status);
        $this->assertSame(0, $created->fulfilled_units);
        $this->assertSame(0, $created->reserved_units);
        $this->assertSame(3, $created->requested_units);
        $this->assertSame($prbc->id, $created->blood_component_id);
        $this->assertDatabaseHas('blood_request_status_histories', [
            'blood_request_id' => $created->id,
            'event_name' => 'Request Submitted',
            'new_status' => 'Pending',
        ]);
    }

    public function test_hospital_coordinator_cannot_create_request_for_another_hospital(): void
    {
        $hospitalA = Hospital::factory()->create();
        $hospitalB = Hospital::factory()->create();
        $coordinatorA = $this->createHospitalCoordinator($hospitalA);

        $response = $this->actingAs($coordinatorA)->post(route('requests.store'), [
            'hospital_id' => $hospitalB->id,
            'blood_group' => 'O-',
            'requested_units' => 2,
            'request_reason' => 'Cross-tenant attempt.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('blood_requests', 0);
    }

    #[TestWith(['hospital'])]
    #[TestWith(['lab_officer'])]
    #[TestWith(['donor'])]
    #[TestWith(['inactive_staff'])]
    public function test_unauthorized_roles_are_forbidden_from_fulfillment_mutations(string $roleCase): void
    {
        $staff = $this->createUserWithRole('staff');
        $hospital = Hospital::factory()->create();
        $otherHospital = Hospital::factory()->create();
        $bloodRequest = $this->fulfillmentService->approveRequest(
            $this->fulfillmentService->createRequest($hospital, $staff, [
                'blood_group' => 'A+',
                'requested_units' => 2,
                'request_reason' => 'Authorization matrix check.',
            ]),
            $staff
        );

        $actor = match ($roleCase) {
            'hospital' => $this->createHospitalCoordinator($hospital),
            'inactive_staff' => $this->createUserWithRole('staff', isActive: false),
            default => $this->createUserWithRole($roleCase),
        };

        if ($roleCase !== 'hospital') {
            $this->actingAs($actor)->post(route('requests.store'), [
                'hospital_id' => $hospital->id,
                'blood_group' => 'A+',
                'requested_units' => 1,
                'request_reason' => 'Unauthorized store.',
            ])->assertForbidden();
        }

        $this->actingAs($actor)->put(route('requests.update', $bloodRequest), ['requested_units' => 4])->assertForbidden();
        $this->actingAs($actor)->post(route('requests.approve', $bloodRequest))->assertForbidden();
        $this->actingAs($actor)->post(route('requests.reject', $bloodRequest), ['rejection_reason' => 'Unauthorized rejection attempt.'])->assertForbidden();
        $this->actingAs($actor)->post(route('requests.reserve', $bloodRequest), ['quantity' => 1])->assertForbidden();
        $this->actingAs($actor)->post(route('requests.release', $bloodRequest), ['reason' => 'Unauthorized release attempt.'])->assertForbidden();
        $this->actingAs($actor)->post(route('requests.dispatch', $bloodRequest), ['quantity' => 1])->assertForbidden();
        $this->actingAs($actor)->post(route('requests.cancel', $bloodRequest), ['cancellation_reason' => 'Unauthorized cancel attempt.'])->assertForbidden();

        if ($roleCase === 'hospital') {
            $otherPending = $this->fulfillmentService->createRequest($otherHospital, $staff, [
                'blood_group' => 'B+',
                'requested_units' => 1,
                'request_reason' => 'Other hospital pending request.',
            ]);
            $this->actingAs($actor)
                ->post(route('requests.cancel', $otherPending), ['cancellation_reason' => 'Cross-hospital cancel attempt.'])
                ->assertForbidden();
        }

        $this->assertSame('Approved', $bloodRequest->fresh()->status);
    }

    public function test_invalid_create_input_returns_validation_errors_without_persisting(): void
    {
        $staff = $this->createUserWithRole('staff');
        $unverifiedHospital = Hospital::factory()->pendingVerification()->create();
        $verifiedHospital = Hospital::factory()->create();

        $this->actingAs($staff)
            ->from(route('requests.create'))
            ->post(route('requests.store'), [])
            ->assertRedirect(route('requests.create'))
            ->assertSessionHasErrors(['hospital_id', 'blood_group', 'requested_units', 'request_reason']);

        $this->actingAs($staff)
            ->from(route('requests.create'))
            ->post(route('requests.store'), [
                'hospital_id' => $verifiedHospital->id,
                'blood_group' => 'INVALID',
                'requested_units' => 0,
                'request_date' => '2026-11-15',
                'required_date' => '2026-11-12',
                'request_reason' => 'Valid text but invalid dates and group.',
            ])
            ->assertRedirect(route('requests.create'))
            ->assertSessionHasErrors(['blood_group', 'requested_units']);

        $this->actingAs($staff)
            ->post(route('requests.store'), [
                'hospital_id' => $unverifiedHospital->id,
                'blood_group' => 'A+',
                'requested_units' => 2,
                'request_reason' => 'Unverified hospital request.',
            ])
            ->assertSessionHasErrors('hospital_id');

        $this->actingAs($staff)
            ->post(route('requests.store'), [
                'hospital_id' => $verifiedHospital->id,
                'blood_group' => 'A+',
                'requested_units' => 2,
                'request_date' => '2026-11-15',
                'required_date' => '2026-11-12',
                'request_reason' => 'Invalid required date ordering.',
            ])
            ->assertSessionHasErrors('required_date');

        $this->assertDatabaseCount('blood_requests', 0);
    }

    public function test_staff_can_approve_and_reject_pending_requests_and_invalid_transitions_fail(): void
    {
        $staff = $this->createUserWithRole('staff');
        $hospital = Hospital::factory()->create();

        $toApprove = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'B+',
            'requested_units' => 2,
            'request_reason' => 'Approval lifecycle verification.',
        ]);
        $toReject = $this->fulfillmentService->createRequest($hospital, $staff, [
            'blood_group' => 'B+',
            'requested_units' => 2,
            'request_reason' => 'Rejection lifecycle verification.',
        ]);

        $this->actingAs($staff)
            ->post(route('requests.approve', $toApprove), ['notes' => 'Cleared for crossmatch.'])
            ->assertRedirect(route('requests.index'))
            ->assertSessionHas('success');

        $toApprove->refresh();
        $this->assertSame('Approved', $toApprove->status);
        $this->assertSame($staff->id, $toApprove->reviewed_by_user_id);

        $this->actingAs($staff)
            ->post(route('requests.approve', $toApprove))
            ->assertSessionHasErrors('status');

        $this->actingAs($staff)
            ->post(route('requests.reject', $toReject), [])
            ->assertSessionHasErrors('rejection_reason');

        $this->actingAs($staff)
            ->post(route('requests.reject', $toReject), ['rejection_reason' => 'Duplicate emergency requisition.'])
            ->assertRedirect(route('requests.index'))
            ->assertSessionHas('success');

        $toReject->refresh();
        $this->assertSame('Rejected', $toReject->status);
        $this->assertSame('Duplicate emergency requisition.', $toReject->rejection_reason);

        $this->actingAs($staff)
            ->post(route('requests.approve', $toReject))
            ->assertSessionHasErrors('status');

        $this->actingAs($staff)
            ->post(route('requests.approve', 999999))
            ->assertNotFound();
    }

    public function test_staff_can_update_eligible_request_and_coordinator_or_staff_can_cancel_eligible_requests(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $hospital = Hospital::factory()->create();
        $coordinator = $this->createHospitalCoordinator($hospital);
        $this->seedScreenedUnits($admin, $staff, $labOfficer, 'A+', 'PRBC', 2);

        $pendingByCoord = $this->fulfillmentService->createRequest($hospital, $coordinator, [
            'blood_group' => 'A+',
            'requested_units' => 3,
            'priority' => 'Normal',
            'request_reason' => 'Coordinator request for update and cancel.',
        ]);

        $this->actingAs($staff)
            ->patch(route('requests.update', $pendingByCoord), [
                'requested_units' => 5,
                'priority' => 'Critical',
            ])
            ->assertRedirect(route('requests.index'))
            ->assertSessionHas('success');

        $pendingByCoord->refresh();
        $this->assertSame(5, $pendingByCoord->requested_units);
        $this->assertSame('Critical', $pendingByCoord->priority);
        $this->assertSame('A+', $pendingByCoord->blood_group);

        $this->actingAs($coordinator)
            ->post(route('requests.cancel', $pendingByCoord), ['cancellation_reason' => 'Surgery postponed by patient.'])
            ->assertRedirect(route('requests.index'))
            ->assertSessionHas('success');

        $this->assertSame('Cancelled', $pendingByCoord->fresh()->status);

        $this->actingAs($staff)
            ->put(route('requests.update', $pendingByCoord), ['requested_units' => 6])
            ->assertSessionHasErrors('status');

        $this->actingAs($staff)
            ->post(route('requests.cancel', $pendingByCoord), ['cancellation_reason' => 'Already cancelled request.'])
            ->assertSessionHasErrors('status');

        $approvedRequest = $this->fulfillmentService->approveRequest(
            $this->fulfillmentService->createRequest($hospital, $staff, [
                'blood_group' => 'A+',
                'blood_component_code' => 'PRBC',
                'requested_units' => 2,
                'request_reason' => 'Approved hold cancellation verification.',
            ]),
            $staff
        );
        $this->fulfillmentService->reserveUnits($approvedRequest, $staff, 2);

        $this->actingAs($staff)
            ->post(route('requests.cancel', $approvedRequest), ['cancellation_reason' => 'Order withdrawn after clinical review.'])
            ->assertRedirect(route('requests.index'))
            ->assertSessionHas('success');

        $approvedRequest->refresh();
        $this->assertSame('Cancelled', $approvedRequest->status);
        $this->assertSame(0, $approvedRequest->reserved_units);
        $this->assertSame(2, BloodUnit::query()->where('blood_group', 'A+')->where('unit_status', 'Available')->count());
    }

    public function test_reserve_and_release_endpoints_mutate_inventory_and_reject_invalid_inputs_atomically(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $hospital = Hospital::factory()->create();

        $seeded = $this->seedScreenedUnits($admin, $staff, $labOfficer, 'O+', 'PRBC', 3);
        /** @var Collection<int, BloodUnit> $units */
        $units = $seeded['units'];
        /** @var InventoryStock $stock */
        $stock = $seeded['stock'];

        $bloodRequest = $this->fulfillmentService->approveRequest(
            $this->fulfillmentService->createRequest($hospital, $staff, [
                'blood_group' => 'O+',
                'blood_component_code' => 'PRBC',
                'requested_units' => 2,
                'request_reason' => 'HTTP reservation and release test.',
            ]),
            $staff
        );

        // Invalid quantity & invalid unit IDs do not mutate DB.
        $this->actingAs($staff)
            ->post(route('requests.reserve', $bloodRequest), ['quantity' => 5])
            ->assertSessionHasErrors('quantity');

        $this->actingAs($staff)
            ->post(route('requests.reserve', $bloodRequest), [
                'quantity' => 2,
                'blood_unit_ids' => [$units[0]->id, 999999],
            ])
            ->assertSessionHasErrors('blood_unit_ids');

        $this->assertSame(0, InventoryAllocation::query()->count());
        $this->assertSame(3, $stock->fresh()->available_units);
        $this->assertSame(0, $stock->fresh()->reserved_units);

        // Reserve 2 explicit units.
        $this->actingAs($staff)
            ->post(route('requests.reserve', $bloodRequest), [
                'quantity' => 2,
                'blood_unit_ids' => [$units[0]->id, $units[2]->id],
            ])
            ->assertRedirect(route('requests.index'))
            ->assertSessionHas('success');

        $this->assertSame(2, $bloodRequest->fresh()->reserved_units);
        $this->assertSame(1, $stock->fresh()->available_units);
        $this->assertSame(2, $stock->fresh()->reserved_units);
        $this->assertSame('Reserved', $units[0]->fresh()->unit_status);
        $this->assertSame('Reserved', $units[2]->fresh()->unit_status);

        $firstAllocation = InventoryAllocation::query()
            ->where('blood_request_id', $bloodRequest->id)
            ->where('blood_unit_id', $units[0]->id)
            ->firstOrFail();

        // Release only the first allocation.
        $this->actingAs($staff)
            ->post(route('requests.release', $bloodRequest), [
                'allocation_ids' => [$firstAllocation->id],
                'reason' => 'Releasing first reserved bag only.',
            ])
            ->assertRedirect(route('requests.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, $bloodRequest->fresh()->reserved_units);
        $this->assertSame(2, $stock->fresh()->available_units);
        $this->assertSame(1, $stock->fresh()->reserved_units);
        $this->assertSame('Released', $firstAllocation->fresh()->allocation_status);
        $this->assertSame('Available', $units[0]->fresh()->unit_status);
    }

    public function test_dispatch_endpoint_completes_fulfillment_and_rejects_excess_capacity_without_partial_writes(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $hospital = Hospital::factory()->create();

        $seeded = $this->seedScreenedUnits($admin, $staff, $labOfficer, 'A+', 'PRBC', 3);
        /** @var InventoryStock $stock */
        $stock = $seeded['stock'];

        $bloodRequest = $this->fulfillmentService->approveRequest(
            $this->fulfillmentService->createRequest($hospital, $staff, [
                'blood_group' => 'A+',
                'blood_component_code' => 'PRBC',
                'requested_units' => 2,
                'request_reason' => 'HTTP dispatch lifecycle verification.',
            ]),
            $staff
        );
        $this->fulfillmentService->reserveUnits($bloodRequest, $staff, 1);

        // Excess dispatch fails cleanly without partial mutations.
        $this->actingAs($staff)
            ->post(route('requests.dispatch', $bloodRequest), ['quantity' => 3])
            ->assertSessionHasErrors('quantity');

        $this->assertDatabaseCount('request_fulfillments', 0);
        $this->assertSame(0, InventoryTransaction::query()->where('operation_type', 'Dispatch')->count());
        $this->assertSame(1, $bloodRequest->fresh()->reserved_units);
        $this->assertSame(0, $bloodRequest->fresh()->fulfilled_units);

        // Dispatch 2 units (1 Reserved + 1 Available).
        $this->actingAs($staff)
            ->post(route('requests.dispatch', $bloodRequest), [
                'quantity' => 2,
                'dispatched_blood_group' => 'A+',
                'courier_reference' => 'TR-9001',
                'notes' => 'Cold-chain courier dispatched.',
            ])
            ->assertRedirect(route('requests.index'))
            ->assertSessionHas('success');

        $bloodRequest->refresh();
        $stock->refresh();
        $this->assertSame('Fulfilled', $bloodRequest->status);
        $this->assertSame(2, $bloodRequest->fulfilled_units);
        $this->assertSame(0, $bloodRequest->reserved_units);
        $this->assertSame(1, $stock->available_units);
        $this->assertSame(0, $stock->reserved_units);
        $this->assertDatabaseCount('request_fulfillments', 1);
        $this->assertSame(2, InventoryTransaction::query()->where('operation_type', 'Dispatch')->count());
    }

    public function test_only_authorized_users_can_perform_compatibility_overrides_over_http(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $hospital = Hospital::factory()->create();
        $prbc = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();

        BloodCompatibilityRule::query()->create([
            'recipient_blood_group' => 'AB+',
            'donor_blood_group' => 'B-',
            'blood_component_id' => $prbc->id,
            'compatibility_level' => 'Emergency Only',
            'requires_medical_override' => true,
            'is_active' => true,
        ]);

        $this->seedScreenedUnits($admin, $staff, $labOfficer, 'B-', 'PRBC', 2);

        $bloodRequest = $this->fulfillmentService->approveRequest(
            $this->fulfillmentService->createRequest($hospital, $staff, [
                'blood_group' => 'AB+',
                'blood_component_code' => 'PRBC',
                'requested_units' => 2,
                'priority' => 'Critical',
                'request_reason' => 'Emergency compatibility override HTTP test.',
            ]),
            $staff
        );

        // Staff cannot override compatibility over HTTP even if trying to inject override_approver fields.
        $this->actingAs($staff)
            ->post(route('requests.dispatch', $bloodRequest), [
                'quantity' => 1,
                'dispatched_blood_group' => 'B-',
                'override_approver' => $admin->id,
                'override_approver_id' => $admin->id,
                'override_reason' => 'Attempted staff override spoofing.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('request_fulfillments', 0);

        // Admin must supply override_reason, then override succeeds with audit trail.
        $this->actingAs($admin)
            ->post(route('requests.dispatch', $bloodRequest), [
                'quantity' => 1,
                'dispatched_blood_group' => 'B-',
            ])
            ->assertSessionHasErrors('override_reason');

        $this->actingAs($admin)
            ->post(route('requests.dispatch', $bloodRequest), [
                'quantity' => 1,
                'dispatched_blood_group' => 'B-',
                'override_reason' => 'Director approved emergency trauma substitution.',
            ])
            ->assertRedirect(route('requests.index'))
            ->assertSessionHas('success');

        $fulfillment = RequestFulfillment::query()->firstOrFail();
        $this->assertTrue($fulfillment->is_compatibility_override);
        $this->assertSame($admin->id, $fulfillment->override_approved_by_user_id);
        $this->assertSame('Director approved emergency trauma substitution.', $fulfillment->override_reason);
    }

    public function test_json_validation_error_returns_unprocessable_status_without_exposing_trace(): void
    {
        $staff = $this->createUserWithRole('staff');
        $hospital = Hospital::factory()->create();

        $response = $this->actingAs($staff)->postJson(route('requests.store'), [
            'hospital_id' => $hospital->id,
            'blood_group' => 'A+',
            'requested_units' => 0,
            'request_reason' => '',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['requested_units', 'request_reason']);
        $this->assertArrayNotHasKey('trace', $response->json());
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
                reason: 'Cleared for HTTP fulfillment test.',
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

    private function createHospitalCoordinator(Hospital $hospital): User
    {
        $user = $this->createUserWithRole('hospital');

        HospitalUser::factory()->create([
            'user_id' => $user->id,
            'hospital_id' => $hospital->id,
            'is_active' => true,
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
