<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonationCampsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $staffUser = User::factory()->create();
        $staffUser->roles()->attach(Role::where('slug', 'staff')->firstOrFail());

        $this->actingAs($staffUser);
    }

    public function test_camps_overview_renders_six_summary_cards_eleven_columns_filters_and_sidebar_link(): void
    {
        $response = $this->get(route('camps.index'));

        $response->assertOk();
        $response->assertViewIs('camps.index');
        $response->assertViewHas('camps');
        $response->assertViewHas('summary');
        $response->assertViewHas('statuses');
        $response->assertViewHas('locations');

        $response->assertSee('Donation Camps Management');
        $response->assertSee('Create Camp');
        $response->assertSee('Donation Camps');

        // Required 6 summary cards
        foreach ([
            'Total Camps',
            'Upcoming Camps',
            'Ongoing Camps',
            'Completed Camps',
            'Total Registration',
            'Total Collection Capacity',
        ] as $cardTitle) {
            $response->assertSee($cardTitle);
        }

        // Required 11 table columns
        foreach ([
            'Camp ID',
            'Camp Name',
            'Location',
            'Start Date',
            'End Date',
            'Start Time',
            'Organizer',
            'Capacity',
            'Registered Donors',
            'Camp Status',
            'Actions',
        ] as $column) {
            $response->assertSee($column);
        }

        // Camp statuses & filter/pagination controls
        foreach ([
            'Draft',
            'Upcoming',
            'Ongoing',
            'Completed',
            'Cancelled',
            'Clear all filters',
            'camp-search',
            'filter-camp-status',
            'filter-camp-location',
            'camps-date-from',
            'camps-date-to',
            'camps-per-page',
        ] as $expected) {
            $response->assertSee($expected);
        }

        $response->assertSee(route('camps.create'));
        $response->assertSee(route('camps.show', 'CMP-308'));
        $response->assertSee(route('camps.edit', 'CMP-308'));
    }

    public function test_create_camp_page_renders_all_five_form_sections_and_required_inputs(): void
    {
        $response = $this->get(route('camps.create'));

        $response->assertOk();
        $response->assertViewIs('camps.create');
        $response->assertSee('Create Donation Camp');
        $response->assertSee('Back to Camps');

        foreach ([
            '1. Camp Information',
            '2. Location Information',
            '3. Schedule',
            '4. Capacity &amp; Registration',
            '5. Additional Information',
            'Camp Name',
            'Description',
            'Organizer Name',
            'Organizer Contact Number',
            'Organizer Email',
            'Venue Name',
            'Full Address',
            'City',
            'Additional Location Notes',
            'Start Date',
            'End Date',
            'Start Time',
            'End Time',
            'Maximum Donor Capacity',
            'Registration Deadline',
            'Allow Walk-in Donors',
            'Requirements for Donors',
            'Additional Notes',
        ] as $label) {
            $response->assertSee($label, false);
        }

        foreach ([
            'name="name"',
            'name="description"',
            'name="organizer_name"',
            'name="organizer_contact"',
            'name="organizer_email"',
            'name="venue_name"',
            'name="full_address"',
            'name="city"',
            'name="location_notes"',
            'name="start_date"',
            'name="end_date"',
            'name="start_time"',
            'name="end_time"',
            'name="capacity"',
            'name="registration_deadline"',
            'name="allow_walk_in"',
            'name="donor_requirements"',
            'name="additional_notes"',
        ] as $inputName) {
            $response->assertSee($inputName, false);
        }
    }

    public function test_camp_details_page_displays_overview_capacity_registrations_and_history(): void
    {
        $response = $this->get(route('camps.show', 'CMP-308'));

        $response->assertOk();
        $response->assertViewIs('camps.show');
        $response->assertViewHas('camp');
        $response->assertViewHas('donors');
        $response->assertViewHas('attendanceStatuses');
        $response->assertViewHas('campStatuses');

        $response->assertSee('CMP-308');
        $response->assertSee('Central Plaza Community Blood Drive');
        $response->assertSee('Capacity Overview');
        $response->assertSee('Maximum Capacity');
        $response->assertSee('Registered Donors');
        $response->assertSee('Available Slots');
        $response->assertSee('Registration Progress');
        $response->assertSee('80%');
        $response->assertSee('Registration Deadline');
        $response->assertSee('Nov 16, 2026');
        $response->assertSee('Donor Registration List');
        $response->assertSee('Registration Management');
        $response->assertSee('Camp Activity &amp; History', false);

        // Initial donor registrations & links (Rule 9: REG-805 Cancelled excluded from active capacity 4/5)
        $response->assertSee('REG-801');
        $response->assertSee('Elena Rostova');
        $response->assertSee('DNR-1041');
        $response->assertSee(route('donors.show', 'DNR-1041'));
        $response->assertSee('REG-805');
        $response->assertSee('Lucas Bennett');
        $response->assertSee('Camp Created');

        foreach (['Registered', 'Confirmed', 'Attended', 'No-show', 'Cancelled'] as $attendanceStatus) {
            $response->assertSee($attendanceStatus);
        }

        $response->assertSee(route('camps.index'));
        $response->assertSee(route('camps.edit', 'CMP-308'));
    }

    public function test_camp_details_covers_ongoing_draft_full_completed_and_cancelled_scenarios(): void
    {
        // Ongoing walk-in allowed & disabled
        $this->get(route('camps.show', 'CMP-307'))
            ->assertOk()
            ->assertSee('University Health Campus Autumn Drive')
            ->assertSee('Walk-in donors allowed during Ongoing status');

        $this->get(route('camps.show', 'CMP-306'))
            ->assertOk()
            ->assertSee('Westgate Tech Park Express Collection')
            ->assertSee('Pre-registered donors only (no walk-ins)');

        // Full capacity
        $this->get(route('camps.show', 'CMP-305'))
            ->assertOk()
            ->assertSee('Harborfront Full-Capacity Mobile Drive')
            ->assertSee('100%');

        // Deadline passed, Draft, Completed, Cancelled
        $this->get(route('camps.show', 'CMP-304'))->assertOk()->assertSee('Nov 09, 2026');
        $this->get(route('camps.show', 'CMP-303'))->assertOk()->assertSee('Draft');

        $this->get(route('camps.show', 'CMP-302'))
            ->assertOk()
            ->assertSee('Northside Autumn Community Drive')
            ->assertSee('Attended')
            ->assertSee('No-show');

        $this->get(route('camps.show', 'CMP-301'))
            ->assertOk()
            ->assertSee('Riverside Holiday Park Drive')
            ->assertSee('David Kim');
    }

    public function test_edit_camp_page_prefills_sample_data_and_shows_guards_and_closed_warning(): void
    {
        $response = $this->get(route('camps.edit', 'CMP-308'));

        $response->assertOk();
        $response->assertViewIs('camps.edit');
        $response->assertViewHas('camp');

        $response->assertSee('Edit Camp');
        $response->assertSee('CMP-308');
        $response->assertSee('Central Plaza Community Blood Drive');
        $response->assertSee('Elena Martinez');
        $response->assertSee('Preserved Donor Registrations Guard');
        $response->assertSee('cannot be reduced below 4');
        $response->assertSee('Save Camp Changes');

        foreach (['CMP-302' => 'Completed', 'CMP-301' => 'Cancelled'] as $campId => $status) {
            $this->get(route('camps.edit', $campId))
                ->assertOk()
                ->assertSee($status.' Camp Modification Notice');
        }
    }

    public function test_unknown_camp_routes_return_not_found(): void
    {
        $this->get(route('camps.show', 'CMP-9999'))->assertNotFound();
        $this->get(route('camps.edit', 'CMP-9999'))->assertNotFound();
    }

    public function test_sample_donor_links_used_in_camps_resolve_cleanly(): void
    {
        foreach (['DNR-1041', 'DNR-1044', 'DNR-1046', 'DNR-1047', 'DNR-1048'] as $donorId) {
            $this->get(route('donors.show', $donorId))->assertOk();
        }
    }
}
