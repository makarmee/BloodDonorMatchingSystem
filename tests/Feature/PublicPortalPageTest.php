<?php

namespace Tests\Feature;

use App\Http\Controllers\InventoryController;
use Tests\TestCase;

class PublicPortalPageTest extends TestCase
{
    public function test_public_landing_page_renders_synchronized_inventory_camps_process_faqs_and_demo_contact(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertViewIs('pages.home');
        $response->assertViewHasAll([
            'bloodStock',
            'stockSummary',
            'upcomingCamps',
            'campSummary',
            'donationSteps',
            'faqs',
            'contactInfo',
        ]);

        // Preserved homepage expectations + new public portal sections
        $response->assertSee('Current Blood Group Availability');
        $response->assertSee('Register as Donor');
        $response->assertSee('How blood donation works from booking to screening');
        $response->assertSee('Frequently Asked Questions');
        $response->assertSee('Demo Placeholder Notice');
        $response->assertSee('[Demo Placeholder]');

        // Synchronized inventory metrics (305 total units across 8 groups)
        $response->assertSee('305');
        foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $group) {
            $response->assertSee($group);
        }

        // Quick navigation to public & donor routes
        $response->assertSee(route('public.stock'), false);
        $response->assertSee(route('public.camps.index'), false);
        $response->assertSee(route('donor-portal.dashboard'), false);

