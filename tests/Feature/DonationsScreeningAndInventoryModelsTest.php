<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\ComponentScreeningRequirement;
use App\Models\Donation;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\ScreeningRecord;
use App\Models\ScreeningTestType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonationsScreeningAndInventoryModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_blood_component_screening_matrix_and_donation_screening_relationships_resolve(): void
    {
        $staff = User::query()->create([
            'name' => 'Lab Specialist',
            'email' => 'lab@bdms.test',
            'password' => 'secret-pass',
        ]);
        $donor = Donor::query()->create([
            'donor_code' => 'DNR-2001',
            'full_name' => 'Tariq Hassan',
            'email' => 'tariq@donor.test',
            'blood_group' => 'B+',
            'registered_at' => '2026-11-01',
        ]);
        $component = BloodComponent::query()->create([
            'code' => 'WB',
            'name' => 'Whole Blood',
            'default_volume_ml' => 450,
            'shelf_life_days' => 35,
            'storage_requirements' => '2-6 C',
            'is_active' => true,
        ]);
        $hivTest = ScreeningTestType::query()->create([
            'code' => 'HIV',
            'name' => 'HIV',
            'description' => 'HIV 1/2 antigen-antibody assay',
            'is_active' => true,
        ]);
        $requirement = ComponentScreeningRequirement::query()->create([
            'blood_component_id' => $component->id,
            'test_type_id' => $hivTest->id,
            'is_required' => true,
            'is_active' => true,
            'notes' => 'Baseline mandatory serology',
        ]);
        $center = DonationCenter::query()->create([
            'center_code' => 'CTR-CENTRAL',
            'name' => 'Central Donor Center',
            'address' => '100 Main Ave',
            'city' => 'Metro',
            'operating_hours' => '08:00-18:00',
            'default_slot_capacity' => 3,
            'is_active' => true,
        ]);

        $donation = Donation::query()->create([
            'donation_code' => 'DON-4405',
            'donor_id' => $donor->id,
            'center_id' => $center->id,
            'collection_location_name' => 'Central Donor Center',
            'recorded_by_user_id' => $staff->id,
            'staff_member_name' => 'Nurse Claire',
            'blood_group' => 'B+',
            'blood_component_id' => $component->id,
            'donation_date' => '2026-11-10',
            'collected_at' => '2026-11-10 09:30:00',
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'screening_status' => 'Passed',
            'released_to_inventory_at' => '2026-11-11 11:00:00',
            'approved_by_user_id' => $staff->id,
        ]);

        $screening = ScreeningRecord::query()->create([
            'screening_code' => 'SCR-7105',
            'donation_id' => $donation->id,
            'test_type_id' => $hivTest->id,
            'test_date' => '2026-11-11',
            'screening_result' => 'Passed',
            'is_confirmatory' => false,
            'performed_by_user_id' => $staff->id,
            'lab_staff_name' => 'Lab Specialist',
            'assay_method' => 'ELISA',
            'specimen_tube_code' => 'EDTA-T-991',
            'collected_at' => '2026-11-10 09:30:00',
            'logged_at' => '2026-11-10 11:00:00',
            'result_recorded_at' => '2026-11-11 10:30:00',
        ]);

        $this->assertSame($requirement->id, ComponentScreeningRequirement::active()->mandatory()->value('id'));
        $this->assertSame($component->id, $requirement->bloodComponent->id);
        $this->assertSame($hivTest->id, $requirement->testType->id);
        $this->assertSame($hivTest->id, $component->screeningTestTypes()->first()?->id);
        $this->assertTrue((bool) $component->screeningTestTypes()->first()?->pivot->is_required);
        $this->assertSame($component->id, $hivTest->bloodComponents()->first()?->id);
        $this->assertSame($screening->id, $hivTest->screeningRecords->first()?->id);

        $this->assertInstanceOf(CarbonInterface::class, $donation->donation_date);
        $this->assertInstanceOf(CarbonInterface::class, $donation->collected_at);
        $this->assertInstanceOf(CarbonInterface::class, $donation->released_to_inventory_at);
        $this->assertSame(450, $donation->quantity_ml);
        $this->assertSame($donor->id, $donation->donor->id);
        $this->assertSame($center->id, $donation->center->id);
        $this->assertSame($component->id, $donation->bloodComponent->id);
        $this->assertSame($staff->id, $donation->recordedBy->id);
        $this->assertSame($staff->id, $donation->approvedBy->id);
        $this->assertSame($screening->id, $donation->screeningRecords->first()?->id);
        $this->assertSame(1, Donation::completed()->screeningPassed()->count());
        $this->assertSame(0, Donation::unreleased()->count());

        $this->assertInstanceOf(CarbonInterface::class, $screening->test_date);
        $this->assertFalse($screening->is_confirmatory);
        $this->assertSame($donation->id, $screening->donation->id);
        $this->assertSame($hivTest->id, $screening->testType->id);
        $this->assertSame($staff->id, $screening->performedBy->id);
        $this->assertSame(1, ScreeningRecord::passed()->count());
        $this->assertSame(0, ScreeningRecord::pending()->count());
        $this->assertSame(0, ScreeningRecord::failed()->count());
    }

    public function test_blood_unit_fractionation_fifo_scope_and_immutable_inventory_ledger_behave_accurately(): void
    {
        $officer = User::query()->create([
            'name' => 'Inventory Officer',
            'email' => 'inventory@bdms.test',
            'password' => 'secret-pass',
        ]);
        $donor = Donor::query()->create([
            'donor_code' => 'DNR-2002',
            'full_name' => 'Sora Takahashi',
            'email' => 'sora@donor.test',
            'blood_group' => 'O-',
            'registered_at' => '2026-11-01',
        ]);
        $wholeBlood = BloodComponent::query()->create([
            'code' => 'WB',
            'name' => 'Whole Blood',
            'default_volume_ml' => 450,
            'shelf_life_days' => 35,
        ]);
        $prbc = BloodComponent::query()->create([
            'code' => 'PRBC',
            'name' => 'Packed RBC',
            'default_volume_ml' => 300,
            'shelf_life_days' => 42,
        ]);
        $donation = Donation::query()->create([
            'donation_code' => 'DON-4406',
            'donor_id' => $donor->id,
            'collection_location_name' => 'Mobile Camp',
            'staff_member_name' => 'Phlebotomist Lee',
            'blood_group' => 'O-',
            'blood_component_id' => $wholeBlood->id,
            'donation_date' => '2026-11-11',
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'screening_status' => 'Passed',
        ]);

        $stock = InventoryStock::query()->create([
            'blood_group' => 'O-',
            'blood_component_id' => $prbc->id,
            'available_units' => 2,
            'reserved_units' => 0,
            'minimum_level' => 5,
            'target_capacity' => 50,
            'last_adjusted_at' => '2026-11-12 09:00:00',
        ]);

        $parentBag = BloodUnit::query()->create([
            'unit_code' => 'UNIT-2026-004406',
            'donation_id' => $donation->id,
            'parent_unit_id' => null,
            'blood_group' => 'O-',
            'blood_component_id' => $wholeBlood->id,
            'volume_ml' => 450,
            'storage_location' => 'Quarantine-Rack-A',
            'unit_status' => 'Quarantined',
            'collected_at' => '2026-11-11 08:00:00',
            'expires_at' => '2026-12-16 08:00:00',
        ]);
        $laterExpiryUnit = BloodUnit::query()->create([
            'unit_code' => 'UNIT-2026-004406-B',
            'donation_id' => $donation->id,
            'parent_unit_id' => $parentBag->id,
            'blood_group' => 'O-',
            'blood_component_id' => $prbc->id,
            'volume_ml' => 290,
            'storage_location' => 'Cold-Bay-1',
            'unit_status' => 'Available',
            'collected_at' => '2026-11-11 08:00:00',
            'expires_at' => '2026-12-23 08:00:00',
            'released_at' => '2026-11-12 08:00:00',
            'released_by_user_id' => $officer->id,
        ]);
        $earlierExpiryUnit = BloodUnit::query()->create([
            'unit_code' => 'UNIT-2026-004406-A',
            'donation_id' => $donation->id,
            'parent_unit_id' => $parentBag->id,
            'blood_group' => 'O-',
            'blood_component_id' => $prbc->id,
            'volume_ml' => 300,
            'storage_location' => 'Cold-Bay-1',
            'unit_status' => 'Available',
            'collected_at' => '2026-11-11 08:00:00',
            'expires_at' => '2026-12-18 08:00:00',
            'released_at' => '2026-11-12 08:00:00',
            'released_by_user_id' => $officer->id,
        ]);
        BloodUnit::query()->create([
            'unit_code' => 'UNIT-2026-004406-EXP',
            'donation_id' => $donation->id,
            'parent_unit_id' => $parentBag->id,
            'blood_group' => 'O-',
            'blood_component_id' => $prbc->id,
            'volume_ml' => 280,
            'unit_status' => 'Available',
            'collected_at' => '2026-10-01 08:00:00',
            'expires_at' => '2026-11-10 08:00:00',
        ]);

        $txn = InventoryTransaction::query()->create([
            'transaction_code' => 'TXN-101',
            'inventory_stock_id' => $stock->id,
            'blood_unit_id' => $earlierExpiryUnit->id,
            'operation_type' => 'Stock In',
            'units_delta' => 1,
            'available_before' => 1,
            'available_after' => 2,
            'reserved_before' => 0,
            'reserved_after' => 0,
            'reference_type' => 'donation',
            'reference_id' => $donation->id,
            'performed_by_user_id' => $officer->id,
            'actor_name' => 'Inventory Officer',
            'description' => 'Released cleared PRBC unit',
            'occurred_at' => '2026-11-12 09:00:00',
        ]);

        $fifoOrder = BloodUnit::fifoAvailable('O-', $prbc->id, CarbonImmutable::parse('2026-11-12 00:00:00'))
            ->pluck('unit_code')
            ->all();

        $this->assertSame($parentBag->id, $donation->primaryBloodUnit->id);
        $this->assertCount(4, $donation->bloodUnits);
        $this->assertCount(3, $parentBag->childUnits);
        $this->assertSame($parentBag->id, $earlierExpiryUnit->parentUnit->id);
        $this->assertSame($donation->id, $earlierExpiryUnit->donation->id);
        $this->assertSame($prbc->id, $earlierExpiryUnit->bloodComponent->id);
        $this->assertSame($officer->id, $earlierExpiryUnit->releasedBy->id);
        $this->assertSame($txn->id, $earlierExpiryUnit->transactions->first()?->id);
        $this->assertSame(['UNIT-2026-004406-A', 'UNIT-2026-004406-B'], $fifoOrder);
        $this->assertSame($stock->id, InventoryStock::forBloodGroup('O-')->forComponent($prbc->id)->lowOrCritical()->value('id'));
        $this->assertSame($prbc->id, $stock->bloodComponent->id);
        $this->assertSame($txn->id, $stock->transactions->first()?->id);

        $this->assertNull(InventoryTransaction::UPDATED_AT);
        $this->assertInstanceOf(CarbonInterface::class, $txn->created_at);
        $this->assertSame(1, $txn->units_delta);
        $this->assertSame($stock->id, $txn->inventoryStock->id);
        $this->assertSame($earlierExpiryUnit->id, $txn->bloodUnit->id);
        $this->assertSame($officer->id, $txn->performedBy->id);
        $this->assertSame($txn->id, InventoryTransaction::forReference('donation', $donation->id)->ofOperation('Stock In')->value('id'));
    }
}
