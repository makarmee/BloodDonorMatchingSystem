<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\Role;
use App\Models\User;
use App\Services\DonationService;
use App\Services\DonorEligibilityService;
use App\Services\InventoryService;
use App\Services\ScreeningService;
use Carbon\CarbonImmutable;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class InventoryManagementIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventoryService;

    private DonationService $donationService;

    private ScreeningService $screeningService;

    private DonorEligibilityService $eligibilityService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryService = $this->app->make(InventoryService::class);
        $this->donationService = $this->app->make(DonationService::class);
        $this->screeningService = $this->app->make(ScreeningService::class);
        $this->eligibilityService = $this->app->make(DonorEligibilityService::class);

        $this->seed([
            RolePermissionSeeder::class,
            BloodComponentSeeder::class,
            ScreeningTestTypeSeeder::class,
            ComponentScreeningRequirementSeeder::class,
        ]);
    }

    public function test_inventory_index_displays_real_database_backed_stock_and_units(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $stockO, 'units' => $unitsO] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O+', 'WB', 2);
        ['stock' => $stockA, 'units' => $unitsA] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'A-', 'PRBC', 1);

        $response = $this->actingAs($staff)->get(route('inventory.index'));

        $response->assertOk();
        $response->assertSee('Blood Inventory Management');
        $response->assertSee('Serialized Blood Units Directory');
        $response->assertSee('Recent Inventory History');

        // Verify serialized unit codes appear in the directory
        $response->assertSee($unitsO[0]->unit_code);
        $response->assertSee($unitsO[1]->unit_code);
        $response->assertSee($unitsA[0]->unit_code);

        // Verify stock numbers match DB
        $this->assertSame(2, $stockO->fresh()->available_units);
        $this->assertSame(1, $stockA->fresh()->available_units);
    }

    public function test_inventory_filtering_by_component_and_units_search(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['units' => $unitsWB] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O+', 'WB', 1);
        ['units' => $unitsPRBC] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O+', 'PRBC', 1);

        $wbComponent = BloodComponent::query()->where('code', 'WB')->firstOrFail();

        // 1. Filter overview by component WB
        $responseComp = $this->actingAs($staff)->get(route('inventory.index', ['component_id' => $wbComponent->id]));
        $responseComp->assertOk();
        $responseComp->assertSee('Whole Blood');

        // 2. Filter blood units directory by search unit_code
        $responseUnitSearch = $this->actingAs($staff)->get(route('inventory.index', [
            'unit_search' => $unitsWB[0]->unit_code,
        ]));
        $responseUnitSearch->assertOk();
        $this->assertTrue($responseUnitSearch->viewData('units')->pluck('unit_code')->contains($unitsWB[0]->unit_code));
        $this->assertFalse($responseUnitSearch->viewData('units')->pluck('unit_code')->contains($unitsPRBC[0]->unit_code));

        // 3. Filter blood units directory by status
        $responseStatus = $this->actingAs($staff)->get(route('inventory.index', [
            'unit_status' => 'Available',
        ]));
        $responseStatus->assertOk();
        $responseStatus->assertSee($unitsWB[0]->unit_code);
        $responseStatus->assertSee($unitsPRBC[0]->unit_code);
    }

    public function test_unit_show_returns_full_provenance_and_traceability_view_and_json(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'B+', 'WB', 1);
        $unit = $units[0];

        // 1. Web HTML view
        $htmlResponse = $this->actingAs($staff)->get(route('inventory.units.show', $unit->id));
        $htmlResponse->assertOk();
        $htmlResponse->assertSee($unit->unit_code);
        $htmlResponse->assertSee('Blood Bag Specifications');
        $htmlResponse->assertSee('Intake &amp; Screening Provenance', false);
        $htmlResponse->assertSee($unit->donation->donation_code);
        $htmlResponse->assertSee('Unit Transaction Ledger');

        // Lookup by unit_code string
        $codeResponse = $this->actingAs($staff)->get(route('inventory.units.show', $unit->unit_code));
        $codeResponse->assertOk();

        // 2. JSON API representation
        $jsonResponse = $this->actingAs($staff)->getJson(route('inventory.units.show', $unit->id));
        $jsonResponse->assertOk();
        $jsonResponse->assertJson([
            'id' => $unit->id,
            'unit_code' => $unit->unit_code,
            'blood_group' => 'B+',
            'component_code' => 'WB',
            'unit_status' => 'Available',
            'donation_code' => $unit->donation->donation_code,
        ]);
    }

    public function test_authorized_staff_can_update_stock_thresholds(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $stock] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'AB+', 'WB', 1);

        $response = $this->actingAs($staff)->post(route('inventory.stocks.adjust', $stock->id), [
            'minimum_level' => 45,
            'target_capacity' => 150,
        ]);

        $response->assertRedirect(route('inventory.index'));
        $response->assertSessionHas('success');

        $stock->refresh();
        $this->assertSame(45, $stock->minimum_level);
        $this->assertSame(150, $stock->target_capacity);
    }

    public function test_manual_positive_stock_inflation_is_strictly_rejected(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $stock] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O-', 'WB', 1);

        // Attempting to post positive delta is rejected with 422 under the Critical Stock Integrity Rule
        $response = $this->actingAs($staff)->post(route('inventory.stocks.adjust', $stock->id), [
            'units_delta' => 5,
            'description' => 'Fabricated positive units without screening provenance.',
        ]);

        $response->assertSessionHasErrors('units_delta');
        $this->assertSame(1, $stock->fresh()->available_units, 'Available units must not be manually inflated.');
    }

    public function test_authorized_staff_can_discard_eligible_unit_with_mandatory_reason(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'A+', 'WB', 2);
        $unitToDiscard = $units[0];

        // 1. Missing reason fails validation
        $failResponse = $this->actingAs($staff)->post(route('inventory.units.discard', $unitToDiscard->id), [
            'reason' => 'No', // less than 3 chars
        ]);
        $failResponse->assertSessionHasErrors('reason');

        // 2. Valid discard
        $response = $this->actingAs($staff)->post(route('inventory.units.discard', $unitToDiscard->id), [
            'reason' => 'Visible fibrin clotting detected during routine quality inspection.',
        ]);

        $response->assertRedirect(route('inventory.index'));
        $response->assertSessionHas('success');

        $unitToDiscard->refresh();
        $this->assertSame('Discarded', $unitToDiscard->unit_status);
        $this->assertSame(1, $stock->fresh()->available_units);

        // Verify ledger recorded Discarded
        $txn = InventoryTransaction::query()
            ->where('blood_unit_id', $unitToDiscard->id)
            ->where('operation_type', 'Discarded')
            ->first();

        $this->assertNotNull($txn);
        $this->assertSame(-1, $txn->units_delta);
        $this->assertStringContainsString('Visible fibrin clotting', $txn->description);
    }

    public function test_expiration_safety_prevents_marking_future_expiring_units_as_expired(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O+', 'WB', 1);
        $unit = $units[0];

        $this->assertTrue($unit->expires_at->gt(now()), 'Unit expiration boundary is in the future.');

        // Attempting to expire a non-expired unit fails validation
        $response = $this->actingAs($staff)->post(route('inventory.units.expire', $unit->id), [
            'reason' => 'Premature expiration attempt.',
        ]);

        $response->assertSessionHasErrors('expires_at');
        $this->assertSame('Available', $unit->fresh()->unit_status);
        $this->assertSame(1, $stock->fresh()->available_units);
    }

    public function test_marking_expired_unit_transitions_state_and_decrements_stock(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'B-', 'WB', 1);
        $unit = $units[0];

        // Force unit expires_at to the past
        $unit->forceFill(['expires_at' => CarbonImmutable::now()->subDay()])->save();

        $response = $this->actingAs($staff)->post(route('inventory.units.expire', $unit->id), [
            'reason' => 'Shelf-life expired while in monitored storage.',
        ]);

        $response->assertRedirect(route('inventory.index'));
        $response->assertSessionHas('success');

        $unit->refresh();
        $this->assertSame('Expired', $unit->unit_status);
        $this->assertSame(0, $stock->fresh()->available_units);

        // Verify ledger recorded Expired
        $txn = InventoryTransaction::query()
            ->where('blood_unit_id', $unit->id)
            ->where('operation_type', 'Expired')
            ->first();

        $this->assertNotNull($txn);
        $this->assertSame(-1, $txn->units_delta);
    }

    public function test_hospital_coordinators_have_read_only_access_and_are_blocked_from_all_mutations(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');
        $hospitalUser = $this->createUserWithRole('hospital');

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'A+', 'WB', 1);
        $unit = $units[0];

        // 1. Hospital coordinator can view index with Available Blood Stock sidebar navigation
        $viewResponse = $this->actingAs($hospitalUser)->get(route('inventory.index'));
        $viewResponse->assertOk();
        $viewResponse->assertSee('Available Blood Stock');
        $viewResponse->assertDontSee('Regional Stock');
        $viewResponse->assertDontSee('Adjust Inventory');

        // 2. All mutation endpoints return 403 Forbidden
        $this->actingAs($hospitalUser)->post(route('inventory.stocks.adjust', $stock->id), [
            'minimum_level' => 30,
        ])->assertForbidden();

        $this->actingAs($hospitalUser)->post(route('inventory.units.discard', $unit->id), [
            'reason' => 'Hospital unauthorized discard.',
        ])->assertForbidden();

        $this->actingAs($hospitalUser)->post(route('inventory.units.expire', $unit->id), [
            'reason' => 'Hospital unauthorized expire.',
        ])->assertForbidden();

        $this->actingAs($hospitalUser)->get(route('inventory.reconcile'))->assertForbidden();
    }

    public function test_unauthorized_and_inactive_users_are_forbidden(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $stock, 'units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O+', 'WB', 1);
        $unit = $units[0];

        $donorUser = $this->createUserWithRole('donor');
        $inactiveStaff = $this->createUserWithRole('staff', false);

        // Donor is forbidden
        $this->actingAs($donorUser)->get(route('inventory.index'))->assertForbidden();
        $this->actingAs($donorUser)->post(route('inventory.stocks.adjust', $stock->id))->assertForbidden();

        // Inactive staff is forbidden
        $this->actingAs($inactiveStaff)->get(route('inventory.index'))->assertForbidden();
        $this->actingAs($inactiveStaff)->post(route('inventory.stocks.adjust', $stock->id))->assertForbidden();

        // Guest is redirected to login
        $this->get(route('inventory.index'))->assertRedirect(route('login'));
        $this->get(route('inventory.units.show', $unit->id))->assertRedirect(route('login'));
    }

    public function test_reconciliation_endpoint_is_read_only_and_modifies_zero_records(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O+', 'WB', 2);
        $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'A-', 'PRBC', 1);

        $countsBefore = [
            'units' => BloodUnit::query()->count(),
            'stocks' => InventoryStock::query()->count(),
            'transactions' => InventoryTransaction::query()->count(),
        ];

        // 1. Web HTML view
        $htmlResponse = $this->actingAs($staff)->get(route('inventory.reconcile'));
        $htmlResponse->assertOk();
        $htmlResponse->assertSee('Inventory Audit &amp; Reconciliation', false);
        $htmlResponse->assertSee('All Inventory Stock Buckets Reconciled');

        // 2. JSON representation
        $jsonResponse = $this->actingAs($staff)->getJson(route('inventory.reconcile'));
        $jsonResponse->assertOk();
        $jsonResponse->assertJsonPath('all_reconciled', true);

        $countsAfter = [
            'units' => BloodUnit::query()->count(),
            'stocks' => InventoryStock::query()->count(),
            'transactions' => InventoryTransaction::query()->count(),
        ];

        $this->assertSame($countsBefore, $countsAfter, 'Reconciliation must be strictly read-only and mutate zero database rows.');
    }

    public function test_immutable_transaction_ledger_cannot_be_mutated(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'O+', 'WB', 1);

        $txn = InventoryTransaction::query()->firstOrFail();

        $this->assertNull(InventoryTransaction::UPDATED_AT, 'InventoryTransaction model must have UPDATED_AT disabled.');
        $this->assertNull($txn->updated_at, 'InventoryTransaction record does not have updated_at column.');
    }

    public function test_eloquent_model_protection_prevents_updates_and_deletions_on_inventory_transactions(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['stock' => $stock] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'AB+', 'WB', 1);

        $txn = InventoryTransaction::query()->firstOrFail();

        // 1. Eloquent update must throw LogicException
        try {
            $txn->update(['description' => 'Tampered ledger entry.']);
            $this->fail('Expected LogicException when updating InventoryTransaction via Eloquent.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable append-only ledger and cannot be updated', $e->getMessage());
        }

        // 2. Eloquent save on existing model must throw LogicException
        try {
            $txn->description = 'Direct property mutation.';
            $txn->save();
            $this->fail('Expected LogicException when saving existing InventoryTransaction via Eloquent.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable append-only ledger and cannot be updated', $e->getMessage());
        }

        // 3. Eloquent delete must throw LogicException
        try {
            $txn->delete();
            $this->fail('Expected LogicException when deleting InventoryTransaction via Eloquent.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable append-only ledger and cannot be deleted', $e->getMessage());
        }

        // 4. Normal creation of new transactions succeeds
        $newTxn = InventoryTransaction::query()->create([
            'transaction_code' => 'TXN-TEST-IMMUTABLE-01',
            'inventory_stock_id' => $stock->id,
            'blood_unit_id' => null,
            'operation_type' => 'Manual Adjustment',
            'units_delta' => -1,
            'available_before' => 1,
            'available_after' => 0,
            'reserved_before' => 0,
            'reserved_after' => 0,
            'reference_type' => null,
            'reference_id' => null,
            'performed_by_user_id' => $staff->id,
            'actor_name' => $staff->name,
            'description' => 'Legitimate ledger row creation.',
            'occurred_at' => now(),
        ]);

        $this->assertDatabaseHas('inventory_transactions', [
            'id' => $newTxn->id,
            'transaction_code' => 'TXN-TEST-IMMUTABLE-01',
        ]);
    }

    public function test_serialized_units_directory_can_filter_by_transfused_status(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $labOfficer = $this->createUserWithRole('lab_officer');

        ['units' => $units] = $this->releaseScreenedUnits($admin, $staff, $labOfficer, 'B+', 'WB', 2);
        $availableUnit = $units[0];
        $transfusedUnit = $units[1];

        // Mark second unit as Transfused
        BloodUnit::query()->whereKey($transfusedUnit->id)->update(['unit_status' => 'Transfused']);

        // Query with ?unit_status=Transfused
        $response = $this->actingAs($staff)->get(route('inventory.index', ['unit_status' => 'Transfused']));
        $response->assertOk();

        $filteredCodes = $response->viewData('units')->pluck('unit_code')->all();
        $this->assertContains($transfusedUnit->unit_code, $filteredCodes);
        $this->assertNotContains($availableUnit->unit_code, $filteredCodes);

        // Query with ?unit_status=Available
        $responseAvailable = $this->actingAs($staff)->get(route('inventory.index', ['unit_status' => 'Available']));
        $responseAvailable->assertOk();

        $availableCodes = $responseAvailable->viewData('units')->pluck('unit_code')->all();
        $this->assertContains($availableUnit->unit_code, $availableCodes);
        $this->assertNotContains($transfusedUnit->unit_code, $availableCodes);
    }

    public function test_empty_inventory_and_ledger_states_render_zero_stock_and_no_fictional_records(): void
    {
        $staff = $this->createUserWithRole('staff');

        $response = $this->actingAs($staff)->get(route('inventory.index'));
        $response->assertOk();

        $summary = $response->viewData('summary');
        $this->assertSame(0, $summary['totalUnits']);
        $this->assertSame(8, $summary['criticalStockGroups']);
        $this->assertEmpty($response->viewData('history'));

        $response->assertSee('No inventory transactions recorded yet.');
        $response->assertSee('No physical blood units recorded yet');
        $response->assertDontSee('Dr. Maya Lin');
        $response->assertDontSee('Samuel Ortega');
        $response->assertDontSee('Regional Stock');
    }

    /**
     * @return array{stock: InventoryStock, units: Collection<int, BloodUnit>}
     */
    private function releaseScreenedUnits(
        User $admin,
        User $staff,
        User $labOfficer,
        string $bloodGroup,
        string $componentCode,
        int $count
    ): array {
        $component = BloodComponent::query()->where('code', $componentCode)->firstOrFail();
        $center = DonationCenter::factory()->create();
        $units = collect();

        for ($i = 0; $i < $count; $i++) {
            $donor = Donor::factory()->create([
                'blood_group' => $bloodGroup,
                'profile_completed' => true,
                'account_status' => 'Active',
            ]);

            $this->eligibilityService->approveEligibility(
                donor: $donor,
                actor: $admin,
                reason: 'Cleared for inventory integration test.',
                clearanceSource: 'Medical Officer Clearance'
            );

            $donation = $this->donationService->registerDonation($donor->fresh(), $staff, [
                'blood_component_id' => $component->id,
                'quantity_ml' => $component->default_volume_ml,
                'center_id' => $center->id,
            ]);

            foreach (['HIV', 'HBV', 'HCV', 'SYPH', 'COMPAT'] as $code) {
                $this->screeningService->recordScreeningResult($donation, $labOfficer, [
                    'test_type_code' => $code,
                    'screening_result' => 'Passed',
                ]);
            }

            $released = $this->screeningService->releaseToInventory($donation, $labOfficer);
            $units->push($released->primaryBloodUnit->fresh());
        }

        $stock = InventoryStock::query()
            ->forBloodGroup($bloodGroup)
            ->forComponent($component->id)
            ->firstOrFail();

        return [
            'stock' => $stock,
            'units' => $units,
        ];
    }

    private function createUserWithRole(string $roleSlug, bool $isActive = true): User
    {
        $user = User::factory()->create(['is_active' => $isActive]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user;
    }
}