        // Strict public privacy checks
        foreach ([
            'Dr. Maya Lin',
            'Samuel Ortega',
            '#REQ-1042',
            'DNR-1041',
            'REG-801',
            'CMP-303',
            'CMP-302',
            'CMP-301',
        ] as $restricted) {
            $response->assertDontSee($restricted);
        }
    }

    public function test_public_blood_stock_page_syncs_with_inventory_controller_without_exposing_private_data(): void
    {
        $inventoryController = app(InventoryController::class);
        $expectedGroups = $inventoryController->sampleBloodGroups();
        $expectedTotalUnits = array_sum(array_column($expectedGroups, 'available'));

        $response = $this->get(route('public.stock'));

        $response->assertOk();
        $response->assertViewIs('public.stock');
        $response->assertSee('Public Blood Stock Availability');
        $response->assertSee('Preview Sample Stock Data');
        $response->assertSee((string) $expectedTotalUnits);
        $response->assertSee('publicBloodStock(', false);

        $viewGroups = $response->viewData('bloodGroups');
        $this->assertCount(8, $viewGroups);

        foreach ($expectedGroups as $index => $expectedRow) {
            $actualRow = $viewGroups[$index];
            $this->assertSame($expectedRow['group'], $actualRow['group']);
            $this->assertSame($expectedRow['available'], $actualRow['available']);
            $this->assertSame(
                $inventoryController->resolveStatus($expectedRow['available'], $expectedRow['minLevel']),
                $actualRow['status']
            );
            $this->assertArrayNotHasKey('reserved', $actualRow);
            $response->assertSee($expectedRow['group']);
        }

        foreach ([
            'Reserved Units',
            'Dr. Maya Lin',
            'Samuel Ortega',
            '#REQ-1042',
            'Elena Rostova',
            'DNR-1041',
        ] as $restricted) {
            $response->assertDontSee($restricted);
        }
    }

    public function test_public_camps_directory_lists_only_upcoming_and_ongoing_drives_without_donor_or_staff_leaks(): void
    {
        $response = $this->get(route('public.camps.index'));

        $response->assertOk();
        $response->assertViewIs('public.camps.index');
        $response->assertSee('Public Blood Donation Camps');
        $response->assertSee('Published Drives Only (Sample Preview)');
        $response->assertSee('publicCampsDirectory(', false);

        // Published Upcoming and Ongoing camps visible with computed capacities
        foreach ([
            'CMP-308',
            'Central Plaza Community Blood Drive',
            '1 / 5 slots available',
            'CMP-307',
            'University Health Campus Autumn Drive',
            '36 / 40 slots available',
            'CMP-306',
            'Westgate Tech Park Express Collection',
            '23 / 25 slots available',
            'CMP-305',
            'Harborfront Full-Capacity Mobile Drive',
            '0 / 3 slots available',
            'CMP-304',
            'Southgate Hospital Pre-Deadline Drive',
            '28 / 30 slots available',
        ] as $expected) {
            $response->assertSee($expected);
        }

        $viewCamps = $response->viewData('camps');
        $this->assertCount(5, $viewCamps);
        foreach ($viewCamps as $camp) {
            $this->assertContains($camp['status'], ['Upcoming', 'Ongoing']);
            $this->assertArrayNotHasKey('registrations', $camp);
            $this->assertArrayNotHasKey('history', $camp);
            $this->assertArrayNotHasKey('organizerContact', $camp);
            $this->assertArrayNotHasKey('organizerEmail', $camp);
            $this->assertArrayNotHasKey('organizerName', $camp);
        }

        // Draft, Completed, Cancelled drives + donor/staff details strictly excluded
        foreach ([
            'CMP-303',
            'Winter Corporate Blood Drive Plan',
            'CMP-302',
            'Northside Autumn Community Drive',
            'CMP-301',
            'Riverside Holiday Park Drive',
            'REG-801',
            'DNR-1041',
            'Elena Rostova',
            'Dr. Maya Lin',
            'Victor Hernandez',
            '+1 (555)',
        ] as $restricted) {
            $response->assertDontSee($restricted);
        }
    }

    public function test_public_camp_detail_shows_booking_state_and_returns_404_for_hidden_or_missing_camps(): void
    {
        // Open Upcoming camp links cleanly to Donor Portal appointment creation
        $openResponse = $this->get(route('public.camps.show', 'CMP-308'));
        $openResponse->assertOk();
        $openResponse->assertViewIs('public.camps.show');
        $openResponse->assertSee('Central Plaza Community Blood Drive');
        $openResponse->assertSee('1 of 5 remaining');
        $openResponse->assertSee(route('donor-portal.appointments.create', ['camp' => 'CMP-308']), false);
        $openResponse->assertSee('[Demo Placeholder]');
        $openResponse->assertDontSee('REG-801');
        $openResponse->assertDontSee('DNR-1041');
        $openResponse->assertDontSee('Elena Martinez');

        // Full camp displays unavailable warning and does not offer booking link
        $fullResponse = $this->get(route('public.camps.show', 'CMP-305'));
        $fullResponse->assertOk();
        $fullResponse->assertSee('Capacity Full');
        $fullResponse->assertDontSee(route('donor-portal.appointments.create', ['camp' => 'CMP-305']), false);

        // Deadline-passed camp does not offer advance online booking CTA
        $deadlineResponse = $this->get(route('public.camps.show', 'CMP-304'));
        $deadlineResponse->assertOk();
        $deadlineResponse->assertSee('Registration Closed');
        $deadlineResponse->assertDontSee(route('donor-portal.appointments.create', ['camp' => 'CMP-304']), false);
        $deadlineResponse->assertDontSee('Dr. Maya Lin');

        // Ongoing pre-booked-only camp does not expose walk-in booking CTA
        $walkInClosedResponse = $this->get(route('public.camps.show', 'CMP-306'));
        $walkInClosedResponse->assertOk();
        $walkInClosedResponse->assertSee('Pre-Booked Only');
        $walkInClosedResponse->assertDontSee(route('donor-portal.appointments.create', ['camp' => 'CMP-306']), false);

        // Draft, Completed, Cancelled, and Unknown camp IDs all return 404
        foreach (['CMP-303', 'CMP-302', 'CMP-301', 'CMP-9999'] as $hiddenOrInvalid) {
            $this->get(route('public.camps.show', $hiddenOrInvalid))->assertNotFound();
        }
    }

    public function test_public_navbar_and_footer_navigation_links_are_accessible_without_authentication(): void
    {
        foreach ([
            route('home'),
            route('public.stock'),
            route('public.camps.index'),
            route('public.camps.show', 'CMP-308'),
        ] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $response->assertSee('Blood Stock');
            $response->assertSee('Donation Camps');
            $response->assertSee('Donor Portal');
            $response->assertSee('Become a Donor');
        }
    }
}
