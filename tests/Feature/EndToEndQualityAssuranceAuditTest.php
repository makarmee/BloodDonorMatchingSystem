<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\DonorAppointment;
use App\Models\DonorEligibilityRecord;
use App\Models\Hospital;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\ScreeningRecord;
use App\Models\User;
use App\Services\DonationService;
use App\Services\ScreeningService;
use Database\Seeders\BloodCompatibilityRuleSeeder;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class EndToEndQualityAssuranceAuditTest extends TestCase
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

        RateLimiter::clear('register:127.0.0.1');
        RateLimiter::clear('login:127.0.0.1');
    }

    /**
     * Audit Role 1: Administrator Full Operational Lifecycle.
     */
    public function test_admin_role_full_operational_lifecycle(): void
    {
        $admin = $this->createUserWithRole('admin');

        // 1. Login & Dashboard
        $response = $this->actingAs($admin)->get(route('dashboard'));
        $response->assertOk();
        $response->assertViewIs('dashboard.index');
        $response->assertViewHas('activeRole', 'admin');

        // 2. Hospital Verification
        $hospital = Hospital::factory()->create([
            'verification_status' => 'Pending',
            'is_active' => true,
        ]);
        $hospResponse = $this->actingAs($admin)->get(route('hospitals.index'));
        $hospResponse->assertOk();
        $hospResponse->assertSee($hospital->name);

        $verifyResponse = $this->actingAs($admin)->post(route('hospitals.verify', $hospital));
        $verifyResponse->assertRedirect();
        $this->assertSame('Verified', $hospital->fresh()->verification_status);

        // 3. Donor Management
        $donor = Donor::factory()->create([
            'donor_code' => 'DNR-QA-001',
            'eligibility_status' => 'Pending Review',
        ]);
        $donorsResponse = $this->actingAs($admin)->get(route('donors.index'));
        $donorsResponse->assertOk();
        $donorsResponse->assertSee('DNR-QA-001');

        $assessResponse = $this->actingAs($admin)->post(route('donors.eligibility.assess', $donor), [
            'eligibility_status' => 'Eligible',
            'reason' => 'QA clinical baseline intake verified.',
        ]);
        $assessResponse->assertRedirect(route('donors.show', $donor));
        $this->assertSame('Eligible', $donor->fresh()->eligibility_status);

        // 4. Donation Management
        $center = DonationCenter::factory()->create();
        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();

        $donationResponse = $this->actingAs($admin)->post(route('donations.store'), [
            'donor_id' => $donor->donor_code,
            'donation_date' => now()->toDateString(),
            'donation_type' => 'Whole Blood',
            'quantity_ml' => 450,
            'collection_location' => $center->name,
            'collection_status' => 'Completed',
        ]);
        $donationResponse->assertRedirect();
        $donation = Donation::query()->where('donor_id', $donor->id)->firstOrFail();
        $this->assertSame('Completed', $donation->collection_status);

        // 5. Screening Management & Mandatory Assays
        $screeningResponse = $this->actingAs($admin)->get(route('screening.index'));
        $screeningResponse->assertOk();

        // 6. Blood Inventory Management & Stock Threshold Adjustments
        $stock = InventoryStock::firstOrCreate(
            ['blood_group' => 'O+', 'blood_component_id' => $component->id],
            ['units_on_hand' => 10, 'available_units' => 10, 'reserved_units' => 0, 'quarantined_units' => 0]
        );
        $invResponse = $this->actingAs($admin)->get(route('inventory.index'));
        $invResponse->assertOk();

        $adjustResponse = $this->actingAs($admin)->post(route('inventory.stocks.adjust', $stock), [
            'minimum_level' => 15,
            'target_capacity' => 50,
            'description' => 'QA safety threshold upgrade',
        ]);
        $adjustResponse->assertRedirect(route('inventory.index'));
        $this->assertSame(15, $stock->fresh()->minimum_level);
        $this->assertSame(50, $stock->fresh()->target_capacity);

        // 7. Donation Camps Management
        $campResponse = $this->actingAs($admin)->post(route('camps.store'), [
            'name' => 'Metro Blood Drive QA',
            'description' => 'Comprehensive university and community blood drive.',
            'organizer_name' => 'QA Health Volunteers',
            'organizer_contact' => '+1-555-442-8819',
            'organizer_email' => 'qa.camps@example.org',
            'venue_name' => 'Civic Center Hall A',
            'full_address' => '500 Civic Center Blvd',
            'city' => 'Metropolis',
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'capacity' => 100,
            'registration_deadline' => now()->addDays(4)->toDateString(),
            'allow_walk_in' => 'Yes',
            'donor_requirements' => 'Age 18-65, min 50kg, valid photo ID.',
            'status' => 'Upcoming',
        ]);
        $campResponse->assertRedirect();
        $this->assertDatabaseHas('donation_camps', ['name' => 'Metro Blood Drive QA']);

        // 8. Logout
        $logoutResponse = $this->actingAs($admin)->post(route('logout'));
        $logoutResponse->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * Audit Role 2: Donor Full Lifecycle & Access Boundaries.
     */
    public function test_donor_role_lifecycle_and_boundary_isolation(): void
    {
        // 1. Donor Registration
        $registerResponse = $this->followingRedirects()->post(route('register.attempt'), [
            'role' => 'donor',
            'name' => 'Audited Donor',
            'email' => 'audited.donor@example.com',
            'blood_group' => 'O-',
            'date_of_birth' => '1995-05-15',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);
        $registerResponse->assertOk();
        $registerResponse->assertViewIs('donor-portal.dashboard');

        $donorUser = User::query()->where('email', 'audited.donor@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($donorUser);
        $this->assertNotNull($donorUser->donor);
        $this->assertSame('O-', $donorUser->donor->blood_group);

        // 2. Donor Dashboard, Eligibility, and History
        $this->actingAs($donorUser)->get(route('donor-portal.dashboard'))->assertOk();
        $this->actingAs($donorUser)->get(route('donor-portal.eligibility'))->assertOk();
        $this->actingAs($donorUser)->get(route('donor-portal.history'))->assertOk();

        // 3. Appointment Booking & Cancellation
        $center = DonationCenter::factory()->create();

        $bookResponse = $this->actingAs($donorUser)->post(route('donor-portal.appointments.store'), [
            'donation_type' => 'Whole Blood',
            'center_id' => $center->id,
            'appointment_date' => now()->addDays(3)->toDateString(),
            'slot_time' => '10:00:00',
            'notes' => 'First time appointment',
        ]);
        $bookResponse->assertRedirect(route('donor-portal.appointments.index'));

        $appointment = DonorAppointment::query()->where('donor_id', $donorUser->donor->id)->firstOrFail();
        $this->assertSame('Pending', $appointment->status);

        $cancelResponse = $this->actingAs($donorUser)->post(route('donor-portal.appointments.cancel', $appointment), [
            'cancellation_reason' => 'Scheduling conflict',
        ]);
        $cancelResponse->assertRedirect(route('donor-portal.appointments.index'));
        $this->assertSame('Cancelled', $appointment->fresh()->status);

        // 4. Strict Security Boundaries: Donor MUST NOT access admin/staff routes
        $otherDonor = Donor::factory()->create(['donor_code' => 'DNR-OTHER-01']);
        $this->actingAs($donorUser)->get(route('donors.index'))->assertForbidden();
        $this->actingAs($donorUser)->get(route('donors.show', $otherDonor))->assertForbidden();
        $this->actingAs($donorUser)->get(route('donors.create'))->assertForbidden();
        $this->actingAs($donorUser)->post(route('donors.store'), [])->assertForbidden();
        $this->actingAs($donorUser)->get(route('donations.index'))->assertForbidden();
        $this->actingAs($donorUser)->get(route('inventory.index'))->assertForbidden();
        $this->actingAs($donorUser)->get(route('screening.index'))->assertForbidden();
        $this->actingAs($donorUser)->get(route('requests.index'))->assertForbidden();
        $this->actingAs($donorUser)->get(route('hospitals.index'))->assertForbidden();
        $this->actingAs($donorUser)->get(route('camps.index'))->assertForbidden();

        // 5. Cannot access another donor's profile via query parameter
        $this->actingAs($donorUser)->get(route('donor-portal.dashboard', ['donor' => 'DNR-OTHER-01']))->assertForbidden();

        // 6. Cannot cancel another donor's appointment
        $otherAppointment = DonorAppointment::factory()->create([
            'donor_id' => $otherDonor->id,
            'status' => 'Pending',
        ]);
        $this->actingAs($donorUser)
            ->post(route('donor-portal.appointments.cancel', $otherAppointment), ['cancellation_reason' => 'Malicious attempt'])
            ->assertForbidden();
    }

    /**
     * Audit Role 3: Hospital Partner Full Lifecycle & Hospital Isolation.
     */
    public function test_hospital_partner_lifecycle_and_hospital_isolation(): void
    {
        // 1. Hospital Registration
        $response = $this->followingRedirects()->post(route('register.attempt'), [
            'role' => 'hospital',
            'name' => 'Dr. Lisa Cuddy',
            'email' => 'lisa.cuddy@princetonplainsboro.org',
            'organization_name' => 'Princeton Plainsboro Hospital',
            'phone' => '+1 (555) 789-0123',
            'city' => 'Princeton',
            'address' => '100 Medical Center Plaza',
            'password' => 'hospitalpass123',
            'password_confirmation' => 'hospitalpass123',
        ]);
        $response->assertOk();
        $response->assertViewIs('dashboard.index');

        $hospitalUser = User::query()->where('email', 'lisa.cuddy@princetonplainsboro.org')->firstOrFail();
        $hospital = Hospital::query()->where('name', 'Princeton Plainsboro Hospital')->firstOrFail();
        $this->assertSame('Pending', $hospital->verification_status);

        // 2. Unverified hospital CANNOT create blood request
        $this->actingAs($hospitalUser)->get(route('requests.create'))->assertForbidden();

        // 3. Admin verifies hospital
        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)->post(route('hospitals.verify', $hospital))->assertRedirect();
        $this->assertSame('Verified', $hospital->fresh()->verification_status);

        // 4. Verified hospital CAN create blood request
        $this->actingAs($hospitalUser)->get(route('requests.create'))->assertOk();

        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();
        $storeReqResponse = $this->actingAs($hospitalUser)->post(route('requests.store'), [
            'hospital_id' => $hospital->id,
            'blood_group' => 'A+',
            'blood_component_id' => $component->id,
            'requested_units' => 4,
            'priority' => 'High',
            'request_date' => now()->toDateString(),
            'required_date' => now()->addDays(2)->toDateString(),
            'request_reason' => 'Emergency surgical unit requirement',
            'contact_person' => 'Dr. Lisa Cuddy',
            'phone' => '+1 (555) 789-0123',
            'email' => 'lisa.cuddy@princetonplainsboro.org',
            'hospital_address' => '100 Medical Center Plaza',
        ]);
        $storeReqResponse->assertRedirect();

        $bloodRequest = BloodRequest::query()->where('hospital_id', $hospital->id)->firstOrFail();
        $this->assertSame(4, $bloodRequest->requested_units);
        $this->assertSame('Pending', $bloodRequest->status);

        // 5. Hospital Isolation: Hospital 1 coordinator cannot see Hospital 2 request
        $hospital2 = Hospital::factory()->create(['verification_status' => 'Verified']);
        $bloodRequest2 = BloodRequest::factory()->create([
            'hospital_id' => $hospital2->id,
            'status' => 'Pending',
        ]);

        $this->actingAs($hospitalUser)->get(route('requests.show', $bloodRequest))->assertOk();
        $this->actingAs($hospitalUser)->get(route('requests.show', $bloodRequest2))->assertForbidden();

        // 6. Hospital partner CANNOT access administrative modules
        $this->actingAs($hospitalUser)->get(route('donors.index'))->assertForbidden();
        $this->actingAs($hospitalUser)->get(route('donations.index'))->assertForbidden();
        $this->actingAs($hospitalUser)->get(route('screening.index'))->assertForbidden();
        $this->actingAs($hospitalUser)->get(route('hospitals.index'))->assertForbidden();
        $this->actingAs($hospitalUser)->get(route('camps.index'))->assertForbidden();

        // 7. Hospital partner CANNOT perform inventory adjustments
        $stock = InventoryStock::firstOrCreate(
            ['blood_group' => 'A+', 'blood_component_id' => $component->id],
            ['units_on_hand' => 10, 'available_units' => 10, 'reserved_units' => 0, 'quarantined_units' => 0]
        );
        $this->actingAs($hospitalUser)->post(route('inventory.stocks.adjust', $stock), [
            'minimum_level' => 20,
        ])->assertForbidden();
    }

    /**
     * Audit Role 4: Lab Technician / Lab Officer Workflow & Inventory Release.
     */
    public function test_lab_technician_screening_and_inventory_release_workflow(): void
    {
        $labOfficer = $this->createUserWithRole('lab_officer');

        // 1. Dashboard View
        $response = $this->actingAs($labOfficer)->get(route('dashboard'));
        $response->assertOk();
        $response->assertViewHas('activeRole', 'lab_officer');

        // 2. Screening Queue View
        $queueResponse = $this->actingAs($labOfficer)->get(route('screening.index'));
        $queueResponse->assertOk();

        // 3. Setup Donation awaiting screening
        $donor = Donor::factory()->eligible()->create(['blood_group' => 'B+']);
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();
        $staff = $this->createUserWithRole('staff');
        $donation = app(DonationService::class)->registerDonation($donor, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location_name' => 'Main Center',
        ]);

        $screeningService = app(ScreeningService::class);
        $screeningService->initializePendingPanel($donation, $labOfficer);

        // 4. Releasing before completing mandatory assays fails
        $prematureRelease = $this->actingAs($labOfficer)->post(route('screening.donations.release', $donation));
        $prematureRelease->assertRedirect();
        $this->assertSame('Pending', $donation->fresh()->screening_status);

        // 5. Record All Mandatory Assays as Passed
        $records = ScreeningRecord::query()->where('donation_id', $donation->id)->get();
        foreach ($records as $record) {
            $screeningService->recordScreeningResult($donation, $labOfficer, [
                'screening_record_id' => $record->id,
                'screening_result' => 'Passed',
                'assay_method' => 'Automated Serology',
            ]);
        }

        // 6. Releasing cleared donation into Available Inventory
        $releaseResponse = $this->actingAs($labOfficer)->post(route('screening.donations.release', $donation), [
            'storage_location' => 'Main Cold Room Rack 1',
            'screening_notes' => 'All mandatory infectious markers non-reactive.',
        ]);
        $releaseResponse->assertRedirect();
        $releaseResponse->assertSessionHas('success');

        $this->assertSame('Passed', $donation->fresh()->screening_status);
        $unit = BloodUnit::query()->where('donation_id', $donation->id)->firstOrFail();
        $this->assertSame('Available', $unit->unit_status);

        // 7. Security Boundaries: Lab Officer cannot verify hospitals, manage camps, or fulfill requests
        $hospital = Hospital::factory()->create(['verification_status' => 'Pending']);
        $this->actingAs($labOfficer)->get(route('hospitals.index'))->assertForbidden();
        $this->actingAs($labOfficer)->post(route('hospitals.verify', $hospital))->assertForbidden();
        $this->actingAs($labOfficer)->get(route('camps.index'))->assertForbidden();
        $this->actingAs($labOfficer)->post(route('camps.store'), [])->assertForbidden();

        $bloodRequest = BloodRequest::factory()->create(['status' => 'Pending']);
        $this->actingAs($labOfficer)->post(route('requests.approve', $bloodRequest))->assertForbidden();
    }

    /**
     * Audit 5: Unauthenticated, Inactive, and Rate Limiting Protections.
     */
    public function test_security_unauthenticated_inactive_and_csrf_protections(): void
    {
        // 1. Unauthenticated users redirected to login
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('inventory.index'))->assertRedirect(route('login'));
        $this->get(route('donors.index'))->assertRedirect(route('login'));
        $this->get(route('donations.index'))->assertRedirect(route('login'));
        $this->get(route('screening.index'))->assertRedirect(route('login'));
        $this->get(route('requests.index'))->assertRedirect(route('login'));
        $this->get(route('hospitals.index'))->assertRedirect(route('login'));
        $this->get(route('camps.index'))->assertRedirect(route('login'));
        $this->get(route('donor-portal.dashboard'))->assertRedirect(route('login'));

        // 2. Inactive User cannot access protected routes
        $inactiveUser = $this->createUserWithRole('admin', isActive: false);
        $this->actingAs($inactiveUser)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($inactiveUser)->get(route('inventory.index'))->assertForbidden();
        $this->actingAs($inactiveUser)->get(route('donors.index'))->assertForbidden();

        // 3. Rate limiting on registration (5 attempts/min)
        RateLimiter::clear('register|127.0.0.1');
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit('register|127.0.0.1', 60);
        }
        $throttled = $this->post(route('register.attempt'), ['email' => 'throttled@example.com']);
        $throttled->assertSessionHasErrors('email');
        RateLimiter::clear('register|127.0.0.1');
    }

    /**
     * Audit 6: Localization, RTL Layout, and Latin System Notation Integrity.
     */
    public function test_localization_bilingual_switching_and_rtl_integrity(): void
    {
        // 1. Persian switch & RTL
        $switchFa = $this->get(route('locale.switch', 'fa'));
        $switchFa->assertRedirect();
        $this->assertSame('fa', session('locale'));

        $homeFa = $this->withSession(['locale' => 'fa'])->get(route('home'));
        $homeFa->assertOk();
        $homeFa->assertSee('dir="rtl"', false);
        $homeFa->assertSee('lang="fa"', false);

        // 2. English switch & LTR
        $switchEn = $this->get(route('locale.switch', 'en'));
        $switchEn->assertRedirect();
        $this->assertSame('en', session('locale'));

        $homeEn = $this->withSession(['locale' => 'en'])->get(route('home'));
        $homeEn->assertOk();
        $homeEn->assertSee('dir="ltr"', false);
        $homeEn->assertSee('lang="en"', false);

        // 3. Public stock preserves Latin blood group notation in Persian mode
        $stockFa = $this->withSession(['locale' => 'fa'])->get(route('public.stock'));
        $stockFa->assertOk();
        foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bg) {
            $stockFa->assertSee($bg);
        }
    }

    private function createUserWithRole(string $roleSlug, bool $isActive = true): User
    {
        $user = User::factory()->create([
            'is_active' => $isActive,
            'password' => Hash::make('password123'),
        ]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user;
    }
}
