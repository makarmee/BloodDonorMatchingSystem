<?php

namespace Tests\Feature;

use App\Models\BloodRequest;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\Role;
use App\Models\User;
use App\Policies\BloodRequestPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class BloodRequestPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_blood_request_policy_is_registered_and_enforces_documented_permissions_and_hospital_ownership(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertInstanceOf(BloodRequestPolicy::class, Gate::getPolicyFor(BloodRequest::class));

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $inactiveStaff = $this->createUserWithRole('staff', isActive: false);
        $labOfficer = $this->createUserWithRole('lab_officer');
        $donor = $this->createUserWithRole('donor');

        $verifiedHospital = Hospital::factory()->create();
        $pendingHospital = Hospital::factory()->pendingVerification()->create();
        $suspendedHospital = Hospital::factory()->suspended()->create(['is_active' => true]);
        $inactiveVerifiedHospital = Hospital::factory()->inactive()->create();

        $verifiedCoordinator = $this->createHospitalCoordinator($verifiedHospital);
        $inactiveCoordinatorLink = $this->createHospitalCoordinator($verifiedHospital, linkActive: false);
        $inactiveCoordinatorAccount = $this->createHospitalCoordinator($verifiedHospital, userActive: false);
        $otherHospital = Hospital::factory()->create();
        $pendingCoordinator = $this->createHospitalCoordinator($pendingHospital);
        $suspendedCoordinator = $this->createHospitalCoordinator($suspendedHospital);
        $inactiveHospitalCoordinator = $this->createHospitalCoordinator($inactiveVerifiedHospital);

        $pendingRequest = BloodRequest::factory()->create([
            'hospital_id' => $verifiedHospital->id,
            'status' => 'Pending',
        ]);
        $approvedRequest = BloodRequest::factory()->approved()->create([
            'hospital_id' => $verifiedHospital->id,
        ]);
        $partialRequest = BloodRequest::factory()->partiallyFulfilled()->create([
            'hospital_id' => $verifiedHospital->id,
        ]);
        $otherPendingRequest = BloodRequest::factory()->create([
            'hospital_id' => $otherHospital->id,
            'status' => 'Pending',
        ]);

        // Creation rules.
        $this->assertTrue(Gate::forUser($admin)->allows('create', [BloodRequest::class, $verifiedHospital]));
        $this->assertTrue(Gate::forUser($staff)->allows('create', [BloodRequest::class, $verifiedHospital]));
        $this->assertTrue(Gate::forUser($verifiedCoordinator)->allows('create', [BloodRequest::class, $verifiedHospital]));

        $this->assertFalse(Gate::forUser($verifiedCoordinator)->allows('create', [BloodRequest::class, $otherHospital]));
        $this->assertFalse(Gate::forUser($pendingCoordinator)->allows('create', [BloodRequest::class, $pendingHospital]));
        $this->assertFalse(Gate::forUser($suspendedCoordinator)->allows('create', [BloodRequest::class, $suspendedHospital]));
        $this->assertFalse(Gate::forUser($inactiveHospitalCoordinator)->allows('create', [BloodRequest::class, $inactiveVerifiedHospital]));
        $this->assertFalse(Gate::forUser($inactiveCoordinatorLink)->allows('create', [BloodRequest::class, $verifiedHospital]));
        $this->assertFalse(Gate::forUser($inactiveCoordinatorAccount)->allows('create', [BloodRequest::class, $verifiedHospital]));
        $this->assertFalse(Gate::forUser($inactiveStaff)->allows('create', [BloodRequest::class, $verifiedHospital]));
        $this->assertFalse(Gate::forUser($labOfficer)->allows('create', [BloodRequest::class, $verifiedHospital]));
        $this->assertFalse(Gate::forUser($donor)->allows('create', [BloodRequest::class, $verifiedHospital]));

        // Fulfillment workflow actions (`requests.fulfill`: admin + staff only).
        foreach (['review', 'update', 'allocate', 'releaseAllocation', 'dispatch'] as $ability) {
            $this->assertTrue(Gate::forUser($admin)->allows($ability, $pendingRequest));
            $this->assertTrue(Gate::forUser($staff)->allows($ability, $pendingRequest));

            $this->assertFalse(Gate::forUser($inactiveStaff)->allows($ability, $pendingRequest));
            $this->assertFalse(Gate::forUser($labOfficer)->allows($ability, $pendingRequest));
            $this->assertFalse(Gate::forUser($donor)->allows($ability, $pendingRequest));
            $this->assertFalse(Gate::forUser($verifiedCoordinator)->allows($ability, $pendingRequest));
        }

        // Compatibility override (`requests.override_compatibility`: admin only; staff denied).
        $this->assertTrue(Gate::forUser($admin)->allows('overrideCompatibility', $approvedRequest));
        $this->assertFalse(Gate::forUser($staff)->allows('overrideCompatibility', $approvedRequest));
        $this->assertFalse(Gate::forUser($inactiveStaff)->allows('overrideCompatibility', $approvedRequest));
        $this->assertFalse(Gate::forUser($labOfficer)->allows('overrideCompatibility', $approvedRequest));
        $this->assertFalse(Gate::forUser($verifiedCoordinator)->allows('overrideCompatibility', $approvedRequest));

        // Cancellation: admin/staff on non-terminal requests; hospital coordinator on own Pending only.
        foreach ([$pendingRequest, $approvedRequest, $partialRequest] as $openRequest) {
            $this->assertTrue(Gate::forUser($admin)->allows('cancel', $openRequest));
            $this->assertTrue(Gate::forUser($staff)->allows('cancel', $openRequest));
            $this->assertFalse(Gate::forUser($inactiveStaff)->allows('cancel', $openRequest));
            $this->assertFalse(Gate::forUser($labOfficer)->allows('cancel', $openRequest));
            $this->assertFalse(Gate::forUser($donor)->allows('cancel', $openRequest));
        }

        $this->assertTrue(Gate::forUser($verifiedCoordinator)->allows('cancel', $pendingRequest));
        $this->assertFalse(Gate::forUser($verifiedCoordinator)->allows('cancel', $approvedRequest));
        $this->assertFalse(Gate::forUser($verifiedCoordinator)->allows('cancel', $partialRequest));
        $this->assertFalse(Gate::forUser($verifiedCoordinator)->allows('cancel', $otherPendingRequest));
        $this->assertFalse(Gate::forUser($inactiveCoordinatorLink)->allows('cancel', $pendingRequest));
        $this->assertFalse(Gate::forUser($inactiveCoordinatorAccount)->allows('cancel', $pendingRequest));
    }

    private function createUserWithRole(string $roleSlug, bool $isActive = true): User
    {
        $user = User::factory()->create(['is_active' => $isActive]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user;
    }

    private function createHospitalCoordinator(
        Hospital $hospital,
        bool $userActive = true,
        bool $linkActive = true
    ): User {
        $user = $this->createUserWithRole('hospital', isActive: $userActive);
        HospitalUser::factory()->create([
            'hospital_id' => $hospital->id,
            'user_id' => $user->id,
            'is_active' => $linkActive,
        ]);

        return $user;
    }
}
