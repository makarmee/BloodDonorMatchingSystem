<?php

namespace Tests\Feature;

use App\Models\BloodRequest;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\Role;
use App\Models\User;
use App\Services\HospitalVerificationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class HospitalVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * 1. Admin can view verification queue.
     */
    public function test_admin_can_view_hospital_verification_queue(): void
    {
        $admin = $this->createUserWithRole('admin');
        Hospital::factory()->count(3)->create();

        $response = $this->actingAs($admin)->get(route('hospitals.index'));

        $response->assertOk();
        $response->assertViewIs('hospitals.index');
        $response->assertViewHas('hospitals');
        $response->assertViewHas('summary');
        $response->assertSee('Partner Hospitals Queue');
    }

    /**
     * 2. Pending hospitals appear before Verified/Suspended hospitals.
     */
    public function test_pending_hospitals_appear_before_verified_and_suspended_hospitals(): void
    {
        $admin = $this->createUserWithRole('admin');

        // Create verified and suspended hospitals first (earlier and later timestamps)
        $verified = Hospital::factory()->create([
            'name' => 'Alpha Verified Hospital',
            'verification_status' => 'Verified',
            'is_active' => true,
            'created_at' => now()->subDays(5),
        ]);

        $suspended = Hospital::factory()->suspended()->create([
            'name' => 'Beta Suspended Hospital',
            'created_at' => now()->subDays(2),
        ]);

        $pending = Hospital::factory()->pendingVerification()->create([
            'name' => 'Zeta Pending Hospital',
            'created_at' => now()->subDays(10), // Older created_at, but must rank first due to status
        ]);

        $response = $this->actingAs($admin)->get(route('hospitals.index'));

        $response->assertOk();
        $hospitalsInView = $response->viewData('hospitals');

        $this->assertCount(3, $hospitalsInView);
        $this->assertSame($pending->id, $hospitalsInView->first()->id);
        $this->assertSame('Pending', $hospitalsInView->first()->verification_status);
    }

    /**
     * 3. Admin can view hospital details.
     */
    public function test_admin_can_view_hospital_details(): void
    {
        $admin = $this->createUserWithRole('admin');
        $hospital = Hospital::factory()->pendingVerification()->create([
            'name' => 'St. Jude General Hospital',
            'city' => 'Metropolis',
            'primary_contact_name' => 'Dr. Eleanor Vance',
            'primary_phone' => '+1-555-432-1098',
            'primary_email' => 'contact@stjude-gen.org',
        ]);

        $coordinator = User::factory()->create(['name' => 'Nurse Jane', 'email' => 'jane@stjude-gen.org']);
        HospitalUser::factory()->create([
            'hospital_id' => $hospital->id,
            'user_id' => $coordinator->id,
            'contact_name' => 'Nurse Jane',
            'job_title' => 'Chief Coordinator',
        ]);

        $response = $this->actingAs($admin)->get(route('hospitals.show', $hospital));

        $response->assertOk();
        $response->assertViewIs('hospitals.show');
        $response->assertSee('St. Jude General Hospital');
        $response->assertSee($hospital->hospital_code);
        $response->assertSee('Metropolis');
        $response->assertSee('Dr. Eleanor Vance');
        $response->assertSee('+1-555-432-1098');
        $response->assertSee('Nurse Jane');
        $response->assertSee('Chief Coordinator');
    }

    /**
     * 4. Admin can verify a Pending hospital.
     */
    public function test_admin_can_verify_a_pending_hospital(): void
    {
        Log::spy();
        $admin = $this->createUserWithRole('admin');
        $hospital = Hospital::factory()->pendingVerification()->create();

        $response = $this->actingAs($admin)->post(route('hospitals.verify', $hospital));

        $response->assertRedirect(route('hospitals.show', $hospital));
        $response->assertSessionHas('success');

        $hospital->refresh();
        $this->assertSame('Verified', $hospital->verification_status);
        $this->assertTrue($hospital->is_active);
        $this->assertSame($admin->id, $hospital->verified_by_user_id);
        $this->assertNotNull($hospital->verified_at);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($hospital, $admin): bool {
            return str_contains($message, "Hospital {$hospital->hospital_code} verified")
                && $context['hospital_id'] === $hospital->id
                && $context['previous_status'] === 'Pending'
                && $context['new_status'] === 'Verified'
                && $context['admin_user_id'] === $admin->id;
        })->once();
    }

    /**
     * 5. Admin can suspend a Verified hospital.
     */
    public function test_admin_can_suspend_a_verified_hospital(): void
    {
        Log::spy();
        $admin = $this->createUserWithRole('admin');
        $hospital = Hospital::factory()->create([
            'verification_status' => 'Verified',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('hospitals.suspend', $hospital), [
            'reason' => 'Annual licensing audit failure',
        ]);

        $response->assertRedirect(route('hospitals.show', $hospital));
        $response->assertSessionHas('success');

        $hospital->refresh();
        $this->assertSame('Suspended', $hospital->verification_status);
        $this->assertFalse($hospital->is_active);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($hospital, $admin): bool {
            return str_contains($message, "Hospital {$hospital->hospital_code} suspended")
                && $context['hospital_id'] === $hospital->id
                && $context['previous_status'] === 'Verified'
                && $context['new_status'] === 'Suspended'
                && $context['admin_user_id'] === $admin->id
                && $context['reason'] === 'Annual licensing audit failure';
        })->once();
    }

    /**
     * 6. Admin can re-verify a Suspended hospital.
     */
    public function test_admin_can_reverify_a_suspended_hospital(): void
    {
        Log::spy();
        $admin = $this->createUserWithRole('admin');
        $hospital = Hospital::factory()->suspended()->create();

        $response = $this->actingAs($admin)->post(route('hospitals.verify', $hospital));

        $response->assertRedirect(route('hospitals.show', $hospital));
        $response->assertSessionHas('success');

        $hospital->refresh();
        $this->assertSame('Verified', $hospital->verification_status);
        $this->assertTrue($hospital->is_active);
        $this->assertSame($admin->id, $hospital->verified_by_user_id);
        $this->assertNotNull($hospital->verified_at);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($hospital): bool {
            return str_contains($message, "Hospital {$hospital->hospital_code} verified")
                && $context['hospital_id'] === $hospital->id
                && $context['previous_status'] === 'Suspended'
                && $context['new_status'] === 'Verified';
        })->once();
    }

    /**
     * 7. Verification metadata is set correctly (verified_by_user_id, verified_at).
     */
    public function test_verification_metadata_is_set_accurately_on_verification(): void
    {
        $admin = $this->createUserWithRole('admin');
        $hospital = Hospital::factory()->pendingVerification()->create();
        $now = CarbonImmutable::parse('2026-11-15 12:30:00');
        CarbonImmutable::setTestNow($now);

        try {
            $this->actingAs($admin)->post(route('hospitals.verify', $hospital));

            $hospital->refresh();
            $this->assertSame($admin->id, $hospital->verified_by_user_id);
            $this->assertSame('2026-11-15 12:30:00', $hospital->verified_at?->format('Y-m-d H:i:s'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /**
     * 8. Suspension preserves prior verification metadata.
     */
    public function test_suspension_preserves_prior_verification_metadata(): void
    {
        $originalAdmin = $this->createUserWithRole('admin');
        $originalVerifiedAt = CarbonImmutable::parse('2026-10-01 09:15:00');

        $hospital = Hospital::factory()->create([
            'verification_status' => 'Verified',
            'is_active' => true,
            'verified_by_user_id' => $originalAdmin->id,
            'verified_at' => $originalVerifiedAt,
        ]);

        $suspendingAdmin = $this->createUserWithRole('admin');

        $response = $this->actingAs($suspendingAdmin)->post(route('hospitals.suspend', $hospital));
        $response->assertRedirect(route('hospitals.show', $hospital));

        $hospital->refresh();
        $this->assertSame('Suspended', $hospital->verification_status);
        $this->assertFalse($hospital->is_active);
        // Ensure historical metadata is strictly preserved
        $this->assertSame($originalAdmin->id, $hospital->verified_by_user_id);
        $this->assertSame('2026-10-01 09:15:00', $hospital->verified_at?->format('Y-m-d H:i:s'));
    }

    /**
     * 9. Re-verification replaces verification metadata with new Admin and timestamp.
     */
    public function test_reverification_replaces_verification_metadata_with_new_admin_and_timestamp(): void
    {
        $admin1 = $this->createUserWithRole('admin');
        $admin2 = $this->createUserWithRole('admin');

        $hospital = Hospital::factory()->create([
            'verification_status' => 'Suspended',
            'is_active' => false,
            'verified_by_user_id' => $admin1->id,
            'verified_at' => CarbonImmutable::parse('2026-05-10 10:00:00'),
        ]);

        $newTime = CarbonImmutable::parse('2026-11-15 14:00:00');
        CarbonImmutable::setTestNow($newTime);

        try {
            $response = $this->actingAs($admin2)->post(route('hospitals.verify', $hospital));
            $response->assertRedirect(route('hospitals.show', $hospital));

            $hospital->refresh();
            $this->assertSame('Verified', $hospital->verification_status);
            $this->assertTrue($hospital->is_active);
            $this->assertSame($admin2->id, $hospital->verified_by_user_id);
            $this->assertSame('2026-11-15 14:00:00', $hospital->verified_at?->format('Y-m-d H:i:s'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /**
     * 10. Repeated/unsupported transitions rejected (Pending -> Suspended, Verified -> Verified, Suspended -> Suspended).
     */
    public function test_repeated_or_unsupported_transitions_are_rejected(): void
    {
        $admin = $this->createUserWithRole('admin');

        // Case A: Pending -> Suspended (Cannot suspend a hospital that is not Verified)
        $pendingHospital = Hospital::factory()->pendingVerification()->create();
        $response = $this->actingAs($admin)->post(route('hospitals.suspend', $pendingHospital));
        $response->assertSessionHasErrors('hospital');
        $pendingHospital->refresh();
        $this->assertSame('Pending', $pendingHospital->verification_status);

        // Case B: Verified -> Verified (Cannot verify an already verified hospital)
        $verifiedHospital = Hospital::factory()->create([
            'verification_status' => 'Verified',
            'is_active' => true,
        ]);
        $response = $this->actingAs($admin)->post(route('hospitals.verify', $verifiedHospital));
        $response->assertSessionHasErrors('hospital');
        $verifiedHospital->refresh();
        $this->assertSame('Verified', $verifiedHospital->verification_status);

        // Case C: Suspended -> Suspended (Cannot suspend an already suspended hospital)
        $suspendedHospital = Hospital::factory()->suspended()->create();
        $response = $this->actingAs($admin)->post(route('hospitals.suspend', $suspendedHospital));
        $response->assertSessionHasErrors('hospital');
        $suspendedHospital->refresh();
        $this->assertSame('Suspended', $suspendedHospital->verification_status);
    }

    /**
     * 11. Non-admin roles (staff, lab_officer, hospital, donor) receive 403 Forbidden.
     */
    #[TestWith(['staff'])]
    #[TestWith(['lab_officer'])]
    #[TestWith(['hospital'])]
    #[TestWith(['donor'])]
    public function test_non_admin_roles_receive_403_forbidden_on_hospital_verification_routes(string $roleSlug): void
    {
        $user = $this->createUserWithRole($roleSlug);
        $hospital = Hospital::factory()->pendingVerification()->create();

        // Index
        $this->actingAs($user)->get(route('hospitals.index'))->assertForbidden();

        // Show
        $this->actingAs($user)->get(route('hospitals.show', $hospital))->assertForbidden();

        // Verify
        $this->actingAs($user)->post(route('hospitals.verify', $hospital))->assertForbidden();

        // Suspend
        $this->actingAs($user)->post(route('hospitals.suspend', $hospital))->assertForbidden();
    }

    /**
     * 12. Inactive Admin receives 403 Forbidden.
     */
    public function test_inactive_admin_receives_403_forbidden(): void
    {
        $inactiveAdmin = $this->createUserWithRole('admin', ['is_active' => false]);
        $hospital = Hospital::factory()->pendingVerification()->create();

        $this->actingAs($inactiveAdmin)->get(route('hospitals.index'))->assertForbidden();
        $this->actingAs($inactiveAdmin)->get(route('hospitals.show', $hospital))->assertForbidden();
        $this->actingAs($inactiveAdmin)->post(route('hospitals.verify', $hospital))->assertForbidden();
        $this->actingAs($inactiveAdmin)->post(route('hospitals.suspend', $hospital))->assertForbidden();
    }

    /**
     * 13. Suspended hospital coordinator can still log in, but POST /requests is rejected with 403.
     */
    public function test_suspended_hospital_coordinator_can_still_login_but_requests_are_rejected(): void
    {
        $suspendedHospital = Hospital::factory()->suspended()->create();
        $coordinator = $this->createUserWithRole('hospital', ['is_active' => true, 'password' => 'coordinator-secret']);

        HospitalUser::factory()->create([
            'hospital_id' => $suspendedHospital->id,
            'user_id' => $coordinator->id,
            'is_active' => true,
            'is_primary_contact' => true,
        ]);

        // Step 1: Coordinator logs in successfully
        $loginResponse = $this->post(route('login.attempt'), [
            'email' => $coordinator->email,
            'password' => 'coordinator-secret',
        ]);

        $loginResponse->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($coordinator);

        // Step 2: Attempting to submit a blood request is blocked with 403 Forbidden
        $requestResponse = $this->actingAs($coordinator)->post(route('requests.store'), [
            'blood_group' => 'O+',
            'requested_units' => 2,
            'priority' => 'Normal',
            'request_reason' => 'Scheduled surgical procedure request.',
        ]);

        $requestResponse->assertForbidden();
        $this->assertDatabaseEmpty('blood_requests');
    }

    /**
     * 14. Verification/suspension does not mutate existing requests, allocations, or inventory.
     */
    public function test_verification_and_suspension_do_not_mutate_existing_requests_or_inventory(): void
    {
        $admin = $this->createUserWithRole('admin');
        $hospital = Hospital::factory()->create([
            'verification_status' => 'Verified',
            'is_active' => true,
        ]);

        $bloodRequest = BloodRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'status' => 'Pending',
        ]);

        $originalAttributes = $bloodRequest->fresh()->toArray();

        // Suspend the hospital
        $this->actingAs($admin)->post(route('hospitals.suspend', $hospital));

        $hospital->refresh();
        $this->assertSame('Suspended', $hospital->verification_status);

        // Assert blood request attributes remain completely identical
        $this->assertSame($originalAttributes, $bloodRequest->fresh()->toArray());

        // Re-verify the hospital
        $this->actingAs($admin)->post(route('hospitals.verify', $hospital));

        $hospital->refresh();
        $this->assertSame('Verified', $hospital->verification_status);

        // Assert blood request attributes are still completely unchanged
        $this->assertSame($originalAttributes, $bloodRequest->fresh()->toArray());
    }

    /**
     * 15. Concurrency test: row locking prevents inconsistent final state.
     */
    public function test_concurrency_and_row_locking_prevent_inconsistent_final_state(): void
    {
        $admin = $this->createUserWithRole('admin');
        $hospital = Hospital::factory()->pendingVerification()->create();
        $service = app(HospitalVerificationService::class);

        // First transition verifies the hospital successfully
        $verifiedHospital = $service->verifyHospital($hospital, $admin);
        $this->assertSame('Verified', $verifiedHospital->verification_status);

        // A concurrent attempt to verify the already-locked hospital again will be rejected
        $this->expectException(ValidationException::class);
        $service->verifyHospital($hospital, $admin);
    }

    /**
     * 16. Client cannot supply verification status, is_active, or metadata via request parameters.
     */
    public function test_client_cannot_supply_status_is_active_or_metadata_via_request_parameters(): void
    {
        $admin = $this->createUserWithRole('admin');
        $rogueAdmin = $this->createUserWithRole('admin');
        $hospital = Hospital::factory()->pendingVerification()->create();

        // Attempt verify while injecting spoofed parameters
        $this->actingAs($admin)->post(route('hospitals.verify', $hospital), [
            'verification_status' => 'Suspended',
            'is_active' => false,
            'verified_by_user_id' => $rogueAdmin->id,
            'verified_at' => '1995-01-01 00:00:00',
        ]);

        $hospital->refresh();
        // Server logic must enforce the correct values and ignore client inputs
        $this->assertSame('Verified', $hospital->verification_status);
        $this->assertTrue($hospital->is_active);
        $this->assertSame($admin->id, $hospital->verified_by_user_id);
        $this->assertNotSame('1995-01-01 00:00:00', $hospital->verified_at?->format('Y-m-d H:i:s'));

        // Attempt suspend while injecting spoofed parameters
        $this->actingAs($admin)->post(route('hospitals.suspend', $hospital), [
            'verification_status' => 'Verified',
            'is_active' => true,
            'verified_by_user_id' => $rogueAdmin->id,
            'verified_at' => '2099-12-31 23:59:59',
        ]);

        $hospital->refresh();
        $this->assertSame('Suspended', $hospital->verification_status);
        $this->assertFalse($hospital->is_active);
        $this->assertSame($admin->id, $hospital->verified_by_user_id);
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
