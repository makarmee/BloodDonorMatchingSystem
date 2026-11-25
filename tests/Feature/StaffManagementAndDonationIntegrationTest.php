<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\DonorEligibilityRecord;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class StaffManagementAndDonationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staffUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            BloodComponentSeeder::class,
            ScreeningTestTypeSeeder::class,
            ComponentScreeningRequirementSeeder::class,
        ]);

        $this->admin = User::factory()->create(['name' => 'System Admin', 'is_active' => true]);
        $this->admin->roles()->attach(Role::where('slug', 'admin')->firstOrFail());

        $this->staffUser = User::factory()->create(['name' => 'Collection Staff', 'is_active' => true]);
        $this->staffUser->roles()->attach(Role::where('slug', 'staff')->firstOrFail());
    }

    public function test_authorized_admin_can_access_staff_management_pages(): void
    {
        $staffMember = Staff::factory()->active()->create([
            'employee_id' => 'EMP-1001',
            'full_name' => 'Dr. Nadia Rahimi',
        ]);

        $this->actingAs($this->admin)->get(route('staff.index'))->assertOk()->assertViewIs('staff.index');
        $this->actingAs($this->admin)->get(route('staff.create'))->assertOk()->assertViewIs('staff.create');
        $this->actingAs($this->admin)->get(route('staff.show', $staffMember))->assertOk()->assertViewIs('staff.show');
        $this->actingAs($this->admin)->get(route('staff.edit', $staffMember))->assertOk()->assertViewIs('staff.edit');
    }

    #[TestWith(['staff'])]
    #[TestWith(['lab_officer'])]
    #[TestWith(['hospital'])]
    #[TestWith(['donor'])]
    public function test_non_admin_roles_cannot_manage_staff(string $roleSlug): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('slug', $roleSlug)->firstOrFail());

        $staffMember = Staff::factory()->active()->create();

        $this->actingAs($user)->get(route('staff.index'))->assertForbidden();
        $this->actingAs($user)->get(route('staff.create'))->assertForbidden();
        $this->actingAs($user)->post(route('staff.store'), [])->assertForbidden();
        $this->actingAs($user)->get(route('staff.show', $staffMember))->assertForbidden();
        $this->actingAs($user)->get(route('staff.edit', $staffMember))->assertForbidden();
        $this->actingAs($user)->put(route('staff.update', $staffMember), [])->assertForbidden();
        $this->actingAs($user)->post(route('staff.toggle-status', $staffMember))->assertForbidden();
        $this->actingAs($user)->delete(route('staff.destroy', $staffMember))->assertForbidden();
    }

    public function test_guests_and_inactive_admins_cannot_access_staff_management(): void
    {
        $this->get(route('staff.index'))->assertRedirect(route('login'));
        $this->get(route('staff.create'))->assertRedirect(route('login'));

        $inactiveAdmin = User::factory()->create(['is_active' => false]);
        $inactiveAdmin->roles()->attach(Role::where('slug', 'admin')->firstOrFail());

        $this->actingAs($inactiveAdmin)->get(route('staff.index'))->assertForbidden();
    }

    public function test_admin_can_create_staff_member_without_creating_user_login_account(): void
    {
        $initialUserCount = User::query()->count();

        $response = $this->actingAs($this->admin)->post(route('staff.store'), [
            'full_name' => 'Samira Karimi',
            'employee_id' => 'EMP-2040',
            'professional_role' => 'Phlebotomist',
            'department' => 'Donor Collection & Phlebotomy',
            'phone' => '+93-700-123-456',
            'email' => 'samira.karimi@bdms.local',
            'status' => 'Active',
            'notes' => 'Lead morning shift phlebotomist.',
        ]);

        $created = Staff::query()->where('employee_id', 'EMP-2040')->firstOrFail();

        $response->assertRedirect(route('staff.show', $created));
        $this->assertSame('Samira Karimi', $created->full_name);
        $this->assertSame('Phlebotomist', $created->professional_role);
        $this->assertSame('Donor Collection & Phlebotomy', $created->department);
        $this->assertSame('Active', $created->status);
        $this->assertNull($created->user_id);
        $this->assertSame($initialUserCount, User::query()->count(), 'Creating a staff member must not auto-create a login account.');
    }

    public function test_admin_can_edit_and_optionally_link_user_to_staff_member(): void
    {
        $staffMember = Staff::factory()->active()->create([
            'employee_id' => 'EMP-3001',
            'full_name' => 'Farid Ahmadi',
            'professional_role' => 'Nurse',
            'department' => 'Donor Collection & Phlebotomy',
        ]);

        $response = $this->actingAs($this->admin)->put(route('staff.update', $staffMember), [
            'full_name' => 'Dr. Farid Ahmadi',
            'employee_id' => 'EMP-3001',
            'professional_role' => 'Medical Officer',
            'department' => 'Administration & Quality',
            'phone' => '+1-555-880-1122',
            'email' => 'farid.ahmadi@bdms.local',
            'status' => 'Active',
            'user_id' => $this->staffUser->id,
            'notes' => 'Promoted to Medical Officer.',
        ]);

        $response->assertRedirect(route('staff.show', $staffMember->fresh()));

        $staffMember->refresh();
        $this->assertSame('Dr. Farid Ahmadi', $staffMember->full_name);
        $this->assertSame('Medical Officer', $staffMember->professional_role);
        $this->assertSame('Administration & Quality', $staffMember->department);
        $this->assertSame($this->staffUser->id, $staffMember->user_id);
    }

    public function test_staff_directory_search_and_filters_work_accurately(): void
    {
        Staff::factory()->active()->create([
            'employee_id' => 'EMP-4001',
            'full_name' => 'Layla Mansoor',
            'professional_role' => 'Phlebotomist',
            'department' => 'Donor Collection & Phlebotomy',
        ]);

        Staff::factory()->inactive()->create([
            'employee_id' => 'EMP-4002',
            'full_name' => 'Tariq Haidari',
            'professional_role' => 'Laboratory Technician',
            'department' => 'Laboratory & Screening',
        ]);

        // Search by Employee ID
        $this->actingAs($this->admin)
            ->get(route('staff.index', ['search' => 'EMP-4001']))
            ->assertOk()
            ->assertSee('Layla Mansoor')
            ->assertDontSee('Tariq Haidari');

        // Filter by Department
        $this->actingAs($this->admin)
            ->get(route('staff.index', ['department' => 'Laboratory & Screening']))
            ->assertOk()
            ->assertSee('Tariq Haidari')
            ->assertDontSee('Layla Mansoor');

        // Filter by Professional Role
        $this->actingAs($this->admin)
            ->get(route('staff.index', ['professional_role' => 'Phlebotomist']))
            ->assertOk()
            ->assertSee('Layla Mansoor')
            ->assertDontSee('Tariq Haidari');

        // Filter by Status
        $this->actingAs($this->admin)
            ->get(route('staff.index', ['status' => 'Inactive']))
            ->assertOk()
            ->assertSee('Tariq Haidari')
            ->assertDontSee('Layla Mansoor');
    }

    public function test_staff_validation_enforces_unique_employee_id_and_rejects_invalid_inputs(): void
    {
        Staff::factory()->active()->create(['employee_id' => 'EMP-9001', 'email' => 'taken@bdms.local']);

        $response = $this->actingAs($this->admin)->post(route('staff.store'), [
            'full_name' => 'A',
            'employee_id' => 'EMP-9001',
            'professional_role' => 'Invalid Role',
            'department' => 'Invalid Dept',
            'phone' => 'abc',
            'email' => 'taken@bdms.local',
            'status' => 'Unknown',
        ]);

        $response->assertSessionHasErrors([
            'full_name',
            'employee_id',
            'professional_role',
            'department',
            'phone',
            'email',
            'status',
        ]);
    }

    public function test_staff_activation_deactivation_and_deletion_protection_for_referenced_donations(): void
    {
        $unassignedStaff = Staff::factory()->active()->create(['employee_id' => 'EMP-5001']);
        $assignedStaff = Staff::factory()->active()->create(['employee_id' => 'EMP-5002']);

        // Deactivate and reactivate
        $this->actingAs($this->admin)
            ->post(route('staff.toggle-status', $assignedStaff))
            ->assertRedirect();
        $this->assertSame('Inactive', $assignedStaff->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('staff.toggle-status', $assignedStaff))
            ->assertRedirect();
        $this->assertSame('Active', $assignedStaff->fresh()->status);

        // Link assignedStaff to a donation
        $component = BloodComponent::where('code', 'WB')->firstOrFail();
        $donor = Donor::factory()->eligible()->create();
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();

        Donation::factory()->create([
            'donor_id' => $donor->id,
            'blood_component_id' => $component->id,
            'staff_id' => $assignedStaff->id,
            'staff_member_name' => $assignedStaff->full_name,
        ]);

        // Deleting assigned staff must be blocked
        $this->actingAs($this->admin)
            ->delete(route('staff.destroy', $assignedStaff))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertDatabaseHas('staff', ['id' => $assignedStaff->id]);

        // Deleting unassigned staff succeeds
        $this->actingAs($this->admin)
            ->delete(route('staff.destroy', $unassignedStaff))
            ->assertRedirect(route('staff.index'));
        $this->assertDatabaseMissing('staff', ['id' => $unassignedStaff->id]);
    }

    public function test_donation_creation_dropdown_shows_only_active_staff_and_no_hardcoded_samples(): void
    {
        $activeStaff = Staff::factory()->active()->create([
            'employee_id' => 'EMP-6001',
            'full_name' => 'Active Nurse Zohra',
        ]);

        $inactiveStaff = Staff::factory()->inactive()->create([
            'employee_id' => 'EMP-6002',
            'full_name' => 'Inactive Doctor Omar',
        ]);

        $response = $this->actingAs($this->staffUser)->get(route('donations.create'));

        $response->assertOk();
        $response->assertSee('name="staff_id"', false);
        $response->assertSee('Active Nurse Zohra (EMP-6001)');
        $response->assertDontSee('Inactive Doctor Omar (EMP-6002)');
        $response->assertDontSee('Nurse Hannah Reed');
        $response->assertDontSee('Dr. Maya Lin');
        $response->assertDontSee('Dr. Tariq Nassar');
        $response->assertDontSee('Nurse Clara Diaz');
        $response->assertSee('<option value="'.$activeStaff->id.'"', false);
        $response->assertDontSee('<option value="'.$inactiveStaff->id.'"', false);
    }

    public function test_donation_creation_stores_staff_id_and_rejects_invalid_or_inactive_staff(): void
    {
        $activeStaff = Staff::factory()->active()->create([
            'employee_id' => 'EMP-7001',
            'full_name' => 'Phlebotomist Reza',
        ]);
        $inactiveStaff = Staff::factory()->inactive()->create([
            'employee_id' => 'EMP-7002',
            'full_name' => 'Inactive Staff Bilal',
        ]);

        $donor = Donor::factory()->eligible()->create(['blood_group' => 'O+']);
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();

        // Reject inactive staff
        $this->actingAs($this->staffUser)->post(route('donations.store'), [
            'donor_id' => $donor->donor_code,
            'donation_date' => now()->toDateString(),
            'donation_type' => 'Whole Blood',
            'quantity_ml' => 450,
            'collection_status' => 'Scheduled',
            'collection_location' => 'Main Center',
            'staff_id' => $inactiveStaff->id,
        ])->assertSessionHasErrors('staff_id');

        // Reject nonexistent staff
        $this->actingAs($this->staffUser)->post(route('donations.store'), [
            'donor_id' => $donor->donor_code,
            'donation_date' => now()->toDateString(),
            'donation_type' => 'Whole Blood',
            'quantity_ml' => 450,
            'collection_status' => 'Scheduled',
            'collection_location' => 'Main Center',
            'staff_id' => 999999,
        ])->assertSessionHasErrors('staff_id');

        // Reject missing staff_id when submitted via form
        $this->actingAs($this->staffUser)->post(route('donations.store'), [
            'donor_id' => $donor->donor_code,
            'donation_date' => now()->toDateString(),
            'donation_type' => 'Whole Blood',
            'quantity_ml' => 450,
            'collection_status' => 'Scheduled',
            'collection_location' => 'Main Center',
        ])->assertSessionHasErrors('staff_id');

        // Accept active staff
        $response = $this->actingAs($this->staffUser)->post(route('donations.store'), [
            'donor_id' => $donor->donor_code,
            'donation_date' => now()->toDateString(),
            'donation_type' => 'Whole Blood',
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location' => 'Main Center',
            'staff_id' => $activeStaff->id,
        ]);

        $donation = Donation::query()->where('donor_id', $donor->id)->firstOrFail();
        $response->assertRedirect(route('donations.show', $donation));
        $this->assertSame($activeStaff->id, $donation->staff_id);
        $this->assertSame('Phlebotomist Reza', $donation->staff_member_name);
    }

    public function test_donation_edit_preserves_and_displays_historical_inactive_staff_assignment(): void
    {
        $assignedStaff = Staff::factory()->active()->create([
            'employee_id' => 'EMP-8001',
            'full_name' => 'Historical Nurse Maryam',
        ]);
        $otherInactiveStaff = Staff::factory()->inactive()->create([
            'employee_id' => 'EMP-8002',
            'full_name' => 'Other Inactive Staff',
        ]);
        $activeReplacement = Staff::factory()->active()->create([
            'employee_id' => 'EMP-8003',
            'full_name' => 'Active Replacement Staff',
        ]);

        $donor = Donor::factory()->eligible()->create(['blood_group' => 'A+']);
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::where('code', 'WB')->firstOrFail();

        $donation = Donation::factory()->create([
            'donor_id' => $donor->id,
            'blood_component_id' => $component->id,
            'blood_group' => 'A+',
            'staff_id' => $assignedStaff->id,
            'staff_member_name' => $assignedStaff->full_name,
            'collection_location_name' => 'Central Hub',
        ]);

        // Deactivate the assigned staff member after the donation was recorded
        $assignedStaff->update(['status' => 'Inactive']);

        // Edit page must still show Historical Nurse Maryam (EMP-8001) selected, plus active options, without other inactive staff
        $editResponse = $this->actingAs($this->staffUser)->get(route('donations.edit', $donation));
        $editResponse->assertOk();
        $editResponse->assertSee('Historical Nurse Maryam (EMP-8001)');
        $editResponse->assertSee('Active Replacement Staff (EMP-8003)');
        $editResponse->assertDontSee('Other Inactive Staff (EMP-8002)');

        // Show page preserves historical attribution
        $this->actingAs($this->staffUser)
            ->get(route('donations.show', $donation))
            ->assertOk()
            ->assertSee('Historical Nurse Maryam')
            ->assertSee('EMP-8001');

        // Updating other fields while keeping historical inactive staff_id succeeds
        $this->actingAs($this->staffUser)->put(route('donations.update', $donation), [
            'collection_location' => 'Central Hub Wing B',
            'staff_id' => $assignedStaff->id,
            'collection_notes' => 'Updated note preserving original collector.',
        ])->assertRedirect(route('donations.show', $donation));

        $this->assertSame($assignedStaff->id, $donation->fresh()->staff_id);
        $this->assertSame('Central Hub Wing B', $donation->fresh()->collection_location_name);

        // Switching to a different inactive staff member is strictly rejected
        $this->actingAs($this->staffUser)->put(route('donations.update', $donation), [
            'collection_location' => 'Central Hub Wing B',
            'staff_id' => $otherInactiveStaff->id,
        ])->assertSessionHasErrors('staff_id');

        // Switching to an active staff member succeeds
        $this->actingAs($this->staffUser)->put(route('donations.update', $donation), [
            'collection_location' => 'Central Hub Wing B',
            'staff_id' => $activeReplacement->id,
        ])->assertRedirect(route('donations.show', $donation));

        $this->assertSame($activeReplacement->id, $donation->fresh()->staff_id);
        $this->assertSame('Active Replacement Staff', $donation->fresh()->staff_member_name);
    }

    public function test_staff_management_renders_clean_persian_rtl_interface(): void
    {
        $staffMember = Staff::factory()->active()->create([
            'employee_id' => 'EMP-9501',
            'full_name' => 'داکتر احمدی',
        ]);

        foreach ([
            route('staff.index'),
            route('staff.create'),
            route('staff.show', $staffMember),
            route('staff.edit', $staffMember),
        ] as $url) {
            $response = $this->actingAs($this->admin)
                ->withSession(['locale' => 'fa'])
                ->get($url);

            $response->assertOk();
            $response->assertSee('dir="rtl"', false);
        }

        $this->actingAs($this->admin)
            ->withSession(['locale' => 'fa'])
            ->get(route('staff.index'))
            ->assertSee('فهرست کارمندان')
            ->assertSee('داکتر احمدی');
    }
}
