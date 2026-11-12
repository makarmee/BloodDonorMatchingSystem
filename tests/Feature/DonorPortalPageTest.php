<?php

namespace Tests\Feature;

use App\Http\Controllers\DonorPortalController;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonorPortalPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $donorUser = User::factory()->create();
        $donorUser->roles()->attach(Role::where('slug', 'donor')->firstOrFail());

        $this->actingAs($donorUser);
    }

    public function test_donor_portal_dashboard_renders_summary_cards_sections_profile_and_donor_navigation(): void
    {
        $response = $this->get(route('donor-portal.dashboard'));

        $response->assertOk();
        $response->assertViewIs('donor-portal.dashboard');
        $response->assertViewHas('donorProfile');
        $response->assertViewHas('eligibility');
        $response->assertViewHas('upcomingAppointments');
        $response->assertViewHas('recentDonations');
        $response->assertViewHas('upcomingCamps');

        // Donor navigation tabs (separated from staff ops)
        foreach ([
            'Overview',
            'My Appointments',
            'Donation History',
            'Eligibility',
        ] as $navItem) {
            $response->assertSee($navItem);
        }

        // Compact Donor Profile Summary with masked contacts
        $response->assertSee('DNR-1041');
        $response->assertSee('Elena Rostova');
        $response->assertSee('O-');
        $response->assertSee('el***@example.org');
        $response->assertSee('+1 (***) ***-8812');
        $response->assertSee('Jan 10, 2025');
        $response->assertSee('Active');

        // Summary cards
        foreach ([
            'Total Donations',
            'Upcoming Appointments',
            'Last Donation Date',
            'Current Eligibility Status',
        ] as $cardTitle) {
            $response->assertSee($cardTitle);
        }

        // Core dashboard sections & synchronized data
        $response->assertSee('Upcoming Appointment');
        $response->assertSee('APT-2041');
        $response->assertSee('Central Plaza Community Blood Drive');
        $response->assertSee('Recent Donation Activity');
        $response->assertSee('DON-4405');
        $response->assertSee('DON-4012');
        $response->assertSee('Eligibility Summary');
        $response->assertSee('Nearby / Upcoming Donation Camps');
        $response->assertSee('CMP-308');
        $response->assertSee('CMP-307');
        $response->assertSee('donorDashboard', false);

        // Must NOT expose sensitive personal data or staff-only management controls
        $response->assertDontSee('elena.rostova@example.org');
        $response->assertDontSee('412 Oakridge Ave');
        $response->assertDontSee('Victor Rostov');
        $response->assertDontSee('13.9 g/dL');
        $response->assertDontSee(route('camps.create'));
        $response->assertDontSee(route('donors.index'));
    }

    public function test_donor_appointments_index_renders_table_columns_statuses_filters_and_modals(): void
    {
        $response = $this->get(route('donor-portal.appointments.index'));

        $response->assertOk();
        $response->assertViewIs('donor-portal.appointments.index');
        $response->assertSee('My Donation Appointments');
        $response->assertSee('donorAppointments', false);

        foreach ([
            'Appointment ID',
            'Donation Type',
            'Date',
            'Time',
            'Location',
            'Related Camp',
            'Appointment Status',
            'Actions',
        ] as $column) {
            $response->assertSee($column);
        }

        foreach ([
            'Upcoming Appointments',
            'Past Appointments',
            'Pending',
            'Confirmed',
            'Completed',
            'Cancelled',
            'apt-search',
            'apt-status-filter',
            'apt-date-from',
            'apt-date-to',
            'Confirm Appointment Cancellation',
            'APT-2041',
            'APT-2038',
            'APT-1994',
            'APT-1962',
        ] as $expected) {
            $response->assertSee($expected);
        }

        $response->assertSee(route('donor-portal.appointments.create'));
    }

    public function test_donor_appointment_booking_form_renders_camps_centers_slots_and_preselection(): void
    {
        $response = $this->get(route('donor-portal.appointments.create', ['camp' => 'CMP-308']));

        $response->assertOk();
        $response->assertViewIs('donor-portal.appointments.create');
        $response->assertSee('Book Donation Appointment');
        $response->assertSee('appointmentForm', false);
        $response->assertSee('CMP-308');

        foreach ([
            'Donation Type',
            'Donation Camp or Donation Center',
            'Available Date',
            'Available Time Slot',
            'Additional Notes',
            'Whole Blood',
            'Platelets',
            'Central Medical Plaza Donor Suite',
            'Westgate Donor Suite',
            'Central Plaza Community Blood Drive',
            'University Health Campus Autumn Drive',
            'min="2026-11-11"',
        ] as $expected) {
            $response->assertSee($expected, false);
        }

        // Advisory banner for deferred/non-eligible preview donor
        $this->get(route('donor-portal.appointments.create', ['donor' => 'DNR-1042']))
            ->assertOk()
            ->assertSee('Eligibility Advisory (Not Eligible)');
    }

    public function test_donor_donation_history_renders_synchronized_records_without_private_staff_notes(): void
    {
        $response = $this->get(route('donor-portal.history'));

        $response->assertOk();
        $response->assertViewIs('donor-portal.history');
        $response->assertSee('My Donation History');
        $response->assertSee('donationHistory', false);

        foreach ([
            'Donation ID',
            'Donation Date',
            'Donation Type',
            'Blood Group',
            'Collection Location',
            'Donation Status',
            'Screening Status',
            'Actions',
            'DON-4405',
            'DON-4012',
            'DON-3720',
            'DON-3411',
        ] as $expected) {
            $response->assertSee($expected);
        }

        // Related screening summary is attached, while private lab/staff-only fields remain excluded
        $response->assertSee('SCR-7105');
        $response->assertSee('Hepatitis C');
        $response->assertDontSee('EDTA-K2 #T-9941A');
        $response->assertDontSee('Dr. Priya Nair');
        $response->assertDontSee('Nurse Hannah Reed');
        $response->assertDontSee('#CB-918');
    }

    public function test_donor_eligibility_renders_explicit_statuses_unknown_fallback_and_disclaimer(): void
    {
        $this->get(route('donor-portal.eligibility'))
            ->assertOk()
            ->assertViewIs('donor-portal.eligibility')
            ->assertSee('donorEligibility', false)
            ->assertSee('Eligible')
            ->assertSee('Nov 08, 2026')
            ->assertSee('Administrative Record vs. Medical Clearance')
            ->assertSee('authorized medical staff');

        $this->get(route('donor-portal.eligibility', ['donor' => 'DNR-1042']))
            ->assertOk()
            ->assertSee('Marcus Vance')
            ->assertSee('Not Eligible')
            ->assertSee('Dec 23, 2026');

        $this->get(route('donor-portal.eligibility', ['donor' => 'DNR-1043']))
            ->assertOk()
            ->assertSee('Priya Sharma')
            ->assertSee('Pending Review');

        // Verify unavailable/missing eligibility falls back to Unknown rather than auto-calculating Eligible
        $controller = app(DonorPortalController::class);
        $unknownResult = $controller->normalizeEligibilitySummary([
            'id' => 'DNR-1099',
            'accountStatus' => 'Active',
            'totalDonations' => 5,
            'lastDonationDate' => 'Jan 01, 2020',
            'eligibilityStatus' => null,
        ]);

        $this->assertSame('Unknown', $unknownResult['status']);
        $this->assertNull($unknownResult['nextEligibleDate']);
    }

    public function test_unknown_donor_record_in_donor_portal_returns_not_found(): void
    {
        foreach ([
            'donor-portal.dashboard',
            'donor-portal.appointments.index',
            'donor-portal.appointments.create',
            'donor-portal.history',
            'donor-portal.eligibility',
        ] as $routeName) {
            $this->get(route($routeName, ['donor' => 'DNR-9999']))->assertNotFound();
        }
    }

    public function test_existing_staff_sidebar_navigation_remains_separate_and_intact(): void
    {
        $adminUser = User::factory()->create();
        $adminUser->roles()->attach(Role::where('slug', 'admin')->firstOrFail());

        $staffResponse = $this->actingAs($adminUser)->get(route('camps.index'));

        $staffResponse->assertOk();
        foreach ([
            'Blood Inventory',
            'Donors',
            'Donations Log',
            'Blood Requests',
            'Donation Camps',
            'Lab Screening',
        ] as $staffNav) {
            $staffResponse->assertSee($staffNav);
        }
    }
}
