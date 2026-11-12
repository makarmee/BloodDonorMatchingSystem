<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonationsPageTest extends TestCase
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

    public function test_donations_overview_displays_summary_statistics_table_columns_and_filters(): void
    {
        $response = $this->get(route('donations.index'));

        $response->assertOk();
        $response->assertViewIs('donations.index');
        $response->assertViewHas('donations');
        $response->assertViewHas('summary');
        $response->assertViewHas('bloodGroups');

        $response->assertSee('Donations Log');
        $response->assertSee('Add Donation');

        // Summary statistic cards
        $response->assertSee('Total Donations');
        $response->assertSee('Donations This Month');
        $response->assertSee('Total Units Collected');
        $response->assertSee('Pending Screening');

        // Required table columns
        foreach ([
            'Donation ID',
            'Donor Name',
            'Blood Group',
            'Donation Date',
            'Quantity (ml)',
            'Donation Type',
            'Screening Status',
            'Collection Status',
            'Actions',
        ] as $column) {
            $response->assertSee($column);
        }

        // Screening & Collection statuses plus Donation types
        foreach (['Pending', 'Passed', 'Failed', 'Scheduled', 'Completed', 'Cancelled', 'Whole Blood', 'Plasma', 'Platelets'] as $value) {
            $response->assertSee($value);
        }

        // Filter controls and action routes
        $response->assertSee('Clear all filters');
        $response->assertSee('filter-date-from');
        $response->assertSee('filter-date-to');
        $response->assertSee(route('donations.create'));
        $response->assertSee(route('donations.show', 'DON-4405'));
        $response->assertSee(route('donations.edit', 'DON-4405'));
        $response->assertSee(route('donors.show', 'DNR-1041'));
    }

    public function test_add_donation_page_renders_donor_picker_and_required_form_sections(): void
    {
        $response = $this->get(route('donations.create'));

        $response->assertOk();
        $response->assertViewIs('donations.create');
        $response->assertSee('Record New Donation');
        $response->assertSee('Back to Donations');

        foreach ([
            'Donor Information',
            'Donation Information',
            'Screening Information',
            'Collection Information',
            'DNR-1041',
            'Elena Rostova',
            'Whole Blood',
            'Plasma',
            'Platelets',
            'Pending',
            'Passed',
            'Failed',
            'Scheduled',
            'Completed',
            'Cancelled',
        ] as $expectedText) {
            $response->assertSee($expectedText);
        }

        foreach ([
            'name="donor_id"',
            'name="donation_date"',
            'name="donation_type"',
            'name="quantity_ml"',
            'name="collection_location"',
            'name="staff_member"',
            'name="screening_status"',
            'name="screening_notes"',
            'name="collection_status"',
            'name="collection_notes"',
        ] as $attribute) {
            $response->assertSee($attribute, false);
        }

        // Screening status must not auto-approve on creation
        $response->assertDontSee('<option value="Passed" selected', false);
    }

    public function test_donation_details_page_displays_full_record_and_links_to_donor_profile(): void
    {
        $response = $this->get(route('donations.show', 'DON-4405'));

        $response->assertOk();
        $response->assertViewIs('donations.show');
        $response->assertViewHas('donation');

        $response->assertSee('DON-4405');
        $response->assertSee('Elena Rostova');
        $response->assertSee('DNR-1041');
        $response->assertSee('O-');
        $response->assertSee('Nov 11, 2026');
        $response->assertSee('450 ml');
        $response->assertSee('Whole Blood');
        $response->assertSee('Central Medical Plaza');
        $response->assertSee('Nurse Hannah Reed');
        $response->assertSee('Screening Information');
        $response->assertSee('Collection Information');
        $response->assertSee('Back to Donations');
        $response->assertSee(route('donations.index'));
        $response->assertSee(route('donors.show', 'DNR-1041'));
        $response->assertSee(route('donations.edit', 'DON-4405'));
    }

    public function test_every_sample_donation_references_a_valid_accessible_donor_profile(): void
    {
        $response = $this->get(route('donations.index'));
        $response->assertOk();

        /** @var array<int, array{donorId: string}> $donations */
        $donations = $response->viewData('donations');
        $donorIds = array_values(array_unique(array_column($donations, 'donorId')));

        $this->assertNotEmpty($donorIds);

        foreach ($donorIds as $donorId) {
            $this->get(route('donors.show', $donorId))->assertOk();
        }
    }

    public function test_edit_donation_page_prefills_sample_donation_data(): void
    {
        $response = $this->get(route('donations.edit', 'DON-4403'));

        $response->assertOk();
        $response->assertViewIs('donations.edit');
        $response->assertViewHas('donation');

        $response->assertSee('Edit Donation');
        $response->assertSee('DON-4403');
        $response->assertSee('DNR-1044');
        $response->assertSee('David Kim');
        $response->assertSee('AB-');
        $response->assertSee('Platelets');
        $response->assertSee('250');
        $response->assertSee('Central Medical Plaza');
        $response->assertSee('Nurse Hannah Reed');
        $response->assertSee('Save Changes');
    }

    public function test_unknown_donation_routes_return_not_found(): void
    {
        $this->get(route('donations.show', 'DON-9999'))->assertNotFound();
        $this->get(route('donations.edit', 'DON-9999'))->assertNotFound();
    }
}
