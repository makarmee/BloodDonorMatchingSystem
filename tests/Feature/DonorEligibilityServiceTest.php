<?php

namespace Tests\Feature;

use App\Models\Donor;
use App\Models\DonorEligibilityRecord;
use App\Models\Role;
use App\Models\User;
use App\Services\DonorEligibilityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class DonorEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private DonorEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DonorEligibilityService::class);
    }

    public function test_authorized_staff_records_eligibility_assessment_and_synchronizes_donor_projections(): void
    {
        $this->travelTo('2026-11-12 09:00:00');
        $this->seed(RolePermissionSeeder::class);

        $staff = $this->createUserWithRole('staff');
        $donor = Donor::factory()->create();

        $initialRecord = $this->service->initializeOnboardingEligibility($donor);
        $this->assertSame('Pending Review', $initialRecord->eligibility_status);
        $this->assertTrue($initialRecord->is_current);
        $this->assertFalse($this->service->isCurrentlyEligible($donor));

        $approvedRecord = $this->service->approveEligibility(
            donor: $donor,
            actor: $staff,
            reason: 'Cleared on intake questionnaire, blood pressure, and hemoglobin 13.8 g/dL.',
            clearanceSource: 'Staff Medical Review',
            reviewedAt: '2026-11-12',
            nextEligibleDate: '2027-01-07',
        );

        $freshDonor = $donor->fresh();
        $freshInitial = $initialRecord->fresh();

        $this->assertModelExists($approvedRecord);
        $this->assertSame('Eligible', $approvedRecord->eligibility_status);
        $this->assertSame($staff->id, $approvedRecord->reviewed_by_user_id);
        $this->assertSame('2026-11-12', $approvedRecord->reviewed_at?->toDateString());
        $this->assertSame('2027-01-07', $approvedRecord->next_eligible_date?->toDateString());
        $this->assertTrue($approvedRecord->is_current);
        $this->assertTrue($approvedRecord->current_record_guard);

        $this->assertFalse($freshInitial->is_current);
        $this->assertNull($freshInitial->current_record_guard);

        $this->assertSame('Eligible', $freshDonor->eligibility_status);
        $this->assertSame('2027-01-07', $freshDonor->next_eligible_date?->toDateString());
        $this->assertTrue($this->service->isCurrentlyEligible($freshDonor));
        $this->assertSame(1, DonorEligibilityRecord::query()->where('donor_id', $donor->id)->current()->count());
        $this->assertSame(2, DonorEligibilityRecord::query()->where('donor_id', $donor->id)->count());
    }

    public function test_historical_eligibility_records_are_preserved_with_single_current_record_invariant(): void
    {
        $this->travelTo('2026-11-12 10:00:00');
        $this->seed(RolePermissionSeeder::class);

        $admin = $this->createUserWithRole('admin');
        $donor = Donor::factory()->create();

        $first = $this->service->initializeOnboardingEligibility($donor);
        $second = $this->service->deferDonor(
            donor: $donor,
            actor: $admin,
            reason: 'Post-donation 56-day recovery window.',
            nextEligibleDate: '2026-12-23',
            clearanceSource: 'Post-Donation Clinical Deferral',
        );
        $third = $this->service->approveEligibility(
            donor: $donor,
            actor: $admin,
            reason: 'Recovery period elapsed and follow-up hemoglobin normal.',
            clearanceSource: 'Medical Officer Clearance',
        );
        $fourth = $this->service->recordAssessment($donor, $admin, [
            'eligibility_status' => 'Unknown',
            'reason' => 'External medical history documentation awaited.',
            'clearance_source' => 'Administrative Hold',
        ]);

        $history = $this->service->getHistory($donor);
        $freshDonor = $donor->fresh();

        $this->assertCount(4, $history);
        $this->assertSame([$fourth->id, $third->id, $second->id, $first->id], $history->pluck('id')->all());
        $this->assertSame(1, $history->where('is_current', true)->count());
        $this->assertSame($fourth->id, $this->service->getCurrentRecord($donor)?->id);

        $preservedSecond = $second->fresh();
        $this->assertFalse($preservedSecond->is_current);
        $this->assertSame('Not Eligible', $preservedSecond->eligibility_status);
        $this->assertSame('Post-donation 56-day recovery window.', $preservedSecond->reason);
        $this->assertSame('2026-12-23', $preservedSecond->next_eligible_date?->toDateString());

        $this->assertSame('Unknown', $freshDonor->eligibility_status);
        $this->assertNull($freshDonor->next_eligible_date);
        $this->assertFalse($this->service->isCurrentlyEligible($freshDonor));
    }

    public function test_eligibility_is_never_inferred_solely_from_last_donation_date(): void
    {
        $this->travelTo('2026-11-12 10:00:00');
        $this->seed(RolePermissionSeeder::class);

        $staff = $this->createUserWithRole('staff');
        $donor = Donor::factory()->create([
            'last_donation_date' => '2025-01-10',
        ]);

        $this->service->deferDonor(
            donor: $donor,
            actor: $staff,
            reason: 'Pending clinician review despite elapsed calendar interval.',
            nextEligibleDate: null,
        );

        $this->assertFalse($this->service->isCurrentlyEligible($donor->fresh()));
        $this->assertSame('Not Eligible', $donor->fresh()->eligibility_status);
    }

    public function test_unauthorized_inactive_and_insufficient_role_users_are_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $donor = Donor::factory()->create();
        $donorUser = $this->createUserWithRole('donor');
        $hospitalUser = $this->createUserWithRole('hospital');
        $rolelessUser = User::factory()->create();
        $inactiveAdmin = $this->createUserWithRole('admin', isActive: false);
        $labOfficer = $this->createUserWithRole('lab_officer');

        foreach ([null, $donorUser, $hospitalUser, $rolelessUser, $inactiveAdmin] as $unauthorizedActor) {
            try {
                $this->service->approveEligibility(
                    donor: $donor,
                    actor: $unauthorizedActor,
                    reason: 'Unauthorized clearance attempt.',
                );
                $this->fail('Expected AuthorizationException was not thrown.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        // Lab officers cannot grant Eligible clearance, only record screening deferrals.
        try {
            $this->service->approveEligibility(
                donor: $donor,
                actor: $labOfficer,
                reason: 'Attempted Eligible clearance by lab officer.',
            );
            $this->fail('Expected AuthorizationException for lab officer Eligible clearance.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, DonorEligibilityRecord::query()->where('donor_id', $donor->id)->count());

        $deferral = $this->service->recordScreeningDeferral(
            donor: $donor,
            actor: $labOfficer,
            reason: 'Reactive screening assay on DON-4409 requires permanent deferral.',
        );

        $this->assertSame('Not Eligible', $deferral->eligibility_status);
        $this->assertSame('Not Eligible', $donor->fresh()->eligibility_status);
    }

    public function test_concurrent_or_conflicting_eligibility_updates_are_safely_blocked(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $staffOne = $this->createUserWithRole('staff');
        $staffTwo = $this->createUserWithRole('staff');
        $donor = Donor::factory()->create();

        $initial = $this->service->initializeOnboardingEligibility($donor);

        // Staff Two commits first, superseding $initial.
        $winningRecord = $this->service->approveEligibility(
            donor: $donor,
            actor: $staffTwo,
            reason: 'Cleared first during concurrent review.',
            expectedCurrentRecordId: $initial->id,
        );

        // Staff One tries to commit using stale $initial->id.
        try {
            $this->service->deferDonor(
                donor: $donor,
                actor: $staffOne,
                reason: 'Stale conflicting review.',
                expectedCurrentRecordId: $initial->id,
            );
            $this->fail('Expected ValidationException due to stale current eligibility record id.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('eligibility_record', $exception->errors());
        }

        $this->assertSame($winningRecord->id, $this->service->getCurrentRecord($donor)?->id);
        $this->assertSame('Eligible', $donor->fresh()->eligibility_status);

        // Storage-level partial unique guard blocks any direct duplicate is_current = true insert.
        $this->expectException(QueryException::class);

        DonorEligibilityRecord::query()->create([
            'donor_id' => $donor->id,
            'eligibility_status' => 'Not Eligible',
            'reason' => 'Direct conflicting insert bypassing service lock.',
            'clearance_source' => 'Bypass Attempt',
            'reviewed_at' => '2026-11-12',
            'is_current' => true,
        ]);
    }

    public function test_failed_projection_sync_rolls_back_entire_eligibility_transaction(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $staff = $this->createUserWithRole('staff');
        $donor = Donor::factory()->create();
        $initial = $this->service->initializeOnboardingEligibility($donor);

        Donor::saving(static function (): void {
            throw new RuntimeException('Simulated projection write failure.');
        });

        try {
            $this->service->approveEligibility(
                donor: $donor,
                actor: $staff,
                reason: 'Should roll back completely on projection failure.',
            );
            $this->fail('Expected RuntimeException from Donor::saving listener.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            Event::forget('eloquent.saving: '.Donor::class);
        }

        $this->assertDatabaseCount('donor_eligibility_records', 1);
        $this->assertTrue($initial->fresh()->is_current);
        $this->assertSame('Pending Review', $donor->fresh()->eligibility_status);
        $this->assertNull($donor->fresh()->next_eligible_date);
    }

    public function test_direct_mass_assignment_cannot_modify_donor_eligibility_projections(): void
    {
        $donor = Donor::factory()->create([
            'full_name' => 'Original Donor',
        ]);

        $donor->fill([
            'full_name' => 'Updated Donor Name',
            'eligibility_status' => 'Eligible',
            'next_eligible_date' => '2027-04-01',
        ])->save();

        $donor->update([
            'eligibility_status' => 'Not Eligible',
            'next_eligible_date' => '2027-05-01',
        ]);

        $fresh = $donor->fresh();

        $this->assertSame('Updated Donor Name', $fresh->full_name);
        $this->assertSame('Pending Review', $fresh->eligibility_status);
        $this->assertNull($fresh->next_eligible_date);
    }

    public function test_validation_rejects_invalid_statuses_blank_justification_bad_dates_and_duplicate_onboarding(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = $this->createUserWithRole('admin');
        $donor = Donor::factory()->create();

        try {
            $this->service->initializeOnboardingEligibility($donor, null, 'Eligible');
            $this->fail('Expected ValidationException for Eligible onboarding shortcut.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('eligibility_status', $exception->errors());
        }

        $this->service->initializeOnboardingEligibility($donor);

        try {
            $this->service->initializeOnboardingEligibility($donor);
            $this->fail('Expected ValidationException for duplicate onboarding initialization.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('eligibility_record', $exception->errors());
        }

        $invalidPayloads = [
            'eligibility_status' => ['eligibility_status' => 'Cleared', 'reason' => 'Valid', 'clearance_source' => 'Desk'],
            'reason' => ['eligibility_status' => 'Eligible', 'reason' => '   ', 'clearance_source' => 'Desk'],
            'clearance_source' => ['eligibility_status' => 'Eligible', 'reason' => 'Cleared', 'clearance_source' => ''],
            'next_eligible_date' => [
                'eligibility_status' => 'Not Eligible',
                'reason' => 'Deferred',
                'clearance_source' => 'Desk',
                'next_eligible_date' => 'not-a-valid-date',
            ],
        ];

        foreach ($invalidPayloads as $expectedErrorKey => $payload) {
            try {
                $this->service->recordAssessment($donor, $admin, $payload);
                $this->fail("Expected ValidationException for field {$expectedErrorKey}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($expectedErrorKey, $exception->errors());
            }
        }

        $this->assertSame(1, DonorEligibilityRecord::query()->where('donor_id', $donor->id)->count());
    }

    private function createUserWithRole(string $roleSlug, bool $isActive = true): User
    {
        $user = User::factory()->create(['is_active' => $isActive]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user;
    }
}

