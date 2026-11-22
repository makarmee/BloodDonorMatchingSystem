<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\DonorAppointment;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\ScreeningRecord;
use App\Models\ScreeningTestType;
use App\Models\User;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAndPublicPortalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            BloodComponentSeeder::class,
            ScreeningTestTypeSeeder::class,
        ]);
    }

    public function test_staff_dashboard_displays_real_database_metrics_and_recent_activity(): void
    {
        $staffUser = User::factory()->create(['name' => 'Alice Phlebotomist']);
        $staffUser->roles()->attach(Role::where('slug', 'staff')->firstOrFail());

        // Create 3 donors (2 active, 1 inactive)
        Donor::factory()->count(2)->create(['account_status' => 'Active']);
        Donor::factory()->create(['account_status' => 'Inactive']);

        // Create inventory stock for O+ (50 units) and A+ (25 units)
        $wb = BloodComponent::where('code', 'WB')->firstOrFail();
        InventoryStock::factory()->create([
            'blood_group' => 'O+',
            'blood_component_id' => $wb->id,
            'available_units' => 50,
            'target_capacity' => 100,
        ]);
        InventoryStock::factory()->create([
            'blood_group' => 'A+',
            'blood_component_id' => $wb->id,
            'available_units' => 25,
            'target_capacity' => 100,
        ]);

        // Create 2 blood requests (1 critical, 1 normal)
        $hospital = Hospital::factory()->create(['name' => 'Mercy General Hospital']);
        BloodRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'blood_component_id' => $wb->id,
            'blood_group' => 'O+',
            'requested_units' => 4,
            'status' => 'Pending',
            'priority' => 'Critical',
        ]);
        BloodRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'blood_component_id' => $wb->id,
            'blood_group' => 'A+',
            'requested_units' => 2,
            'status' => 'Pending',
            'priority' => 'Normal',
        ]);

        $response = $this->actingAs($staffUser)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewIs('dashboard.index');
        $response->assertSee('Blood Bank Operations Dashboard');

        // Total available units: 75
        $response->assertSee('75');
        // Registered donors: 3 (2 active registered donors)
        $response->assertSee('3');
        $response->assertSee('2 active registered donors');
        // Pending requests: 2 (1 critical priority requests)
        $response->assertSee('2');
        $response->assertSee('1 critical priority requests');

        // Recent activity renders real hospital request
        $response->assertSee('Mercy General Hospital');
        $response->assertSee('4 Units');
    }

    public function test_hospital_dashboard_displays_real_hospital_metrics_and_isolation(): void
    {
        $hospital = Hospital::factory()->create([
            'name' => 'St. Jude Children Hospital',
            'verification_status' => 'Verified',
        ]);

        $hospitalUser = User::factory()->create();
        $hospitalUser->roles()->attach(Role::where('slug', 'hospital')->firstOrFail());
        HospitalUser::create([
            'user_id' => $hospitalUser->id,
            'hospital_id' => $hospital->id,
            'contact_name' => 'Dr. Coordinator',
            'is_active' => true,
        ]);

        $wb = BloodComponent::where('code', 'WB')->firstOrFail();

        // 1 active request for this hospital
        BloodRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'blood_component_id' => $wb->id,
            'blood_group' => 'B+',
            'requested_units' => 3,
            'status' => 'Pending',
            'priority' => 'Normal',
        ]);

        // 1 request for a different hospital (should not leak into this coordinator's dashboard)
        $otherHospital = Hospital::factory()->create(['name' => 'Foreign Clinic']);
        BloodRequest::factory()->create([
            'hospital_id' => $otherHospital->id,
            'blood_component_id' => $wb->id,
            'blood_group' => 'AB-',
            'requested_units' => 10,
            'status' => 'Pending',
            'priority' => 'Normal',
        ]);

        $response = $this->actingAs($hospitalUser)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Hospital Requester Portal');
        $response->assertSee('Authorized hospital partner');
        $response->assertSee('St. Jude Children Hospital');
        $response->assertSee('1 requests in fulfillment queue');
        $response->assertDontSee('Foreign Clinic');
    }

    public function test_lab_officer_dashboard_displays_real_screening_metrics(): void
    {
        $labUser = User::factory()->create();
        $labUser->roles()->attach(Role::where('slug', 'lab_officer')->firstOrFail());

        $donor = Donor::factory()->eligible()->create();
        $wb = BloodComponent::where('code', 'WB')->firstOrFail();
        $hivTest = ScreeningTestType::where('code', 'HIV')->firstOrFail();

        $donation = Donation::factory()->create([
            'donor_id' => $donor->id,
            'blood_component_id' => $wb->id,
            'collection_status' => 'Completed',
        ]);

        // 1 pending screening record
        ScreeningRecord::factory()->create([
            'donation_id' => $donation->id,
            'test_type_id' => $hivTest->id,
            'screening_result' => 'Pending',
        ]);

        // 1 quarantined unit
        BloodUnit::factory()->create([
            'donation_id' => $donation->id,
            'blood_component_id' => $wb->id,
            'unit_status' => 'Quarantined',
        ]);

        $response = $this->actingAs($labUser)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Laboratory Screening Dashboard');
        $response->assertSee('Units awaiting laboratory review');
        $response->assertSee('Quarantined Units');
    }

    public function test_public_blood_stock_shows_real_database_inventory_and_respects_component_filtering(): void
    {
        $wb = BloodComponent::where('code', 'WB')->firstOrFail();
        $plt = BloodComponent::where('code', 'PLT')->firstOrFail();

        // Stock in database: 40 units O+ Whole Blood, 15 units O+ Platelets
        InventoryStock::factory()->create([
            'blood_group' => 'O+',
            'blood_component_id' => $wb->id,
            'available_units' => 40,
            'target_capacity' => 100,
        ]);
        InventoryStock::factory()->create([
            'blood_group' => 'O+',
            'blood_component_id' => $plt->id,
            'available_units' => 15,
            'target_capacity' => 100,
        ]);

        // All components: total O+ available = 55
        $responseAll = $this->get(route('public.stock'));
        $responseAll->assertOk();
        $responseAll->assertSee('55');
        $responseAll->assertSee('O+');

        // Filter by Whole Blood component only: total O+ available = 40
        $responseWb = $this->get(route('public.stock', ['component_id' => $wb->id]));
        $responseWb->assertOk();
        $responseWb->assertSee('40');

        // Filter by Platelets component only: total O+ available = 15
        $responsePlt = $this->get(route('public.stock', ['component_id' => $plt->id]));
        $responsePlt->assertOk();
        $responsePlt->assertSee('15');
    }

    public function test_public_blood_stock_shows_honest_empty_state_when_database_has_no_inventory(): void
    {
        // No InventoryStock records exist in database
        $this->assertSame(0, InventoryStock::count());

        $response = $this->get(route('public.stock'));

        $response->assertOk();
        $response->assertSee('No Available Blood Stock');
        $response->assertSee('0');
        $response->assertSee('units available');

        // Does NOT display fabricated sample units (such as 305 or sample figures)
        $response->assertDontSee('305');
        $response->assertDontSee('Preview Sample Stock Data');
    }

    public function test_public_portal_preserves_strict_data_privacy(): void
    {
        $donor = Donor::factory()->create([
            'full_name' => 'Secret Donor Person',
            'email' => 'private_donor@example.org',
            'phone' => '+1-999-555-0199',
        ]);

        $hospital = Hospital::factory()->create([
            'name' => 'Restricted Military Clinic',
        ]);

        $response = $this->get(route('public.stock'));

        $response->assertOk();
        $response->assertDontSee('Secret Donor Person');
        $response->assertDontSee('private_donor@example.org');
        $response->assertDontSee('+1-999-555-0199');
        $response->assertDontSee('Restricted Military Clinic');
    }

    public function test_donor_dashboard_displays_real_donor_metrics_and_isolation(): void
    {
        $donorUser = User::factory()->create();
        $donorUser->roles()->attach(Role::where('slug', 'donor')->firstOrFail());

        $donor = Donor::factory()->create([
            'user_id' => $donorUser->id,
            'full_name' => 'John Dedicated Donor',
            'blood_group' => 'O-',
            'eligibility_status' => 'Eligible',
            'total_completed_donations' => 4,
        ]);

        $wb = BloodComponent::where('code', 'WB')->firstOrFail();

        DonorAppointment::factory()->create([
            'donor_id' => $donor->id,
            'donation_component_id' => $wb->id,
            'appointment_date' => now()->addDays(3)->toDateString(),
            'status' => 'Confirmed',
        ]);

        // Another donor's appointment to verify isolation
        $otherDonor = Donor::factory()->create(['full_name' => 'Other Donor']);
        DonorAppointment::factory()->create([
            'donor_id' => $otherDonor->id,
            'donation_component_id' => $wb->id,
            'appointment_date' => now()->addDays(10)->toDateString(),
            'status' => 'Confirmed',
        ]);

        $response = $this->actingAs($donorUser)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Donor Portal Overview');
        $response->assertSee('O-');
        $response->assertSee('Eligible');
        $response->assertSee('4');
        $response->assertSee('Verified lifetime contributions');
        $response->assertSee(now()->addDays(3)->format('M d'));
    }

    public function test_roleless_user_dashboard_displays_account_overview_with_no_operational_leaks(): void
    {
        $plainUser = User::factory()->create(['name' => 'Unassigned User']);

        $response = $this->actingAs($plainUser)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Account Overview');
        $response->assertSee('Contact an administrator to enable role-specific access');
        $response->assertDontSee('Total Available Units');
        $response->assertDontSee('Pending Screening Queue');
        $response->assertDontSee('Active Blood Requests');
    }

    public function test_staff_dashboard_correctly_excludes_reserved_quarantined_and_expired_units_from_available_counts(): void
    {
        $staffUser = User::factory()->create(['name' => 'Lab Tech Lead']);
        $staffUser->roles()->attach(Role::where('slug', 'staff')->firstOrFail());

        $wb = BloodComponent::where('code', 'WB')->firstOrFail();

        // 100 available units, 25 reserved units
        InventoryStock::factory()->create([
            'blood_group' => 'B+',
            'blood_component_id' => $wb->id,
            'available_units' => 100,
            'reserved_units' => 25,
            'target_capacity' => 200,
        ]);

        $donor = Donor::factory()->eligible()->create();
        $donation = Donation::factory()->create([
            'donor_id' => $donor->id,
            'blood_component_id' => $wb->id,
            'collection_status' => 'Completed',
        ]);

        // 1 unit released today (Available)
        BloodUnit::factory()->create([
            'donation_id' => $donation->id,
            'blood_component_id' => $wb->id,
            'blood_group' => 'B+',
            'unit_status' => 'Available',
            'released_at' => now(),
            'expires_at' => now()->addDays(20),
        ]);

        // 1 unit expiring in 3 days (Available)
        BloodUnit::factory()->create([
            'donation_id' => $donation->id,
            'blood_component_id' => $wb->id,
            'blood_group' => 'B+',
            'unit_status' => 'Available',
            'released_at' => now()->subDays(5),
            'expires_at' => now()->addDays(3),
        ]);

        // 1 Quarantined unit (should NOT count as available or expiring)
        BloodUnit::factory()->create([
            'donation_id' => $donation->id,
            'blood_component_id' => $wb->id,
            'blood_group' => 'B+',
            'unit_status' => 'Quarantined',
            'released_at' => null,
            'expires_at' => now()->addDays(2),
        ]);

        // 1 Expired unit (should NOT count as available)
        BloodUnit::factory()->create([
            'donation_id' => $donation->id,
            'blood_component_id' => $wb->id,
            'blood_group' => 'B+',
            'unit_status' => 'Expired',
            'released_at' => now()->subDays(45),
            'expires_at' => now()->subDays(5),
        ]);

        $response = $this->actingAs($staffUser)->get(route('dashboard'));

        $response->assertOk();
        // Total Available Units shows 100 (excluding 25 reserved units)
        $response->assertSee('100');
        // Trend shows 1 unit added today
        $response->assertSee('+1 screened units added today');
        // Expiring within 7d shows 1 (the available one, ignoring the quarantined one)
        $response->assertSee('1');
        $response->assertSee('Prioritize FIFO dispatch rotation');
    }
}
