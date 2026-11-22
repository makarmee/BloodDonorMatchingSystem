<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\DonorEligibilityRecord;
use App\Models\Role;
use App\Models\ScreeningRecord;
use App\Models\User;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonationsWorkflowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(BloodComponentSeeder::class);
        $this->seed(ScreeningTestTypeSeeder::class);
        $this->seed(ComponentScreeningRequirementSeeder::class);

        $this->staff = User::factory()->create(['name' => 'Nurse Jane']);
        $this->staff->roles()->attach(Role::where('slug', 'staff')->firstOrFail());
    }

    public function test_staff_can_view_donations_index_with_real_database_records_and_summary_metrics(): void
    {
        $wholeBlood = BloodComponent::where('code', 'WB')->firstOrFail();

        $d1 = Donation::factory()->create([
            'donation_code' => 'DON-9001',
            'collection_status' => 'Completed',
            'screening_status' => 'Pending',
            'quantity_ml' => 450,
            'blood_component_id' => $wholeBlood->id,
            'donation_date' => now()->toDateString(),
        ]);

        $d2 = Donation::factory()->create([
            'donation_code' => 'DON-9002',
            'collection_status' => 'Scheduled',
            'screening_status' => 'Pending',
            'quantity_ml' => 450,
            'blood_component_id' => $wholeBlood->id,
            'donation_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->staff)->get(route('donations.index'));

        $response->assertOk();
        $response->assertSee('DON-9001');
        $response->assertSee('DON-9002');

        $summary = $response->viewData('summary');
        $this->assertSame(2, $summary['totalDonations']);
        $this->assertSame(1, $summary['totalUnitsCollected']);
        $this->assertSame(450, $summary['totalVolumeMl']);
        $this->assertSame(2, $summary['pendingScreening']);
    }

    public function test_staff_can_search_and_filter_real_donations(): void
    {
        $donorA = Donor::factory()->eligible()->create(['full_name' => 'Alice Johnson', 'blood_group' => 'A+']);
        DonorEligibilityRecord::factory()->eligible()->for($donorA)->create();
        $donorB = Donor::factory()->eligible()->create(['full_name' => 'Bob Smith', 'blood_group' => 'O-']);
        DonorEligibilityRecord::factory()->eligible()->for($donorB)->create();

        Donation::factory()->create([
            'donation_code' => 'DON-ALICE-1',
            'donor_id' => $donorA->id,
            'blood_group' => 'A+',
            'collection_status' => 'Completed',
        ]);

        Donation::factory()->create([
            'donation_code' => 'DON-BOB-1',
            'donor_id' => $donorB->id,
            'blood_group' => 'O-',
            'collection_status' => 'Scheduled',
        ]);

        // Filter by blood group
        $response = $this->actingAs($this->staff)->get(route('donations.index', ['blood_group' => 'A+']));
        $response->assertOk();
        $response->assertSee('DON-ALICE-1');
        $response->assertDontSee('DON-BOB-1');

        // Search by donor name
        $searchResponse = $this->actingAs($this->staff)->get(route('donations.index', ['search' => 'Alice']));
        $searchResponse->assertOk();
        $searchResponse->assertSee('DON-ALICE-1');
        $searchResponse->assertDontSee('DON-BOB-1');
    }

    public function test_staff_can_register_walk_in_scheduled_donation(): void
    {
        $donor = Donor::factory()->eligible()->create(['blood_group' => 'B+']);
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();

        $response = $this->actingAs($this->staff)->post(route('donations.store'), [
            'donor_id' => $donor->donor_code,
            'donation_date' => now()->toDateString(),
            'donation_type' => 'Whole Blood',
            'quantity_ml' => 450,
            'collection_status' => 'Scheduled',
            'collection_location' => 'Main Blood Center',
            'staff_member' => 'Nurse Jane',
        ]);

        $donation = Donation::where('donor_id', $donor->id)->first();
        $this->assertNotNull($donation);

        $response->assertRedirect(route('donations.show', $donation));
        $this->assertSame('Scheduled', $donation->collection_status);
        $this->assertSame('Pending', $donation->screening_status);
        $this->assertSame(0, BloodUnit::where('donation_id', $donation->id)->count());
    }

    public function test_staff_can_register_immediately_completed_donation_with_unit_and_screening_panel(): void
    {
        $donor = Donor::factory()->eligible()->create(['blood_group' => 'O+']);
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();

        $response = $this->actingAs($this->staff)->post(route('donations.store'), [
            'donor_id' => $donor->donor_code,
            'donation_date' => now()->toDateString(),
            'donation_type' => 'Whole Blood',
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'collection_location' => 'Main Blood Center',
            'staff_member' => 'Nurse Jane',
            'collection_notes' => 'Flawless collection',
        ]);

        $donation = Donation::where('donor_id', $donor->id)->first();
        $this->assertNotNull($donation);

        $response->assertRedirect(route('donations.show', $donation));
        $this->assertSame('Completed', $donation->collection_status);
        $this->assertSame('Pending', $donation->screening_status);

        // Primary blood unit should be created in Quarantined status
        $unit = BloodUnit::where('donation_id', $donation->id)->first();
        $this->assertNotNull($unit);
        $this->assertSame('Quarantined', $unit->unit_status);
        $this->assertSame('O+', $unit->blood_group);

        // Mandatory screening panel should be initialized
        $screeningCount = ScreeningRecord::where('donation_id', $donation->id)->count();
        $this->assertGreaterThanOrEqual(4, $screeningCount);
    }

    public function test_staff_can_complete_scheduled_donation_via_action_modal_endpoint(): void
    {
        $donor = Donor::factory()->eligible()->create(['blood_group' => 'AB+']);
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();
        $wholeBlood = BloodComponent::where('code', 'WB')->firstOrFail();

        $donation = Donation::factory()->create([
            'donor_id' => $donor->id,
            'blood_group' => 'AB+',
            'blood_component_id' => $wholeBlood->id,
            'collection_status' => 'Scheduled',
            'screening_status' => 'Pending',
        ]);

        $this->assertSame(0, BloodUnit::where('donation_id', $donation->id)->count());

        $response = $this->actingAs($this->staff)->post(route('donations.complete', $donation), [
            'quantity_ml' => 450,
            'collection_notes' => 'Collection completed successfully.',
        ]);

        $response->assertRedirect(route('donations.show', $donation));
        $donation->refresh();

        $this->assertSame('Completed', $donation->collection_status);
        $this->assertSame('Pending', $donation->screening_status);

        // Unit created in Quarantined status
        $unit = BloodUnit::where('donation_id', $donation->id)->first();
        $this->assertNotNull($unit);
        $this->assertSame('Quarantined', $unit->unit_status);

        // Screening records initialized
        $this->assertGreaterThan(0, ScreeningRecord::where('donation_id', $donation->id)->count());
    }

    public function test_staff_can_cancel_scheduled_donation_via_action_modal_endpoint(): void
    {
        $donation = Donation::factory()->create([
            'collection_status' => 'Scheduled',
            'screening_status' => 'Pending',
        ]);

        $response = $this->actingAs($this->staff)->post(route('donations.cancel', $donation), [
            'reason' => 'Donor withdrew consent prior to phlebotomy.',
        ]);

        $response->assertRedirect(route('donations.show', $donation));
        $donation->refresh();

        $this->assertSame('Cancelled', $donation->collection_status);
    }

    public function test_cannot_complete_already_cancelled_or_completed_donation(): void
    {
        $donation = Donation::factory()->create([
            'collection_status' => 'Cancelled',
        ]);

        $response = $this->actingAs($this->staff)->post(route('donations.complete', $donation), [
            'quantity_ml' => 450,
        ]);

        $response->assertSessionHasErrors();
        $donation->refresh();
        $this->assertSame('Cancelled', $donation->collection_status);
    }

    public function test_unauthorized_users_cannot_access_or_mutate_donations(): void
    {
        $regularUser = User::factory()->create(); // No staff/admin/lab role

        $this->actingAs($regularUser)
            ->get(route('donations.index'))
            ->assertForbidden();

        $this->actingAs($regularUser)
            ->post(route('donations.store'), [])
            ->assertForbidden();
    }
}
