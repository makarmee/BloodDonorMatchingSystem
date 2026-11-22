<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\CampRegistration;
use App\Models\DonationCamp;
use App\Models\Donor;
use App\Models\DonorAppointment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonationCampManagementAndIntegrationTest extends TestCase
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

    public function test_staff_or_admin_can_create_a_donation_camp(): void
    {
        $staffUser = User::factory()->create(['name' => 'Sara Phlebotomy']);
        $staffUser->roles()->attach(Role::where('slug', 'staff')->firstOrFail());

        $payload = [
            'name' => 'Metro University Spring Blood Drive',
            'description' => 'Annual collegiate spring blood drive collecting Whole Blood and Platelets.',
            'organizer_name' => 'Metro Red Cross Youth',
            'organizer_contact' => '+1-555-442-8819',
            'organizer_email' => 'contact@metroyouth.org',
            'venue_name' => 'University Student Union, Hall B',
            'full_address' => '700 Campus Drive',
            'city' => 'Metro Center',
            'location_notes' => 'Dedicated van parking behind Hall B.',
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(11)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'capacity' => 80,
            'registration_deadline' => now()->addDays(8)->toDateString(),
            'allow_walk_in' => 'Yes',
            'donor_requirements' => 'Age 18-65, min 50kg, valid photo ID.',
            'additional_notes' => 'Mobile cold chain unit deployed.',
            'status' => 'Upcoming',
        ];

        $response = $this->actingAs($staffUser)->post(route('camps.store'), $payload);

        $response->assertRedirect();

        $camp = DonationCamp::where('name', 'Metro University Spring Blood Drive')->first();
        $this->assertNotNull($camp);
        $this->assertSame('Upcoming', $camp->status);
        $this->assertSame(80, $camp->capacity);
        $this->assertTrue($camp->allow_walk_in);
        $this->assertSame($staffUser->id, $camp->created_by_user_id);

        // Status history recorded
        $this->assertDatabaseHas('camp_status_histories', [
            'camp_id' => $camp->id,
            'event_name' => 'Camp Created',
            'new_status' => 'Upcoming',
            'actor_user_id' => $staffUser->id,
        ]);
    }

    public function test_camp_creation_fails_when_validation_constraints_violated(): void
    {
        $staffUser = User::factory()->create();
        $staffUser->roles()->attach(Role::where('slug', 'staff')->firstOrFail());

        // 1. Capacity < 1
        $response = $this->actingAs($staffUser)->post(route('camps.store'), [
            'name' => 'Invalid Camp',
            'description' => 'Test invalid parameters.',
            'organizer_name' => 'Organizer',
            'organizer_contact' => '+1-555-123-4567',
            'organizer_email' => 'org@example.org',
            'venue_name' => 'Civic Hall',
            'full_address' => '100 Main St',
            'city' => 'Metro Center',
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(11)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'capacity' => 0,
            'registration_deadline' => now()->addDays(8)->toDateString(),
            'donor_requirements' => 'Requirements text.',
        ]);
        $response->assertSessionHasErrors('capacity');

        // 2. End date before start date
        $response = $this->actingAs($staffUser)->post(route('camps.store'), [
            'name' => 'Invalid Camp',
            'description' => 'Test invalid parameters.',
            'organizer_name' => 'Organizer',
            'organizer_contact' => '+1-555-123-4567',
            'organizer_email' => 'org@example.org',
            'venue_name' => 'Civic Hall',
            'full_address' => '100 Main St',
            'city' => 'Metro Center',
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'capacity' => 50,
            'registration_deadline' => now()->addDays(3)->toDateString(),
            'donor_requirements' => 'Requirements text.',
        ]);
        $response->assertSessionHasErrors('end_date');

        // 3. Registration deadline after start date
        $response = $this->actingAs($staffUser)->post(route('camps.store'), [
            'name' => 'Invalid Camp',
            'description' => 'Test invalid parameters.',
            'organizer_name' => 'Organizer',
            'organizer_contact' => '+1-555-123-4567',
            'organizer_email' => 'org@example.org',
            'venue_name' => 'Civic Hall',
            'full_address' => '100 Main St',
            'city' => 'Metro Center',
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(11)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'capacity' => 50,
            'registration_deadline' => now()->addDays(12)->toDateString(),
            'donor_requirements' => 'Requirements text.',
        ]);
        $response->assertSessionHasErrors('registration_deadline');

        // 4. Same day, end time before start time
        $response = $this->actingAs($staffUser)->post(route('camps.store'), [
            'name' => 'Invalid Camp',
            'description' => 'Test invalid parameters.',
            'organizer_name' => 'Organizer',
            'organizer_contact' => '+1-555-123-4567',
            'organizer_email' => 'org@example.org',
            'venue_name' => 'Civic Hall',
            'full_address' => '100 Main St',
            'city' => 'Metro Center',
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'start_time' => '16:00',
            'end_time' => '10:00',
            'capacity' => 50,
            'registration_deadline' => now()->addDays(8)->toDateString(),
            'donor_requirements' => 'Requirements text.',
        ]);
        $response->assertSessionHasErrors('end_time');
    }

    public function test_unauthorized_users_cannot_manage_camps(): void
    {
        $donorUser = User::factory()->create();
        $donorUser->roles()->attach(Role::where('slug', 'donor')->firstOrFail());

        // Donor cannot view staff camps index
        $this->actingAs($donorUser)->get(route('camps.index'))->assertForbidden();

        // Donor cannot access camp create
        $this->actingAs($donorUser)->get(route('camps.create'))->assertForbidden();

        // Donor cannot store camp
        $this->actingAs($donorUser)->post(route('camps.store'), [
            'name' => 'Unauthorized Camp',
        ])->assertForbidden();
    }

    public function test_staff_can_update_camp_and_status_history_is_logged(): void
    {
        $staffUser = User::factory()->create(['name' => 'Coordinator Mike']);
        $staffUser->roles()->attach(Role::where('slug', 'staff')->firstOrFail());

        $camp = DonationCamp::factory()->create([
            'status' => 'Upcoming',
            'capacity' => 50,
        ]);

        $updatePayload = [
            'name' => 'Updated Drive Title',
            'description' => 'Updated description text for blood collection.',
            'organizer_name' => $camp->organizer_name,
            'organizer_contact' => $camp->organizer_contact,
            'organizer_email' => $camp->organizer_email,
            'venue_name' => 'New Venue Building',
            'full_address' => $camp->full_address,
            'city' => $camp->city,
            'start_date' => $camp->start_date->toDateString(),
            'end_date' => $camp->end_date->toDateString(),
            'start_time' => '10:00',
            'end_time' => '18:00',
            'capacity' => 75,
            'registration_deadline' => $camp->registration_deadline->toDateString(),
            'allow_walk_in' => 'No',
            'donor_requirements' => $camp->donor_requirements,
            'status' => 'Ongoing',
        ];

        $response = $this->actingAs($staffUser)->put(route('camps.update', $camp->camp_code), $updatePayload);

        $response->assertRedirect(route('camps.show', $camp->camp_code));

        $camp->refresh();
        $this->assertSame('Updated Drive Title', $camp->name);
        $this->assertSame('New Venue Building', $camp->venue_name);
        $this->assertSame(75, $camp->capacity);
        $this->assertSame('Ongoing', $camp->status);
        $this->assertFalse($camp->allow_walk_in);

        // Status transition logged
        $this->assertDatabaseHas('camp_status_histories', [
            'camp_id' => $camp->id,
            'event_name' => 'Camp Marked Ongoing',
            'previous_status' => 'Upcoming',
            'new_status' => 'Ongoing',
            'actor_user_id' => $staffUser->id,
        ]);
    }

    public function test_camp_capacity_cannot_be_reduced_below_active_registrations_count(): void
    {
        $staffUser = User::factory()->create();
        $staffUser->roles()->attach(Role::where('slug', 'staff')->firstOrFail());

        $camp = DonationCamp::factory()->upcoming()->create(['capacity' => 10]);
        $donor = Donor::factory()->eligible()->create();

        // 1 active registration
        CampRegistration::factory()->create([
            'camp_id' => $camp->id,
            'donor_id' => $donor->id,
            'attendance_status' => 'Registered',
        ]);

        $payload = [
            'name' => $camp->name,
            'description' => $camp->description,
            'organizer_name' => $camp->organizer_name,
            'organizer_contact' => $camp->organizer_contact,
            'organizer_email' => $camp->organizer_email,
            'venue_name' => $camp->venue_name,
            'full_address' => $camp->full_address,
            'city' => $camp->city,
            'start_date' => $camp->start_date->toDateString(),
            'end_date' => $camp->end_date->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'capacity' => 0, // below 1
            'registration_deadline' => $camp->registration_deadline->toDateString(),
            'donor_requirements' => $camp->donor_requirements,
        ];

        $response = $this->actingAs($staffUser)->put(route('camps.update', $camp->camp_code), $payload);
        $response->assertSessionHasErrors('capacity');
    }

    public function test_public_camps_directory_shows_only_active_published_camps_and_filters_inactive(): void
    {
        // 1 published upcoming camp
        $upcoming = DonationCamp::factory()->upcoming()->create(['name' => 'Public Upcoming Drive']);

        // 1 published ongoing camp
        $ongoing = DonationCamp::factory()->ongoing()->create(['name' => 'Public Active Ongoing Drive']);

        // 1 draft camp (should be hidden publicly)
        $draft = DonationCamp::factory()->create(['name' => 'Secret Draft Plan', 'status' => 'Draft']);

        // 1 cancelled camp (should be hidden publicly)
        $cancelled = DonationCamp::factory()->create(['name' => 'Aborted Weather Camp', 'status' => 'Cancelled']);

        // 1 completed camp (should be hidden publicly)
        $completed = DonationCamp::factory()->create(['name' => 'Past Finished Camp', 'status' => 'Completed']);

        $response = $this->get(route('public.camps.index'));

        $response->assertOk();
        $response->assertSee('Public Upcoming Drive');
        $response->assertSee('Public Active Ongoing Drive');

        // Inactive / non-published camps are strictly excluded
        $response->assertDontSee('Secret Draft Plan');
        $response->assertDontSee('Aborted Weather Camp');
        $response->assertDontSee('Past Finished Camp');
    }

    public function test_public_camps_shows_honest_empty_state_when_no_published_camps_exist(): void
    {
        // Only draft or cancelled camps exist
        DonationCamp::factory()->create(['status' => 'Draft']);
        DonationCamp::factory()->create(['status' => 'Cancelled']);

        $response = $this->get(route('public.camps.index'));

        $response->assertOk();
        $response->assertSee('No public donation camps match your criteria');
    }

    public function test_public_camps_preserves_strict_privacy(): void
    {
        $camp = DonationCamp::factory()->upcoming()->create([
            'name' => 'Community Blood Event',
            'organizer_contact' => '+1-555-400-0199',
            'location_notes' => 'Internal courier code #9941',
        ]);

        $donor = Donor::factory()->create([
            'full_name' => 'Secret Public Visitor',
            'phone' => '+1-555-999-8877',
        ]);

        CampRegistration::factory()->create([
            'camp_id' => $camp->id,
            'donor_id' => $donor->id,
            'attendance_status' => 'Registered',
        ]);

        $response = $this->get(route('public.camps.index'));

        $response->assertOk();
        $response->assertDontSee('Secret Public Visitor');
        $response->assertDontSee('+1-555-999-8877');
    }

    public function test_donor_can_register_for_an_upcoming_camp_via_appointment_system(): void
    {
        $donorUser = User::factory()->create();
        $donorUser->roles()->attach(Role::where('slug', 'donor')->firstOrFail());

        $donor = Donor::factory()->eligible()->create([
            'user_id' => $donorUser->id,
            'full_name' => 'Jane Dedicated',
        ]);

        $wb = BloodComponent::where('code', 'WB')->firstOrFail();
        $camp = DonationCamp::factory()->upcoming()->create([
            'capacity' => 20,
        ]);

        $payload = [
            'donation_type' => 'Whole Blood',
            'donation_component_id' => $wb->id,
            'donation_camp_id' => $camp->id,
            'appointment_date' => now()->addDays(5)->toDateString(),
            'slot_time' => '10:00:00',
            'notes' => 'First time donating at community drive.',
        ];

        $response = $this->actingAs($donorUser)->post(route('donor-portal.appointments.store'), $payload);

        $response->assertRedirect(route('donor-portal.appointments.index'));

        // Appointment created targeting the camp
        $appointment = DonorAppointment::where('donor_id', $donor->id)->where('donation_camp_id', $camp->id)->first();
        $this->assertNotNull($appointment);
        $this->assertSame('Pending', $appointment->status);

        // Synchronized CampRegistration created
        $campRegistration = CampRegistration::where('camp_id', $camp->id)->where('donor_id', $donor->id)->first();
        $this->assertNotNull($campRegistration);
        $this->assertSame('Registered', $campRegistration->attendance_status);
        $this->assertSame($appointment->id, $campRegistration->appointment_id);
    }

    public function test_duplicate_camp_registration_is_prevented(): void
    {
        $donorUser = User::factory()->create();
        $donorUser->roles()->attach(Role::where('slug', 'donor')->firstOrFail());

        $donor = Donor::factory()->eligible()->create(['user_id' => $donorUser->id]);
        $wb = BloodComponent::where('code', 'WB')->firstOrFail();
        $camp = DonationCamp::factory()->upcoming()->create(['capacity' => 20]);

        // Existing active registration for this donor
        CampRegistration::factory()->create([
            'camp_id' => $camp->id,
            'donor_id' => $donor->id,
            'attendance_status' => 'Registered',
        ]);

        // Attempt second registration for the same camp
        $payload = [
            'donation_type' => 'Whole Blood',
            'donation_component_id' => $wb->id,
            'donation_camp_id' => $camp->id,
            'appointment_date' => now()->addDays(5)->toDateString(),
            'slot_time' => '11:00:00',
        ];

        $response = $this->actingAs($donorUser)->post(route('donor-portal.appointments.store'), $payload);

        $response->assertSessionHasErrors('location_id');
        $this->assertSame(1, CampRegistration::where('camp_id', $camp->id)->where('donor_id', $donor->id)->count());
    }

    public function test_camp_capacity_limit_is_enforced(): void
    {
        $donorUser = User::factory()->create();
        $donorUser->roles()->attach(Role::where('slug', 'donor')->firstOrFail());

        $donor = Donor::factory()->eligible()->create(['user_id' => $donorUser->id]);
        $wb = BloodComponent::where('code', 'WB')->firstOrFail();

        // Camp with capacity = 1, already full
        $camp = DonationCamp::factory()->upcoming()->create(['capacity' => 1]);
        $otherDonor = Donor::factory()->eligible()->create();
        CampRegistration::factory()->create([
            'camp_id' => $camp->id,
            'donor_id' => $otherDonor->id,
            'attendance_status' => 'Registered',
        ]);

        $payload = [
            'donation_type' => 'Whole Blood',
            'donation_component_id' => $wb->id,
            'donation_camp_id' => $camp->id,
            'appointment_date' => now()->addDays(5)->toDateString(),
            'slot_time' => '10:00:00',
        ];

        $response = $this->actingAs($donorUser)->post(route('donor-portal.appointments.store'), $payload);

        $response->assertSessionHasErrors('location_id');
        $this->assertSame(1, CampRegistration::where('camp_id', $camp->id)->count());
    }

    public function test_donor_can_cancel_camp_appointment_and_registration_is_synchronized(): void
    {
        $donorUser = User::factory()->create();
        $donorUser->roles()->attach(Role::where('slug', 'donor')->firstOrFail());

        $donor = Donor::factory()->eligible()->create(['user_id' => $donorUser->id]);
        $camp = DonationCamp::factory()->upcoming()->create();
        $wb = BloodComponent::where('code', 'WB')->firstOrFail();

        $appointment = DonorAppointment::factory()->create([
            'donor_id' => $donor->id,
            'donation_camp_id' => $camp->id,
            'donation_center_id' => null,
            'donation_component_id' => $wb->id,
            'status' => 'Pending',
        ]);

        $registration = CampRegistration::factory()->create([
            'camp_id' => $camp->id,
            'donor_id' => $donor->id,
            'appointment_id' => $appointment->id,
            'attendance_status' => 'Registered',
        ]);

        $response = $this->actingAs($donorUser)->post(route('donor-portal.appointments.cancel', $appointment), [
            'cancellation_reason' => 'Schedule conflict.',
        ]);

        $response->assertRedirect(route('donor-portal.appointments.index'));

        $appointment->refresh();
        $registration->refresh();

        $this->assertSame('Cancelled', $appointment->status);
        $this->assertSame('Cancelled', $registration->attendance_status);
        $this->assertNotNull($registration->cancelled_at);
    }

    public function test_donor_cannot_cancel_another_donors_appointment(): void
    {
        $donorUser = User::factory()->create();
        $donorUser->roles()->attach(Role::where('slug', 'donor')->firstOrFail());
        $donor = Donor::factory()->eligible()->create(['user_id' => $donorUser->id]);

        $otherDonor = Donor::factory()->eligible()->create();
        $camp = DonationCamp::factory()->upcoming()->create();
        $wb = BloodComponent::where('code', 'WB')->firstOrFail();

        $otherAppointment = DonorAppointment::factory()->create([
            'donor_id' => $otherDonor->id,
            'donation_camp_id' => $camp->id,
            'donation_center_id' => null,
            'donation_component_id' => $wb->id,
            'status' => 'Pending',
        ]);

        $response = $this->actingAs($donorUser)->post(route('donor-portal.appointments.cancel', $otherAppointment), [
            'cancellation_reason' => 'Malicious cancellation.',
        ]);

        $response->assertForbidden();
        $otherAppointment->refresh();
        $this->assertSame('Pending', $otherAppointment->status);
    }
}
