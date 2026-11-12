<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreeningPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $labOfficerUser = User::factory()->create();
        $labOfficerUser->roles()->attach(Role::where('slug', 'lab_officer')->firstOrFail());

        $this->actingAs($labOfficerUser);
    }

    public function test_lab_screening_overview_renders_summary_cards_columns_filters_and_test_types(): void
    {
        $response = $this->get(route('screening.index'));

        $response->assertOk();
        $response->assertViewIs('screening.index');
        $response->assertViewHas('samples');
        $response->assertViewHas('summary');
        $response->assertViewHas('bloodGroups');
        $response->assertViewHas('testTypes');

        $response->assertSee('Lab Screening');
        $response->assertSee('Record Screening Test');

        // Summary cards
        $response->assertSee('Total Samples');
        $response->assertSee('Pending Tests');
        $response->assertSee('Passed Samples');
        $response->assertSee('Failed Samples');

        // Table columns
        foreach ([
            'Screening ID',
            'Donation ID',
            'Donor Name',
            'Blood Group',
            'Sample Collection Date',
            'Test Date',
            'Test Type',
            'Screening Result',
            'Actions',
        ] as $column) {
            $response->assertSee($column);
        }

        // Required test types and result badges
        foreach ([
            'HIV',
            'Hepatitis B',
            'Hepatitis C',
            'Syphilis',
            'Blood Group Compatibility',
            'Pending',
            'Passed',
            'Failed',
            'Clear all filters',
            'screening-date-from',
            'screening-date-to',
        ] as $expected) {
            $response->assertSee($expected);
        }

        // Route links
        $response->assertSee(route('screening.create'));
        $response->assertSee(route('screening.show', 'SCR-7105'));
        $response->assertSee(route('donations.show', 'DON-4405'));
        $response->assertSee(route('donors.show', 'DNR-1041'));
    }

    public function test_screening_creation_page_renders_donation_selector_and_requires_explicit_result(): void
    {
        $response = $this->get(route('screening.create'));

        $response->assertOk();
        $response->assertViewIs('screening.create');
        $response->assertViewHas('donations');
        $response->assertViewHas('testTypes');

        $response->assertSee('Record Lab Screening Test');
        $response->assertSee('Back to Screening');
        $response->assertSee('Associated Donor');

        foreach ([
            'Select Donation',
            'Test Type',
            'Test Date',
            'Screening Result',
            'Test Notes',
            'Laboratory Staff',
            'HIV',
            'Hepatitis B',
            'Hepatitis C',
            'Syphilis',
            'Blood Group Compatibility',
            'DON-4405',
            'Elena Rostova',
            'do not represent real clinical clearance',
        ] as $text) {
            $response->assertSee($text);
        }

        foreach ([
            'name="donation_id"',
            'name="test_type"',
            'name="test_date"',
            'name="screening_result"',
            'name="test_notes"',
            'name="lab_staff"',
        ] as $attribute) {
            $response->assertSee($attribute, false);
        }

        // Never auto-select Passed
        $response->assertDontSee('<option value="Passed" selected', false);
    }

    public function test_screening_details_page_displays_donor_donation_test_notes_and_timestamps(): void
    {
        $response = $this->get(route('screening.show', 'SCR-7102'));

        $response->assertOk();
        $response->assertViewIs('screening.show');
        $response->assertViewHas('sample');

        $response->assertSee('SCR-7102');
        $response->assertSee('DON-4403');
        $response->assertSee('DNR-1044');
        $response->assertSee('David Kim');
        $response->assertSee('AB-');
        $response->assertSee('Hepatitis C');
        $response->assertSee('Passed');
        $response->assertSee('Nov 08, 2026');
        $response->assertSee('Nov 09, 2026');
        $response->assertSee('Dr. Priya Nair (Serology)');
        $response->assertSee('Audit &amp; Processing Timestamps', false);
        $response->assertSee('Back to Screening');
        $response->assertSee(route('screening.index'));
        $response->assertSee(route('donations.show', 'DON-4403'));
        $response->assertSee(route('donors.show', 'DNR-1044'));
    }

    public function test_every_sample_screening_links_to_valid_accessible_donation_and_donor_routes(): void
    {
        $response = $this->get(route('screening.index'));
        $response->assertOk();

        /** @var array<int, array{donationId: string, donorId: string}> $samples */
        $samples = $response->viewData('samples');
        $this->assertNotEmpty($samples);

        foreach (array_unique(array_column($samples, 'donationId')) as $donationId) {
            $this->get(route('donations.show', $donationId))->assertOk();
        }

        foreach (array_unique(array_column($samples, 'donorId')) as $donorId) {
            $this->get(route('donors.show', $donorId))->assertOk();
        }
    }

    public function test_unknown_screening_route_returns_not_found(): void
    {
        $this->get(route('screening.show', 'SCR-9999'))->assertNotFound();
    }
}
