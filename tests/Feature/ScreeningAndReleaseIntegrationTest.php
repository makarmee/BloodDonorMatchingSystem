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
use App\Services\ScreeningService;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $response->assertRedirect(route('screening.show', $pendingRecord));
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

        $response->assertRedirect(route('screening.show', $pendingRecord));
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

            $response->assertRedirect(route('screening.show', $pendingRecord));
            $this->assertSame($method, $pendingRecord->fresh()->assay_method);

            // Verify it displays correctly on show page
            $showResponse = $this->actingAs($this->labOfficer)->get(route('screening.show', $pendingRecord));
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
        $pendingRecord = ScreeningRecord::where('donation_id', $donation->id)->firstOrFail();

        $response = $this->actingAs($this->labOfficer)->get(route('screening.show', $pendingRecord));
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
}
