<?php

namespace Tests\Feature;

use App\Models\BloodRequest;
use App\Models\Hospital;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BloodRequestsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $staffUser = User::factory()->create();
        $staffUser->roles()->attach(Role::where('slug', 'staff')->firstOrFail());

        $this->actingAs($staffUser);

        $hospitalMetro = Hospital::factory()->create(['name' => 'Metro General Trauma Center']);
        $hospitalHarbor = Hospital::factory()->create(['name' => 'Harborview Cardiac Institute']);
        $hospitalCity = Hospital::factory()->create(['name' => 'City General Hospital']);
        $hospitalValley = Hospital::factory()->create(['name' => 'Valley Community Hospital']);
        $hospitalEastside = Hospital::factory()->create(['name' => 'Eastside Orthopedics']);

        BloodRequest::query()->create([
            'request_code' => 'REQ-1044',
            'hospital_id' => $hospitalMetro->id,
            'requested_by_user_id' => $staffUser->id,
            'contact_person_snapshot' => 'Dr. Arthur Vance',
            'contact_phone_snapshot' => '+1 (555) 402-8810',
            'contact_email_snapshot' => 'transfusion.lab@metrotrauma.org',
            'blood_group' => 'O-',
            'requested_units' => 14,
            'reserved_units' => 0,
            'fulfilled_units' => 0,
            'priority' => 'Critical',
            'status' => 'Pending',
            'request_date' => now(),
            'required_date' => now(),
            'request_reason' => 'Mass casualty emergency surgical protocol requiring universal O-negative packed RBCs.',
        ]);

        $req1043 = BloodRequest::query()->create([
            'request_code' => 'REQ-1043',
            'hospital_id' => $hospitalHarbor->id,
            'requested_by_user_id' => $staffUser->id,
            'contact_person_snapshot' => 'Nurse Mei Chen',
            'contact_phone_snapshot' => '+1 (555) 719-3304',
            'contact_email_snapshot' => 'bloodbank@harborviewcardiac.org',
            'department_or_ward' => '220 Bayfront Blvd, Suite 400, Eastside',
            'blood_group' => 'B-',
            'requested_units' => 8,
            'reserved_units' => 0,
            'fulfilled_units' => 4,
            'priority' => 'High',
            'status' => 'Partially Fulfilled',
            'request_date' => now(),
            'required_date' => now(),
            'request_reason' => 'Scheduled cardiothoracic bypass surgery standby and post-op transfusion reserve.',
        ]);

        $req1043->statusHistories()->create([
            'event_name' => 'Partial Dispatch Recorded',
            'previous_status' => 'Approved',
            'new_status' => 'Partially Fulfilled',
            'units_affected' => 4,
            'actor_user_id' => $staffUser->id,
            'actor_name' => 'Nurse Hannah Reed',
            'notes' => 'Dispatched initial 4 units B- via cold-chain courier #TR-208.',
            'occurred_at' => now(),
        ]);

        BloodRequest::query()->create([
            'request_code' => 'REQ-1042',
            'hospital_id' => $hospitalCity->id,
            'requested_by_user_id' => $staffUser->id,
            'contact_person_snapshot' => 'Dr. Elena Morales',
            'contact_phone_snapshot' => '+1 (555) 289-4100',
            'contact_email_snapshot' => 'icu.transfusion@citygeneral.org',
            'blood_group' => 'AB-',
            'requested_units' => 4,
            'reserved_units' => 0,
            'fulfilled_units' => 0,
            'priority' => 'Critical',
            'status' => 'Approved',
            'request_date' => now(),
            'required_date' => now(),
            'request_reason' => 'ICU Trauma Unit Ward 3B acute hemorrhage stabilization.',
        ]);

        BloodRequest::query()->create([
            'request_code' => 'REQ-1037',
            'hospital_id' => $hospitalValley->id,
            'requested_by_user_id' => $staffUser->id,
            'contact_person_snapshot' => 'Lydia Gomez',
            'contact_phone_snapshot' => '+1 (555) 664-1190',
            'contact_email_snapshot' => 'supply@valleycommunity.org',
            'blood_group' => 'AB+',
            'requested_units' => 5,
            'reserved_units' => 0,
            'fulfilled_units' => 0,
            'priority' => 'Low',
            'status' => 'Rejected',
            'request_date' => now(),
            'required_date' => now(),
            'request_reason' => 'Routine ward stock buffer transfer request.',
            'rejection_reason' => 'Duplicate buffer request already covered under regional redistribution #RD-44.',
            'reviewed_by_user_id' => $staffUser->id,
            'reviewed_at' => now(),
        ]);

        BloodRequest::query()->create([
            'request_code' => 'REQ-1036',
            'hospital_id' => $hospitalEastside->id,
            'requested_by_user_id' => $staffUser->id,
            'contact_person_snapshot' => 'Dr. Henrik Lindqvist',
            'contact_phone_snapshot' => '+1 (555) 930-4481',
            'contact_email_snapshot' => 'surgery@eastsideortho.org',
            'blood_group' => 'A+',
            'requested_units' => 4,
            'reserved_units' => 0,
            'fulfilled_units' => 0,
            'priority' => 'Low',
            'status' => 'Cancelled',
            'request_date' => now(),
            'required_date' => now(),
            'request_reason' => 'Elective joint arthroplasty preoperative blood order.',
            'cancellation_reason' => 'Surgery rescheduled to next quarter; blood units released.',
            'cancelled_by_user_id' => $staffUser->id,
            'cancelled_at' => now(),
        ]);
    }

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

    public function test_request_form_date_input_supports_both_english_ltr_and_persian_rtl_modes(): void
    {
        // English (LTR) mode
        $enResponse = $this->withSession(['locale' => 'en'])->get(route('requests.create'));
        $enResponse->assertOk();
        $enResponse->assertSee('dir="ltr"', false);
        $enResponse->assertSee('id="request-required-date"', false);
        $enResponse->assertSee('name="required_date"', false);
        $enResponse->assertSee('type="date"', false);
        $enResponse->assertSee('Required Date');

        // Persian (RTL) mode on both create and edit forms
        $faCreateResponse = $this->withSession(['locale' => 'fa'])->get(route('requests.create'));
        $faCreateResponse->assertOk();
        $faCreateResponse->assertSee('dir="rtl"', false);
        $faCreateResponse->assertSee('id="request-required-date"', false);
        $faCreateResponse->assertSee('name="required_date"', false);
        $faCreateResponse->assertSee('type="date"', false);
        $faCreateResponse->assertSee('تاریخ نیاز');

        $faEditResponse = $this->withSession(['locale' => 'fa'])->get(route('requests.edit', 'REQ-1043'));
        $faEditResponse->assertOk();
        $faEditResponse->assertSee('dir="rtl"', false);
        $faEditResponse->assertSee('id="request-required-date"', false);

        // Verify global reusable RTL CSS rules in resources/css/app.css and compiled Vite bundle
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertNotFalse($css);
        $this->assertStringContainsString('[dir="rtl"] input[type="date"]', $css);
        $this->assertStringContainsString('direction: rtl;', $css);
        $this->assertStringContainsString('text-align: right;', $css);
        $this->assertStringContainsString('[dir="rtl"] input[type="date"]::-webkit-datetime-edit-fields-wrapper', $css);
        $this->assertStringContainsString('unicode-bidi: isolate;', $css);
        $this->assertStringNotContainsString('#request-required-date', $css);

        $manifestPath = public_path('build/manifest.json');
        $this->assertFileExists($manifestPath);
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $cssFile = $manifest['resources/css/app.css']['file'] ?? null;
        $this->assertNotNull($cssFile);
        $builtCss = (string) file_get_contents(public_path('build/'.$cssFile));
        $this->assertStringContainsString('[dir=rtl] input[type=date]{text-align:right;direction:rtl}', $builtCss);
        $this->assertStringContainsString('[dir=rtl] input[type=date]::-webkit-datetime-edit-fields-wrapper{direction:ltr;unicode-bidi:isolate}', $builtCss);
    }
}
