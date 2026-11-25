<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\Donor;
use App\Models\DonorEligibilityRecord;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\Role;
use App\Models\ScreeningRecord;
use App\Models\User;
use App\Services\DonationService;
use App\Services\InventoryService;
use App\Services\ScreeningService;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScreeningAndReleaseIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $labOfficer;

    private User $staff;

    private User $regularUser;

    private DonationService $donationService;

    private ScreeningService $screeningService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(BloodComponentSeeder::class);
        $this->seed(ScreeningTestTypeSeeder::class);
        $this->seed(ComponentScreeningRequirementSeeder::class);

        $this->donationService = app(DonationService::class);
        $this->screeningService = app(ScreeningService::class);

        $this->labOfficer = User::factory()->create(['name' => 'Dr. Priya Nair']);
        $this->labOfficer->roles()->attach(Role::where('slug', 'lab_officer')->firstOrFail());

        $this->staff = User::factory()->create(['name' => 'Nurse Jane']);
        $this->staff->roles()->attach(Role::where('slug', 'staff')->firstOrFail());

        $this->regularUser = User::factory()->create(['name' => 'John Public']);
    }

    public function test_lab_officer_can_view_screening_index_with_real_database_records_and_summary_metrics(): void
    {
        $donor = Donor::factory()->eligible()->create();
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location_name' => 'Main Center',
        ]);

        $this->screeningService->initializePendingPanel($donation, $this->staff);

        $response = $this->actingAs($this->labOfficer)->get(route('screening.index'));

        $response->assertOk();
        $summary = $response->viewData('summary');

        $this->assertGreaterThanOrEqual(4, $summary['totalSamples']);
        $this->assertSame($summary['totalSamples'], $summary['pendingTests']);
        $this->assertSame(0, $summary['passedSamples']);
        $this->assertSame(0, $summary['failedSamples']);
    }

    public function test_lab_officer_can_record_passed_screening_result(): void
    {
        $donor = Donor::factory()->eligible()->create();
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location_name' => 'Main Center',
        ]);

        $this->screeningService->initializePendingPanel($donation, $this->staff);
        $pendingRecord = ScreeningRecord::where('donation_id', $donation->id)->firstOrFail();

        $response = $this->actingAs($this->labOfficer)->post(route('screening.records.store'), [
            'donation_id' => $donation->donation_code,
            'screening_record_id' => $pendingRecord->id,
            'screening_result' => 'Passed',
            'assay_method' => 'CLIA — Chemiluminescent Immunoassay',
            'test_notes' => 'Negative / Non-reactive verified.',
        ]);

        $response->assertRedirect(route('screening.show', $donation));
        $pendingRecord->refresh();

        $this->assertSame('Passed', $pendingRecord->screening_result);
        $this->assertNotNull($pendingRecord->result_recorded_at);
        $this->assertSame('CLIA — Chemiluminescent Immunoassay', $pendingRecord->assay_method);
    }

    public function test_failed_screening_result_triggers_quarantined_unit_discard_and_donor_deferral(): void
    {
        $donor = Donor::factory()->eligible()->create();
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location_name' => 'Main Center',
        ]);

        $this->screeningService->initializePendingPanel($donation, $this->staff);
        $pendingRecord = ScreeningRecord::where('donation_id', $donation->id)->firstOrFail();
        $quarantinedUnit = BloodUnit::where('donation_id', $donation->id)->firstOrFail();

        $response = $this->actingAs($this->labOfficer)->post(route('screening.records.store'), [
            'donation_id' => $donation->donation_code,
            'screening_record_id' => $pendingRecord->id,
            'screening_result' => 'Failed',
            'test_notes' => 'Confirmed reactive signal on viral marker.',
        ]);

        $response->assertRedirect(route('screening.show', $donation));
        $pendingRecord->refresh();
        $donation->refresh();
        $quarantinedUnit->refresh();
        $donor->refresh();

        $this->assertSame('Failed', $pendingRecord->screening_result);
        $this->assertSame('Failed', $donation->screening_status);

        // Unit is discarded
        $this->assertSame('Discarded', $quarantinedUnit->unit_status);

        // Donor is deferred (Not Eligible)
        $this->assertSame('Not Eligible', $donor->eligibility_status);
        $this->assertSame(1, DonorEligibilityRecord::where('donor_id', $donor->id)->current()->withStatus('Not Eligible')->count());
    }

    public function test_cannot_release_to_inventory_when_mandatory_tests_are_pending(): void
    {
        $donor = Donor::factory()->eligible()->create();
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location_name' => 'Main Center',
        ]);

        $this->screeningService->initializePendingPanel($donation, $this->staff);

        // Only pass 1 test, leave others pending
        $firstRecord = ScreeningRecord::where('donation_id', $donation->id)->firstOrFail();
        $this->screeningService->recordScreeningResult($donation, $this->labOfficer, [
            'screening_record_id' => $firstRecord->id,
            'screening_result' => 'Passed',
        ]);

        $response = $this->actingAs($this->labOfficer)->post(route('screening.donations.release', $donation));

        $response->assertSessionHasErrors();
        $unit = BloodUnit::where('donation_id', $donation->id)->firstOrFail();
        $this->assertSame('Quarantined', $unit->unit_status);
    }

    public function test_authorized_user_can_release_fully_passed_donation_to_inventory(): void
    {
        $donor = Donor::factory()->eligible()->create(['blood_group' => 'O+']);
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location_name' => 'Main Center',
        ]);

        $this->screeningService->initializePendingPanel($donation, $this->staff);

        // Pass all mandatory screening records
        $records = ScreeningRecord::where('donation_id', $donation->id)->get();
        foreach ($records as $rec) {
            $this->screeningService->recordScreeningResult($donation, $this->labOfficer, [
                'screening_record_id' => $rec->id,
                'screening_result' => 'Passed',
            ]);
        }

        $response = $this->actingAs($this->labOfficer)->post(route('screening.donations.release', $donation), [
            'storage_location' => 'Main Cold Room Rack 1',
            'screening_notes' => 'All mandatory infectious markers non-reactive.',
        ]);

        $response->assertRedirect(route('donations.show', $donation));

        $unit = BloodUnit::where('donation_id', $donation->id)->firstOrFail();
        $this->assertSame('Available', $unit->unit_status);
        $this->assertNotNull($unit->released_at);

        // Inventory stock incremented
        $stock = InventoryStock::where('blood_group', 'O+')->where('blood_component_id', $component->id)->first();
        $this->assertNotNull($stock);
        $this->assertGreaterThanOrEqual(1, $stock->available_units);

        // Inventory transaction created
        $tx = InventoryTransaction::where('blood_unit_id', $unit->id)->first();
        $this->assertNotNull($tx);
        $this->assertSame('Stock In', $tx->operation_type);
        $this->assertSame(1, $tx->units_delta);
    }

    public function test_unauthorized_users_cannot_record_results_or_release_inventory(): void
    {
        $donor = Donor::factory()->eligible()->create();
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location_name' => 'Main Center',
        ]);

        $this->actingAs($this->regularUser)
            ->get(route('screening.index'))
            ->assertForbidden();

        $this->actingAs($this->regularUser)
            ->post(route('screening.records.store'), [
                'donation_id' => $donation->donation_code,
                'screening_result' => 'Passed',
            ])
            ->assertForbidden();

        $this->actingAs($this->regularUser)
            ->post(route('screening.donations.release', $donation))
            ->assertForbidden();
    }

    public function test_assay_method_accepts_all_six_canonical_options_and_saves_correctly(): void
    {
        $donor = Donor::factory()->eligible()->create();
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::where('code', 'WB')->firstOrFail();

        $expectedMethods = [
            'CLIA — Chemiluminescent Immunoassay',
            'ECLIA — Electrochemiluminescence Immunoassay',
            'CMIA — Chemiluminescent Microparticle Immunoassay',
            'ELISA / EIA — Enzyme-Linked Immunosorbent Assay',
            'NAT — Nucleic Acid Testing',
            'Rapid Test — Rapid Diagnostic Test',
        ];

        $this->assertSame($expectedMethods, ScreeningRecord::ASSAY_METHODS);

        foreach ($expectedMethods as $method) {
            $donation = $this->donationService->registerDonation($donor, $this->staff, [
                'blood_component_id' => $component->id,
                'quantity_ml' => 450,
                'collection_status' => 'Completed',
                'collection_location_name' => 'Main Center',
            ]);

            $this->screeningService->initializePendingPanel($donation, $this->staff);
            $pendingRecord = ScreeningRecord::where('donation_id', $donation->id)->firstOrFail();

            $response = $this->actingAs($this->labOfficer)->post(route('screening.records.store'), [
                'donation_id' => $donation->donation_code,
                'screening_record_id' => $pendingRecord->id,
                'screening_result' => 'Passed',
                'assay_method' => $method,
                'test_notes' => "Tested with {$method}.",
            ]);

            $response->assertRedirect(route('screening.show', $donation));
            $this->assertSame($method, $pendingRecord->fresh()->assay_method);

            // Verify it displays correctly on show page
            $showResponse = $this->actingAs($this->labOfficer)->get(route('screening.show', $donation));
            $showResponse->assertOk();
            $showResponse->assertSee($method);
        }
    }

    public function test_assay_method_rejects_unlisted_and_other_values(): void
    {
        $donor = Donor::factory()->eligible()->create();
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location_name' => 'Main Center',
        ]);

        $this->screeningService->initializePendingPanel($donation, $this->staff);
        $pendingRecord = ScreeningRecord::where('donation_id', $donation->id)->firstOrFail();

        foreach (['Other', 'Manual', 'Random String', 'CLIA', 'ELISA'] as $invalidMethod) {
            $response = $this->actingAs($this->labOfficer)->post(route('screening.records.store'), [
                'donation_id' => $donation->donation_code,
                'screening_record_id' => $pendingRecord->id,
                'screening_result' => 'Passed',
                'assay_method' => $invalidMethod,
            ]);

            $response->assertSessionHasErrors('assay_method');
            $this->assertNotSame($invalidMethod, $pendingRecord->fresh()->assay_method);
        }
    }

    public function test_screening_details_view_renders_assay_method_dropdown_with_exact_options(): void
    {
        $donor = Donor::factory()->eligible()->create();
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location_name' => 'Main Center',
        ]);

        $this->screeningService->initializePendingPanel($donation, $this->staff);

        $response = $this->actingAs($this->labOfficer)->get(route('screening.show', $donation));
        $response->assertOk();

        // Must render a select element for assay_method
        $response->assertSee('<select', false);
        $response->assertSee('name="assay_method"', false);
        $response->assertSee('id="modal-assay-method"', false);

        // All 6 options must exist in dropdown
        foreach (ScreeningRecord::ASSAY_METHODS as $method) {
            $response->assertSee('<option value="'.$method.'"', false);
        }

        // Must NOT include an "Other" option
        $response->assertDontSee('<option value="Other"', false);
        $response->assertDontSee('>Other<', false);
    }

    public function test_screening_details_route_resolves_donation_code_and_displays_all_screening_records(): void
    {
        $donor = Donor::factory()->eligible()->create(['full_name' => 'Test Panel Donor']);
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location_name' => 'Main Center',
        ]);
        $donation->update(['donation_code' => 'DON-358904']);

        $this->screeningService->initializePendingPanel($donation, $this->staff);

        $records = ScreeningRecord::with('testType')
            ->where('donation_id', $donation->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(5, $records);

        // Pass the first two records with distinct assay methods and notes, leave remaining pending
        $this->screeningService->recordScreeningResult($donation, $this->labOfficer, [
            'screening_record_id' => $records[0]->id,
            'screening_result' => 'Passed',
            'assay_method' => 'NAT — Nucleic Acid Testing',
            'test_notes' => 'Primary assay negative.',
        ]);

        $this->screeningService->recordScreeningResult($donation, $this->labOfficer, [
            'screening_record_id' => $records[1]->id,
            'screening_result' => 'Passed',
            'assay_method' => 'ECLIA — Electrochemiluminescence Immunoassay',
            'test_notes' => 'Secondary assay negative.',
        ]);

        $response = $this->actingAs($this->labOfficer)->get('/screening/DON-358904');

        $response->assertOk();
        $response->assertViewIs('screening.show');
        $response->assertSee('DON-358904');
        $response->assertSee('Test Panel Donor');

        foreach ($records as $record) {
            $response->assertSee($record->screening_code);
            $response->assertSee($record->testType->name);
        }

        $response->assertSee('NAT — Nucleic Acid Testing');
        $response->assertSee('Primary assay negative.');
        $response->assertSee('ECLIA — Electrochemiluminescence Immunoassay');
        $response->assertSee('Secondary assay negative.');
        $response->assertSee('Pending');
        $response->assertSee('Passed');

        // Verify screening index links to Donation Code rather than SCR code
        $indexResponse = $this->actingAs($this->labOfficer)->get(route('screening.index'));
        $indexResponse->assertOk();
        $indexResponse->assertSee(route('screening.show', 'DON-358904'));
    }

    public function test_screening_details_route_returns_404_for_invalid_donation_code(): void
    {
        $this->actingAs($this->labOfficer)
            ->get('/screening/DON-999999')
            ->assertNotFound();

        $this->actingAs($this->labOfficer)
            ->get('/screening/INVALID-DONATION-CODE')
            ->assertNotFound();
    }

    public function test_unauthorized_users_cannot_access_screening_details_by_donation_code(): void
    {
        $donor = Donor::factory()->eligible()->create();
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location_name' => 'Main Center',
        ]);
        $donation->update(['donation_code' => 'DON-358905']);

        $this->screeningService->initializePendingPanel($donation, $this->staff);

        // Guest redirects to login
        $this->get('/screening/DON-358905')->assertRedirect(route('login'));

        // Regular user without role/permission gets 403
        $this->actingAs($this->regularUser)
            ->get('/screening/DON-358905')
            ->assertForbidden();

        // Donor user gets 403
        $donorUser = User::factory()->create(['is_active' => true]);
        $donorUser->roles()->attach(Role::where('slug', 'donor')->firstOrFail());
        $this->actingAs($donorUser)
            ->get('/screening/DON-358905')
            ->assertForbidden();

        // Hospital user gets 403
        $hospitalUser = User::factory()->create(['is_active' => true]);
        $hospitalUser->roles()->attach(Role::where('slug', 'hospital')->firstOrFail());
        $this->actingAs($hospitalUser)
            ->get('/screening/DON-358905')
            ->assertForbidden();

        // Inactive lab officer gets 403 / blocked by active middleware
        $inactiveLabOfficer = User::factory()->create(['is_active' => false]);
        $inactiveLabOfficer->roles()->attach(Role::where('slug', 'lab_officer')->firstOrFail());
        $this->actingAs($inactiveLabOfficer)
            ->get('/screening/DON-358905')
            ->assertStatus(403);
    }

    public function test_screening_details_page_renders_clean_persian_rtl_layout(): void
    {
        $donor = Donor::factory()->eligible()->create(['full_name' => 'احمد رضایی']);
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location_name' => 'Main Center',
        ]);
        $donation->update(['donation_code' => 'DON-358906']);

        $this->screeningService->initializePendingPanel($donation, $this->staff);

        $response = $this->actingAs($this->labOfficer)
            ->withSession(['locale' => 'fa'])
            ->get('/screening/DON-358906');

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('DON-358906');
        $response->assertSee('پنل معاینات و نتایج آزمایش‌ها');
        $response->assertSee('مشخصات اهداکننده');
        $response->assertSee('آزمایش‌های غربالگری در انتظار باید تکمیل شوند');
    }

    public function test_screening_create_page_submits_persisted_records_and_enforces_record_result_authorization(): void
    {
        $donor = Donor::factory()->eligible()->create(['blood_group' => 'A+']);
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location_name' => 'Main Center',
        ]);

        // Persisted donations render real POST form with assay_method and confirmatory option
        $createResponse = $this->actingAs($this->labOfficer)->get(route('screening.create', ['donation' => $donation->donation_code]));
        $createResponse->assertOk();
        $createResponse->assertViewHas('hasPersistedDonations', true);
        $createResponse->assertSee('method="POST"', false);
        $createResponse->assertSee('action="'.route('screening.records.store').'"', false);
        $createResponse->assertSee('name="assay_method"', false);
        $createResponse->assertSee('name="is_confirmatory"', false);

        // Staff lacking screening.record receives 403 immediately even with nonexistent donation_id
        $this->actingAs($this->staff)
            ->post(route('screening.records.store'), [
                'donation_id' => 'DON-NONEXISTENT-403',
                'test_type' => 'HIV',
                'screening_result' => 'Passed',
            ])
            ->assertForbidden();

        $this->actingAs($this->staff)
            ->post(route('screening.donations.release', $donation))
            ->assertForbidden();

        // Lab officer submits real screening record via screening.records.store
        $storeResponse = $this->actingAs($this->labOfficer)->post(route('screening.records.store'), [
            'donation_id' => $donation->donation_code,
            'test_type' => 'HIV',
            'test_date' => now()->toDateString(),
            'screening_result' => 'Passed',
            'assay_method' => 'NAT — Nucleic Acid Testing',
            'lab_staff' => 'Dr. Priya Nair',
            'test_notes' => 'Submitted from screening.create form.',
        ]);

        $storeResponse->assertRedirect(route('screening.show', $donation));
        $this->assertDatabaseHas('screening_records', [
            'donation_id' => $donation->id,
            'screening_result' => 'Passed',
            'assay_method' => 'NAT — Nucleic Acid Testing',
        ]);
    }

    public function test_screening_show_displays_accurate_states_and_guards_actions_for_failed_released_and_staff(): void
    {
        $donor = Donor::factory()->eligible()->create(['blood_group' => 'O+']);
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location_name' => 'Main Center',
        ]);
        $this->screeningService->initializePendingPanel($donation, $this->staff);

        // Staff user can inspect screening.show but must not see Record Result or Release forms/modals
        $staffShow = $this->actingAs($this->staff)->get(route('screening.show', $donation));
        $staffShow->assertOk();
        $staffShow->assertDontSee(route('screening.records.store'), false);
        $staffShow->assertDontSee(route('screening.donations.release', $donation), false);
        $staffShow->assertDontSee('release-inventory-modal', false);

        // Pass all tests and release to inventory
        foreach (ScreeningRecord::where('donation_id', $donation->id)->get() as $record) {
            $this->screeningService->recordScreeningResult($donation, $this->labOfficer, [
                'screening_record_id' => $record->id,
                'screening_result' => 'Passed',
            ]);
        }
        $this->screeningService->releaseToInventory($donation, $this->labOfficer);

        // Released state must display Released banner, never Inventory Release Blocked or Record Result modal
        $releasedShow = $this->actingAs($this->labOfficer)->get(route('screening.show', $donation));
        $releasedShow->assertOk();
        $releasedShow->assertSee('Released to Available Inventory');
        $releasedShow->assertDontSee('Inventory Release Blocked');
        $releasedShow->assertDontSee('release-inventory-modal', false);

        // Failed donation + confirmatory Passed remains permanently disqualified
        $donor2 = Donor::factory()->eligible()->create(['blood_group' => 'B+']);
        DonorEligibilityRecord::factory()->eligible()->for($donor2)->create();
        $failedDonation = $this->donationService->registerDonation($donor2, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location_name' => 'Main Center',
        ]);
        $this->screeningService->initializePendingPanel($failedDonation, $this->staff);

        $records = ScreeningRecord::where('donation_id', $failedDonation->id)->orderBy('id')->get();
        $first = $records->first();
        $this->screeningService->recordScreeningResult($failedDonation, $this->labOfficer, [
            'screening_record_id' => $first->id,
            'screening_result' => 'Failed',
        ]);
        foreach ($records->slice(1) as $remaining) {
            $this->screeningService->recordScreeningResult($failedDonation, $this->labOfficer, [
                'screening_record_id' => $remaining->id,
                'screening_result' => 'Passed',
            ]);
        }
        // Even a confirmatory Passed test on the same failed test type cannot unlock release
        $this->screeningService->recordScreeningResult($failedDonation, $this->labOfficer, [
            'test_type_id' => $first->test_type_id,
            'screening_result' => 'Passed',
            'is_confirmatory' => true,
        ]);

        $failedShow = $this->actingAs($this->labOfficer)->get(route('screening.show', $failedDonation));
        $failedShow->assertOk();
        $failedShow->assertSee('Screening Failed — Permanently Disqualified from Release');
        $failedShow->assertDontSee('release-inventory-modal', false);

        $readiness = $this->screeningService->evaluateReleaseReadiness($failedDonation->fresh());
        $this->assertFalse($readiness['is_ready_for_release']);
    }

    public function test_admit_screened_unit_cannot_bypass_mandatory_screening_or_release_permission(): void
    {
        $donor = Donor::factory()->eligible()->create(['blood_group' => 'AB-']);
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $component = BloodComponent::where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location_name' => 'Main Center',
        ]);
        $this->screeningService->initializePendingPanel($donation, $this->staff);
        $unit = BloodUnit::where('donation_id', $donation->id)->firstOrFail();

        $inventoryService = app(InventoryService::class);

        // Staff holding inventory.adjust (but NOT screening.release_inventory) must be denied
        try {
            $inventoryService->admitScreenedUnit($unit, $this->staff);
            $this->fail('Expected AuthorizationException when staff attempts admitScreenedUnit without screening.release_inventory.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        // Even an admin/lab officer cannot bypass pending mandatory screening records via admitScreenedUnit
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('slug', 'admin')->firstOrFail());

        try {
            $inventoryService->admitScreenedUnit($unit, $admin);
            $this->fail('Expected ValidationException when admitting unit with pending screening records.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('Quarantined', $unit->fresh()->unit_status);
        $this->assertNull($donation->fresh()->released_to_inventory_at);
    }
}
