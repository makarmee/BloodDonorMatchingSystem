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
use App\Models\Donation;
use App\Models\DonationCamp;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\DonorAppointment;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\InventoryAllocation;
use App\Models\InventoryStock;
use App\Models\RequestFulfillment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestsHospitalsAndCampsModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_hospital_compatibility_request_fulfillment_and_allocation_relationships_resolve(): void
    {
        $staff = User::query()->create([
            'name' => 'Medical Director',
            'email' => 'director@bdms.test',
            'password' => 'secret-pass',
        ]);
        $coordinator = User::query()->create([
            'name' => 'Dr. Alice Meyer',
            'email' => 'alice@hospital.test',
            'password' => 'secret-pass',
        ]);
        $hospital = Hospital::query()->create([
            'hospital_code' => 'HSP-2049',
            'name' => 'St. Jude Medical Center',
            'city' => 'Springfield',
            'verification_status' => 'Verified',
            'verified_by_user_id' => $staff->id,
            'verified_at' => '2026-11-01 10:00:00',
            'is_active' => true,
        ]);
        $hospitalUser = HospitalUser::query()->create([
            'hospital_id' => $hospital->id,
            'user_id' => $coordinator->id,
            'contact_name' => 'Dr. Alice Meyer',
            'department' => 'Emergency Trauma',
            'job_title' => 'Lead Surgeon',
            'is_primary_contact' => true,
            'is_active' => true,
        ]);
        $prbc = BloodComponent::query()->create([
            'code' => 'PRBC',
            'name' => 'Packed RBC',
            'default_volume_ml' => 300,
            'shelf_life_days' => 42,
        ]);
        $compatRule = BloodCompatibilityRule::query()->create([
            'recipient_blood_group' => 'AB+',
            'donor_blood_group' => 'O-',
            'blood_component_id' => $prbc->id,
            'compatibility_level' => 'Emergency Only',
            'requires_medical_override' => true,
            'is_active' => true,
        ]);
        $donor = Donor::query()->create([
            'donor_code' => 'DNR-3001',
            'full_name' => 'Samira Ali',
            'email' => 'samira@donor.test',
            'blood_group' => 'O-',
            'registered_at' => '2026-10-01',
        ]);
        $donation = Donation::query()->create([
            'donation_code' => 'DON-5001',
            'donor_id' => $donor->id,
            'collection_location_name' => 'Central',
            'staff_member_name' => 'Staff A',
            'blood_group' => 'O-',
            'blood_component_id' => $prbc->id,
            'donation_date' => '2026-11-10',
            'quantity_ml' => 300,
            'collection_status' => 'Completed',
            'screening_status' => 'Passed',
        ]);
        $stock = InventoryStock::query()->create([
            'blood_group' => 'O-',
            'blood_component_id' => $prbc->id,
            'available_units' => 3,
            'reserved_units' => 1,
            'minimum_level' => 5,
            'target_capacity' => 25,
        ]);
        $unit = BloodUnit::query()->create([
            'unit_code' => 'UNIT-2026-005001',
            'donation_id' => $donation->id,
            'blood_group' => 'O-',
            'blood_component_id' => $prbc->id,
            'volume_ml' => 300,
            'unit_status' => 'Dispatched',
            'collected_at' => '2026-11-10 08:00:00',
            'expires_at' => '2026-12-22 08:00:00',
        ]);

        $request = BloodRequest::query()->create([
            'request_code' => 'REQ-1044',
            'hospital_id' => $hospital->id,
            'requested_by_user_id' => $coordinator->id,
            'contact_person_snapshot' => 'Dr. Alice Meyer',
            'contact_phone_snapshot' => '+15559900',
            'contact_email_snapshot' => 'alice@hospital.test',
            'blood_group' => 'AB+',
            'blood_component_id' => $prbc->id,
            'requested_units' => 2,
            'reserved_units' => 0,
            'fulfilled_units' => 1,
            'priority' => 'Critical',
            'status' => 'Partially Fulfilled',
            'request_date' => '2026-11-12',
            'required_date' => '2026-11-12',
            'request_reason' => 'Massive transfusion protocol',
            'reviewed_by_user_id' => $staff->id,
            'reviewed_at' => '2026-11-12 09:05:00',
        ]);
        $history = BloodRequestStatusHistory::query()->create([
            'blood_request_id' => $request->id,
            'event_name' => 'Partial Emergency Dispatch',
            'previous_status' => 'Approved',
            'new_status' => 'Partially Fulfilled',
            'units_affected' => 1,
            'actor_user_id' => $staff->id,
            'actor_name' => 'Medical Director',
            'occurred_at' => '2026-11-12 09:30:00',
        ]);
        $fulfillment = RequestFulfillment::query()->create([
            'fulfillment_code' => 'FUL-5001',
            'blood_request_id' => $request->id,
            'dispatched_by_user_id' => $staff->id,
            'dispatched_blood_group' => 'O-',
            'blood_component_id' => $prbc->id,
            'units_dispatched' => 1,
            'dispatch_date' => '2026-11-12',
            'dispatched_at' => '2026-11-12 09:30:00',
            'courier_reference' => '#TR-208',
            'delivery_status' => 'Dispatched',
            'is_compatibility_override' => true,
            'override_approved_by_user_id' => $staff->id,
            'override_approved_at' => '2026-11-12 09:20:00',
            'override_reason' => 'Emergency O- universal PRBC issue',
        ]);
        $allocation = InventoryAllocation::query()->create([
            'allocation_code' => 'ALC-3001',
            'blood_request_id' => $request->id,
            'inventory_stock_id' => $stock->id,
            'blood_unit_id' => $unit->id,
            'fulfillment_id' => $fulfillment->id,
            'units_allocated' => 1,
            'allocation_status' => 'Dispatched',
            'allocated_by_user_id' => $staff->id,
            'allocated_at' => '2026-11-12 09:15:00',
            'dispatched_at' => '2026-11-12 09:30:00',
        ]);

        $this->assertFalse($history->usesTimestamps());
        $this->assertInstanceOf(CarbonInterface::class, $history->occurred_at);
        $this->assertSame($request->id, $history->bloodRequest->id);
        $this->assertSame($staff->id, $history->actor->id);
        $this->assertSame($hospital->id, Hospital::verified()->value('id'));
        $this->assertSame($staff->id, $hospital->verifiedBy->id);
        $this->assertSame($hospitalUser->id, $hospital->hospitalUsers->first()?->id);
        $this->assertSame($hospitalUser->id, HospitalUser::active()->primaryContact()->value('id'));
        $this->assertSame($hospital->id, $hospitalUser->hospital->id);
        $this->assertSame($coordinator->id, $hospitalUser->user->id);
        $this->assertSame($coordinator->id, $hospital->users()->first()?->id);
        $this->assertSame($compatRule->id, BloodCompatibilityRule::active()->forPair('AB+', 'O-', $prbc->id)->value('id'));
        $this->assertSame($prbc->id, $compatRule->bloodComponent->id);

        $this->assertSame($request->id, BloodRequest::openForFulfillment()->critical()->value('id'));
        $this->assertSame($hospital->id, $request->hospital->id);
        $this->assertSame($coordinator->id, $request->requestedBy->id);
        $this->assertSame($prbc->id, $request->bloodComponent->id);
        $this->assertSame($staff->id, $request->reviewedBy->id);
        $this->assertSame($fulfillment->id, RequestFulfillment::compatibilityOverrides()->value('id'));
        $this->assertSame($request->id, $fulfillment->bloodRequest->id);
        $this->assertSame($staff->id, $fulfillment->dispatchedBy->id);
        $this->assertSame($prbc->id, $fulfillment->bloodComponent->id);
        $this->assertSame($staff->id, $fulfillment->overrideApprovedBy->id);
        $this->assertSame($allocation->id, $fulfillment->allocations->first()?->id);
        $this->assertSame($allocation->id, $unit->activeAllocation->id);
        $this->assertSame($request->id, $allocation->bloodRequest->id);
        $this->assertSame($stock->id, $allocation->inventoryStock->id);
        $this->assertSame($unit->id, $allocation->bloodUnit->id);
        $this->assertSame($fulfillment->id, $allocation->fulfillment->id);
        $this->assertSame($staff->id, $allocation->allocatedBy->id);
        $this->assertCount(1, $request->statusHistories);
        $this->assertCount(1, $request->fulfillments);
        $this->assertCount(1, $request->allocations);
    }

    public function test_camps_registrations_appointments_and_immutable_histories_resolve_accurately(): void
    {
        $staff = User::query()->create([
            'name' => 'Camp Coordinator',
            'email' => 'camps@bdms.test',
            'password' => 'secret-pass',
        ]);
        $donor = Donor::query()->create([
            'donor_code' => 'DNR-4001',
            'full_name' => 'Nadia Chen',
            'email' => 'nadia@donor.test',
            'blood_group' => 'A+',
            'registered_at' => '2026-11-01',
        ]);
        $component = BloodComponent::query()->create([
            'code' => 'WB',
            'name' => 'Whole Blood',
            'default_volume_ml' => 450,
            'shelf_life_days' => 35,
        ]);
        $center = DonationCenter::query()->create([
            'center_code' => 'CTR-WEST',
            'name' => 'West Clinic',
            'address' => '55 West Blvd',
            'city' => 'Springfield',
            'operating_hours' => '09:00-17:00',
            'default_slot_capacity' => 3,
            'is_active' => true,
        ]);
        $camp = DonationCamp::query()->create([
            'camp_code' => 'CMP-308',
            'name' => 'Civic Blood Drive',
            'description' => 'Community holiday drive',
            'organizer_name' => 'Civic Health',
            'organizer_contact' => '+15550200',
            'organizer_email' => 'civic@drive.test',
            'created_by_user_id' => $staff->id,
            'venue_name' => 'Civic Auditorium',
            'full_address' => '200 Civic Plaza',
            'city' => 'Springfield',
            'start_date' => '2026-12-10',
            'end_date' => '2026-12-10',
            'start_time' => '09:00:00',
            'end_time' => '16:00:00',
            'capacity' => 80,
            'registration_deadline' => '2026-12-09',
            'allow_walk_in' => true,
            'donor_requirements' => 'Photo ID required',
            'status' => 'Upcoming',
        ]);
        $campHistory = CampStatusHistory::query()->create([
            'camp_id' => $camp->id,
            'event_name' => 'Camp Published',
            'previous_status' => 'Draft',
            'new_status' => 'Upcoming',
            'actor_user_id' => $staff->id,
            'actor_name' => 'Camp Coordinator',
            'occurred_at' => '2026-11-12 10:00:00',
        ]);

        $campAppointment = DonorAppointment::query()->create([
            'appointment_code' => 'APT-2041',
            'donor_id' => $donor->id,
            'donation_component_id' => $component->id,
            'donation_center_id' => null,
            'donation_camp_id' => $camp->id,
            'appointment_date' => '2026-12-10',
            'slot_time' => '10:00:00',
            'status' => 'Confirmed',
        ]);
        $centerAppointment = DonorAppointment::query()->create([
            'appointment_code' => 'APT-2042',
            'donor_id' => $donor->id,
            'donation_component_id' => $component->id,
            'donation_center_id' => $center->id,
            'donation_camp_id' => null,
            'appointment_date' => '2026-12-15',
            'slot_time' => '11:00:00',
            'status' => 'Pending',
        ]);
        $apptHistory = AppointmentStatusHistory::query()->create([
            'appointment_id' => $campAppointment->id,
            'event_name' => 'Confirmed',
            'previous_status' => 'Pending',
            'new_status' => 'Confirmed',
            'actor_user_id' => $staff->id,
            'occurred_at' => '2026-11-12 10:05:00',
        ]);

        CampRegistration::query()->create([
            'registration_code' => 'REG-805',
            'camp_id' => $camp->id,
            'donor_id' => $donor->id,
            'appointment_id' => null,
            'registered_by_user_id' => $staff->id,
            'registration_date' => '2026-11-11',
            'is_walk_in' => false,
            'attendance_status' => 'Cancelled',
            'registered_at' => '2026-11-11 09:00:00',
            'cancelled_at' => '2026-11-11 12:00:00',
        ]);
        $activeRegistration = CampRegistration::query()->create([
            'registration_code' => 'REG-806',
            'camp_id' => $camp->id,
            'donor_id' => $donor->id,
            'appointment_id' => $campAppointment->id,
            'registered_by_user_id' => $staff->id,
            'registration_date' => '2026-11-12',
            'is_walk_in' => false,
            'attendance_status' => 'Confirmed',
            'registered_at' => '2026-11-12 10:05:00',
        ]);

        $this->assertFalse($campHistory->usesTimestamps());
        $this->assertFalse($apptHistory->usesTimestamps());
        $this->assertSame($camp->id, $campHistory->camp->id);
        $this->assertSame($staff->id, $campHistory->actor->id);
        $this->assertSame($campAppointment->id, $apptHistory->appointment->id);
        $this->assertSame($staff->id, $apptHistory->actor->id);
        $this->assertSame($camp->id, DonationCamp::published()->withStatus('Upcoming')->value('id'));
        $this->assertSame($staff->id, $camp->createdBy->id);
        $this->assertCount(1, $camp->statusHistories);
        $this->assertCount(1, $camp->appointments);
        $this->assertSame($center->id, DonationCenter::active()->inCity('Springfield')->value('id'));
        $this->assertCount(1, $center->appointments);
        $this->assertCount(2, $camp->registrations);
        $this->assertCount(1, $camp->activeRegistrations);
        $this->assertSame($activeRegistration->id, CampRegistration::active()->value('id'));
        $this->assertSame($camp->id, $activeRegistration->camp->id);
        $this->assertSame($donor->id, $activeRegistration->donor->id);
        $this->assertSame($campAppointment->id, $activeRegistration->appointment->id);
        $this->assertSame($staff->id, $activeRegistration->registeredBy->id);
        $this->assertSame($donor->id, $campAppointment->donor->id);
        $this->assertSame($component->id, $campAppointment->bloodComponent->id);
        $this->assertSame($camp->id, $campAppointment->donationCamp->id);
        $this->assertNull($campAppointment->donationCenter);
        $this->assertSame($center->id, $centerAppointment->donationCenter->id);
        $this->assertSame($activeRegistration->id, $campAppointment->campRegistration->id);
        $this->assertCount(1, $campAppointment->statusHistories);
        $this->assertSame(2, DonorAppointment::active()->count());
    }
}
