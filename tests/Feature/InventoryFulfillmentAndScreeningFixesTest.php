<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\ComponentScreeningRequirement;
use App\Models\Donation;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\ScreeningRecord;
use App\Models\User;
use App\Services\DonationService;
use App\Services\DonorEligibilityService;
use App\Services\FulfillmentService;
use App\Services\InventoryService;
use App\Services\ScreeningService;
use Carbon\CarbonImmutable;
use Database\Seeders\BloodCompatibilityRuleSeeder;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\InventoryStockSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryFulfillmentAndScreeningFixesTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventoryService;

    private DonationService $donationService;

    private ScreeningService $screeningService;

    private DonorEligibilityService $eligibilityService;

    private FulfillmentService $fulfillmentService;

    private User $admin;

    private User $donorUser;

    private User $hospitalOfficer;

    private Hospital $hospital;

    private DonationCenter $center;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            BloodComponentSeeder::class,
            ScreeningTestTypeSeeder::class,
            ComponentScreeningRequirementSeeder::class,
            BloodCompatibilityRuleSeeder::class,
            InventoryStockSeeder::class,
        ]);

        $this->inventoryService = app(InventoryService::class);
        $this->donationService = app(DonationService::class);
        $this->screeningService = app(ScreeningService::class);
        $this->eligibilityService = app(DonorEligibilityService::class);
        $this->fulfillmentService = app(FulfillmentService::class);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $donorRole = Role::query()->where('slug', 'donor')->firstOrFail();
        $hospitalRole = Role::query()->where('slug', 'hospital')->firstOrFail();

        $this->admin = User::factory()->create(['name' => 'Admin User', 'is_active' => true]);
        $this->admin->roles()->attach($adminRole);

        $this->donorUser = User::factory()->create(['name' => 'Donor User', 'is_active' => true]);
        $this->donorUser->roles()->attach($donorRole);

        $this->hospitalOfficer = User::factory()->create(['name' => 'Hospital Officer', 'is_active' => true]);
        $this->hospitalOfficer->roles()->attach($hospitalRole);

        $this->hospital = Hospital::factory()->create([
            'name' => 'City General Hospital',
            'is_active' => true,
            'verification_status' => 'Verified',
        ]);

        HospitalUser::factory()->create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->hospitalOfficer->id,
            'is_active' => true,
        ]);

        $this->center = DonationCenter::factory()->create(['name' => 'Central Hub', 'is_active' => true]);
    }

    private function createCompletedDonationWithPrimaryUnit(string $bloodGroup = 'O+'): Donation
    {
        $donor = Donor::factory()->create([
            'blood_group' => $bloodGroup,
            'account_status' => 'Active',
            'profile_completed' => true,
        ]);

        $this->eligibilityService->approveEligibility(
            donor: $donor,
            actor: $this->admin,
            reason: 'Clinical clearance approved for testing.'
        );

        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();

        $donation = $this->donationService->registerDonation($donor, $this->admin, [
            'blood_group' => $bloodGroup,
            'blood_component_id' => $component->id,
            'center_id' => $this->center->id,
            'donation_date' => CarbonImmutable::today()->toDateString(),
            'quantity_ml' => 450,
            'collection_status' => 'Scheduled',
        ]);

        return $this->donationService->completeDonation($donation, $this->admin, [
            'storage_location' => 'Quarantine Shelf A',
        ]);
    }

    // =========================================================================
    // 1. SCREENING READINESS TESTS
    // =========================================================================

    public function test_pending_records_are_not_classified_as_missing(): void
    {
        $donation = $this->createCompletedDonationWithPrimaryUnit('O+');

        // Initialize all mandatory screening requirements
        $this->screeningService->initializePendingPanel($donation, $this->admin);

        // Mark 3 as Passed and leave 2 as Pending
        $records = ScreeningRecord::query()->where('donation_id', $donation->id)->get();
        $this->assertCount(5, $records);

        $records->take(3)->each(function (ScreeningRecord $rec) use ($donation): void {
            $this->screeningService->recordScreeningResult($donation, $this->admin, [
                'screening_record_id' => $rec->id,
                'screening_result' => 'Passed',
                'assay_method' => 'CLIA — Chemiluminescent Immunoassay',
                'test_date' => CarbonImmutable::today()->toDateString(),
            ]);
        });

        $readiness = $this->screeningService->evaluateReleaseReadiness($donation->fresh());

        $this->assertFalse($readiness['is_ready_for_release']);
        $this->assertCount(2, $readiness['pending_test_codes']);
        $this->assertEmpty($readiness['missing_test_codes'], 'Pending screening records must NOT be classified as missing.');
        $this->assertContains('Pending screening tests must be completed: '.implode(', ', $readiness['pending_test_codes']).'.', $readiness['blocking_reasons']);
        $this->assertNotContains('Missing required screening tests: '.implode(', ', $readiness['pending_test_codes']).'.', $readiness['blocking_reasons']);
    }

    public function test_truly_absent_records_are_classified_as_missing(): void
    {
        $donation = $this->createCompletedDonationWithPrimaryUnit('O+');

        // Intentionally create only 2 of the 5 required screening test records
        $requirements = ComponentScreeningRequirement::query()
            ->where('blood_component_id', $donation->blood_component_id)
            ->mandatory()
            ->get();

        $firstTwo = $requirements->take(2);
        foreach ($firstTwo as $req) {
            ScreeningRecord::create([
                'screening_code' => 'SCR-TEST-'.$req->test_type_id,
                'donation_id' => $donation->id,
                'test_type_id' => $req->test_type_id,
                'screening_result' => 'Passed',
                'assay_method' => 'CLIA — Chemiluminescent Immunoassay',
                'performed_by_user_id' => $this->admin->id,
                'lab_staff_name' => 'Lab Tech',
                'logged_at' => now(),
            ]);
        }

        $readiness = $this->screeningService->evaluateReleaseReadiness($donation->fresh());

        $this->assertFalse($readiness['is_ready_for_release']);
        $this->assertCount(3, $readiness['missing_test_codes'], 'Absent test requirements must be classified as missing.');
        $this->assertContains('Missing required screening tests: '.implode(', ', $readiness['missing_test_codes']).'.', $readiness['blocking_reasons']);
    }

    public function test_pending_tests_still_block_release(): void
    {
        $donation = $this->createCompletedDonationWithPrimaryUnit('O+');
        $this->screeningService->initializePendingPanel($donation, $this->admin);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Pending screening tests must be completed');

        $this->screeningService->releaseToInventory($donation, $this->admin);
    }

    public function test_failed_tests_still_block_release(): void
    {
        $donation = $this->createCompletedDonationWithPrimaryUnit('O+');
        $this->screeningService->initializePendingPanel($donation, $this->admin);

        $record = ScreeningRecord::query()->where('donation_id', $donation->id)->firstOrFail();
        $this->screeningService->recordScreeningResult($donation, $this->admin, [
            'screening_record_id' => $record->id,
            'screening_result' => 'Failed',
            'assay_method' => 'NAT — Nucleic Acid Testing',
            'test_date' => CarbonImmutable::today()->toDateString(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Failed screening tests present');

        $this->screeningService->releaseToInventory($donation->fresh(), $this->admin);
    }

    public function test_previously_admitted_unit_cannot_be_admitted_twice(): void
    {
        $donation = $this->createCompletedDonationWithPrimaryUnit('O+');
        $this->screeningService->initializePendingPanel($donation, $this->admin);

        // Pass all screening tests
        ScreeningRecord::query()->where('donation_id', $donation->id)->get()->each(function (ScreeningRecord $rec) use ($donation): void {
            $this->screeningService->recordScreeningResult($donation, $this->admin, [
                'screening_record_id' => $rec->id,
                'screening_result' => 'Passed',
                'assay_method' => 'NAT — Nucleic Acid Testing',
                'test_date' => CarbonImmutable::today()->toDateString(),
            ]);
        });

        // First release succeeds
        $this->screeningService->releaseToInventory($donation, $this->admin);
        $unit = $donation->fresh()->primaryBloodUnit;
        $this->assertSame('Available', $unit->unit_status);

        // Second release must fail
        $this->expectException(ValidationException::class);
        $this->screeningService->releaseToInventory($donation->fresh(), $this->admin);
    }

    public function test_discarded_units_cannot_be_released(): void
    {
        $donation = $this->createCompletedDonationWithPrimaryUnit('O+');
        $this->screeningService->initializePendingPanel($donation, $this->admin);

        ScreeningRecord::query()->where('donation_id', $donation->id)->get()->each(function (ScreeningRecord $rec) use ($donation): void {
            $this->screeningService->recordScreeningResult($donation, $this->admin, [
                'screening_record_id' => $rec->id,
                'screening_result' => 'Passed',
                'assay_method' => 'NAT — Nucleic Acid Testing',
                'test_date' => CarbonImmutable::today()->toDateString(),
            ]);
        });

        // Discard the quarantined unit manually
        $unit = $donation->primaryBloodUnit;
        $this->inventoryService->discardUnit($unit, $this->admin, ['reason' => 'Contamination detected.']);
        $this->assertSame('Discarded', $unit->fresh()->unit_status);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Primary blood unit is not in Quarantined status.');

        $this->screeningService->releaseToInventory($donation->fresh(), $this->admin);
    }

    // =========================================================================
    // 2. TRANSFUSION AUTHORIZATION TESTS
    // =========================================================================

    public function test_admin_with_permission_can_perform_transfusion_action(): void
    {
        $donation = $this->createCompletedDonationWithPrimaryUnit('AB+');
        $this->screeningService->initializePendingPanel($donation, $this->admin);
        ScreeningRecord::query()->where('donation_id', $donation->id)->get()->each(function (ScreeningRecord $rec) use ($donation): void {
            $this->screeningService->recordScreeningResult($donation, $this->admin, [
                'screening_record_id' => $rec->id,
                'screening_result' => 'Passed',
                'assay_method' => 'NAT — Nucleic Acid Testing',
                'test_date' => CarbonImmutable::today()->toDateString(),
            ]);
        });
        $this->screeningService->releaseToInventory($donation, $this->admin);

        $unit = $donation->fresh()->primaryBloodUnit;
        $this->assertSame('Available', $unit->unit_status);

        $response = $this->actingAs($this->admin)->post(route('inventory.units.transfuse', $unit->id), [
            'reason' => 'Patient transfusion administered in Emergency Dept.',
        ]);

        $response->assertRedirect(route('inventory.units.show', $unit->id));
        $response->assertSessionHas('success');
        $this->assertSame('Transfused', $unit->fresh()->unit_status);
    }

    public function test_unauthorized_user_receives_403_on_transfusion(): void
    {
        $donation = $this->createCompletedDonationWithPrimaryUnit('AB+');
        $this->screeningService->initializePendingPanel($donation, $this->admin);
        ScreeningRecord::query()->where('donation_id', $donation->id)->get()->each(function (ScreeningRecord $rec) use ($donation): void {
            $this->screeningService->recordScreeningResult($donation, $this->admin, [
                'screening_record_id' => $rec->id,
                'screening_result' => 'Passed',
                'assay_method' => 'NAT — Nucleic Acid Testing',
                'test_date' => CarbonImmutable::today()->toDateString(),
            ]);
        });
        $this->screeningService->releaseToInventory($donation, $this->admin);

        $unit = $donation->fresh()->primaryBloodUnit;

        // Donor user has no inventory.adjust permission
        $response = $this->actingAs($this->donorUser)->post(route('inventory.units.transfuse', $unit->id), [
            'reason' => 'Unauthorized transfusion attempt.',
        ]);

        $response->assertForbidden();
        $this->assertSame('Available', $unit->fresh()->unit_status);
    }

    public function test_transfusion_does_not_decrement_inventory_twice_for_already_dispatched_unit(): void
    {
        $donation = $this->createCompletedDonationWithPrimaryUnit('O+');
        $this->screeningService->initializePendingPanel($donation, $this->admin);
        ScreeningRecord::query()->where('donation_id', $donation->id)->get()->each(function (ScreeningRecord $rec) use ($donation): void {
            $this->screeningService->recordScreeningResult($donation, $this->admin, [
                'screening_record_id' => $rec->id,
                'screening_result' => 'Passed',
                'assay_method' => 'NAT — Nucleic Acid Testing',
                'test_date' => CarbonImmutable::today()->toDateString(),
            ]);
        });
        $this->screeningService->releaseToInventory($donation, $this->admin);

        $unit = $donation->fresh()->primaryBloodUnit;
        $component = $unit->bloodComponent;
        $stock = InventoryStock::query()->where('blood_group', 'O+')->where('blood_component_id', $component->id)->firstOrFail();
        $this->assertSame(1, $stock->available_units);

        // Create and approve request
        $request = $this->fulfillmentService->createRequest($this->hospital, $this->hospitalOfficer, [
            'blood_group' => 'O+',
            'blood_component_id' => $component->id,
            'requested_units' => 1,
            'priority' => 'High',
            'required_date' => CarbonImmutable::today()->addDay()->toDateString(),
            'request_reason' => 'Emergency surgery.',
        ]);
        $this->fulfillmentService->approveRequest($request, $this->admin);

        // Dispatch unit
        $this->fulfillmentService->dispatchUnits($request, $this->admin, ['quantity' => 1]);
        $this->assertSame('Dispatched', $unit->fresh()->unit_status);
        $stock->refresh();
        $this->assertSame(0, $stock->available_units);
        $this->assertSame(0, $stock->reserved_units);

        // Mark as Transfused via controller route
        $response = $this->actingAs($this->admin)->post(route('inventory.units.transfuse', $unit->id), [
            'reason' => 'Post-dispatch patient transfusion confirmed.',
        ]);

        $response->assertRedirect(route('inventory.units.show', $unit->id));
        $this->assertSame('Transfused', $unit->fresh()->unit_status);

        // Assert stock was NOT decremented a second time (remains 0)
        $stock->refresh();
        $this->assertSame(0, $stock->available_units);
        $this->assertSame(0, $stock->reserved_units);
    }

    // =========================================================================
    // 3. BLOOD COMPONENT SELECTION & FULFILLMENT TESTS
    // =========================================================================

    public function test_component_can_be_selected_during_request_creation(): void
    {
        $component = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();

        $response = $this->actingAs($this->admin)->post(route('requests.store'), [
            'hospital_id' => $this->hospital->id,
            'blood_group' => 'B+',
            'blood_component_id' => $component->id,
            'requested_units' => 3,
            'priority' => 'Normal',
            'required_date' => CarbonImmutable::today()->addDays(2)->toDateString(),
            'request_reason' => 'Scheduled oncology transfusion.',
        ]);

        $response->assertRedirect(route('requests.index'));

        $created = BloodRequest::query()->where('blood_group', 'B+')->latest('id')->firstOrFail();
        $this->assertSame($component->id, $created->blood_component_id);
    }

    public function test_blood_request_creation_without_component_is_optional(): void
    {
        $response = $this->actingAs($this->admin)->post(route('requests.store'), [
            'hospital_id' => $this->hospital->id,
            'blood_group' => 'A+',
            'requested_units' => 2,
            'priority' => 'High',
            'required_date' => CarbonImmutable::today()->addDay()->toDateString(),
            'request_reason' => 'Trauma stabilization pending lab typing.',
        ]);

        $response->assertRedirect(route('requests.index'));

        $created = BloodRequest::query()->where('blood_group', 'A+')->latest('id')->firstOrFail();
        $this->assertNull($created->blood_component_id);
    }

    public function test_request_views_render_component_selectors_correctly(): void
    {
        // View create page
        $createResponse = $this->actingAs($this->admin)->get(route('requests.create'));
        $createResponse->assertOk();
        $createResponse->assertSee('name="blood_component_id"', false);
        $createResponse->assertSee('Select component (optional)');

        // Create request without component
        $reqWithoutComp = $this->fulfillmentService->createRequest($this->hospital, $this->hospitalOfficer, [
            'blood_group' => 'O-',
            'requested_units' => 1,
            'priority' => 'High',
            'required_date' => CarbonImmutable::today()->addDay()->toDateString(),
            'request_reason' => 'Emergency.',
        ]);
        $this->fulfillmentService->approveRequest($reqWithoutComp, $this->admin);

        // Show page for request without component must render component selectors
        $showResponse = $this->actingAs($this->admin)->get(route('requests.show', $reqWithoutComp->id));
        $showResponse->assertOk();
        $showResponse->assertSee('Select component to dispatch');
        $showResponse->assertSee('Select component to hold');
        $showResponse->assertSee('Any / Unspecified Component');
    }

    public function test_request_without_component_requires_explicit_selection_during_reservation_and_dispatch(): void
    {
        $req = $this->fulfillmentService->createRequest($this->hospital, $this->hospitalOfficer, [
            'blood_group' => 'AB+',
            'requested_units' => 2,
            'priority' => 'Normal',
            'required_date' => CarbonImmutable::today()->addDays(2)->toDateString(),
            'request_reason' => 'General surgery.',
        ]);
        $this->fulfillmentService->approveRequest($req, $this->admin);

        // Attempting reservation without component throws validation exception
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A blood component ID or code is required when the blood request does not specify a component.');

        $this->fulfillmentService->reserveUnits($req, $this->admin, 1, []);
    }

    public function test_request_with_fixed_component_rejects_dispatch_mismatch(): void
    {
        $wb = BloodComponent::query()->where('code', 'WB')->firstOrFail();
        $prbc = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();

        $req = $this->fulfillmentService->createRequest($this->hospital, $this->hospitalOfficer, [
            'blood_group' => 'A-',
            'blood_component_id' => $wb->id,
            'requested_units' => 1,
            'priority' => 'High',
            'required_date' => CarbonImmutable::today()->addDay()->toDateString(),
            'request_reason' => 'Surgery.',
        ]);
        $this->fulfillmentService->approveRequest($req, $this->admin);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Dispatched/allocated component (PRBC) must match requested component (WB).');

        $this->fulfillmentService->dispatchUnits($req, $this->admin, [
            'quantity' => 1,
            'blood_component_id' => $prbc->id,
        ]);
    }

    public function test_invalid_or_inactive_components_are_rejected(): void
    {
        $req = $this->fulfillmentService->createRequest($this->hospital, $this->hospitalOfficer, [
            'blood_group' => 'B-',
            'requested_units' => 1,
            'priority' => 'Normal',
            'required_date' => CarbonImmutable::today()->addDay()->toDateString(),
            'request_reason' => 'Ward requirement.',
        ]);
        $this->fulfillmentService->approveRequest($req, $this->admin);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Selected blood component does not exist or is inactive.');

        $this->fulfillmentService->dispatchUnits($req, $this->admin, [
            'quantity' => 1,
            'blood_component_id' => 99999,
        ]);
    }
}
