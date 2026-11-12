<?php

namespace Tests\Feature;

use App\Models\AppointmentStatusHistory;
use App\Models\BloodCompatibilityRule;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodRequestStatusHistory;
use App\Models\BloodUnit;
use App\Models\CampRegistration;
use App\Models\CampStatusHistory;
use App\Models\ComponentScreeningRequirement;
use App\Models\Donation;
use App\Models\DonationCamp;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\DonorAppointment;
use App\Models\DonorEligibilityRecord;
use App\Models\DonorMedicalProfile;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\InventoryAllocation;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\Permission;
use App\Models\RequestFulfillment;
use App\Models\Role;
use App\Models\ScreeningRecord;
use App\Models\ScreeningTestType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelFactoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_twenty_seven_factories_create_valid_records_and_support_batches(): void
    {
        $modelClasses = [
            User::class,
            Role::class,
            Permission::class,
            BloodComponent::class,
            ScreeningTestType::class,
            ComponentScreeningRequirement::class,
            Donor::class,
            DonorMedicalProfile::class,
            DonorEligibilityRecord::class,
            DonationCenter::class,
            DonationCamp::class,
            DonorAppointment::class,
            AppointmentStatusHistory::class,
            CampRegistration::class,
            CampStatusHistory::class,
            Donation::class,
            ScreeningRecord::class,
            InventoryStock::class,
            BloodUnit::class,
            InventoryTransaction::class,
            Hospital::class,
            HospitalUser::class,
            BloodCompatibilityRule::class,
            BloodRequest::class,
            BloodRequestStatusHistory::class,
            RequestFulfillment::class,
            InventoryAllocation::class,
        ];

        $this->assertCount(27, $modelClasses);

        foreach ($modelClasses as $modelClass) {
            $records = $modelClass::factory()->count(2)->create();

            $this->assertCount(2, $records, "Expected batch creation to succeed for {$modelClass}");
            $this->assertModelExists($records[0]);
            $this->assertModelExists($records[1]);
        }
    }

    public function test_user_donor_medical_and_eligibility_factory_states_work_cleanly(): void
    {
        $inactiveUser = User::factory()->inactive()->unverified()->loggedIn()->create();
        $this->assertFalse($inactiveUser->is_active);
        $this->assertNull($inactiveUser->email_verified_at);
        $this->assertNotNull($inactiveUser->last_login_at);

        $eligibleDonor = Donor::factory()->eligible()->withUser()->create();
        $this->assertSame('Eligible', $eligibleDonor->eligibility_status);
        $this->assertTrue($eligibleDonor->profile_completed);
        $this->assertNotNull($eligibleDonor->user_id);
        $this->assertNotNull($eligibleDonor->next_eligible_date);

        $incompleteDonor = Donor::factory()->incompleteProfile()->create();
        $this->assertFalse($incompleteDonor->profile_completed);
        $this->assertNull($incompleteDonor->phone);
        $this->assertNull($incompleteDonor->blood_group);

        $deferredDonor = Donor::factory()->notEligible('2027-01-15')->inactive()->create();
        $this->assertSame('Not Eligible', $deferredDonor->eligibility_status);
        $this->assertSame('Inactive', $deferredDonor->account_status);
        $this->assertSame('2027-01-15', $deferredDonor->next_eligible_date?->toDateString());

        $medicalProfile = DonorMedicalProfile::factory()->deferred()->for($eligibleDonor)->create();
        $this->assertSame($eligibleDonor->id, $medicalProfile->donor_id);
        $this->assertNotNull($medicalProfile->deferral_notes);

        $historical = DonorEligibilityRecord::factory()->historical()->for($eligibleDonor)->create();
        $current = DonorEligibilityRecord::factory()->eligible()->for($eligibleDonor)->create();
        $this->assertFalse($historical->is_current);
        $this->assertTrue($current->is_current);
    }

    public function test_collection_screening_and_inventory_factory_states_stay_consistent(): void
    {
        $donor = Donor::factory()->eligible()->create(['blood_group' => 'AB-']);
        $component = BloodComponent::factory()->create();

        $donation = Donation::factory()
            ->screeningPassed()
            ->for($donor)
            ->for($component, 'bloodComponent')
            ->create();

        $this->assertSame('Completed', $donation->collection_status);
        $this->assertSame('Passed', $donation->screening_status);
        $this->assertSame('AB-', $donation->blood_group);
        $this->assertNotNull($donation->released_to_inventory_at);
        $this->assertNotNull($donation->center_id);
        $this->assertNull($donation->camp_id);

        $campDonation = Donation::factory()->completed()->atCamp()->create();
        $this->assertNull($campDonation->center_id);
        $this->assertNotNull($campDonation->camp_id);

        $passedScreen = ScreeningRecord::factory()->passed()->confirmatory()->for($donation)->create();
        $failedScreen = ScreeningRecord::factory()->failed()->for($campDonation)->create();
        $this->assertSame('Passed', $passedScreen->screening_result);
        $this->assertTrue($passedScreen->is_confirmatory);
        $this->assertSame('Failed', $failedScreen->screening_result);

        $unit = BloodUnit::factory()->available()->for($donation)->create();
        $this->assertSame('Available', $unit->unit_status);
        $this->assertSame('AB-', $unit->blood_group);
        $this->assertSame($component->id, $unit->blood_component_id);

        $expiringUnit = BloodUnit::factory()->expiringSoon(4)->create();
        $this->assertSame('Available', $expiringUnit->unit_status);
        $this->assertTrue($expiringUnit->expires_at->isFuture());

        $lowStock = InventoryStock::factory()->lowStock()->create();
        $criticalStock = InventoryStock::factory()->critical()->create();
        $emptyStock = InventoryStock::factory()->outOfStock()->create();
        $this->assertLessThan($lowStock->minimum_level, $lowStock->available_units);
        $this->assertSame(2, $criticalStock->available_units);
        $this->assertSame(0, $emptyStock->available_units);

        $dispatchTxn = InventoryTransaction::factory()->dispatch()->create();
        $this->assertSame('Dispatch', $dispatchTxn->operation_type);
        $this->assertSame(-1, $dispatchTxn->units_delta);
    }

    public function test_hospital_request_fulfillment_and_camp_appointment_factory_states_satisfy_constraints(): void
    {
        $pendingHospital = Hospital::factory()->pendingVerification()->create();
        $this->assertSame('Pending', $pendingHospital->verification_status);
        $this->assertNull($pendingHospital->verified_at);

        $primaryUser = HospitalUser::factory()->primaryContact()->for($pendingHospital)->create();
        $this->assertTrue($primaryUser->is_primary_contact);

        $approvedRequest = BloodRequest::factory()->approved()->critical()->create();
        $partialRequest = BloodRequest::factory()->partiallyFulfilled()->create();
        $fulfilledRequest = BloodRequest::factory()->fulfilled()->create();
        $rejectedRequest = BloodRequest::factory()->rejected()->create();
        $cancelledRequest = BloodRequest::factory()->cancelled()->create();

        $this->assertSame('Approved', $approvedRequest->status);
        $this->assertSame('Critical', $approvedRequest->priority);
        $this->assertSame(2, $partialRequest->fulfilled_units);
        $this->assertSame(1, $partialRequest->reserved_units);
        $this->assertSame($fulfilledRequest->requested_units, $fulfilledRequest->fulfilled_units);
        $this->assertSame('Rejected', $rejectedRequest->status);
        $this->assertSame('Cancelled', $cancelledRequest->status);

        $overrideFulfillment = RequestFulfillment::factory()
            ->withOverride('Emergency trauma override.')
            ->delivered()
            ->for($approvedRequest, 'bloodRequest')
            ->create();

        $this->assertTrue($overrideFulfillment->is_compatibility_override);
        $this->assertNotNull($overrideFulfillment->override_approved_by_user_id);
        $this->assertNotNull($overrideFulfillment->override_approved_at);
        $this->assertSame('Delivered', $overrideFulfillment->delivery_status);

        $releasedAllocation = InventoryAllocation::factory()->released()->create();
        $dispatchedAllocation = InventoryAllocation::factory()->dispatched($overrideFulfillment)->create();
        $this->assertSame('Released', $releasedAllocation->allocation_status);
        $this->assertSame('Dispatched', $dispatchedAllocation->allocation_status);

        $upcomingCamp = DonationCamp::factory()->upcoming()->create();
        $this->assertSame('Upcoming', $upcomingCamp->status);

        $campAppointment = DonorAppointment::factory()
            ->confirmed()
            ->for($upcomingCamp, 'donationCamp')
            ->create();
        $this->assertSame($upcomingCamp->id, $campAppointment->donation_camp_id);
        $this->assertNull($campAppointment->donation_center_id);

        $centerAppointment = DonorAppointment::factory()->atCenter()->cancelled()->create();
        $this->assertNotNull($centerAppointment->donation_center_id);
        $this->assertNull($centerAppointment->donation_camp_id);
        $this->assertSame('Cancelled', $centerAppointment->status);

        $walkInReg = CampRegistration::factory()->walkIn()->for($upcomingCamp, 'camp')->create();
        $cancelledReg = CampRegistration::factory()->cancelled()->for($upcomingCamp, 'camp')->create();
        $this->assertTrue($walkInReg->is_walk_in);
        $this->assertSame('Attended', $walkInReg->attendance_status);
        $this->assertSame('Cancelled', $cancelledReg->attendance_status);
    }
}
