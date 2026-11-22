<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class HospitalEndToEndWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            BloodComponentSeeder::class,
        ]);
    }

    /**
     * 1. Hospital registration creates correct User, Hospital (Pending), and HospitalUser records.
     */
    public function test_1_hospital_registration_creates_correct_records(): void
    {
        $response = $this->post(route('register.attempt'), [
            'role' => 'hospital',
            'name' => 'Dr. Gregory House',
            'email' => 'gregory.house@princetonplainsboro.org',
            'organization_name' => 'Princeton-Plainsboro Teaching Hospital',
            'phone' => '+1-555-019-8800',
            'city' => 'Princeton',
            'address' => '100 Medical Center Way',
            'password' => 'secure-pass-123',
            'password_confirmation' => 'secure-pass-123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('status');

        $user = User::query()->where('email', 'gregory.house@princetonplainsboro.org')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Dr. Gregory House', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertSame(['hospital'], $user->roles()->pluck('slug')->all());

        $hospital = Hospital::query()->firstOrFail();
        $this->assertSame('Princeton-Plainsboro Teaching Hospital', $hospital->name);
        $this->assertSame('Pending', $hospital->verification_status);
        $this->assertTrue($hospital->is_active);
        $this->assertNull($hospital->verified_by_user_id);
        $this->assertNull($hospital->verified_at);

        $coordinatorLink = HospitalUser::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame($hospital->id, $coordinatorLink->hospital_id);
        $this->assertTrue($coordinatorLink->is_active);
        $this->assertTrue($coordinatorLink->is_primary_contact);
    }

    /**
     * 2. Pending hospital coordinator can log in and observe verification pending status.
     */
    public function test_2_pending_hospital_coordinator_can_login_and_see_pending_status(): void
    {
        $hospital = Hospital::factory()->pendingVerification()->create([
            'name' => 'Metro Hope Clinic',
            'hospital_code' => 'HSP-METRO-01',
        ]);
        $coordinator = $this->createCoordinatorFor($hospital, 'secret-password');

        // Log out any session
        Auth::logout();

        // Successful login
        $loginResponse = $this->post(route('login.attempt'), [
            'email' => $coordinator->email,
            'password' => 'secret-password',
        ]);

        $loginResponse->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($coordinator);

        // Dashboard renders pending notice and lock-indicator
        $dashboardResponse = $this->actingAs($coordinator)->get(route('dashboard'));
        $dashboardResponse->assertOk();
        $dashboardResponse->assertSee('Facility Verification Pending');
        $dashboardResponse->assertSee('Metro Hope Clinic');
        $dashboardResponse->assertSee('Verification Pending');
    }

    /**
     * 3. Pending hospital coordinator cannot access request creation form or submit requests.
     */
    public function test_3_pending_hospital_coordinator_cannot_create_requests(): void
    {
        $hospital = Hospital::factory()->pendingVerification()->create();
        $coordinator = $this->createCoordinatorFor($hospital);

        // GET request creation form is blocked with 403
        $this->actingAs($coordinator)->get(route('requests.create'))->assertForbidden();

        // POST request creation is blocked with 403
        $response = $this->actingAs($coordinator)->post(route('requests.store'), [
            'blood_group' => 'A+',
            'requested_units' => 2,
            'priority' => 'High',
            'request_reason' => 'Emergency surgery crossmatch.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseEmpty('blood_requests');
    }

    /**
     * 4. Admin verifies the hospital.
     */
    public function test_4_admin_can_view_queue_and_verify_hospital(): void
    {
        $admin = $this->createUserWithRole('admin');
        $hospital = Hospital::factory()->pendingVerification()->create([
            'name' => 'City Trauma Hospital',
        ]);

        // Admin sees hospital in queue
        $queueResponse = $this->actingAs($admin)->get(route('hospitals.index'));
        $queueResponse->assertOk();
        $queueResponse->assertSee('City Trauma Hospital');

        // Admin verifies the hospital
        $verifyResponse = $this->actingAs($admin)->post(route('hospitals.verify', $hospital));
        $verifyResponse->assertRedirect(route('hospitals.show', $hospital));
        $verifyResponse->assertSessionHas('success');

        $hospital->refresh();
        $this->assertSame('Verified', $hospital->verification_status);
        $this->assertTrue($hospital->is_active);
        $this->assertSame($admin->id, $hospital->verified_by_user_id);
        $this->assertNotNull($hospital->verified_at);
    }

    /**
     * 5. Verified hospital coordinator can access request form and create blood request.
     */
    public function test_5_verified_hospital_coordinator_can_create_blood_request(): void
    {
        $admin = $this->createUserWithRole('admin');
        $hospital = Hospital::factory()->create([
            'name' => 'Mercy General Hospital',
            'verification_status' => 'Verified',
            'is_active' => true,
            'verified_by_user_id' => $admin->id,
            'verified_at' => now(),
        ]);
        $coordinator = $this->createCoordinatorFor($hospital);

        // Dashboard reflects verified status
        $dashboardResponse = $this->actingAs($coordinator)->get(route('dashboard'));
        $dashboardResponse->assertOk();
        $dashboardResponse->assertSee('Verified Hospital Partner');
        $dashboardResponse->assertSee('Mercy General Hospital');

        // Form access succeeds
        $formResponse = $this->actingAs($coordinator)->get(route('requests.create'));
        $formResponse->assertOk();
        $formResponse->assertSee('Create New Blood Request');

        // Post request succeeds
        $prbc = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();
        $storeResponse = $this->actingAs($coordinator)->post(route('requests.store'), [
            'blood_group' => 'O-',
            'blood_component_id' => $prbc->id,
            'requested_units' => 4,
            'priority' => 'Critical',
            'request_reason' => 'Acute pediatric hemoperitoneum resuscitation.',
            'department_or_ward' => 'Pediatric Emergency Care',
        ]);

        $storeResponse->assertRedirect(route('requests.index'));
        $storeResponse->assertSessionHas('success');

        $this->assertDatabaseCount('blood_requests', 1);
        $createdRequest = BloodRequest::query()->firstOrFail();
        $this->assertSame('Pending', $createdRequest->status);
        $this->assertSame('O-', $createdRequest->blood_group);
        $this->assertSame(4, $createdRequest->requested_units);
        $this->assertSame('Critical', $createdRequest->priority);
        $this->assertSame($prbc->id, $createdRequest->blood_component_id);
    }

    /**
     * 6. Blood request is stored with correct hospital and requester relationships.
     */
    public function test_6_blood_request_stores_correct_hospital_and_requester_relationships(): void
    {
        $hospital = Hospital::factory()->create([
            'verification_status' => 'Verified',
            'is_active' => true,
        ]);
        $coordinator = $this->createCoordinatorFor($hospital);

        $this->actingAs($coordinator)->post(route('requests.store'), [
            'blood_group' => 'B+',
            'requested_units' => 2,
            'priority' => 'Normal',
            'request_reason' => 'Scheduled joint replacement surgery.',
        ]);

        $bloodRequest = BloodRequest::query()->firstOrFail();

        // Exact relationships
        $this->assertSame($hospital->id, $bloodRequest->hospital_id);
        $this->assertSame($coordinator->id, $bloodRequest->requested_by_user_id);
        $this->assertSame($hospital->name, $bloodRequest->hospital->name);
        $this->assertSame($coordinator->email, $bloodRequest->requestedBy->email);
    }

    /**
     * 7. Spoofed hospital_id in request payload is rejected.
     */
    public function test_7_spoofed_hospital_id_is_rejected(): void
    {
        $hospitalA = Hospital::factory()->create(['verification_status' => 'Verified', 'is_active' => true]);
        $hospitalB = Hospital::factory()->create(['verification_status' => 'Verified', 'is_active' => true]);

        $coordinatorA = $this->createCoordinatorFor($hospitalA);

        // Coordinator A passes Hospital B's ID
        $response = $this->actingAs($coordinatorA)->post(route('requests.store'), [
            'hospital_id' => $hospitalB->id,
            'blood_group' => 'A+',
            'requested_units' => 3,
            'priority' => 'Normal',
            'request_reason' => 'Attempting cross-tenant spoofing.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseEmpty('blood_requests');
    }

    /**
     * 8. Hospital A coordinator cannot cancel or access Hospital B's blood request.
     */
    public function test_8_hospital_coordinator_cannot_cancel_another_hospitals_request(): void
    {
        $hospitalA = Hospital::factory()->create(['verification_status' => 'Verified', 'is_active' => true]);
        $hospitalB = Hospital::factory()->create(['verification_status' => 'Verified', 'is_active' => true]);

        $coordinatorA = $this->createCoordinatorFor($hospitalA);
        $coordinatorB = $this->createCoordinatorFor($hospitalB);

        $requestB = BloodRequest::factory()->create([
            'hospital_id' => $hospitalB->id,
            'status' => 'Pending',
        ]);

        // Coordinator A tries to cancel Hospital B's request -> 403 Forbidden
        $this->actingAs($coordinatorA)
            ->post(route('requests.cancel', $requestB), ['reason' => 'Unauthorized cancellation.'])
            ->assertForbidden();

        $this->assertSame('Pending', $requestB->fresh()->status);

        // Coordinator B cancels their own hospital's request -> 302 Redirect
        $this->actingAs($coordinatorB)
            ->post(route('requests.cancel', $requestB), ['reason' => 'Patient transferred.'])
            ->assertRedirect(route('requests.index'));

        $this->assertSame('Cancelled', $requestB->fresh()->status);
    }

    /**
     * 9. Suspended hospital coordinator cannot create requests.
     */
    public function test_9_suspended_hospital_cannot_create_requests(): void
    {
        $hospital = Hospital::factory()->suspended()->create();
        $coordinator = $this->createCoordinatorFor($hospital);

        // Suspended coordinator cannot access form
        $this->actingAs($coordinator)->get(route('requests.create'))->assertForbidden();

        // Suspended coordinator cannot submit request
        $this->actingAs($coordinator)->post(route('requests.store'), [
            'blood_group' => 'AB+',
            'requested_units' => 1,
            'priority' => 'Normal',
            'request_reason' => 'Suspended facility request attempt.',
        ])->assertForbidden();

        // Dashboard shows suspended alert
        $dashboardResponse = $this->actingAs($coordinator)->get(route('dashboard'));
        $dashboardResponse->assertOk();
        $dashboardResponse->assertSee('Facility Access Suspended');
    }

    /**
     * 10. Unauthenticated and unauthorized roles cannot bypass request creation barriers.
     */
    public function test_10_unauthenticated_and_unauthorized_users_are_blocked(): void
    {
        // Unauthenticated
        $this->get(route('requests.create'))->assertRedirect(route('login'));
        $this->post(route('requests.store'), [
            'blood_group' => 'O+',
            'requested_units' => 1,
            'request_reason' => 'Guest attempt.',
        ])->assertRedirect(route('login'));

        // Donor role
        $donor = $this->createUserWithRole('donor');
        $this->actingAs($donor)->get(route('requests.create'))->assertForbidden();
        $this->actingAs($donor)->post(route('requests.store'), [
            'blood_group' => 'O+',
            'requested_units' => 1,
            'request_reason' => 'Donor attempt.',
        ])->assertForbidden();

        // Lab Officer role
        $labOfficer = $this->createUserWithRole('lab_officer');
        $this->actingAs($labOfficer)->get(route('requests.create'))->assertForbidden();
        $this->actingAs($labOfficer)->post(route('requests.store'), [
            'blood_group' => 'O+',
            'requested_units' => 1,
            'request_reason' => 'Lab officer attempt.',
        ])->assertForbidden();

        $this->assertDatabaseEmpty('blood_requests');
    }

    /**
     * Helper to create an active hospital coordinator User and HospitalUser pivot link.
     */
    private function createCoordinatorFor(Hospital $hospital, string $password = 'password'): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'password' => bcrypt($password),
        ]);
        $role = Role::query()->where('slug', 'hospital')->firstOrFail();
        $user->roles()->attach($role);

        HospitalUser::factory()->create([
            'hospital_id' => $hospital->id,
            'user_id' => $user->id,
            'is_active' => true,
            'is_primary_contact' => true,
        ]);

        return $user;
    }

    /**
     * Helper to create a user with a specific role.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createUserWithRole(string $roleSlug, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['is_active' => true], $attributes));
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }
}
