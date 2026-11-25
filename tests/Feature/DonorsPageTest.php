<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\Donor;
use App\Models\DonorEligibilityRecord;
use App\Models\DonorMedicalProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonorsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $staffUser = User::factory()->create();
        $staffUser->roles()->attach(Role::where('slug', 'staff')->firstOrFail());

        $this->actingAs($staffUser);

        $elena = Donor::factory()->create([
            'donor_code' => 'DNR-1041',
            'full_name' => 'Elena Rostova',
            'email' => 'elena.rostova@example.org',
            'phone' => '+1 (555) 234-8812',
            'gender' => 'Female',
            'date_of_birth' => '1996-04-18',
            'blood_group' => 'O-',
            'address' => '412 Oakridge Ave, Apt 4B, Central District',
            'emergency_contact' => 'Victor Rostov — +1 (555) 234-8890',
            'availability_status' => 'Available',
            'account_status' => 'Active',
            'eligibility_status' => 'Eligible',
            'last_donation_date' => '2026-07-22',
            'total_completed_donations' => 8,
            'registered_at' => now(),
            'profile_completed' => true,
        ]);

        DonorEligibilityRecord::create([
            'donor_id' => $elena->id,
            'eligibility_status' => 'Eligible',
            'reason' => 'Verified by clinical screening criteria; ready for scheduled collection.',
            'clearance_source' => 'Medical Officer Clearance',
            'reviewed_at' => '2026-07-22',
            'is_current' => true,
        ]);

        DonorMedicalProfile::create([
            'donor_id' => $elena->id,
            'medical_notes' => 'Cleared on prior physical & hemoglobin screening (13.9 g/dL). Universal RBC donor.',
            'updated_by_user_id' => $staffUser->id,
        ]);

        Donation::factory()->create([
            'donation_code' => 'DON-4012',
            'donor_id' => $elena->id,
            'donation_date' => '2026-07-22',
            'collection_location_name' => 'Central Medical Plaza',
            'center_id' => null,
            'camp_id' => null,
            'collection_status' => 'Completed',
            'quantity_ml' => 450,
        ]);

        $marcus = Donor::factory()->create([
            'donor_code' => 'DNR-1042',
            'full_name' => 'Marcus Vance',
            'email' => 'marcus.vance@example.org',
            'phone' => '+1 (555) 391-4420',
            'gender' => 'Male',
            'date_of_birth' => '1991-09-03',
            'blood_group' => 'A+',
            'address' => '88 Harbor View Rd, Eastside',
            'emergency_contact' => 'Claire Vance — +1 (555) 391-0014',
            'availability_status' => 'Temporarily Unavailable',
            'account_status' => 'Active',
            'registered_at' => now(),
            'profile_completed' => true,
        ]);

        DonorEligibilityRecord::create([
            'donor_id' => $marcus->id,
            'eligibility_status' => 'Not Eligible',
            'reason' => 'Currently ineligible per verified post-donation clinical deferral rule.',
            'clearance_source' => 'Clinical Deferral',
            'is_current' => true,
        ]);

        $priya = Donor::factory()->create([
            'donor_code' => 'DNR-1043',
            'full_name' => 'Priya Sharma',
            'email' => 'priya.sharma@example.org',
            'phone' => '+1 (555) 612-7745',
            'gender' => 'Female',
            'date_of_birth' => '1999-11-12',
            'blood_group' => 'B+',
            'address' => '19 Cedar Crest Blvd, West End',
            'emergency_contact' => 'Rohan Sharma — +1 (555) 612-7700',
            'availability_status' => 'Available',
            'account_status' => 'Active',
            'registered_at' => now(),
            'profile_completed' => true,
        ]);

        DonorEligibilityRecord::create([
            'donor_id' => $priya->id,
            'eligibility_status' => 'Pending Review',
            'reason' => 'First-time registrant awaiting initial medical screening verification.',
            'clearance_source' => 'Donor Self-Registration',
            'is_current' => true,
        ]);
    }

    public function test_donors_directory_renders_overview_statistics_filters_and_all_columns(): void
    {
        $response = $this->get(route('donors.index'));

        $response->assertOk();
        $response->assertViewIs('donors.index');
        $response->assertViewHas('donors');
        $response->assertViewHas('summary');
        $response->assertViewHas('bloodGroups');

        $response->assertSee('Donors Directory');
        $response->assertSee('Add Donor');

        // Overview statistics cards
        $response->assertSee('Total Donors');
        $response->assertSee('Active Donors');
        $response->assertSee('Eligible Donors');
        $response->assertSee('New Donors This Month');

        // Table headers
        foreach ([
            'Donor ID',
            'Full Name',
            'Blood Group',
            'Gender',
            'Phone Number',
            'Last Donation Date',
            'Eligibility Status',
            'Account Status',
            'Actions',
        ] as $column) {
            $response->assertSee($column);
        }

        // Eligibility status indicators
        $response->assertSee('Eligible');
        $response->assertSee('Not Eligible');
        $response->assertSee('Pending Review');

        // Sample donor records & action links
        $response->assertSee('DNR-1041');
        $response->assertSee('Elena Rostova');
        $response->assertSee('DNR-1042');
        $response->assertSee('Marcus Vance');
        $response->assertSee('DNR-1043');
        $response->assertSee('Priya Sharma');
        $response->assertSee(route('donors.create'));
        $response->assertSee(route('donors.show', 'DNR-1041'));
        $response->assertSee(route('donors.edit', 'DNR-1041'));
    }

    public function test_add_donor_page_renders_all_required_registration_fields_and_blood_groups(): void
    {
        $response = $this->get(route('donors.create'));

        $response->assertOk();
        $response->assertViewIs('donors.create');
        $response->assertSee('Register New Donor');

        foreach ([
            'Full Name',
            'Email',
            'Phone Number',
            'Gender',
            'Date of Birth',
            'Blood Group',
            'Address',
            'Emergency Contact',
            'Medical Notes',
            'Availability Status',
        ] as $fieldLabel) {
            $response->assertSee($fieldLabel);
        }

        foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bloodGroup) {
            $response->assertSee($bloodGroup);
        }
    }

    public function test_donor_profile_page_displays_personal_contact_eligibility_and_donation_history(): void
    {
        $response = $this->get(route('donors.show', 'DNR-1041'));

        $response->assertOk();
        $response->assertViewIs('donors.show');
        $response->assertViewHas('donor');

        $response->assertSee('Elena Rostova');
        $response->assertSee('DNR-1041');
        $response->assertSee('elena.rostova@example.org');
        $response->assertSee('+1 (555) 234-8812');
        $response->assertSee('O-');
        $response->assertSee('Eligible');
        $response->assertSee('Jul 22, 2026');
        $response->assertSee('Personal Information');
        $response->assertSee('Contact Information');
        $response->assertSee('Donation History');
        $response->assertSee('DON-4012');
        $response->assertSee('Central Medical Plaza');
    }

    public function test_donor_profile_page_displays_empty_state_when_donor_has_no_donations(): void
    {
        $response = $this->get(route('donors.show', 'DNR-1043'));

        $response->assertOk();
        $response->assertSee('Priya Sharma');
        $response->assertSee('Pending Review');
        $response->assertSee('No donation history on record');
    }

    public function test_edit_donor_page_prefills_sample_donor_details(): void
    {
        $response = $this->get(route('donors.edit', 'DNR-1041'));

        $response->assertOk();
        $response->assertViewIs('donors.edit');
        $response->assertViewHas('donor');

        $response->assertSee('Edit Donor');
        $response->assertSee('Elena Rostova');
        $response->assertSee('elena.rostova@example.org');
        $response->assertSee('+1 (555) 234-8812');
        $response->assertSee('412 Oakridge Ave, Apt 4B, Central District');
        $response->assertSee('Save Changes');
    }

    public function test_unknown_donor_routes_return_not_found(): void
    {
        $this->get(route('donors.show', 'DNR-9999'))->assertNotFound();
        $this->get(route('donors.edit', 'DNR-9999'))->assertNotFound();
    }

    public function test_donor_create_and_edit_date_inputs_support_english_ltr_and_persian_rtl_modes(): void
    {
        // English (LTR) mode: /donors/create and /donors/{donor}/edit
        $enCreate = $this->withSession(['locale' => 'en'])->get(route('donors.create'));
        $enCreate->assertOk();
        $enCreate->assertSee('dir="ltr"', false);
        $enCreate->assertSee('id="donor-dob"', false);
        $enCreate->assertSee('name="date_of_birth"', false);
        $enCreate->assertSee('type="date"', false);
        $enCreate->assertSee('start-0 flex items-center ps-3', false);
        $enCreate->assertSee('ps-10', false);
        $enCreate->assertSee('Date of Birth');

        $enEdit = $this->withSession(['locale' => 'en'])->get(route('donors.edit', 'DNR-1041'));
        $enEdit->assertOk();
        $enEdit->assertSee('dir="ltr"', false);
        $enEdit->assertSee('id="donor-dob"', false);
        $enEdit->assertSee('type="date"', false);

        // Persian (RTL) mode: /donors/create and /donors/{donor}/edit
        $faCreate = $this->withSession(['locale' => 'fa'])->get(route('donors.create'));
        $faCreate->assertOk();
        $faCreate->assertSee('dir="rtl"', false);
        $faCreate->assertSee('id="donor-dob"', false);
        $faCreate->assertSee('name="date_of_birth"', false);
        $faCreate->assertSee('type="date"', false);
        $faCreate->assertSee('start-0 flex items-center ps-3', false);
        $faCreate->assertSee('ps-10', false);
        $faCreate->assertSee('تاریخ تولد');

        $faEdit = $this->withSession(['locale' => 'fa'])->get(route('donors.edit', 'DNR-1041'));
        $faEdit->assertOk();
        $faEdit->assertSee('dir="rtl"', false);
        $faEdit->assertSee('id="donor-dob"', false);
        $faEdit->assertSee('type="date"', false);
        $faEdit->assertSee('تاریخ تولد');
    }
}
