<?php

namespace Tests\Feature;

use Tests\TestCase;

class DonorsPageTest extends TestCase
{
    public function test_donors_directory_renders_overview_statistics_filters_and_all_columns(): void
    {
        $response = $this->get(route('donors.index'));

        $response->assertOk();
        $response->assertViewIs('donors.index');
        $response->assertViewHas('donors');
        $response->assertViewHas('summary');
        $response->assertViewHas('bloodGroups');

        $response->assertSee('Donors Directory');
        $response->assertSee('Add Donor');

        // Overview statistics cards
        $response->assertSee('Total Donors');
        $response->assertSee('Active Donors');
        $response->assertSee('Eligible Donors');
        $response->assertSee('New Donors This Month');

        // Table headers
        foreach ([
            'Donor ID',
            'Full Name',
            'Blood Group',
            'Gender',
            'Phone Number',
            'Last Donation Date',
            'Eligibility Status',
            'Account Status',
            'Actions',
        ] as $column) {
            $response->assertSee($column);
        }

        // Eligibility status indicators
        $response->assertSee('Eligible');
        $response->assertSee('Not Eligible');
        $response->assertSee('Pending Review');

        // Sample donor records & action links
        $response->assertSee('DNR-1041');
        $response->assertSee('Elena Rostova');
        $response->assertSee('DNR-1042');
        $response->assertSee('Marcus Vance');
        $response->assertSee('DNR-1043');
        $response->assertSee('Priya Sharma');
        $response->assertSee(route('donors.create'));
        $response->assertSee(route('donors.show', 'DNR-1041'));
        $response->assertSee(route('donors.edit', 'DNR-1041'));
    }

    public function test_add_donor_page_renders_all_required_registration_fields_and_blood_groups(): void
    {
        $response = $this->get(route('donors.create'));

        $response->assertOk();
        $response->assertViewIs('donors.create');
        $response->assertSee('Register New Donor');

        foreach ([
            'Full Name',
            'Email',
            'Phone Number',
            'Gender',
            'Date of Birth',
            'Blood Group',
            'Address',
            'Emergency Contact',
            'Medical Notes',
            'Availability Status',
        ] as $fieldLabel) {
            $response->assertSee($fieldLabel);
        }

        foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bloodGroup) {
            $response->assertSee($bloodGroup);
        }
    }

    public function test_donor_profile_page_displays_personal_contact_eligibility_and_donation_history(): void
    {
        $response = $this->get(route('donors.show', 'DNR-1041'));

        $response->assertOk();
        $response->assertViewIs('donors.show');
        $response->assertViewHas('donor');

        $response->assertSee('Elena Rostova');
        $response->assertSee('DNR-1041');
        $response->assertSee('elena.rostova@example.org');
        $response->assertSee('+1 (555) 234-8812');
        $response->assertSee('O-');
        $response->assertSee('Eligible');
        $response->assertSee('Jul 22, 2026');
        $response->assertSee('Personal Information');
        $response->assertSee('Contact Information');
        $response->assertSee('Donation History');
        $response->assertSee('DON-4012');
        $response->assertSee('Central Medical Plaza');
    }

    public function test_donor_profile_page_displays_empty_state_when_donor_has_no_donations(): void
    {
        $response = $this->get(route('donors.show', 'DNR-1043'));

        $response->assertOk();
        $response->assertSee('Priya Sharma');
        $response->assertSee('Pending Review');
        $response->assertSee('No donation history on record');
    }

    public function test_edit_donor_page_prefills_sample_donor_details(): void
    {
        $response = $this->get(route('donors.edit', 'DNR-1041'));

        $response->assertOk();
        $response->assertViewIs('donors.edit');
        $response->assertViewHas('donor');

        $response->assertSee('Edit Donor');
        $response->assertSee('Elena Rostova');
        $response->assertSee('elena.rostova@example.org');
        $response->assertSee('+1 (555) 234-8812');
        $response->assertSee('412 Oakridge Ave, Apt 4B, Central District');
        $response->assertSee('Save Changes');
    }

    public function test_unknown_donor_routes_return_not_found(): void
    {
        $this->get(route('donors.show', 'DNR-9999'))->assertNotFound();
        $this->get(route('donors.edit', 'DNR-9999'))->assertNotFound();
    }
}
