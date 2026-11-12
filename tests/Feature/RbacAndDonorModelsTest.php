<?php

namespace Tests\Feature;

use App\Models\Donor;
use App\Models\DonorEligibilityRecord;
use App\Models\DonorMedicalProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacAndDonorModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_role_and_permission_relationships_and_scopes_resolve_accurately(): void
    {
        $admin = User::query()->create([
            'name' => 'System Admin',
            'email' => 'admin@bdms.test',
            'password' => 'secret-pass',
            'is_active' => true,
            'last_login_at' => '2026-11-12 08:30:00',
        ]);
        $inactiveUser = User::query()->create([
            'name' => 'Inactive Staff',
            'email' => 'inactive@bdms.test',
            'password' => 'secret-pass',
            'is_active' => false,
        ]);
        $role = Role::query()->create([
            'name' => 'Lab Officer',
            'slug' => 'lab_officer',
            'description' => 'Performs lab screening',
        ]);
        $permission = Permission::query()->create([
            'name' => 'View Medical Profile',
            'slug' => 'donors.view_medical',
            'module' => 'donors',
            'description' => 'Read sensitive clinical measurements',
        ]);

        $role->permissions()->attach($permission->id, ['created_at' => '2026-11-12 08:00:00']);
        $admin->roles()->attach($role->id, [
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => '2026-11-12 08:15:00',
        ]);

        $loadedRole = Role::bySlug('lab_officer')->with(['users', 'permissions'])->firstOrFail();

        $this->assertTrue($admin->is_active);
        $this->assertFalse($inactiveUser->is_active);
        $this->assertInstanceOf(CarbonInterface::class, $admin->last_login_at);
        $this->assertCount(1, User::active()->get());
        $this->assertSame($admin->id, User::withRole('lab_officer')->value('id'));
        $this->assertSame($admin->id, $loadedRole->users->first()?->id);
        $this->assertSame($admin->id, $loadedRole->users->first()?->pivot->assigned_by_user_id);
        $this->assertSame($permission->id, $loadedRole->permissions->first()?->id);
        $this->assertSame('donors', Permission::bySlug('donors.view_medical')->inModule('donors')->value('module'));
    }

    public function test_donor_mass_assignment_protects_read_only_projections_while_casting_attributes(): void
    {
        $portalUser = User::query()->create([
            'name' => 'Elena Rostova',
            'email' => 'elena@donor.test',
            'password' => 'secret-pass',
        ]);

        $donor = Donor::query()->create([
            'donor_code' => 'DNR-1041',
            'user_id' => $portalUser->id,
            'full_name' => 'Elena Rostova',
            'email' => 'elena@donor.test',
            'phone' => '+15551001',
            'gender' => 'Female',
            'date_of_birth' => '1993-04-18',
            'blood_group' => 'O+',
            'address' => '42 Oak Street',
            'emergency_contact' => 'Ivan Rostov (+15551002)',
            'availability_status' => 'Available',
            'account_status' => 'Active',
            'registered_at' => '2026-11-01',
            // Attempt to mass-assign system-managed read-only fields:
            'eligibility_status' => 'Eligible',
            'next_eligible_date' => '2026-12-30',
            'last_donation_date' => '2026-10-01',
            'total_completed_donations' => 9,
            'profile_completed' => true,
        ]);

        $fresh = $donor->fresh();

        $this->assertModelExists($fresh);
        $this->assertSame('Pending Review', $fresh->eligibility_status);
        $this->assertNull($fresh->next_eligible_date);
        $this->assertNull($fresh->last_donation_date);
        $this->assertSame(0, $fresh->total_completed_donations);
        $this->assertFalse($fresh->profile_completed);
        $this->assertInstanceOf(CarbonInterface::class, $fresh->date_of_birth);
        $this->assertInstanceOf(CarbonInterface::class, $fresh->registered_at);
        $this->assertSame($portalUser->id, $fresh->user->id);
        $this->assertSame($fresh->id, $portalUser->donor->id);
    }

    public function test_donor_medical_profile_and_authoritative_eligibility_records_resolve_correctly(): void
    {
        $clinician = User::query()->create([
            'name' => 'Dr. Maya Lin',
            'email' => 'maya@bdms.test',
            'password' => 'secret-pass',
        ]);
        $donor = Donor::query()->create([
            'donor_code' => 'DNR-1042',
            'full_name' => 'Marcus Vance',
            'email' => 'marcus@donor.test',
            'blood_group' => 'A-',
            'availability_status' => 'Available',
            'account_status' => 'Active',
            'registered_at' => '2026-10-15',
        ]);
        $donor->profile_completed = true;
        $donor->save();

        $medicalProfile = DonorMedicalProfile::query()->create([
            'donor_id' => $donor->id,
            'medical_notes' => 'Healthy vitals verified.',
            'last_hemoglobin_g_dl' => 14.25,
            'weight_kg' => 74.50,
            'deferral_notes' => null,
            'updated_by_user_id' => $clinician->id,
        ]);

        DonorEligibilityRecord::query()->create([
            'donor_id' => $donor->id,
            'eligibility_status' => 'Not Eligible',
            'reason' => 'Recent minor cold',
            'clearance_source' => 'Intake Triage',
            'reviewed_at' => '2026-10-20',
            'next_eligible_date' => '2026-11-05',
            'reviewed_by_user_id' => $clinician->id,
            'is_current' => false,
        ]);

        $currentEligibility = DonorEligibilityRecord::query()->create([
            'donor_id' => $donor->id,
            'eligibility_status' => 'Eligible',
            'reason' => 'Cleared after 14-day recovery window',
            'clearance_source' => 'Medical Officer Review',
            'reviewed_at' => '2026-11-10',
            'next_eligible_date' => '2027-01-10',
            'reviewed_by_user_id' => $clinician->id,
            'is_current' => true,
        ]);

        $donor->load(['medicalProfile', 'eligibilityRecords', 'currentEligibilityRecord']);

        $this->assertSame($medicalProfile->id, $donor->medicalProfile->id);
        $this->assertSame('14.25', $donor->medicalProfile->last_hemoglobin_g_dl);
        $this->assertSame('74.50', $donor->medicalProfile->weight_kg);
        $this->assertSame($clinician->id, $donor->medicalProfile->updatedBy->id);

        $this->assertCount(2, $donor->eligibilityRecords);
        $this->assertSame($currentEligibility->id, $donor->currentEligibilityRecord->id);
        $this->assertTrue($donor->currentEligibilityRecord->is_current);
        $this->assertTrue($donor->currentEligibilityRecord->current_record_guard);
        $this->assertInstanceOf(CarbonInterface::class, $donor->currentEligibilityRecord->reviewed_at);
        $this->assertInstanceOf(CarbonInterface::class, $donor->currentEligibilityRecord->next_eligible_date);
        $this->assertSame($clinician->id, $donor->currentEligibilityRecord->reviewedBy->id);

        $this->assertSame(1, DonorEligibilityRecord::current()->withStatus('Eligible')->count());
        $this->assertSame($donor->id, Donor::active()->available()->eligible()->profileCompleted()->forBloodGroup('A-')->value('id'));
    }
}
