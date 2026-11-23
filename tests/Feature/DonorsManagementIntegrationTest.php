<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\Donor;
use App\Models\DonorEligibilityRecord;
use App\Models\DonorMedicalProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonorsManagementIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_staff_can_view_donors_directory_with_real_database_records_and_summary_statistics(): void
    {
        $staff = $this->createUserWithRole('staff');

        $donor1 = Donor::factory()->create([
            'donor_code' => 'DNR-2001',
            'full_name' => 'Alice Walker',
            'blood_group' => 'O+',
            'account_status' => 'Active',
            'eligibility_status' => 'Eligible',
            'registered_at' => now(),
        ]);
        DonorEligibilityRecord::create([
            'donor_id' => $donor1->id,
            'eligibility_status' => 'Eligible',
            'reason' => 'Baseline intake cleared.',
            'clearance_source' => 'Clinical Screening',
            'is_current' => true,
        ]);

        $donor2 = Donor::factory()->create([
            'donor_code' => 'DNR-2002',
            'full_name' => 'Bob Builder',
            'blood_group' => 'A-',
            'account_status' => 'Inactive',
            'eligibility_status' => 'Not Eligible',
            'registered_at' => now()->subMonths(2),
        ]);
        DonorEligibilityRecord::create([
            'donor_id' => $donor2->id,
            'eligibility_status' => 'Not Eligible',
            'reason' => 'Temporary deferral.',
            'clearance_source' => 'Clinical Screening',
            'is_current' => true,
        ]);

        $response = $this->actingAs($staff)->get(route('donors.index'));

        $response->assertOk();
        $response->assertViewIs('donors.index');
        $response->assertViewHas('summary', function (array $summary): bool {
            return $summary['totalDonors'] === 2
                && $summary['activeDonors'] === 1
                && $summary['eligibleDonors'] === 1
                && $summary['newThisMonth'] === 1;
        });

        $response->assertSee('DNR-2001');
        $response->assertSee('Alice Walker');
        $response->assertSee('DNR-2002');
        $response->assertSee('Bob Builder');
    }

    public function test_donors_directory_search_and_filters_query_real_records(): void
    {
        $staff = $this->createUserWithRole('staff');

        $target = Donor::factory()->create([
            'donor_code' => 'DNR-8888',
            'full_name' => 'Zelda Fitzgerald',
            'email' => 'zelda@example.org',
            'phone' => '+15559876543',
            'blood_group' => 'AB+',
            'account_status' => 'Active',
            'eligibility_status' => 'Eligible',
        ]);
        DonorEligibilityRecord::create([
            'donor_id' => $target->id,
            'eligibility_status' => 'Eligible',
            'reason' => 'Cleared',
            'clearance_source' => 'Intake',
            'is_current' => true,
        ]);

        $other = Donor::factory()->create([
            'donor_code' => 'DNR-9999',
            'full_name' => 'F. Scott Fitzgerald',
            'email' => 'scott@example.org',
            'blood_group' => 'O-',
            'account_status' => 'Inactive',
            'eligibility_status' => 'Not Eligible',
        ]);
        DonorEligibilityRecord::create([
            'donor_id' => $other->id,
            'eligibility_status' => 'Not Eligible',
            'reason' => 'Deferred',
            'clearance_source' => 'Intake',
            'is_current' => true,
        ]);

        // Search by donor code
        $resCode = $this->actingAs($staff)->get(route('donors.index', ['search' => 'DNR-8888']));
        $resCode->assertOk();
        $resCode->assertSee('Zelda Fitzgerald');
        $resCode->assertDontSee('F. Scott Fitzgerald');

        // Filter by blood group
        $resGroup = $this->actingAs($staff)->get(route('donors.index', ['blood_group' => 'AB+']));
        $resGroup->assertOk();
        $resGroup->assertSee('Zelda Fitzgerald');
        $resGroup->assertDontSee('F. Scott Fitzgerald');

        // Filter by eligibility
        $resElig = $this->actingAs($staff)->get(route('donors.index', ['eligibility_status' => 'Eligible']));
        $resElig->assertOk();
        $resElig->assertSee('Zelda Fitzgerald');
        $resElig->assertDontSee('F. Scott Fitzgerald');

        // Filter by account status
        $resAcc = $this->actingAs($staff)->get(route('donors.index', ['account_status' => 'Inactive']));
        $resAcc->assertOk();
        $resAcc->assertSee('F. Scott Fitzgerald');
        $resAcc->assertDontSee('Zelda Fitzgerald');
    }

    public function test_staff_can_view_donor_profile_with_real_relations_and_donation_history(): void
    {
        $staff = $this->createUserWithRole('staff');

        $donor = Donor::factory()->create([
            'donor_code' => 'DNR-3001',
            'full_name' => 'Clara Barton',
            'email' => 'clara@redcross.org',
            'blood_group' => 'O-',
            'account_status' => 'Active',
            'eligibility_status' => 'Eligible',
            'last_donation_date' => '2026-08-15',
            'total_completed_donations' => 1,
        ]);

        DonorEligibilityRecord::create([
            'donor_id' => $donor->id,
            'eligibility_status' => 'Eligible',
            'reason' => 'Annual clearance verified by medical officer.',
            'clearance_source' => 'Medical Clearance',
            'reviewed_at' => '2026-08-15',
            'is_current' => true,
        ]);

        DonorMedicalProfile::create([
            'donor_id' => $donor->id,
            'medical_notes' => 'Rare universal red cell phenotype.',
            'updated_by_user_id' => $staff->id,
        ]);

        Donation::factory()->create([
            'donation_code' => 'DON-7777',
            'donor_id' => $donor->id,
            'donation_date' => '2026-08-15',
            'collection_location_name' => 'Metro Regional Center',
            'center_id' => null,
            'camp_id' => null,
            'collection_status' => 'Completed',
            'quantity_ml' => 450,
        ]);

        $response = $this->actingAs($staff)->get(route('donors.show', $donor));

        $response->assertOk();
        $response->assertViewIs('donors.show');
        $response->assertSee('Clara Barton');
        $response->assertSee('DNR-3001');
        $response->assertSee('clara@redcross.org');
        $response->assertSee('DON-7777');
        $response->assertSee('Metro Regional Center');
        $response->assertSee('Annual clearance verified by medical officer.');
    }

    public function test_medical_notes_privacy_guarded_by_view_medical_permission(): void
    {
        $staffWithoutMedical = User::factory()->create(['is_active' => true]);
        $staffRole = Role::where('slug', 'staff')->firstOrFail();
        $staffWithoutMedical->roles()->attach($staffRole);

        // Revoke donors.view_medical permission from this specific test user
        $medicalPerm = Permission::bySlug('donors.view_medical')->firstOrFail();
        $staffRole->permissions()->detach($medicalPerm->id);

        $donor = Donor::factory()->create([
            'donor_code' => 'DNR-3002',
            'full_name' => 'Secret Medical Donor',
        ]);

        DonorMedicalProfile::create([
            'donor_id' => $donor->id,
            'medical_notes' => 'Highly confidential clinical diagnostics.',
            'updated_by_user_id' => null,
        ]);

        // Unauthorized user cannot see confidential notes
        $responseUnauthorized = $this->actingAs($staffWithoutMedical)->get(route('donors.show', $donor));
        $responseUnauthorized->assertOk();
        $responseUnauthorized->assertDontSee('Highly confidential clinical diagnostics.');
        $responseUnauthorized->assertSee('[Protected clinical information - restricted to authorized medical personnel]');

        // Re-grant or test with Admin user
        $admin = $this->createUserWithRole('admin');
        $responseAdmin = $this->actingAs($admin)->get(route('donors.show', $donor));
        $responseAdmin->assertOk();
        $responseAdmin->assertSee('Highly confidential clinical diagnostics.');
    }

    public function test_staff_can_register_walk_in_donor_with_automatic_onboarding_eligibility(): void
    {
        $staff = $this->createUserWithRole('staff');

        $payload = [
            'name' => 'Julian Alvarez',
            'email' => 'julian.alvarez@example.org',
            'phone' => '+1 (555) 345-6789',
            'gender' => 'Male',
            'date_of_birth' => '1995-03-20',
            'blood_group' => 'B+',
            'address' => '742 Evergreen Terrace, Sector 4',
            'emergency_contact' => 'Maria Alvarez (Mother) - +1 (555) 345-6700',
            'availability_status' => 'Available',
            'medical_notes' => 'First-time walk-in donor; no known chronic conditions.',
            'weight_kg' => 74.5,
            'last_hemoglobin_g_dl' => 14.8,
        ];

        $response = $this->actingAs($staff)->post(route('donors.store'), $payload);

        $donor = Donor::query()->where('email', 'julian.alvarez@example.org')->first();

        $this->assertNotNull($donor);
        $this->assertNull($donor->user_id, 'Walk-in donors must not be linked to a user account.');
        $this->assertStringStartsWith('DNR-', $donor->donor_code);
        $this->assertSame('Julian Alvarez', $donor->full_name);
        $this->assertSame('B+', $donor->blood_group);
        $this->assertSame('Active', $donor->account_status);
        $this->assertSame('Pending Review', $donor->eligibility_status);
        $this->assertTrue($donor->profile_completed);

        // Check initial eligibility record was created
        $this->assertDatabaseHas('donor_eligibility_records', [
            'donor_id' => $donor->id,
            'eligibility_status' => 'Pending Review',
            'is_current' => true,
            'clearance_source' => 'Staff Walk-in Registration',
        ]);

        // Check medical profile was created
        $this->assertDatabaseHas('donor_medical_profiles', [
            'donor_id' => $donor->id,
            'medical_notes' => 'First-time walk-in donor; no known chronic conditions.',
            'weight_kg' => '74.50',
            'last_hemoglobin_g_dl' => '14.80',
            'updated_by_user_id' => $staff->id,
        ]);

        $response->assertRedirect(route('donors.show', $donor));
        $response->assertSessionHas('success', 'Donor registered successfully.');
    }

    public function test_donor_registration_validation_enforces_required_fields_unique_email_and_age_constraints(): void
    {
        $staff = $this->createUserWithRole('staff');

        $existing = Donor::factory()->create(['email' => 'duplicate@example.org']);

        // Missing fields
        $resMissing = $this->actingAs($staff)->post(route('donors.store'), []);
        $resMissing->assertSessionHasErrors([
            'name',
            'email',
            'phone',
            'gender',
            'date_of_birth',
            'blood_group',
            'address',
            'emergency_contact',
            'availability_status',
        ]);

        // Duplicate email
        $resDup = $this->actingAs($staff)->post(route('donors.store'), [
            'name' => 'Duplicate Tester',
            'email' => 'duplicate@example.org',
            'phone' => '+15551234567',
            'gender' => 'Other',
            'date_of_birth' => '1995-01-01',
            'blood_group' => 'A+',
            'address' => '123 Fake Street',
            'emergency_contact' => 'Contact Person - +15551234567',
            'availability_status' => 'Available',
        ]);
        $resDup->assertSessionHasErrors(['email']);

        // Under 16 years old
        $resUnderage = $this->actingAs($staff)->post(route('donors.store'), [
            'name' => 'Underage Donor',
            'email' => 'underage@example.org',
            'phone' => '+15551234567',
            'gender' => 'Female',
            'date_of_birth' => now()->subYears(15)->toDateString(),
            'blood_group' => 'O+',
            'address' => '123 Fake Street',
            'emergency_contact' => 'Parent - +15551234567',
            'availability_status' => 'Available',
        ]);
        $resUnderage->assertSessionHasErrors(['date_of_birth']);

        // Over 110 years old
        $resOverage = $this->actingAs($staff)->post(route('donors.store'), [
            'name' => 'Overage Donor',
            'email' => 'overage@example.org',
            'phone' => '+15551234567',
            'gender' => 'Male',
            'date_of_birth' => now()->subYears(115)->toDateString(),
            'blood_group' => 'O+',
            'address' => '123 Fake Street',
            'emergency_contact' => 'Kin - +15551234567',
            'availability_status' => 'Available',
        ]);
        $resOverage->assertSessionHasErrors(['date_of_birth']);
    }

    public function test_staff_can_update_donor_profile_preserving_protected_fields(): void
    {
        $staff = $this->createUserWithRole('staff');

        $donor = Donor::factory()->create([
            'donor_code' => 'DNR-4001',
            'full_name' => 'Original Name',
            'email' => 'original@example.org',
            'phone' => '+15551112222',
            'gender' => 'Male',
            'date_of_birth' => '1990-05-15',
            'blood_group' => 'O+',
            'address' => 'Old Address 123',
            'emergency_contact' => 'Old Contact +15551112222',
            'availability_status' => 'Available',
            'account_status' => 'Active',
        ]);

        $updatePayload = [
            'name' => 'Updated Name',
            'email' => 'updated@example.org',
            'phone' => '+15553334444',
            'gender' => 'Male',
            'date_of_birth' => '1990-05-15',
            'blood_group' => 'O+',
            'address' => 'New Address 456 Boulevard',
            'emergency_contact' => 'New Contact +15553334444',
            'availability_status' => 'Temporarily Unavailable',
            'medical_notes' => 'Updated clinical observation.',
        ];

        $response = $this->actingAs($staff)->put(route('donors.update', $donor), $updatePayload);

        $donor->refresh();

        $this->assertSame('Updated Name', $donor->full_name);
        $this->assertSame('updated@example.org', $donor->email);
        $this->assertSame('+15553334444', $donor->phone);
        $this->assertSame('New Address 456 Boulevard', $donor->address);
        $this->assertSame('Temporarily Unavailable', $donor->availability_status);
        $this->assertSame('DNR-4001', $donor->donor_code, 'donor_code must remain immutable.');

        $this->assertDatabaseHas('donor_medical_profiles', [
            'donor_id' => $donor->id,
            'medical_notes' => 'Updated clinical observation.',
            'updated_by_user_id' => $staff->id,
        ]);

        $response->assertRedirect(route('donors.show', $donor));
        $response->assertSessionHas('success', 'Donor updated successfully.');
    }

    public function test_authorized_admin_can_record_eligibility_assessment_synchronizing_projections(): void
    {
        $admin = $this->createUserWithRole('admin');

        $donor = Donor::factory()->create([
            'donor_code' => 'DNR-5001',
            'eligibility_status' => 'Pending Review',
        ]);

        $initialRecord = DonorEligibilityRecord::create([
            'donor_id' => $donor->id,
            'eligibility_status' => 'Pending Review',
            'reason' => 'Initial onboarding',
            'clearance_source' => 'Registration',
            'is_current' => true,
        ]);

        $payload = [
            'eligibility_status' => 'Eligible',
            'reason' => 'Comprehensive physical examination and hematology panel cleared.',
            'clearance_source' => 'Chief Medical Officer',
            'reviewed_at' => now()->toDateString(),
            'next_eligible_date' => now()->addDays(56)->toDateString(),
        ];

        $response = $this->actingAs($admin)->post(route('donors.eligibility.assess', $donor), $payload);

        $response->assertRedirect(route('donors.show', $donor));
        $response->assertSessionHas('success', 'Donor eligibility assessment recorded successfully.');

        $donor->refresh();
        $this->assertSame('Eligible', $donor->eligibility_status);
        $this->assertSame(now()->addDays(56)->toDateString(), $donor->next_eligible_date?->toDateString());

        // Previous record must no longer be current
        $initialRecord->refresh();
        $this->assertFalse($initialRecord->is_current);

        // New record must be current
        $currentRecord = $donor->currentEligibilityRecord;
        $this->assertNotNull($currentRecord);
        $this->assertSame('Eligible', $currentRecord->eligibility_status);
        $this->assertSame($admin->id, $currentRecord->reviewed_by_user_id);
        $this->assertSame('Chief Medical Officer', $currentRecord->clearance_source);
    }

    public function test_unauthorized_staff_cannot_assess_donor_eligibility(): void
    {
        $staff = $this->createUserWithRole('staff');

        $donor = Donor::factory()->create([
            'donor_code' => 'DNR-5002',
            'eligibility_status' => 'Pending Review',
        ]);

        $payload = [
            'eligibility_status' => 'Eligible',
            'reason' => 'Staff attempting bypass.',
        ];

        $response = $this->actingAs($staff)->post(route('donors.eligibility.assess', $donor), $payload);

        $response->assertForbidden();
    }

    public function test_unauthenticated_or_unauthorized_user_cannot_access_donors_management(): void
    {
        $donorModel = Donor::factory()->create(['donor_code' => 'DNR-6001']);

        // Guest redirected to login
        $this->get(route('donors.index'))->assertRedirect(route('login'));
        $this->get(route('donors.show', $donorModel))->assertRedirect(route('login'));
        $this->post(route('donors.store'), [])->assertRedirect(route('login'));

        // Donor portal user is forbidden from staff directory and mutation routes
        $donorUser = $this->createUserWithRole('donor');
        $this->actingAs($donorUser)->get(route('donors.index'))->assertForbidden();
        $this->actingAs($donorUser)->get(route('donors.show', $donorModel))->assertForbidden();
        $this->actingAs($donorUser)->get(route('donors.create'))->assertForbidden();
        $this->actingAs($donorUser)->post(route('donors.store'), [])->assertForbidden();
        $this->actingAs($donorUser)->get(route('donors.edit', $donorModel))->assertForbidden();
        $this->actingAs($donorUser)->put(route('donors.update', $donorModel), [])->assertForbidden();

        // Hospital portal user is also forbidden from administrative donor management
        $hospitalUser = $this->createUserWithRole('hospital');
        $this->actingAs($hospitalUser)->get(route('donors.index'))->assertForbidden();
        $this->actingAs($hospitalUser)->get(route('donors.show', $donorModel))->assertForbidden();
        $this->actingAs($hospitalUser)->get(route('donors.create'))->assertForbidden();
        $this->actingAs($hospitalUser)->post(route('donors.store'), [])->assertForbidden();
        $this->actingAs($hospitalUser)->get(route('donors.edit', $donorModel))->assertForbidden();
        $this->actingAs($hospitalUser)->put(route('donors.update', $donorModel), [])->assertForbidden();
    }

    public function test_donors_directory_authorization_boundaries_across_all_roles_and_multirole_accounts(): void
    {
        $hospitalRole = Role::query()->where('slug', 'hospital')->firstOrFail();
        $donorRole = Role::query()->where('slug', 'donor')->firstOrFail();

        // 1. Admin standalone has access (200 OK)
        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)->get(route('donors.index'))->assertOk();

        // 2. Multi-role Admin (admin + hospital) has access (200 OK)
        $adminWithHospital = $this->createUserWithRole('admin');
        $adminWithHospital->roles()->attach($hospitalRole->id, ['assigned_at' => now()]);
        $this->actingAs($adminWithHospital->fresh())->get(route('donors.index'))->assertOk();

        // 3. Multi-role Admin (admin + donor) has access (200 OK)
        $adminWithDonor = $this->createUserWithRole('admin');
        $adminWithDonor->roles()->attach($donorRole->id, ['assigned_at' => now()]);
        $this->actingAs($adminWithDonor->fresh())->get(route('donors.index'))->assertOk();

        // 4. Staff standalone has access (200 OK)
        $staff = $this->createUserWithRole('staff');
        $this->actingAs($staff)->get(route('donors.index'))->assertOk();

        // 5. Multi-role Staff (staff + hospital) has access (200 OK)
        $staffWithHospital = $this->createUserWithRole('staff');
        $staffWithHospital->roles()->attach($hospitalRole->id, ['assigned_at' => now()]);
        $this->actingAs($staffWithHospital->fresh())->get(route('donors.index'))->assertOk();

        // 6. Lab Officer standalone has access (200 OK)
        $labOfficer = $this->createUserWithRole('lab_officer');
        $this->actingAs($labOfficer)->get(route('donors.index'))->assertOk();

        // 7. Multi-role Lab Officer (lab_officer + donor) has access (200 OK)
        $labOfficerWithDonor = $this->createUserWithRole('lab_officer');
        $labOfficerWithDonor->roles()->attach($donorRole->id, ['assigned_at' => now()]);
        $this->actingAs($labOfficerWithDonor->fresh())->get(route('donors.index'))->assertOk();

        // 8. Donor standalone account is forbidden (403 Forbidden)
        $donor = $this->createUserWithRole('donor');
        $this->actingAs($donor)->get(route('donors.index'))->assertForbidden();

        // 9. Hospital standalone account is forbidden (403 Forbidden)
        $hospital = $this->createUserWithRole('hospital');
        $this->actingAs($hospital)->get(route('donors.index'))->assertForbidden();

        // 10. Multi-role non-operational user (donor + hospital) is forbidden (403 Forbidden)
        $donorAndHospital = $this->createUserWithRole('donor');
        $donorAndHospital->roles()->attach($hospitalRole->id, ['assigned_at' => now()]);
        $this->actingAs($donorAndHospital->fresh())->get(route('donors.index'))->assertForbidden();

        // 11. Inactive admin is forbidden (403 Forbidden)
        $inactiveAdmin = $this->createUserWithRole('admin', false);
        $this->actingAs($inactiveAdmin)->get(route('donors.index'))->assertForbidden();

        // 12. Unauthenticated guest is redirected to login (302 Redirect)
        auth()->logout();
        $this->get(route('donors.index'))->assertRedirect(route('login'));
    }

    private function createUserWithRole(string $roleSlug, bool $isActive = true): User
    {
        $user = User::factory()->create(['is_active' => $isActive]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user;
    }
}
