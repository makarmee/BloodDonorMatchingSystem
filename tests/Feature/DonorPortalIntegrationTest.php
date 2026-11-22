<?php

namespace Tests\Feature;

use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\DonorAppointment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
