<?php

namespace App\Services;

use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\ComponentScreeningRequirement;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\ScreeningRecord;
use App\Models\ScreeningTestType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ScreeningService
{
    public function __construct(
        private DonorEligibilityService $eligibilityService,
        private ReferenceCodeGenerator $codeGenerator,
        private ServiceInputNormalizer $inputNormalizer
    ) {}

    /**
     * Initialize missing `Pending` screening records for all active mandatory tests of a completed donation.
     *
     * @param  array{
     *     lab_staff_name?: string|null,
     *     specimen_tube_code?: string|null,
     *     logged_at?: CarbonInterface|string|null
     * }  $attributes
     * @return Collection<int, ScreeningRecord>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function initializePendingPanel(Donation $donation, ?User $actor, array $attributes = []): Collection
    {
        $this->authorizeAction($actor, 'manage', ScreeningRecord::class);

        $labStaffName = array_key_exists('lab_staff_name', $attributes)
            ? $this->inputNormalizer->normalizeRequiredText($attributes['lab_staff_name'], 150, 'lab_staff_name', 'Lab staff name is required.')
            : $this->inputNormalizer->normalizeRequiredText($actor->name, 150, 'lab_staff_name', 'Lab staff name is required.');
        $specimenTubeCode = $this->inputNormalizer->normalizeOptionalText($attributes['specimen_tube_code'] ?? null, 64, 'specimen_tube_code');
        $loggedAt = $this->inputNormalizer->parseOptionalDateTime($attributes['logged_at'] ?? null, 'logged_at') ?? CarbonImmutable::now();

        return DB::transaction(function () use ($donation, $actor, $labStaffName, $specimenTubeCode, $loggedAt): Collection {
            Donor::query()
                ->whereKey($donation->donor_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedDonation = Donation::query()
                ->whereKey($donation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureDonationIsMutableForScreening($lockedDonation);

            $requirements = ComponentScreeningRequirement::query()
                ->where('blood_component_id', $lockedDonation->blood_component_id)
                ->mandatory()
                ->with('testType')
                ->get();

            if ($requirements->isEmpty()) {
                throw ValidationException::withMessages([
                    'component_screening_requirements' => 'No active mandatory screening requirements are configured for this blood component.',
                ]);
            }

            $existingTestTypeIds = ScreeningRecord::query()
                ->where('donation_id', $lockedDonation->id)
                ->lockForUpdate()
                ->pluck('test_type_id')
                ->all();

            $created = new EloquentCollection;

            foreach ($requirements as $requirement) {
                if (! $requirement->testType instanceof ScreeningTestType || ! $requirement->testType->is_active) {
                    continue;
                }

                if (in_array($requirement->test_type_id, $existingTestTypeIds, true)) {
                    continue;
                }

                $created->push(ScreeningRecord::query()->create([
                    'screening_code' => $this->codeGenerator->generate(ScreeningRecord::class, 'screening_code', 'SCR-'),
                    'donation_id' => $lockedDonation->id,
                    'test_type_id' => $requirement->test_type_id,
                    'test_date' => null,
                    'screening_result' => 'Pending',
                    'is_confirmatory' => false,
                    'performed_by_user_id' => $actor->getKey(),
                    'lab_staff_name' => $labStaffName,
                    'assay_method' => null,
                    'specimen_tube_code' => $specimenTubeCode,
                    'test_notes' => null,
                    'collected_at' => $lockedDonation->collected_at,
                    'logged_at' => $loggedAt,
                    'result_recorded_at' => null,
                ]));
            }

            return $created;
        }, 3);
    }

    /**
     * Record or settle an individual laboratory screening assay result (`Pending`, `Passed`, or `Failed`).
     *
     * Finalized records (`Passed`/`Failed`) remain immutable; repeat tests must set `is_confirmatory = true`.
     * Any `Failed` result marks the donation failed, discards quarantined units with ledger audit, and
     * triggers donor clinical deferral through DonorEligibilityService.
     *
     * @param  array{
     *     screening_record_id?: int|string|null,
     *     test_type_id?: int|string|null,
     *     test_type_code?: string|null,
     *     screening_result?: string,
     *     is_confirmatory?: bool,
     *     test_date?: CarbonInterface|string|null,
     *     lab_staff_name?: string|null,
     *     assay_method?: string|null,
     *     specimen_tube_code?: string|null,
     *     test_notes?: string|null,
     *     collected_at?: CarbonInterface|string|null,
     *     logged_at?: CarbonInterface|string|null,
     *     result_recorded_at?: CarbonInterface|string|null
     * }  $attributes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function recordScreeningResult(Donation $donation, ?User $actor, array $attributes): ScreeningRecord
    {
        $this->authorizeAction($actor, 'recordResult', ScreeningRecord::class);

        $validated = $this->validateScreeningResultAttributes($attributes, $actor);

        $persistedRecord = DB::transaction(function () use ($donation, $actor, $validated): ScreeningRecord {
            $lockedDonor = Donor::query()
                ->whereKey($donation->donor_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedDonation = Donation::query()
                ->whereKey($donation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureDonationIsMutableForScreening($lockedDonation);

            $testType = $this->resolveTestTypeForResult($lockedDonation, $validated);

            $record = $this->persistScreeningRecord(
                lockedDonation: $lockedDonation,
                testType: $testType,
                actor: $actor,
                validated: $validated
            );

            if ($record->screening_result === 'Failed') {
                $this->handleFailedScreeningSideEffects(
                    lockedDonor: $lockedDonor,
                    lockedDonation: $lockedDonation,
                    failedRecord: $record,
                    testType: $testType,
                    actor: $actor
                );
            }

            return $record;
        }, 3);

        return $persistedRecord->fresh(['donation', 'testType', 'performedBy']) ?? $persistedRecord;
    }

    /**
     * Evaluate whether a donation satisfies all mandatory screening rules and preconditions for release.
     *
     * @return array{
     *     is_ready_for_release: bool,
     *     blocking_reasons: list<string>,
     *     required_test_codes: list<string>,
     *     passed_test_codes: list<string>,
     *     missing_test_codes: list<string>,
     *     pending_test_codes: list<string>,
     *     failed_test_codes: list<string>
     * }
     */
    public function evaluateReleaseReadiness(Donation $donation): array
    {
        $freshDonation = Donation::query()->whereKey($donation->getKey())->firstOrFail();

        $requirements = ComponentScreeningRequirement::query()
            ->where('blood_component_id', $freshDonation->blood_component_id)
            ->mandatory()
            ->with('testType')
            ->get();

        $records = ScreeningRecord::query()
            ->where('donation_id', $freshDonation->id)
            ->with('testType')
            ->get();

        $primaryUnits = BloodUnit::query()
            ->where('donation_id', $freshDonation->id)
            ->whereNull('parent_unit_id')
            ->get();

        return $this->buildReadinessEvaluation($freshDonation, $requirements, $records, $primaryUnits);
    }

    /**
     * Determine whether all active mandatory component screening requirements have passed with zero pending or failed tests.
     */
    public function hasPassedAllMandatoryRequirements(Donation $donation): bool
    {
        return $this->evaluateReleaseReadiness($donation)['is_ready_for_release'];
    }

    /**
     * Approve screening completion and release quarantined donation unit(s) into available inventory.
     *
     * Requires explicit `screening.release_inventory` permission, completed draw, all active mandatory
     * screening requirements passed, zero pending/failed records, and atomic inventory ledger entry.
     *
     * @param  array{
     *     screening_notes?: string|null,
     *     storage_location?: string|null
     * }  $attributes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function releaseToInventory(Donation $donation, ?User $actor, array $attributes = []): Donation
    {
        $this->authorizeAction($actor, 'releaseToInventory', $donation);

        $screeningNotes = $this->inputNormalizer->normalizeOptionalText($attributes['screening_notes'] ?? null, null, 'screening_notes');
        $storageLocation = $this->inputNormalizer->normalizeOptionalText($attributes['storage_location'] ?? null, 120, 'storage_location');

        $releasedDonation = DB::transaction(function () use ($donation, $actor, $screeningNotes, $storageLocation): Donation {
            Donor::query()
                ->whereKey($donation->donor_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedDonation = Donation::query()
                ->whereKey($donation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $requirements = ComponentScreeningRequirement::query()
                ->where('blood_component_id', $lockedDonation->blood_component_id)
                ->mandatory()
                ->with('testType')
                ->get();

            $records = ScreeningRecord::query()
                ->where('donation_id', $lockedDonation->id)
                ->with('testType')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // Global lock order: donors -> donations -> screening_records -> inventory_stocks -> blood_units (id ASC).
            $lockedStock = $this->ensureAndLockInventoryStock(
                $lockedDonation->blood_group,
                $lockedDonation->blood_component_id
            );

            $primaryUnits = BloodUnit::query()
                ->where('donation_id', $lockedDonation->id)
                ->whereNull('parent_unit_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $evaluation = $this->buildReadinessEvaluation($lockedDonation, $requirements, $records, $primaryUnits);

            if (! $evaluation['is_ready_for_release']) {
                throw ValidationException::withMessages([
                    'screening_release' => implode(' ', $evaluation['blocking_reasons']),
                ]);
            }

            $component = BloodComponent::query()
                ->whereKey($lockedDonation->blood_component_id)
                ->firstOrFail();

            if (! $component->is_active) {
                throw ValidationException::withMessages([
                    'blood_component_id' => 'Blood component is inactive and cannot be released into inventory.',
                ]);
            }

            foreach ($primaryUnits as $unit) {
                if (
                    $unit->blood_group !== $lockedStock->blood_group
                    || (int) $unit->blood_component_id !== (int) $lockedStock->blood_component_id
                ) {
                    throw ValidationException::withMessages([
                        'blood_unit_id' => 'Blood unit group or component does not match donation stock bucket.',
                    ]);
                }
            }

            $releaseTimestamp = CarbonImmutable::now();

            $lockedDonation->screening_status = 'Passed';
            $lockedDonation->released_to_inventory_at = $releaseTimestamp;
            $lockedDonation->approved_by_user_id = $actor->getKey();

            if ($screeningNotes !== null) {
                $lockedDonation->screening_notes = $screeningNotes;
            }

            $lockedDonation->save();

            $runningAvailable = $lockedStock->available_units;
            $reservedUnits = $lockedStock->reserved_units;

            foreach ($primaryUnits as $unit) {
                $collectedAt = $unit->collected_at?->toImmutable()
                    ?? $lockedDonation->collected_at?->toImmutable()
                    ?? $releaseTimestamp;

                $unit->unit_status = 'Available';
                $unit->released_at = $releaseTimestamp;
                $unit->released_by_user_id = $actor->getKey();
                $unit->expires_at = $collectedAt->addDays($component->shelf_life_days);

                if ($storageLocation !== null) {
                    $unit->storage_location = $storageLocation;
                }

                $unit->save();

                $availableBefore = $runningAvailable;
                $runningAvailable++;

                InventoryTransaction::query()->create([
                    'transaction_code' => $this->codeGenerator->generate(InventoryTransaction::class, 'transaction_code', 'TXN-', 7),
                    'inventory_stock_id' => $lockedStock->id,
                    'blood_unit_id' => $unit->id,
                    'operation_type' => 'Stock In',
                    'units_delta' => 1,
                    'available_before' => $availableBefore,
                    'available_after' => $runningAvailable,
                    'reserved_before' => $reservedUnits,
                    'reserved_after' => $reservedUnits,
                    'reference_type' => 'donation',
                    'reference_id' => $lockedDonation->id,
                    'performed_by_user_id' => $actor->getKey(),
                    'actor_name' => $actor->name,
                    'description' => "Screened unit {$unit->unit_code} ({$lockedDonation->donation_code}) approved and released to available inventory.",
                    'occurred_at' => $releaseTimestamp,
                ]);
            }

            $lockedStock->available_units = $runningAvailable;
            $lockedStock->last_adjusted_at = $releaseTimestamp;
            $lockedStock->save();

            return $lockedDonation;
        }, 3);

        return $releasedDonation->fresh([
            'donor',
            'bloodComponent',
            'screeningRecords.testType',
            'bloodUnits',
            'approvedBy',
        ]) ?? $releasedDonation;
    }

    /**
     * Authorize the actor against ScreeningRecordPolicy or DonationPolicy after validating authentication.
     *
     * @throws AuthorizationException
     */
    private function authorizeAction(?User $actor, string $ability, mixed $arguments): void
    {
        if (! $actor instanceof User) {
            throw new AuthorizationException('An authenticated and authorized user is required for screening operations.');
        }

        Gate::forUser($actor)->authorize($ability, $arguments);
    }

    /**
     * Ensure the donation has completed collection and has not already been locked by inventory release.
     *
     * @throws ValidationException
     */
    private function ensureDonationIsMutableForScreening(Donation $lockedDonation): void
    {
        if ($lockedDonation->collection_status !== 'Completed') {
            throw ValidationException::withMessages([
                'collection_status' => 'Laboratory screening requires a Completed blood collection.',
            ]);
        }

        if ($lockedDonation->released_to_inventory_at !== null) {
            throw ValidationException::withMessages([
                'released_to_inventory_at' => 'Screening records cannot be modified after inventory release.',
            ]);
        }
    }

    /**
     * Persist or settle a screening record while enforcing strict immutability of finalized results.
     *
     * @param  array{
     *     screening_record_id: ?int,
     *     screening_result: string,
     *     is_confirmatory: bool,
     *     test_date: ?string,
     *     lab_staff_name: string,
     *     assay_method: ?string,
     *     specimen_tube_code: ?string,
     *     test_notes: ?string,
     *     collected_at: ?CarbonImmutable,
     *     logged_at: ?CarbonImmutable,
     *     result_recorded_at: ?CarbonImmutable
     * }  $validated
     *
     * @throws ValidationException
     */
    private function persistScreeningRecord(
        Donation $lockedDonation,
        ScreeningTestType $testType,
        User $actor,
        array $validated
    ): ScreeningRecord {
        $now = CarbonImmutable::now();
        $result = $validated['screening_result'];
        $isFinalResult = in_array($result, ['Passed', 'Failed'], true);
        $testDate = $isFinalResult ? ($validated['test_date'] ?? $now->toDateString()) : null;
        $recordedAt = $isFinalResult ? ($validated['result_recorded_at'] ?? $now) : null;

        if ($validated['screening_record_id'] !== null) {
            $existing = ScreeningRecord::query()
                ->where('donation_id', $lockedDonation->id)
                ->whereKey($validated['screening_record_id'])
                ->lockForUpdate()
                ->first();

            if (! $existing instanceof ScreeningRecord) {
                throw ValidationException::withMessages([
                    'screening_record_id' => 'Screening record does not belong to this donation.',
                ]);
            }

            if ($existing->screening_result !== 'Pending') {
                throw ValidationException::withMessages([
                    'screening_result' => 'Finalized screening records are immutable; log a confirmatory record instead.',
                ]);
            }

            if (! $isFinalResult) {
                throw ValidationException::withMessages([
                    'screening_result' => 'Updating a pending screening record requires a Passed or Failed outcome.',
                ]);
            }

            $existing->fill([
                'test_date' => $testDate,
                'screening_result' => $result,
                'performed_by_user_id' => $actor->getKey(),
                'lab_staff_name' => $validated['lab_staff_name'],
                'assay_method' => $validated['assay_method'] ?? $existing->assay_method,
                'specimen_tube_code' => $validated['specimen_tube_code'] ?? $existing->specimen_tube_code,
                'test_notes' => $validated['test_notes'] ?? $existing->test_notes,
                'result_recorded_at' => $recordedAt,
            ]);
            $existing->save();

            return $existing;
        }

        if (! $validated['is_confirmatory']) {
            $existingRecords = ScreeningRecord::query()
                ->where('donation_id', $lockedDonation->id)
                ->where('test_type_id', $testType->id)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            $pendingRecord = $existingRecords->firstWhere('screening_result', 'Pending');

            if ($pendingRecord instanceof ScreeningRecord) {
                if (! $isFinalResult) {
                    throw ValidationException::withMessages([
                        'screening_result' => 'A pending screening record already exists for this test panel.',
                    ]);
                }

                $pendingRecord->fill([
                    'test_date' => $testDate,
                    'screening_result' => $result,
                    'performed_by_user_id' => $actor->getKey(),
                    'lab_staff_name' => $validated['lab_staff_name'],
                    'assay_method' => $validated['assay_method'] ?? $pendingRecord->assay_method,
                    'specimen_tube_code' => $validated['specimen_tube_code'] ?? $pendingRecord->specimen_tube_code,
                    'test_notes' => $validated['test_notes'] ?? $pendingRecord->test_notes,
                    'result_recorded_at' => $recordedAt,
                ]);
                $pendingRecord->save();

                return $pendingRecord;
            }

            $hasFinalizedRecord = $existingRecords->contains(
                static fn (ScreeningRecord $record): bool => in_array($record->screening_result, ['Passed', 'Failed'], true)
            );

            if ($hasFinalizedRecord) {
                throw ValidationException::withMessages([
                    'test_type_id' => 'A finalized screening result already exists for this test panel; submit a confirmatory assay instead.',
                ]);
            }
        }

        return ScreeningRecord::query()->create([
            'screening_code' => $this->codeGenerator->generate(ScreeningRecord::class, 'screening_code', 'SCR-'),
            'donation_id' => $lockedDonation->id,
            'test_type_id' => $testType->id,
            'test_date' => $testDate,
            'screening_result' => $result,
            'is_confirmatory' => $validated['is_confirmatory'],
            'performed_by_user_id' => $actor->getKey(),
            'lab_staff_name' => $validated['lab_staff_name'],
            'assay_method' => $validated['assay_method'],
            'specimen_tube_code' => $validated['specimen_tube_code'],
            'test_notes' => $validated['test_notes'],
            'collected_at' => $validated['collected_at'] ?? $lockedDonation->collected_at,
            'logged_at' => $validated['logged_at'] ?? $now,
            'result_recorded_at' => $recordedAt,
        ]);
    }

    /**
     * Quarantine-discard linked units, append audit ledger events, and defer the donor on failed screening.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function handleFailedScreeningSideEffects(
        Donor $lockedDonor,
        Donation $lockedDonation,
        ScreeningRecord $failedRecord,
        ScreeningTestType $testType,
        User $actor
    ): void {
        $lockedDonation->screening_status = 'Failed';
        $lockedDonation->save();

        // Global lock order: donors -> donations -> screening_records -> inventory_stocks -> blood_units (id ASC).
        $lockedStock = $this->ensureAndLockInventoryStock(
            $lockedDonation->blood_group,
            $lockedDonation->blood_component_id
        );

        $discardableUnits = BloodUnit::query()
            ->where('donation_id', $lockedDonation->id)
            ->where('unit_status', 'Quarantined')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($discardableUnits->isNotEmpty()) {
            $occurredAt = CarbonImmutable::now();

            foreach ($discardableUnits as $unit) {
                $unit->unit_status = 'Discarded';
                $unit->save();

                InventoryTransaction::query()->create([
                    'transaction_code' => $this->codeGenerator->generate(InventoryTransaction::class, 'transaction_code', 'TXN-', 7),
                    'inventory_stock_id' => $lockedStock->id,
                    'blood_unit_id' => $unit->id,
                    'operation_type' => 'Discarded',
                    'units_delta' => 0,
                    'available_before' => $lockedStock->available_units,
                    'available_after' => $lockedStock->available_units,
                    'reserved_before' => $lockedStock->reserved_units,
                    'reserved_after' => $lockedStock->reserved_units,
                    'reference_type' => 'donation',
                    'reference_id' => $lockedDonation->id,
                    'performed_by_user_id' => $actor->getKey(),
                    'actor_name' => $actor->name,
                    'description' => "Quarantined unit {$unit->unit_code} discarded after reactive/failed {$testType->name} screening ({$failedRecord->screening_code}).",
                    'occurred_at' => $occurredAt,
                ]);
            }
        }

        $deferralReason = "Failed {$testType->name} laboratory screening ({$failedRecord->screening_code}) on donation {$lockedDonation->donation_code}.";

        $this->eligibilityService->recordFailedScreeningDeferral(
            donor: $lockedDonor,
            failedScreeningRecord: $failedRecord,
            triggeredBy: $actor,
            reason: $deferralReason,
            clearanceSource: 'Automated Failed Screening Deferral'
        );
    }

    /**
     * Evaluate mandatory component screening rules, pending/failed blocks, and unit readiness.
     *
     * @param  EloquentCollection<int, ComponentScreeningRequirement>  $requirements
     * @param  EloquentCollection<int, ScreeningRecord>  $records
     * @param  EloquentCollection<int, BloodUnit>  $primaryUnits
     * @return array{
     *     is_ready_for_release: bool,
     *     blocking_reasons: list<string>,
     *     required_test_codes: list<string>,
     *     passed_test_codes: list<string>,
     *     missing_test_codes: list<string>,
     *     pending_test_codes: list<string>,
     *     failed_test_codes: list<string>
     * }
     */
    private function buildReadinessEvaluation(
        Donation $donation,
        EloquentCollection $requirements,
        EloquentCollection $records,
        EloquentCollection $primaryUnits
    ): array {
        $blockingReasons = [];

        if ($donation->collection_status !== 'Completed') {
            $blockingReasons[] = 'Donation collection must be Completed before screening release.';
        }

        if ($donation->released_to_inventory_at !== null) {
            $blockingReasons[] = 'Donation units have already been released to inventory.';
        }

        if ($donation->screening_status === 'Failed') {
            $blockingReasons[] = 'Donation screening status is Failed and permanently barred from release.';
        }

        $activeRequirements = $requirements->filter(
            static fn (ComponentScreeningRequirement $req): bool => $req->testType instanceof ScreeningTestType && $req->testType->is_active
        );

        if ($activeRequirements->isEmpty()) {
            $blockingReasons[] = 'No active mandatory component screening requirements are configured.';
        }

        $requiredTestCodes = $activeRequirements
            ->map(static fn (ComponentScreeningRequirement $req): string => (string) $req->testType?->code)
            ->values()
            ->all();

        $pendingRecords = $records->where('screening_result', 'Pending');
        $failedRecords = $records->where('screening_result', 'Failed');
        $passedTestIds = $records
            ->where('screening_result', 'Passed')
            ->pluck('test_type_id')
            ->unique()
            ->all();

        $pendingTestCodes = $pendingRecords
            ->map(static fn (ScreeningRecord $record): string => (string) ($record->testType?->code ?? $record->test_type_id))
            ->unique()
            ->values()
            ->all();

        $failedTestCodes = $failedRecords
            ->map(static fn (ScreeningRecord $record): string => (string) ($record->testType?->code ?? $record->test_type_id))
            ->unique()
            ->values()
            ->all();

        $passedTestCodes = [];
        $missingTestCodes = [];

        foreach ($activeRequirements as $requirement) {
            $code = (string) $requirement->testType?->code;

            if (in_array($requirement->test_type_id, $passedTestIds, true)) {
                $passedTestCodes[] = $code;
            } else {
                $missingTestCodes[] = $code;
            }
        }

        if ($failedTestCodes !== []) {
            $blockingReasons[] = 'Failed screening tests present: '.implode(', ', $failedTestCodes).'.';
        }

        if ($pendingTestCodes !== []) {
            $blockingReasons[] = 'Pending screening tests must be completed: '.implode(', ', $pendingTestCodes).'.';
        }

        if ($missingTestCodes !== []) {
            $blockingReasons[] = 'Missing required screening tests: '.implode(', ', $missingTestCodes).'.';
        }

        if ($primaryUnits->isEmpty()) {
            $blockingReasons[] = 'No primary quarantined blood unit exists for this donation.';
        } elseif ($primaryUnits->contains(static fn (BloodUnit $unit): bool => $unit->unit_status !== 'Quarantined')) {
            $blockingReasons[] = 'Primary blood unit is not in Quarantined status.';
        }

        return [
            'is_ready_for_release' => $blockingReasons === [],
            'blocking_reasons' => $blockingReasons,
            'required_test_codes' => $requiredTestCodes,
            'passed_test_codes' => array_values(array_unique($passedTestCodes)),
            'missing_test_codes' => $missingTestCodes,
            'pending_test_codes' => $pendingTestCodes,
            'failed_test_codes' => $failedTestCodes,
        ];
    }

    /**
     * Ensure the `(blood_group, blood_component_id)` stock bucket exists and lock it exclusively.
     */
    private function ensureAndLockInventoryStock(string $bloodGroup, int $bloodComponentId): InventoryStock
    {
        $now = CarbonImmutable::now();

        DB::table('inventory_stocks')->insertOrIgnore([
            'blood_group' => $bloodGroup,
            'blood_component_id' => $bloodComponentId,
            'available_units' => 0,
            'reserved_units' => 0,
            'minimum_level' => 20,
            'target_capacity' => 100,
            'last_adjusted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return InventoryStock::query()
            ->where('blood_group', $bloodGroup)
            ->where('blood_component_id', $bloodComponentId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Resolve and validate the target screening test type, inferring from `screening_record_id` when supplied.
     *
     * @param  array{
     *     screening_record_id: ?int,
     *     test_type_id: ?int,
     *     test_type_code: ?string
     * }  $validated
     *
     * @throws ValidationException
     */
    private function resolveTestTypeForResult(Donation $lockedDonation, array $validated): ScreeningTestType
    {
        if ($validated['screening_record_id'] !== null && $validated['test_type_id'] === null && $validated['test_type_code'] === null) {
            $recordTestTypeId = ScreeningRecord::query()
                ->where('donation_id', $lockedDonation->id)
                ->whereKey($validated['screening_record_id'])
                ->value('test_type_id');

            if ($recordTestTypeId === null) {
                throw ValidationException::withMessages([
                    'screening_record_id' => 'Screening record does not belong to this donation.',
                ]);
            }

            return $this->resolveActiveTestType((int) $recordTestTypeId, null);
        }

        return $this->resolveActiveTestType($validated['test_type_id'], $validated['test_type_code']);
    }

    /**
     * Resolve an active screening test type from ID or code.
     *
     * @throws ValidationException
     */
    private function resolveActiveTestType(?int $testTypeId, ?string $testTypeCode): ScreeningTestType
    {
        $query = ScreeningTestType::query();

        if ($testTypeId !== null) {
            $testType = $query->whereKey($testTypeId)->first();
        } else {
            $testType = $query->where('code', $testTypeCode)->first();
        }

        if (! $testType instanceof ScreeningTestType || ! $testType->is_active) {
            throw ValidationException::withMessages([
                'test_type_id' => 'Selected screening test type does not exist or is inactive.',
            ]);
        }

        return $testType;
    }

    /**
     * Validate and normalize screening result attributes before acquiring row locks.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     screening_record_id: ?int,
     *     test_type_id: ?int,
     *     test_type_code: ?string,
     *     screening_result: string,
     *     is_confirmatory: bool,
     *     test_date: ?string,
     *     lab_staff_name: string,
     *     assay_method: ?string,
     *     specimen_tube_code: ?string,
     *     test_notes: ?string,
     *     collected_at: ?CarbonImmutable,
     *     logged_at: ?CarbonImmutable,
     *     result_recorded_at: ?CarbonImmutable
     * }
     *
     * @throws ValidationException
     */
    private function validateScreeningResultAttributes(array $attributes, User $actor): array
    {
        $recordId = isset($attributes['screening_record_id']) && $attributes['screening_record_id'] !== ''
            ? (int) $attributes['screening_record_id']
            : null;
        $testTypeId = isset($attributes['test_type_id']) && $attributes['test_type_id'] !== ''
            ? (int) $attributes['test_type_id']
            : null;
        $testTypeCode = isset($attributes['test_type_code']) && is_string($attributes['test_type_code'])
            ? trim($attributes['test_type_code'])
            : null;

        if ($recordId === null && $testTypeId === null && ($testTypeCode === null || $testTypeCode === '')) {
            throw ValidationException::withMessages([
                'test_type_id' => 'A screening test type ID or code is required.',
            ]);
        }

        $result = $attributes['screening_result'] ?? null;

        if (! is_string($result) || ! in_array($result, ScreeningRecord::SCREENING_RESULTS, true)) {
            throw ValidationException::withMessages([
                'screening_result' => 'Screening result must be one of: '.implode(', ', ScreeningRecord::SCREENING_RESULTS).'.',
            ]);
        }

        $labStaffName = array_key_exists('lab_staff_name', $attributes)
            ? $this->inputNormalizer->normalizeRequiredText($attributes['lab_staff_name'], 150, 'lab_staff_name', 'Lab staff name is required.')
            : $this->inputNormalizer->normalizeRequiredText($actor->name, 150, 'lab_staff_name', 'Lab staff name is required.');

        $testDate = $this->inputNormalizer->parseOptionalDate($attributes['test_date'] ?? null, 'test_date');

        return [
            'screening_record_id' => $recordId,
            'test_type_id' => $testTypeId,
            'test_type_code' => $testTypeCode !== '' ? $testTypeCode : null,
            'screening_result' => $result,
            'is_confirmatory' => (bool) ($attributes['is_confirmatory'] ?? false),
            'test_date' => $testDate,
            'lab_staff_name' => $labStaffName,
            'assay_method' => $this->inputNormalizer->normalizeOptionalText($attributes['assay_method'] ?? null, 191, 'assay_method'),
            'specimen_tube_code' => $this->inputNormalizer->normalizeOptionalText($attributes['specimen_tube_code'] ?? null, 64, 'specimen_tube_code'),
            'test_notes' => $this->inputNormalizer->normalizeOptionalText($attributes['test_notes'] ?? null, null, 'test_notes'),
            'collected_at' => $this->inputNormalizer->parseOptionalDateTime($attributes['collected_at'] ?? null, 'collected_at'),
            'logged_at' => $this->inputNormalizer->parseOptionalDateTime($attributes['logged_at'] ?? null, 'logged_at'),
            'result_recorded_at' => $this->inputNormalizer->parseOptionalDateTime($attributes['result_recorded_at'] ?? null, 'result_recorded_at'),
        ];
    }
}
