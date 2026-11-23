<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BloodCompatibilityRuleSeeder;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BloodRequestCreateAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            BloodComponentSeeder::class,
            ScreeningTestTypeSeeder::class,
            ComponentScreeningRequirementSeeder::class,
            BloodCompatibilityRuleSeeder::class,
        ]);
    }

    public function test_admin_can_access_blood_request_create_page(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach(Role::where('slug', 'admin')->firstOrFail());

        $verifiedHospital = Hospital::factory()->create([
            'is_active' => true,
            'verification_status' => 'Verified',
        ]);

        $response = $this->actingAs($admin)->get(route('requests.create'));

        $response->assertOk();
        $response->assertViewIs('requests.create');
        $response->assertSee('Create New Blood Request');
        $response->assertSee($verifiedHospital->name);
    }

    public function test_verified_hospital_representative_can_access_blood_request_create_page_and_see_form(): void
    {
        $hospital = Hospital::factory()->create([
            'is_active' => true,
            'verification_status' => 'Verified',
            'name' => 'Metro General Hospital',
            'primary_contact_name' => 'Dr. Samantha Cole',
            'primary_phone' => '+1 (555) 301-4400',
            'primary_email' => 'er@metrohospital.org',
            'address' => '500 Central Ave, Medical Ward 3',
        ]);

        $coordinator = User::factory()->create(['is_active' => true, 'name' => 'Dr. Samantha Cole']);
        $coordinator->roles()->attach(Role::where('slug', 'hospital')->firstOrFail());

        HospitalUser::factory()->create([
            'user_id' => $coordinator->id,
            'hospital_id' => $hospital->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($coordinator)->get(route('requests.create'));

        $response->assertOk();
        $response->assertViewIs('requests.create');
        $response->assertSee('Metro General Hospital');
        $response->assertSee('Dr. Samantha Cole');
    }

    public function test_unverified_or_pending_hospital_representative_receives_403_forbidden(): void
    {
        $hospital = Hospital::factory()->create([
            'is_active' => true,
            'verification_status' => 'Pending',
        ]);

        $coordinator = User::factory()->create(['is_active' => true]);
        $coordinator->roles()->attach(Role::where('slug', 'hospital')->firstOrFail());

        HospitalUser::factory()->create([
            'user_id' => $coordinator->id,
            'hospital_id' => $hospital->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($coordinator)->get(route('requests.create'));

        $response->assertForbidden();
        $response->assertSee('Your hospital account is currently pending administrative verification');
    }

    public function test_suspended_hospital_representative_receives_403_forbidden(): void
    {
        $hospital = Hospital::factory()->create([
            'is_active' => true,
            'verification_status' => 'Suspended',
        ]);

        $coordinator = User::factory()->create(['is_active' => true]);
        $coordinator->roles()->attach(Role::where('slug', 'hospital')->firstOrFail());

        HospitalUser::factory()->create([
            'user_id' => $coordinator->id,
            'hospital_id' => $hospital->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($coordinator)->get(route('requests.create'));

        $response->assertForbidden();
    }

    public function test_donor_receives_403_forbidden_on_requests_create(): void
    {
        $donor = User::factory()->create(['is_active' => true]);
        $donor->roles()->attach(Role::where('slug', 'donor')->firstOrFail());

        $response = $this->actingAs($donor)->get(route('requests.create'));

        $response->assertForbidden();
        $this->assertNotSame(404, $response->status());
    }

    public function test_lab_officer_receives_403_forbidden_on_requests_create(): void
    {
        $labOfficer = User::factory()->create(['is_active' => true]);
        $labOfficer->roles()->attach(Role::where('slug', 'lab_officer')->firstOrFail());

        $response = $this->actingAs($labOfficer)->get(route('requests.create'));

        $response->assertForbidden();
        $this->assertNotSame(404, $response->status());
    }

    public function test_unauthenticated_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('requests.create'));

        $response->assertRedirect(route('login'));
        $this->assertNotSame(404, $response->status());
    }

    public function test_create_page_loads_with_all_expected_form_fields(): void
    {
        $hospital = Hospital::factory()->create([
            'is_active' => true,
            'verification_status' => 'Verified',
        ]);

        $coordinator = User::factory()->create(['is_active' => true]);
        $coordinator->roles()->attach(Role::where('slug', 'hospital')->firstOrFail());

        HospitalUser::factory()->create([
            'user_id' => $coordinator->id,
            'hospital_id' => $hospital->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($coordinator)->get(route('requests.create'));

        $response->assertOk();

        foreach ([
            'Hospital Information',
            'Request Information',
            'Hospital Name',
            'Contact Person',
            'Contact Number',
            'Hospital Address',
            'Email',
            'Blood Group',
            'Number of Units Required',
            'Required Date',
            'Priority',
            'Request Reason',
            'Additional Notes',
        ] as $expectedLabel) {
            $response->assertSee($expectedLabel);
        }

        foreach ([
            'name="hospital_name"',
            'name="contact_person"',
            'name="contact_number"',
            'name="hospital_address"',
            'name="email"',
            'name="blood_group"',
            'name="requested_units"',
            'name="required_date"',
            'name="priority"',
            'name="request_reason"',
            'name="additional_notes"',
        ] as $expectedInput) {
            $response->assertSee($expectedInput, false);
        }
    }

    public function test_submitting_valid_blood_request_works_end_to_end_for_verified_hospital(): void
    {
        $hospital = Hospital::factory()->create([
            'is_active' => true,
            'verification_status' => 'Verified',
            'name' => 'St. Jude Children Research Hospital',
        ]);

        $coordinator = User::factory()->create(['is_active' => true, 'name' => 'Dr. Jude']);
        $coordinator->roles()->attach(Role::where('slug', 'hospital')->firstOrFail());

        HospitalUser::factory()->create([
            'user_id' => $coordinator->id,
            'hospital_id' => $hospital->id,
            'is_active' => true,
        ]);

        $prbc = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();

        $response = $this->actingAs($coordinator)->post(route('requests.store'), [
            'blood_group' => 'O-',
            'blood_component_id' => $prbc->id,
            'requested_units' => 4,
            'priority' => 'Critical',
            'request_date' => now()->toDateString(),
            'required_date' => now()->addDays(2)->toDateString(),
            'request_reason' => 'Emergency pediatric trauma transfusion protocol.',
            'hospital_name' => 'St. Jude Children Research Hospital',
            'contact_person' => 'Dr. Jude',
            'contact_number' => '+1 (555) 789-0123',
            'email' => 'transfusion@stjude.org',
            'hospital_address' => '262 Danny Thomas Pl, Memphis, TN',
        ]);

        $response->assertRedirect(route('requests.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('blood_requests', [
            'hospital_id' => $hospital->id,
            'requested_by_user_id' => $coordinator->id,
            'blood_group' => 'O-',
            'requested_units' => 4,
            'priority' => 'Critical',
            'status' => 'Pending',
        ]);
    }

    public function test_submitting_valid_blood_request_works_end_to_end_for_admin_selecting_verified_hospital(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach(Role::where('slug', 'admin')->firstOrFail());

        $hospital = Hospital::factory()->create([
            'is_active' => true,
            'verification_status' => 'Verified',
            'name' => 'Memorial Regional Trauma Hospital',
        ]);

        $prbc = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();

        $response = $this->actingAs($admin)->post(route('requests.store'), [
            'hospital_id' => $hospital->id,
            'blood_group' => 'A+',
            'blood_component_id' => $prbc->id,
            'requested_units' => 2,
            'priority' => 'High',
            'request_date' => now()->toDateString(),
            'required_date' => now()->addDay()->toDateString(),
            'request_reason' => 'Admin emergency override order for cardiovascular surgery.',
            'contact_person' => 'Dr. Alan Grant',
            'contact_number' => '+1 (555) 444-2222',
            'email' => 'icu@memorialhospital.org',
            'hospital_address' => '100 Medical Center Drive',
        ]);

        $response->assertRedirect(route('requests.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('blood_requests', [
            'hospital_id' => $hospital->id,
            'requested_by_user_id' => $admin->id,
            'blood_group' => 'A+',
            'requested_units' => 2,
            'priority' => 'High',
            'status' => 'Pending',
        ]);
    }

    public function test_persian_language_renders_properly_on_requests_create(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach(Role::where('slug', 'admin')->firstOrFail());

        Hospital::factory()->create([
            'is_active' => true,
            'verification_status' => 'Verified',
            'name' => 'شفاخانه مرکزی کابل',
        ]);

        $this->get(route('locale.switch', 'fa'))->assertRedirect();

        $response = $this->actingAs($admin)->get(route('requests.create'));

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('ایجاد درخواست');
    }
}
