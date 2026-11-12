<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\DonorAppointment;
use App\Models\DonorEligibilityRecord;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ScreeningRecord;
use App\Models\User;
use App\Policies\DonationPolicy;
use App\Policies\ScreeningRecordPolicy;
use App\Services\DonationService;
use App\Services\DonorEligibilityService;
use App\Services\ScreeningService;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class DonationAndScreeningServiceTest extends TestCase
{
    use RefreshDatabase;

    private DonationService $donationService;

    private ScreeningService $screeningService;

    private DonorEligibilityService $eligibilityService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->donationService = app(DonationService::class);
        $this->screeningService = app(ScreeningService::class);
        $this->eligibilityService = app(DonorEligibilityService::class);
    }

    public function test_authorized_staff_registers_completed_and_scheduled_donations_with_single_quarantined_unit(): void
    {
        $this->travelTo('2026-11-12 09:30:00');
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $donor = $this->createEligibleDonor($admin, 'O+');
        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();
        $center = DonationCenter::factory()->create();

        $appointment = DonorAppointment::factory()->create([
            'donor_id' => $donor->id,
            'donation_center_id' => $center->id,
            'donation_component_id' => $component->id,
            'status' => 'Confirmed',
        ]);

        // 1. Register immediately Completed donation linked to appointment.
        $completedDonation = $this->donationService->registerDonation($donor, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'center_id' => $center->id,
            'appointment_id' => $appointment->id,
            'storage_location' => 'Quarantine Fridge B - Slot 2',
        ]);

        $primaryUnit = $completedDonation->primaryBloodUnit;

        $this->assertModelExists($completedDonation);
        $this->assertSame('Completed', $completedDonation->collection_status);
        $this->assertSame('Pending', $completedDonation->screening_status);
        $this->assertNull($completedDonation->released_to_inventory_at);
        $this->assertInstanceOf(BloodUnit::class, $primaryUnit);
        $this->assertSame('Quarantined', $primaryUnit->unit_status);
        $this->assertNull($primaryUnit->parent_unit_id);
        $this->assertSame(450, $primaryUnit->volume_ml);
        $this->assertSame(
            $primaryUnit->collected_at->toImmutable()->addDays($component->shelf_life_days)->toDateTimeString(),
            $primaryUnit->expires_at->toDateTimeString()
        );
        $this->assertSame(1, BloodUnit::query()->where('donation_id', $completedDonation->id)->count());
        $this->assertSame(0, InventoryStock::query()->sum('available_units'));
        $this->assertSame('Completed', $appointment->fresh()->status);

        $freshDonor = $donor->fresh();
        $this->assertSame('2026-11-12', $freshDonor->last_donation_date?->toDateString());
        $this->assertSame(1, $freshDonor->total_completed_donations);

        // 2. Schedule a future donation (no BloodUnit created until physical draw completes).
        $scheduledDonation = $this->donationService->registerDonation($freshDonor, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Scheduled',
            'center_id' => $center->id,
            'donation_date' => '2026-11-20',
        ]);

        $this->assertSame('Scheduled', $scheduledDonation->collection_status);
        $this->assertNull($scheduledDonation->collected_at);
        $this->assertSame(0, BloodUnit::query()->where('donation_id', $scheduledDonation->id)->count());
        $this->assertSame(1, $freshDonor->fresh()->total_completed_donations);

        // Complete the scheduled donation later.
        $nowCompleted = $this->donationService->completeDonation($scheduledDonation, $staff, [
            'quantity_ml' => 460,
        ]);

        $this->assertSame('Completed', $nowCompleted->collection_status);
        $this->assertSame(460, $nowCompleted->primaryBloodUnit->volume_ml);
        $this->assertSame('Quarantined', $nowCompleted->primaryBloodUnit->unit_status);
        $this->assertSame(2, $freshDonor->fresh()->total_completed_donations);
    }

    public function test_incomplete_donor_profiles_and_ineligible_or_inactive_donors_are_rejected(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();
        $center = DonationCenter::factory()->create();

        // Incomplete profile (profile_completed = false).
        $incompleteDonor = Donor::factory()->incompleteProfile()->create();
        $this->assertDonationRejected($incompleteDonor, $staff, $component->id, $center->id, 'profile_completed');

        // Stale flag profile_completed = true while required field (emergency_contact) is missing.
        $missingFieldDonor = Donor::factory()->create([
            'emergency_contact' => '   ',
            'profile_completed' => true,
        ]);
        $this->eligibilityService->approveEligibility($missingFieldDonor, $admin, 'Cleared clinically.');
        $this->assertDonationRejected($missingFieldDonor, $staff, $component->id, $center->id, 'profile_completed');

        // Pending Review donor without Eligible clearance.
        $pendingDonor = Donor::factory()->create();
        $this->eligibilityService->initializeOnboardingEligibility($pendingDonor);
        $this->assertDonationRejected($pendingDonor, $staff, $component->id, $center->id, 'eligibility_status');

        // Not Eligible donor (even with tampered donors.eligibility_status projection).
        $deferredDonor = Donor::factory()->create();
        $this->eligibilityService->deferDonor($deferredDonor, $admin, 'Low hemoglobin.', '2026-12-20');
        DB::table('donors')->where('id', $deferredDonor->id)->update(['eligibility_status' => 'Eligible']);
        $this->assertDonationRejected($deferredDonor->fresh(), $staff, $component->id, $center->id, 'eligibility_status');

        // Inactive donor account.
        $inactiveDonor = $this->createEligibleDonor($admin);
        $inactiveDonor->update(['account_status' => 'Inactive']);
        $this->assertDonationRejected($inactiveDonor->fresh(), $staff, $component->id, $center->id, 'donor');

        // Donor deferred after scheduling cannot complete draw later.
        $scheduledDonor = $this->createEligibleDonor($admin);
        $scheduledDonation = $this->donationService->registerDonation($scheduledDonor, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Scheduled',
            'center_id' => $center->id,
        ]);
        $this->eligibilityService->deferDonor($scheduledDonor, $admin, 'Intervening travel deferral.');

        try {
            $this->donationService->completeDonation($scheduledDonation, $staff);
            $this->fail('Expected ValidationException when completing donation for deferred donor.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('eligibility_status', $exception->errors());
        }

        $this->assertSame('Scheduled', $scheduledDonation->fresh()->collection_status);
        $this->assertSame(0, BloodUnit::query()->count());
    }

    public function test_missing_pending_and_failed_screening_tests_prevent_inventory_release_even_for_authorized_officers(): void
    {
        $this->travelTo('2026-11-12 10:00:00');
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $component = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();
        $center = DonationCenter::factory()->create();

        $donorOne = $this->createEligibleDonor($admin, 'A+');
        $donationOne = $this->donationService->registerDonation($donorOne, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 300,
            'center_id' => $center->id,
        ]);

        // Case A: Missing tests (0 recorded -> blocked).
        $this->assertFalse($this->screeningService->hasPassedAllMandatoryRequirements($donationOne));
        $this->assertReleaseRejectedWithValidation($donationOne, $labOfficer);

        // Record 4 of 5 mandatory panels (leaving COMPAT missing).
        foreach (['HIV', 'HBV', 'HCV', 'SYPH'] as $code) {
            $this->screeningService->recordScreeningResult($donationOne, $labOfficer, [
                'test_type_code' => $code,
                'screening_result' => 'Passed',
            ]);
        }
        $this->assertFalse($this->screeningService->hasPassedAllMandatoryRequirements($donationOne));
        $this->assertReleaseRejectedWithValidation($donationOne, $labOfficer);

        // Case B: 5th test recorded as Pending -> still blocked by Pending test prevention.
        $pendingRecord = $this->screeningService->recordScreeningResult($donationOne, $labOfficer, [
            'test_type_code' => 'COMPAT',
            'screening_result' => 'Pending',
        ]);
        $this->assertNull($pendingRecord->test_date);
        $this->assertFalse($this->screeningService->hasPassedAllMandatoryRequirements($donationOne));
        $this->assertReleaseRejectedWithValidation($donationOne, $labOfficer);

        // Passing the 5th test makes it ready, but MUST NOT auto-release until explicit officer approval.
        $settledCompat = $this->screeningService->recordScreeningResult($donationOne, $labOfficer, [
            'screening_record_id' => $pendingRecord->id,
            'screening_result' => 'Passed',
            'assay_method' => 'Tube Agglutination',
        ]);
        $this->assertSame($pendingRecord->id, $settledCompat->id);
        $this->assertSame('Passed', $settledCompat->screening_result);
        $this->assertTrue($this->screeningService->hasPassedAllMandatoryRequirements($donationOne));

        $donationOneFresh = $donationOne->fresh();
        $this->assertNull($donationOneFresh->released_to_inventory_at);
        $this->assertNull($donationOneFresh->approved_by_user_id);
        $this->assertSame('Pending', $donationOneFresh->screening_status);
        $this->assertSame('Quarantined', $donationOneFresh->primaryBloodUnit->unit_status);
        $this->assertSame(0, InventoryStock::query()->sum('available_units'));

        // Finalized screening results are immutable; confirmatory retest preserves history cleanly.
        try {
            $this->screeningService->recordScreeningResult($donationOne, $labOfficer, [
                'screening_record_id' => $settledCompat->id,
                'screening_result' => 'Failed',
            ]);
            $this->fail('Expected ValidationException when modifying a finalized ScreeningRecord.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('screening_result', $exception->errors());
        }

        // Case C: Failed screening test blocks release permanently even if another test passes.
        $donorTwo = $this->createEligibleDonor($admin, 'B+');
        $donationTwo = $this->donationService->registerDonation($donorTwo, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 300,
            'center_id' => $center->id,
        ]);

        $this->screeningService->recordScreeningResult($donationTwo, $labOfficer, [
            'test_type_code' => 'HIV',
            'screening_result' => 'Failed',
            'assay_method' => 'CLIA Reactive Screen',
        ]);
        foreach (['HBV', 'HCV', 'SYPH', 'COMPAT'] as $code) {
            $this->screeningService->recordScreeningResult($donationTwo, $labOfficer, [
                'test_type_code' => $code,
                'screening_result' => 'Passed',
            ]);
        }

        $this->assertFalse($this->screeningService->hasPassedAllMandatoryRequirements($donationTwo));
        $this->assertReleaseRejectedWithValidation($donationTwo, $admin);
        $this->assertSame('Discarded', $donationTwo->primaryBloodUnit->fresh()->unit_status);
    }

    public function test_failed_screening_records_discard_audit_and_deferral_via_controlled_eligibility_workflow(): void
    {
        $this->travelTo('2026-11-12 11:15:00');
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();
        $center = DonationCenter::factory()->create();

        $donor = $this->createEligibleDonor($admin, 'O-');
        $donation = $this->donationService->registerDonation($donor, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'center_id' => $center->id,
        ]);

        // Verify lab_officer still does NOT have direct eligibility assessment permission.
        try {
            $this->eligibilityService->recordScreeningDeferral($donor, $labOfficer, 'Direct attempt.');
            $this->fail('Expected AuthorizationException for direct lab_officer eligibility write.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $failedRecord = $this->screeningService->recordScreeningResult($donation, $labOfficer, [
            'test_type_code' => 'HBV',
            'screening_result' => 'Failed',
            'specimen_tube_code' => 'EDTA-88412',
            'test_notes' => 'Repeatedly reactive HBsAg.',
        ]);

        $freshDonation = $donation->fresh();
        $freshUnit = $donation->primaryBloodUnit->fresh();
        $freshDonor = $donor->fresh();
        $currentEligibility = $this->eligibilityService->getCurrentRecord($freshDonor);

        $this->assertSame('Failed', $freshDonation->screening_status);
        $this->assertNull($freshDonation->released_to_inventory_at);
        $this->assertSame('Discarded', $freshUnit->unit_status);
        $this->assertSame('Not Eligible', $freshDonor->eligibility_status);
        $this->assertFalse($this->eligibilityService->isCurrentlyEligible($freshDonor));

        $this->assertNotNull($currentEligibility);
        $this->assertSame('Not Eligible', $currentEligibility->eligibility_status);
        $this->assertNull($currentEligibility->reviewed_by_user_id);
        $this->assertNull($currentEligibility->reviewed_at);
        $this->assertNull($currentEligibility->next_eligible_date);
        $this->assertNull($freshDonor->next_eligible_date);
        $this->assertSame('Automated Failed Screening Deferral', $currentEligibility->clearance_source);
        $this->assertStringContainsString($failedRecord->screening_code, $currentEligibility->reason);

        $discardTxn = InventoryTransaction::query()
            ->ofOperation('Discarded')
            ->forReference('donation', $freshDonation->id)
            ->first();

        $this->assertNotNull($discardTxn);
        $this->assertSame(0, $discardTxn->units_delta);
        $this->assertSame(0, $discardTxn->available_before);
        $this->assertSame(0, $discardTxn->available_after);
        $this->assertSame(0, $discardTxn->reserved_before);
        $this->assertSame(0, $discardTxn->reserved_after);
        $this->assertSame($freshUnit->id, $discardTxn->blood_unit_id);

        $totalLedgerDelta = (int) InventoryTransaction::query()->sum('units_delta');
        $totalStockBalance = (int) InventoryStock::query()->sum('available_units')
            + (int) InventoryStock::query()->sum('reserved_units');
        $this->assertSame($totalStockBalance, $totalLedgerDelta);

        // Passing a fake/non-failed record directly to recordFailedScreeningDeferral is blocked.
        $passedRecord = ScreeningRecord::factory()->passed()->create([
            'donation_id' => $freshDonation->id,
        ]);

        try {
            $this->eligibilityService->recordFailedScreeningDeferral($freshDonor, $passedRecord, $labOfficer);
            $this->fail('Expected ValidationException when recordFailedScreeningDeferral receives a Passed record.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('screening_record', $exception->errors());
        }
    }

    public function test_successful_inventory_release_after_all_five_tests_pass_and_authorized_approval(): void
    {
        $this->travelTo('2026-11-12 12:00:00');
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $component = BloodComponent::query()->where('code', 'PLT')->firstOrFail();
        $center = DonationCenter::factory()->create();

        $donor = $this->createEligibleDonor($admin, 'AB+');
        $donation = $this->donationService->registerDonation($donor, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 250,
            'center_id' => $center->id,
        ]);

        $pendingPanel = $this->screeningService->initializePendingPanel($donation, $labOfficer, [
            'specimen_tube_code' => 'PLT-TUBE-100',
        ]);
        $this->assertCount(5, $pendingPanel);

        foreach (['HIV', 'HBV', 'HCV', 'SYPH', 'COMPAT'] as $code) {
            $this->screeningService->recordScreeningResult($donation, $labOfficer, [
                'test_type_code' => $code,
                'screening_result' => 'Passed',
            ]);
        }

        $this->travelTo('2026-11-12 14:30:00');
        $releasedDonation = $this->screeningService->releaseToInventory($donation, $labOfficer, [
            'screening_notes' => 'All 5 mandatory panels non-reactive and verified.',
            'storage_location' => 'Platelet Agitator A1',
        ]);

        $releasedUnit = $releasedDonation->primaryBloodUnit;
        $stock = InventoryStock::query()
            ->forBloodGroup('AB+')
            ->forComponent($component->id)
            ->firstOrFail();

        $this->assertSame('Passed', $releasedDonation->screening_status);
        $this->assertSame($labOfficer->id, $releasedDonation->approved_by_user_id);
        $this->assertNotNull($releasedDonation->released_to_inventory_at);
        $this->assertSame('Available', $releasedUnit->unit_status);
        $this->assertSame($labOfficer->id, $releasedUnit->released_by_user_id);
        $this->assertSame('Platelet Agitator A1', $releasedUnit->storage_location);
        $this->assertSame(
            $releasedUnit->collected_at->toImmutable()->addDays($component->shelf_life_days)->toDateTimeString(),
            $releasedUnit->expires_at->toDateTimeString()
        );

        $this->assertSame(1, $stock->available_units);
        $this->assertSame(0, $stock->reserved_units);

        $stockInTxn = InventoryTransaction::query()
            ->ofOperation('Stock In')
            ->forReference('donation', $releasedDonation->id)
            ->firstOrFail();

        $this->assertSame(1, $stockInTxn->units_delta);
        $this->assertSame(0, $stockInTxn->available_before);
        $this->assertSame(1, $stockInTxn->available_after);
        $this->assertSame($releasedUnit->id, $stockInTxn->blood_unit_id);

        // Double release is blocked cleanly.
        $this->assertReleaseRejectedWithValidation($releasedDonation, $labOfficer);
        $this->assertSame(1, $stock->fresh()->available_units);

        // Screening cannot be modified after inventory release.
        try {
            $this->screeningService->recordScreeningResult($releasedDonation, $labOfficer, [
                'test_type_code' => 'HIV',
                'screening_result' => 'Failed',
                'is_confirmatory' => true,
            ]);
            $this->fail('Expected ValidationException when modifying screening after inventory release.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('released_to_inventory_at', $exception->errors());
        }
    }

    public function test_unauthorized_actors_are_rejected_across_donation_screening_and_release_boundaries(): void
    {
        $this->seedBaselineCatalogs();

        $this->assertInstanceOf(DonationPolicy::class, Gate::getPolicyFor(Donation::class));
        $this->assertInstanceOf(ScreeningRecordPolicy::class, Gate::getPolicyFor(ScreeningRecord::class));

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $donorUser = $this->createUserWithRole('donor');
        $hospitalUser = $this->createUserWithRole('hospital');
        $inactiveStaff = $this->createUserWithRole('staff', isActive: false);
        $inactiveLab = $this->createUserWithRole('lab_officer', isActive: false);

        // Custom role holding ONLY screening.record (lacking screening.release_inventory).
        $recordOnlyUser = $this->createUserWithPermissions('record_only', ['screening.record']);
        // Custom role holding ONLY screening.release_inventory (lacking screening.record).
        $releaseOnlyUser = $this->createUserWithPermissions('release_only', ['screening.release_inventory']);

        $donor = $this->createEligibleDonor($admin, 'O+');
        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();
        $center = DonationCenter::factory()->create();

        // Donation registration denied to non-collection roles & inactive/unauthenticated actors.
        foreach ([null, $labOfficer, $donorUser, $hospitalUser, $inactiveStaff, $recordOnlyUser] as $badCollectionActor) {
            try {
                $this->donationService->registerDonation($donor, $badCollectionActor, [
                    'blood_component_id' => $component->id,
                    'quantity_ml' => 450,
                    'center_id' => $center->id,
                ]);
                $this->fail('Expected AuthorizationException on unauthorized registerDonation.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $donation = $this->donationService->registerDonation($donor, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'center_id' => $center->id,
        ]);

        // Screening recording denied without screening.record permission.
        foreach ([null, $staff, $donorUser, $hospitalUser, $inactiveLab, $releaseOnlyUser] as $badScreeningActor) {
            try {
                $this->screeningService->recordScreeningResult($donation, $badScreeningActor, [
                    'test_type_code' => 'HIV',
                    'screening_result' => 'Passed',
                ]);
                $this->fail('Expected AuthorizationException on unauthorized recordScreeningResult.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        // Complete all 5 screening tests cleanly via recordOnlyUser.
        foreach (['HIV', 'HBV', 'HCV', 'SYPH', 'COMPAT'] as $code) {
            $this->screeningService->recordScreeningResult($donation, $recordOnlyUser, [
                'test_type_code' => $code,
                'screening_result' => 'Passed',
            ]);
        }

        // Release denied to anyone lacking active screening.release_inventory permission (including staff & recordOnlyUser).
        foreach ([null, $staff, $donorUser, $hospitalUser, $inactiveLab, $recordOnlyUser] as $badReleaseActor) {
            try {
                $this->screeningService->releaseToInventory($donation, $badReleaseActor);
                $this->fail('Expected AuthorizationException on unauthorized releaseToInventory.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('Quarantined', $donation->primaryBloodUnit->fresh()->unit_status);
        $this->assertNull($donation->fresh()->released_to_inventory_at);
    }

    public function test_duplicate_primary_blood_unit_creation_is_prevented(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();
        $center = DonationCenter::factory()->create();
        $donor = $this->createEligibleDonor($admin, 'A-');

        $donation = $this->donationService->registerDonation($donor, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'center_id' => $center->id,
        ]);

        $this->assertSame(1, BloodUnit::query()->where('donation_id', $donation->id)->count());

        try {
            $this->donationService->createPrimaryBloodUnit($donation, $staff);
            $this->fail('Expected ValidationException on duplicate primary BloodUnit creation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('blood_unit', $exception->errors());
        }

        $this->assertSame(1, BloodUnit::query()->where('donation_id', $donation->id)->count());
    }

    public function test_transactions_roll_back_atomically_on_domain_or_persistence_failures(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();
        $center = DonationCenter::factory()->create();

        $donor = $this->createEligibleDonor($admin, 'B-');

        // 1. Rollback donation registration if BloodUnit creation fails.
        BloodUnit::creating(static function (): void {
            throw new RuntimeException('Simulated BloodUnit persistence failure.');
        });

        try {
            $this->donationService->registerDonation($donor, $staff, [
                'blood_component_id' => $component->id,
                'quantity_ml' => 450,
                'center_id' => $center->id,
            ]);
            $this->fail('Expected RuntimeException during simulated BloodUnit failure.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            Event::forget('eloquent.creating: '.BloodUnit::class);
        }

        $this->assertDatabaseCount('donations', 0);
        $this->assertDatabaseCount('blood_units', 0);
        $this->assertSame(0, $donor->fresh()->total_completed_donations);
        $this->assertNull($donor->fresh()->last_donation_date);

        // Register real donation now.
        $donation = $this->donationService->registerDonation($donor, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'center_id' => $center->id,
        ]);

        // 2. Rollback failed-screening workflow if DonorEligibilityRecord creation fails.
        DonorEligibilityRecord::creating(static function (): void {
            throw new RuntimeException('Simulated eligibility deferral write failure.');
        });

        try {
            $this->screeningService->recordScreeningResult($donation, $labOfficer, [
                'test_type_code' => 'HCV',
                'screening_result' => 'Failed',
            ]);
            $this->fail('Expected RuntimeException during simulated deferral failure.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            Event::forget('eloquent.creating: '.DonorEligibilityRecord::class);
        }

        $this->assertDatabaseCount('screening_records', 0);
        $this->assertSame('Pending', $donation->fresh()->screening_status);
        $this->assertSame('Quarantined', $donation->primaryBloodUnit->fresh()->unit_status);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertTrue($this->eligibilityService->isCurrentlyEligible($donor->fresh()));

        // 3. Rollback inventory release if InventoryTransaction ledger creation fails.
        foreach (['HIV', 'HBV', 'HCV', 'SYPH', 'COMPAT'] as $code) {
            $this->screeningService->recordScreeningResult($donation, $labOfficer, [
                'test_type_code' => $code,
                'screening_result' => 'Passed',
            ]);
        }

        InventoryTransaction::creating(static function (): void {
            throw new RuntimeException('Simulated ledger insert failure.');
        });

        try {
            $this->screeningService->releaseToInventory($donation, $labOfficer);
            $this->fail('Expected RuntimeException during simulated ledger failure.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            Event::forget('eloquent.creating: '.InventoryTransaction::class);
        }

        $this->assertNull($donation->fresh()->released_to_inventory_at);
        $this->assertSame('Pending', $donation->fresh()->screening_status);
        $this->assertSame('Quarantined', $donation->primaryBloodUnit->fresh()->unit_status);
        $this->assertSame(0, InventoryStock::query()->sum('available_units'));
    }

    public function test_confirmatory_assays_and_donation_state_transitions_preserve_history_safely(): void
    {
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();
        $center = DonationCenter::factory()->create();
        $donor = $this->createEligibleDonor($admin, 'AB-');

        // Scheduled -> Cancelled leaves no BloodUnit and cannot later complete or cancel twice.
        $scheduled = $this->donationService->registerDonation($donor, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'collection_status' => 'Scheduled',
            'center_id' => $center->id,
        ]);

        $cancelled = $this->donationService->cancelDonation($scheduled, $staff, 'Donor declined draw on arrival.');
        $this->assertSame('Cancelled', $cancelled->collection_status);
        $this->assertSame(0, BloodUnit::query()->where('donation_id', $cancelled->id)->count());

        try {
            $this->donationService->completeDonation($cancelled, $staff);
            $this->fail('Expected ValidationException when completing a cancelled donation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection_status', $exception->errors());
        }

        // Completed donation with duplicate Passed test requires confirmatory flag and preserves history.
        $completed = $this->donationService->registerDonation($donor, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'center_id' => $center->id,
        ]);

        $firstHiv = $this->screeningService->recordScreeningResult($completed, $labOfficer, [
            'test_type_code' => 'HIV',
            'screening_result' => 'Passed',
        ]);

        try {
            $this->screeningService->recordScreeningResult($completed, $labOfficer, [
                'test_type_code' => 'HIV',
                'screening_result' => 'Passed',
            ]);
            $this->fail('Expected ValidationException on non-confirmatory duplicate screening entry.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('test_type_id', $exception->errors());
        }

        $confirmatoryHiv = $this->screeningService->recordScreeningResult($completed, $labOfficer, [
            'test_type_code' => 'HIV',
            'screening_result' => 'Passed',
            'is_confirmatory' => true,
            'assay_method' => 'NAT Confirmatory',
        ]);

        $this->assertNotSame($firstHiv->id, $confirmatoryHiv->id);
        $this->assertTrue($confirmatoryHiv->is_confirmatory);
        $this->assertSame(2, ScreeningRecord::query()->where('donation_id', $completed->id)->count());
    }

    public function test_admin_triggered_failed_screening_keeps_automated_deferral_unreviewed_until_medical_officer_assessment(): void
    {
        $this->travelTo('2026-11-12 10:00:00');
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $component = BloodComponent::query()->where('code', 'WB')->firstOrFail();
        $center = DonationCenter::factory()->create();

        $donor = $this->createEligibleDonor($admin, 'A+');
        $donation = $this->donationService->registerDonation($donor, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'center_id' => $center->id,
        ]);

        // Even when an admin records the failed lab result, the automated deferral remains unreviewed.
        $failedRecord = $this->screeningService->recordScreeningResult($donation, $admin, [
            'test_type_code' => 'HCV',
            'screening_result' => 'Failed',
            'specimen_tube_code' => 'EDTA-99102',
        ]);

        $automatedHold = $this->eligibilityService->getCurrentRecord($donor->fresh());

        $this->assertNotNull($automatedHold);
        $this->assertSame('Not Eligible', $automatedHold->eligibility_status);
        $this->assertSame('Automated Failed Screening Deferral', $automatedHold->clearance_source);
        $this->assertNull($automatedHold->reviewed_by_user_id);
        $this->assertNull($automatedHold->reviewed_at);
        $this->assertNull($automatedHold->next_eligible_date);
        $this->assertStringContainsString($failedRecord->screening_code, $automatedHold->reason);

        // Authorizing medical officer later performs final clinical review and sets deferral end date.
        $this->travelTo('2026-11-12 15:00:00');
        $finalDecision = $this->eligibilityService->deferDonor(
            donor: $donor->fresh(),
            actor: $admin,
            reason: "Confirmatory clinical review completed for {$failedRecord->screening_code}.",
            nextEligibleDate: '2027-05-12',
        );

        $this->assertSame('Not Eligible', $finalDecision->eligibility_status);
        $this->assertSame('Medical Officer Deferral', $finalDecision->clearance_source);
        $this->assertSame($admin->id, $finalDecision->reviewed_by_user_id);
        $this->assertSame('2026-11-12', $finalDecision->reviewed_at?->toDateString());
        $this->assertSame('2027-05-12', $finalDecision->next_eligible_date?->toDateString());
        $this->assertFalse($automatedHold->fresh()->is_current);
    }

    public function test_inventory_transaction_check_rejects_zero_units_delta_for_non_quarantined_discard_operations_and_reconciles_ledger(): void
    {
        $this->travelTo('2026-11-12 11:00:00');
        $this->seedBaselineCatalogs();

        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $component = BloodComponent::query()->where('code', 'PRBC')->firstOrFail();
        $center = DonationCenter::factory()->create();

        $stock = InventoryStock::factory()->create([
            'blood_group' => 'B+',
            'blood_component_id' => $component->id,
            'available_units' => 0,
            'reserved_units' => 0,
        ]);

        // Zero units_delta for Stock In must still fail chk_transactions_units_delta.
        try {
            InventoryTransaction::query()->create([
                'inventory_stock_id' => $stock->id,
                'blood_unit_id' => null,
                'operation_type' => 'Stock In',
                'units_delta' => 0,
                'available_before' => 0,
                'available_after' => 0,
                'reserved_before' => 0,
                'reserved_after' => 0,
                'reference_type' => 'manual',
                'reference_id' => 1,
                'performed_by_user_id' => $admin->id,
                'actor_name' => $admin->name,
                'description' => 'Invalid zero delta stock in check.',
                'transacted_at' => now(),
            ]);
            $this->fail('Expected QueryException enforcing chk_transactions_units_delta.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // Release one B+ RBC unit and discard another failed-screening B+ RBC unit in the same stock group.
        $donorOne = $this->createEligibleDonor($admin, 'B+');
        $donationOne = $this->donationService->registerDonation($donorOne, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'center_id' => $center->id,
        ]);

        foreach (['HIV', 'HBV', 'HCV', 'SYPH', 'COMPAT'] as $code) {
            $this->screeningService->recordScreeningResult($donationOne, $labOfficer, [
                'test_type_code' => $code,
                'screening_result' => 'Passed',
            ]);
        }

        $this->screeningService->releaseToInventory($donationOne, $labOfficer);

        $donorTwo = $this->createEligibleDonor($admin, 'B+');
        $donationTwo = $this->donationService->registerDonation($donorTwo, $staff, [
            'blood_component_id' => $component->id,
            'quantity_ml' => 450,
            'center_id' => $center->id,
        ]);

        $this->screeningService->recordScreeningResult($donationTwo, $labOfficer, [
            'test_type_code' => 'HIV',
            'screening_result' => 'Failed',
        ]);

        $freshStock = $stock->fresh();
        $ledgerDeltaSum = (int) InventoryTransaction::query()
            ->where('inventory_stock_id', $freshStock->id)
            ->sum('units_delta');

        $this->assertSame(1, $freshStock->available_units);
        $this->assertSame(0, $freshStock->reserved_units);
        $this->assertSame(1, $ledgerDeltaSum);
        $this->assertSame($freshStock->available_units + $freshStock->reserved_units, $ledgerDeltaSum);
    }

    private function seedBaselineCatalogs(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            BloodComponentSeeder::class,
            ScreeningTestTypeSeeder::class,
            ComponentScreeningRequirementSeeder::class,
        ]);
    }

    private function createEligibleDonor(User $admin, string $bloodGroup = 'O+'): Donor
    {
        $donor = Donor::factory()->create([
            'blood_group' => $bloodGroup,
            'profile_completed' => true,
            'account_status' => 'Active',
        ]);

        $this->eligibilityService->approveEligibility(
            donor: $donor,
            actor: $admin,
            reason: 'Cleared for donation in test setup.',
            clearanceSource: 'Medical Officer Clearance',
        );

        return $donor->fresh();
    }

    private function createUserWithRole(string $roleSlug, bool $isActive = true): User
    {
        $user = User::factory()->create(['is_active' => $isActive]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user;
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function createUserWithPermissions(string $roleSlug, array $permissionSlugs): User
    {
        $role = Role::factory()->create([
            'name' => ucfirst(str_replace('_', ' ', $roleSlug)),
            'slug' => $roleSlug,
        ]);

        $permissionIds = Permission::query()->whereIn('slug', $permissionSlugs)->pluck('id')->all();
        $role->permissions()->attach($permissionIds);

        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user;
    }

    private function assertDonationRejected(
        Donor $donor,
        User $actor,
        int $componentId,
        int $centerId,
        string $expectedErrorKey
    ): void {
        try {
            $this->donationService->registerDonation($donor, $actor, [
                'blood_component_id' => $componentId,
                'quantity_ml' => 450,
                'center_id' => $centerId,
            ]);
            $this->fail("Expected ValidationException for {$expectedErrorKey}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($expectedErrorKey, $exception->errors());
        }
    }

    private function assertReleaseRejectedWithValidation(Donation $donation, User $actor): void
    {
        try {
            $this->screeningService->releaseToInventory($donation, $actor);
            $this->fail('Expected ValidationException blocking premature/duplicate inventory release.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('screening_release', $exception->errors());
        }
    }
}
