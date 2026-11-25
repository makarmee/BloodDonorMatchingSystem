<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\Donation;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\DonorAppointment;
use App\Models\DonorEligibilityRecord;
use App\Models\DonorMedicalProfile;
use App\Models\Role;
use App\Models\ScreeningRecord;
use App\Models\ScreeningTestType;
use App\Models\User;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class DonorPortalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $donorUser;

    protected Donor $donor;

    protected DonationCenter $center;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            BloodComponentSeeder::class,
        ]);

        $this->donorUser = User::factory()->create([
            'name' => 'Sarah Jenkins',
            'email' => 'sarah.jenkins@example.com',
        ]);
        $this->donorUser->roles()->attach(Role::where('slug', 'donor')->firstOrFail());

        $this->donor = Donor::factory()->create([
            'user_id' => $this->donorUser->id,
            'full_name' => 'Sarah Jenkins',
            'email' => 'sarah.jenkins@example.com',
            'phone' => '+1 (555) 234-5678',
            'blood_group' => 'A+',
            'eligibility_status' => 'Eligible',
            'account_status' => 'Active',
        ]);

        $this->center = DonationCenter::factory()->create([
            'name' => 'Central City Blood Center',
            'city' => 'Metro City',
        ]);

        $this->actingAs($this->donorUser);
    }

    public function test_donor_portal_dashboard_loads_real_database_donor_profile_and_appointments(): void
    {
        $appointment = DonorAppointment::factory()->create([
            'donor_id' => $this->donor->id,
            'donation_center_id' => $this->center->id,
            'donation_camp_id' => null,
            'appointment_date' => now()->addDays(3)->toDateString(),
            'slot_time' => '10:00:00',
            'status' => 'Pending',
        ]);

        $response = $this->get(route('donor-portal.dashboard'));

        $response->assertOk();
        $response->assertViewIs('donor-portal.dashboard');
        $response->assertSee('Sarah Jenkins');
        $response->assertSee('A+');
        $response->assertSee($this->donor->donor_code);
        $response->assertSee('sa***@example.com');
        $response->assertSee('+1 (***) ***-5678');
        $response->assertSee($appointment->appointment_code);
        $response->assertSee('Central City Blood Center');
    }

    public function test_donor_appointments_index_renders_real_database_records(): void
    {
        $appointment = DonorAppointment::factory()->create([
            'donor_id' => $this->donor->id,
            'donation_center_id' => $this->center->id,
            'donation_camp_id' => null,
            'appointment_date' => now()->addDays(4)->toDateString(),
            'slot_time' => '11:00:00',
            'status' => 'Confirmed',
        ]);

        $response = $this->get(route('donor-portal.appointments.index'));

        $response->assertOk();
        $response->assertViewIs('donor-portal.appointments.index');
        $response->assertSee($appointment->appointment_code);
        $response->assertSee('Central City Blood Center');
        $response->assertSee('Confirmed');
    }

    public function test_authenticated_donor_can_book_real_appointment(): void
    {
        $targetDate = now()->addDays(5)->toDateString();

        $response = $this->post(route('donor-portal.appointments.store'), [
            'donation_type' => 'Whole Blood',
            'appointment_date' => $targetDate,
            'slot_time' => '09:30 AM',
            'donation_center_id' => $this->center->id,
            'notes' => 'First weekend booking after health check.',
        ]);

        $response->assertRedirect(route('donor-portal.appointments.index'));
        $response->assertSessionHas('success');

        $created = DonorAppointment::where('donor_id', $this->donor->id)->latest('id')->first();
        $this->assertNotNull($created);
        $this->assertStringStartsWith('APT-', $created->appointment_code);
        $this->assertSame($targetDate, $created->appointment_date->toDateString());
        $this->assertSame('09:30 AM', $created->slot_time);
        $this->assertSame('Pending', $created->status);
        $this->assertSame('First weekend booking after health check.', $created->notes);
    }

    public function test_authenticated_donor_can_cancel_their_scheduled_appointment(): void
    {
        $appointment = DonorAppointment::factory()->create([
            'donor_id' => $this->donor->id,
            'donation_center_id' => $this->center->id,
            'status' => 'Pending',
        ]);

        $response = $this->post(route('donor-portal.appointments.cancel', $appointment), [
            'cancellation_reason' => 'Work meeting rescheduled unexpectedly.',
        ]);

        $response->assertRedirect(route('donor-portal.appointments.index'));
        $response->assertSessionHas('success');

        $appointment->refresh();
        $this->assertSame('Cancelled', $appointment->status);
        $this->assertNotNull($appointment->cancelled_at);
        $this->assertSame('Work meeting rescheduled unexpectedly.', $appointment->cancellation_reason);
    }

    public function test_authenticated_donor_cannot_cancel_another_donors_appointment(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach(Role::where('slug', 'donor')->firstOrFail());
        $otherDonor = Donor::factory()->create(['user_id' => $otherUser->id]);

        $otherAppointment = DonorAppointment::factory()->create([
            'donor_id' => $otherDonor->id,
            'donation_center_id' => $this->center->id,
            'status' => 'Pending',
        ]);

        // Sarah Jenkins attempts to cancel other donor's appointment
        $response = $this->post(route('donor-portal.appointments.cancel', $otherAppointment), [
            'cancellation_reason' => 'Malicious cancellation attempt',
        ]);

        $response->assertForbidden();

        $otherAppointment->refresh();
        $this->assertSame('Pending', $otherAppointment->status);
        $this->assertNull($otherAppointment->cancelled_at);
    }

    public function test_authenticated_donor_cannot_view_another_donors_data_via_query_tampering(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach(Role::where('slug', 'donor')->firstOrFail());
        $otherDonor = Donor::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->get(route('donor-portal.dashboard', ['donor' => $otherDonor->donor_code]));

        $response->assertForbidden();
    }

    public function test_authenticated_user_without_linked_donor_profile_is_denied(): void
    {
        $unlinkedUser = User::factory()->create();
        $unlinkedUser->roles()->attach(Role::where('slug', 'donor')->firstOrFail());

        $this->actingAs($unlinkedUser);

        $response = $this->get(route('donor-portal.dashboard'));

        $response->assertForbidden();
    }

    public function test_donor_can_view_their_donation_history_and_eligibility_views(): void
    {
        $historyResponse = $this->get(route('donor-portal.history'));
        $historyResponse->assertOk();
        $historyResponse->assertViewIs('donor-portal.history');
        $historyResponse->assertSee('Sarah Jenkins');

        $eligibilityResponse = $this->get(route('donor-portal.eligibility'));
        $eligibilityResponse->assertOk();
        $eligibilityResponse->assertViewIs('donor-portal.eligibility');
        $eligibilityResponse->assertSee('Sarah Jenkins');
    }

    public function test_guest_access_to_donor_portal_routes_redirects_to_login(): void
    {
        Auth::logout();

        foreach ([
            route('donor-portal.dashboard'),
            route('donor-portal.profile.show'),
            route('donor-portal.history'),
            route('donor-portal.eligibility'),
            route('donor-portal.appointments.index'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        $this->patch(route('donor-portal.profile.update'), [
            'full_name' => 'Guest Tamper',
        ])->assertRedirect(route('login'));
    }

    public function test_donor_can_view_and_update_own_profile_while_protected_fields_remain_immutable(): void
    {
        $profileResponse = $this->get(route('donor-portal.profile.show'));
        $profileResponse->assertOk();
        $profileResponse->assertViewIs('donor-portal.profile');
        $profileResponse->assertSee($this->donor->donor_code);
        $profileResponse->assertSee('Sarah Jenkins');
        $profileResponse->assertSee('A+');
        $profileResponse->assertSee('My Profile');

        $dob = now()->subYears(28)->toDateString();

        // Attempt both valid personal updates and crafted protected fields
        $updateResponse = $this->patch(route('donor-portal.profile.update'), [
            'full_name' => 'Sarah M. Jenkins',
            'phone' => '+1 (555) 901-7788',
            'gender' => 'Female',
            'date_of_birth' => $dob,
            'address' => '742 Evergreen Terrace, Metro City',
            'emergency_contact' => 'Mark Jenkins (+1 555-333-4444)',
            'availability_status' => 'Temporarily Unavailable',
            // Protected fields that must be completely ignored
            'donor_code' => 'DNR-HACKED-999',
            'user_id' => 99999,
            'email' => 'hacked@example.com',
            'blood_group' => 'AB-',
            'eligibility_status' => 'Not Eligible',
            'account_status' => 'Inactive',
            'total_completed_donations' => 42,
            'last_donation_date' => '2020-01-01',
            'next_eligible_date' => '2030-01-01',
            'registered_at' => '2010-01-01',
        ]);

        $updateResponse->assertRedirect(route('donor-portal.profile.show'));
        $updateResponse->assertSessionHas('success');

        $this->donor->refresh();
        $this->donorUser->refresh();

        $this->assertSame('Sarah M. Jenkins', $this->donor->full_name);
        $this->assertSame('Sarah M. Jenkins', $this->donorUser->name);
        $this->assertSame('+1 (555) 901-7788', $this->donor->phone);
        $this->assertSame('Female', $this->donor->gender);
        $this->assertSame($dob, $this->donor->date_of_birth?->toDateString());
        $this->assertSame('742 Evergreen Terrace, Metro City', $this->donor->address);
        $this->assertSame('Mark Jenkins (+1 555-333-4444)', $this->donor->emergency_contact);
        $this->assertSame('Temporarily Unavailable', $this->donor->availability_status);
        $this->assertTrue($this->donor->profile_completed);

        // Protected fields remain unchanged
        $this->assertNotSame('DNR-HACKED-999', $this->donor->donor_code);
        $this->assertSame($this->donorUser->id, $this->donor->user_id);
        $this->assertSame('sarah.jenkins@example.com', $this->donor->email);
        $this->assertSame('A+', $this->donor->blood_group);
        $this->assertSame('Eligible', $this->donor->eligibility_status);
        $this->assertSame('Active', $this->donor->account_status);
        $this->assertSame(0, $this->donor->total_completed_donations);
        $this->assertNull($this->donor->last_donation_date);
        $this->assertNull($this->donor->next_eligible_date);
    }

    public function test_unlinked_donor_cannot_bypass_via_donor_query_param_and_non_donor_cannot_access_portal(): void
    {
        $unlinkedUser = User::factory()->create();
        $unlinkedUser->roles()->attach(Role::where('slug', 'donor')->firstOrFail());

        // Even passing an existing donor_code in query string must be denied (403)
        foreach ([
            route('donor-portal.dashboard', ['donor' => $this->donor->donor_code]),
            route('donor-portal.profile.show', ['donor' => $this->donor->donor_code]),
            route('donor-portal.history', ['donor' => $this->donor->donor_code]),
            route('donor-portal.eligibility', ['donor' => $this->donor->donor_code]),
        ] as $tamperedUrl) {
            $this->actingAs($unlinkedUser)->get($tamperedUrl)->assertForbidden();
        }

        $this->actingAs($unlinkedUser)
            ->patch(route('donor-portal.profile.update', ['donor' => $this->donor->donor_code]), [
                'full_name' => 'Tamper',
                'phone' => '+15551112222',
                'availability_status' => 'Available',
            ])
            ->assertForbidden();

        // Linked donor tampering with ?donor= on profile, history, eligibility also gets 403
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach(Role::where('slug', 'donor')->firstOrFail());
        $otherDonor = Donor::factory()->create(['user_id' => $otherUser->id]);

        foreach ([
            route('donor-portal.profile.show', ['donor' => $otherDonor->donor_code]),
            route('donor-portal.history', ['donor' => $otherDonor->donor_code]),
            route('donor-portal.eligibility', ['donor' => $otherDonor->donor_code]),
        ] as $otherUrl) {
            $this->actingAs($this->donorUser)->get($otherUrl)->assertForbidden();
        }

        // Staff/Admin cannot enter role:donor portal, and donor cannot enter staff/admin routes
        $staffUser = User::factory()->create();
        $staffUser->roles()->attach(Role::where('slug', 'staff')->firstOrFail());
        $this->actingAs($staffUser)->get(route('donor-portal.profile.show'))->assertForbidden();

        foreach ([
            route('staff.index'),
            route('donors.index'),
            route('donors.show', $this->donor),
            route('donations.index'),
            route('screening.index'),
            route('inventory.index'),
            route('requests.index'),
            route('hospitals.index'),
        ] as $staffUrl) {
            $this->actingAs($this->donorUser)->get($staffUrl)->assertForbidden();
        }
    }

    public function test_donation_history_isolates_records_and_redacts_internal_staff_notes_and_medical_profile(): void
    {
        $this->seed([
            ScreeningTestTypeSeeder::class,
            ComponentScreeningRequirementSeeder::class,
        ]);

        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();
        $testType = ScreeningTestType::query()->firstOrFail();

        DonorMedicalProfile::create([
            'donor_id' => $this->donor->id,
            'medical_notes' => 'CONFIDENTIAL MEDICAL NOTE 9876',
            'deferral_notes' => 'INTERNAL DEFERRAL NOTE 5432',
            'last_hemoglobin_g_dl' => 14.2,
            'weight_kg' => 68.5,
        ]);

        DonorEligibilityRecord::factory()->eligible()->for($this->donor)->create([
            'reason' => 'STAFF INTERNAL REASON 1122',
            'clearance_source' => 'Internal Audit Source',
        ]);

        $ownDonation = Donation::factory()->create([
            'donor_id' => $this->donor->id,
            'blood_component_id' => $component->id,
            'blood_group' => $this->donor->blood_group,
            'collection_status' => 'Completed',
            'screening_status' => 'Passed',
            'staff_member_name' => 'Secret Phlebotomist Name',
            'collection_notes' => 'PRIVATE BAG NOTE #XYZ-99',
            'screening_notes' => 'INTERNAL LAB NOTE #LAB-88',
        ]);

        ScreeningRecord::factory()->passed()->create([
            'donation_id' => $ownDonation->id,
            'test_type_id' => $testType->id,
            'lab_staff_name' => 'Dr. Confidential Officer',
            'assay_method' => 'NAT — Nucleic Acid Testing',
            'specimen_tube_code' => 'TUBE-SECRET-777',
            'test_notes' => 'PRIVATE ASSAY COMMENT 654',
        ]);

        $otherDonor = Donor::factory()->create(['full_name' => 'Other Hidden Donor']);
        $otherDonation = Donation::factory()->create([
            'donor_id' => $otherDonor->id,
            'blood_component_id' => $component->id,
            'blood_group' => $otherDonor->blood_group,
            'collection_status' => 'Completed',
        ]);

        $historyResponse = $this->get(route('donor-portal.history'));
        $historyResponse->assertOk();
        $historyResponse->assertSee($ownDonation->donation_code);
        $historyResponse->assertDontSee($otherDonation->donation_code);
        $historyResponse->assertDontSee('Other Hidden Donor');
        $historyResponse->assertDontSee('Secret Phlebotomist Name');
        $historyResponse->assertDontSee('PRIVATE BAG NOTE #XYZ-99');
        $historyResponse->assertDontSee('INTERNAL LAB NOTE #LAB-88');
        $historyResponse->assertDontSee('Dr. Confidential Officer');
        $historyResponse->assertDontSee('TUBE-SECRET-777');
        $historyResponse->assertDontSee('PRIVATE ASSAY COMMENT 654');
        $historyResponse->assertDontSee('CONFIDENTIAL MEDICAL NOTE 9876');

        // Dashboard shows real completed count and must not leak staff/medical notes
        $dashResponse = $this->get(route('donor-portal.dashboard'));
        $dashResponse->assertOk();
        $dashResponse->assertSee($ownDonation->donation_code);
        $dashResponse->assertDontSee($otherDonation->donation_code);
        $dashResponse->assertDontSee('CONFIDENTIAL MEDICAL NOTE 9876');

        // Eligibility must not leak internal deferral/medical notes or invent dates
        $eligResponse = $this->get(route('donor-portal.eligibility'));
        $eligResponse->assertOk();
        $eligResponse->assertDontSee('INTERNAL DEFERRAL NOTE 5432');
        $eligResponse->assertDontSee('CONFIDENTIAL MEDICAL NOTE 9876');
    }

    public function test_donor_portal_profile_renders_in_english_ltr_and_persian_rtl(): void
    {
        $enResponse = $this->withSession(['locale' => 'en'])->get(route('donor-portal.profile.show'));
        $enResponse->assertOk();
        $enResponse->assertSee('dir="ltr"', false);
        $enResponse->assertSee('My Donor Profile');
        $enResponse->assertSee('Save Profile Changes');

        $faResponse = $this->withSession(['locale' => 'fa'])->get(route('donor-portal.profile.show'));
        $faResponse->assertOk();
        $faResponse->assertSee('dir="rtl"', false);
        $faResponse->assertSee('پروفایل اهداکننده من');
        $faResponse->assertSee('ذخیره تغییرات پروفایل');

        // Persian validation message uses translated attributes
        $invalidFa = $this->withSession(['locale' => 'fa'])
            ->from(route('donor-portal.profile.show'))
            ->patch(route('donor-portal.profile.update'), [
                'full_name' => '',
                'phone' => 'bad',
                'availability_status' => 'Invalid',
            ]);
        $invalidFa->assertSessionHasErrors(['full_name', 'phone', 'availability_status']);
        $errorsText = implode(' ', session('errors')->all());
        $this->assertStringContainsString('نام کامل', $errorsText);
        $this->assertStringContainsString('شماره تماس', $errorsText);
        $this->assertStringContainsString('وضعیت در دسترس بودن', $errorsText);
    }
}
