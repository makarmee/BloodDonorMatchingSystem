<?php

namespace Tests\Feature;

use Tests\TestCase;

class BloodRequestsPageTest extends TestCase
{
    public function test_requests_overview_renders_all_six_summary_cards_ten_columns_and_filters(): void
    {
        $response = $this->get(route('requests.index'));

        $response->assertOk();
        $response->assertViewIs('requests.index');
        $response->assertViewHas('requests');
        $response->assertViewHas('summary');
        $response->assertViewHas('bloodGroups');
        $response->assertViewHas('priorities');
        $response->assertViewHas('statuses');

        $response->assertSee('Blood Requests Management');
        $response->assertSee('New Blood Request');

        // Required 6 statistic cards
        foreach ([
            'Total Requests',
            'Pending Requests',
            'Urgent Requests',
            'Fulfilled Requests',
            'Total Units Requested',
            'Total Units Fulfilled',
        ] as $cardTitle) {
            $response->assertSee($cardTitle);
        }

        // Required 10 table columns
        foreach ([
            'Request ID',
            'Hospital Name',
            'Blood Group',
            'Requested Units',
            'Fulfilled Units',
            'Request Date',
            'Required Date',
            'Priority',
            'Request Status',
            'Actions',
        ] as $column) {
            $response->assertSee($column);
        }

        // Priorities and Statuses
        foreach ([
            'Low',
            'Normal',
            'High',
            'Critical',
            'Pending',
            'Approved',
            'Rejected',
            'Partially Fulfilled',
            'Fulfilled',
            'Cancelled',
            'Clear all filters',
            'requests-date-from',
            'requests-date-to',
        ] as $expected) {
            $response->assertSee($expected);
        }

        // Action route links
        $response->assertSee(route('requests.create'));
        $response->assertSee(route('requests.show', 'REQ-1042'));
        $response->assertSee(route('requests.edit', 'REQ-1042'));
    }

    public function test_create_request_page_renders_hospital_and_request_fields(): void
    {
        $response = $this->get(route('requests.create'));

        $response->assertOk();
        $response->assertViewIs('requests.create');
        $response->assertSee('Create New Blood Request');
        $response->assertSee('Back to Requests');

        foreach ([
            'Hospital Information',
            'Request Information',
            'Hospital Name',
            'Contact Person',
            'Contact Number',
            'Hospital Address',
            'Email',
            'Blood Group',
            'Number of Units Required',
            'Required Date',
            'Priority',
            'Request Reason',
            'Additional Notes',
        ] as $label) {
            $response->assertSee($label);
        }

        foreach ([
            'name="hospital_name"',
            'name="contact_person"',
            'name="contact_number"',
            'name="hospital_address"',
            'name="email"',
            'name="blood_group"',
            'name="requested_units"',
            'name="required_date"',
            'name="priority"',
            'name="request_reason"',
            'name="additional_notes"',
        ] as $inputName) {
            $response->assertSee($inputName, false);
        }
    }

    public function test_request_details_page_displays_progress_history_and_fulfillment_workflow(): void
    {
        $response = $this->get(route('requests.show', 'REQ-1043'));

        $response->assertOk();
        $response->assertViewIs('requests.show');
        $response->assertViewHas('bloodRequest');
        $response->assertViewHas('sampleInventory');

        $response->assertSee('REQ-1043');
        $response->assertSee('Harborview Cardiac Institute');
        $response->assertSee('B-');
        $response->assertSee('8 units');
        $response->assertSee('4');
        $response->assertSee('Partially Fulfilled');
        $response->assertSee('Fulfillment Progress');
        $response->assertSee('50%');
        $response->assertSee('Fulfillment Workflow');
        $response->assertSee('Record Fulfilled Units');
        $response->assertSee('Status &amp; Fulfillment History', false);
        $response->assertSee('Partial Dispatch Recorded');
        $response->assertSee('Back to Requests');
        $response->assertSee(route('requests.index'));
        $response->assertSee(route('requests.edit', 'REQ-1043'));
    }

    public function test_insufficient_inventory_warning_renders_when_remaining_units_exceed_sample_stock(): void
    {
        $response = $this->get(route('requests.show', 'REQ-1044'));

        $response->assertOk();
        $response->assertSee('REQ-1044');
        $response->assertSee('Insufficient sample inventory warning');
        $response->assertSee('Approve Request');
        $response->assertSee('Reject Request');
    }

    public function test_rejected_and_cancelled_requests_show_locked_fulfillment_notice(): void
    {
        foreach (['REQ-1037', 'REQ-1036'] as $closedId) {
            $response = $this->get(route('requests.show', $closedId));

            $response->assertOk();
            $response->assertSee('Fulfillment Locked');
            $response->assertSee('Rejected and Cancelled requests cannot receive additional fulfillment');
        }
    }

    public function test_edit_request_page_prefills_sample_data_and_shows_consistency_guard(): void
    {
        $response = $this->get(route('requests.edit', 'REQ-1043'));

        $response->assertOk();
        $response->assertViewIs('requests.edit');
        $response->assertViewHas('bloodRequest');

        $response->assertSee('Edit Request');
        $response->assertSee('REQ-1043');
        $response->assertSee('Harborview Cardiac Institute');
        $response->assertSee('Nurse Mei Chen');
        $response->assertSee('4 fulfilled units');
        $response->assertSee('Save Request Changes');
    }

    public function test_unknown_request_routes_return_not_found(): void
    {
        $this->get(route('requests.show', 'REQ-9999'))->assertNotFound();
        $this->get(route('requests.edit', 'REQ-9999'))->assertNotFound();
    }
}
