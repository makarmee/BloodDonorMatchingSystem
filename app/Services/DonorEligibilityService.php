<?php

namespace App\Services;

use App\Models\Donation;
use App\Models\Donor;
use App\Models\DonorEligibilityRecord;
use App\Models\ScreeningRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class DonorEligibilityService
{
    /**
     * Valid initial onboarding eligibility statuses that do not grant collection clearance.
     *
     * @var list<string>
     */
    private const array ONBOARDING_STATUSES = ['Pending Review', 'Unknown'];

    /**
     * Record a new authoritative eligibility assessment and synchronize the donor's read-only projections.
     *
     * @param  array{
     *     eligibility_status?: string,
     *     reason?: string,
     *     clearance_source?: string,
     *     reviewed_at?: CarbonInterface|string|null,
     *     next_eligible_date?: CarbonInterface|string|null
     * }  $attributes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function recordAssessment(
        Donor $donor,
        ?User $actor,
        array $attributes,
        ?int $expectedCurrentRecordId = null
    ): DonorEligibilityRecord {
        $requestedStatus = is_string($attributes['eligibility_status'] ?? null)
            ? $attributes['eligibility_status']
            : null;

        $this->authorizeAssessment($actor, $donor, $requestedStatus);
        $validated = $this->validateAssessmentAttributes($attributes);

        return $this->persistAuthoritativeRecord(
            donor: $donor,
            actor: $actor,
            status: $validated['eligibility_status'],
            reason: $validated['reason'],
            clearanceSource: $validated['clearance_source'],
            reviewedAt: $validated['reviewed_at'],
            nextEligibleDate: $validated['next_eligible_date'],
            expectedCurrentRecordId: $expectedCurrentRecordId,
            requireEmptyHistory: false,
        );
    }

    /**
     * Clear a donor as Eligible following authorized medical review.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function approveEligibility(
        Donor $donor,
        ?User $actor,
        string $reason,
        string $clearanceSource = 'Medical Officer Clearance',
        CarbonInterface|string|null $reviewedAt = null,
        CarbonInterface|string|null $nextEligibleDate = null,
        ?int $expectedCurrentRecordId = null
    ): DonorEligibilityRecord {
        return $this->recordAssessment($donor, $actor, [
            'eligibility_status' => 'Eligible',
            'reason' => $reason,
            'clearance_source' => $clearanceSource,
            'reviewed_at' => $reviewedAt,
            'next_eligible_date' => $nextEligibleDate,
        ], $expectedCurrentRecordId);
    }

    /**
     * Record a temporary or ongoing clinical deferral (`Not Eligible`) for a donor.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function deferDonor(
        Donor $donor,
        ?User $actor,
        string $reason,
        CarbonInterface|string|null $nextEligibleDate = null,
        string $clearanceSource = 'Medical Officer Deferral',
        CarbonInterface|string|null $reviewedAt = null,
        ?int $expectedCurrentRecordId = null
    ): DonorEligibilityRecord {
        return $this->recordAssessment($donor, $actor, [
            'eligibility_status' => 'Not Eligible',
            'reason' => $reason,
            'clearance_source' => $clearanceSource,
            'reviewed_at' => $reviewedAt,
            'next_eligible_date' => $nextEligibleDate,
        ], $expectedCurrentRecordId);
    }

    /**
     * Record a lab-screening deferral (`Not Eligible`) triggered by reactive/failed test findings.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function recordScreeningDeferral(
        Donor $donor,
        ?User $actor,
        string $reason,
        string $clearanceSource = 'Laboratory Screening Deferral',
        CarbonInterface|string|null $nextEligibleDate = null,
        CarbonInterface|string|null $reviewedAt = null,
        ?int $expectedCurrentRecordId = null
    ): DonorEligibilityRecord {
        return $this->deferDonor(
            donor: $donor,
            actor: $actor,
            reason: $reason,
            nextEligibleDate: $nextEligibleDate,
            clearanceSource: $clearanceSource,
            reviewedAt: $reviewedAt,
            expectedCurrentRecordId: $expectedCurrentRecordId,
        );
    }

    /**
     * Record an automated `Not Eligible` deferral backed strictly by a persisted `Failed` screening record.
     *
     * Keeps `reviewed_by_user_id`, `reviewed_at`, and `next_eligible_date` null so an automated safety hold
     * triggered by laboratory result entry is never confused with a final medical officer eligibility decision.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function recordFailedScreeningDeferral(
        Donor $donor,
        ScreeningRecord $failedScreeningRecord,
        ?User $triggeredBy,
        ?string $reason = null,
        string $clearanceSource = 'Automated Failed Screening Deferral'
    ): DonorEligibilityRecord {
        if (! $triggeredBy instanceof User || ! $triggeredBy->is_active) {
            throw new AuthorizationException('An authenticated active user is required to record a failed-screening deferral.');
        }

        if (! $triggeredBy->hasRole('admin') && ! Gate::forUser($triggeredBy)->allows('recordResult', ScreeningRecord::class)) {
            throw new AuthorizationException('The acting user is not authorized to record screening-triggered deferrals.');
        }

        $effectiveReason = is_string($reason) && trim($reason) !== ''
            ? trim($reason)
            : "Automated clinical deferral triggered by reactive/failed screening assay {$failedScreeningRecord->screening_code}.";

        $validated = $this->validateAssessmentAttributes([
            'eligibility_status' => 'Not Eligible',
            'reason' => $effectiveReason,
            'clearance_source' => $clearanceSource,
            'reviewed_at' => null,
            'next_eligible_date' => null,
        ]);

        return DB::transaction(function () use ($donor, $failedScreeningRecord, $validated): DonorEligibilityRecord {
            $lockedDonor = Donor::query()
                ->whereKey($donor->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRecord = ScreeningRecord::query()
                ->whereKey($failedScreeningRecord->getKey())
                ->lockForUpdate()
                ->first();

            $lockedDonation = $lockedRecord instanceof ScreeningRecord
                ? Donation::query()->whereKey($lockedRecord->donation_id)->lockForUpdate()->first()
                : null;

            if (
                ! $lockedRecord instanceof ScreeningRecord
                || ! $lockedDonation instanceof Donation
                || (int) $lockedDonation->donor_id !== (int) $lockedDonor->id
                || $lockedRecord->screening_result !== 'Failed'
            ) {
                throw ValidationException::withMessages([
                    'screening_record' => 'Automated deferral requires a persisted Failed screening record belonging to this donor.',
                ]);
            }

            return $this->persistAuthoritativeRecord(
                donor: $lockedDonor,
                actor: null,
                status: $validated['eligibility_status'],
                reason: $validated['reason'],
                clearanceSource: $validated['clearance_source'],
                reviewedAt: null,
                nextEligibleDate: null,
                expectedCurrentRecordId: null,
                requireEmptyHistory: false,
            );
        }, 3);
    }

    /**
     * Initialize the first authoritative unreviewed eligibility record for a newly onboarded donor.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function initializeOnboardingEligibility(
        Donor $donor,
        ?User $actor = null,
        string $status = 'Pending Review',
        string $reason = 'Initial donor onboarding awaiting clinical eligibility review.',
        string $clearanceSource = 'Donor Registration Onboarding'
    ): DonorEligibilityRecord {
        if ($actor instanceof User) {
            $this->authorizeAssessment($actor, $donor, $status);
        }

        if (! in_array($status, self::ONBOARDING_STATUSES, true)) {
            throw ValidationException::withMessages([
                'eligibility_status' => 'Initial onboarding eligibility may only start as Pending Review or Unknown.',
            ]);
        }

        $trimmedReason = trim($reason);
        $trimmedSource = trim($clearanceSource);

        if ($trimmedReason === '') {
            throw ValidationException::withMessages([
                'reason' => 'An onboarding eligibility reason is required.',
            ]);
        }

        if ($trimmedSource === '' || mb_strlen($trimmedSource) > 191) {
            throw ValidationException::withMessages([
                'clearance_source' => 'Clearance source must be between 1 and 191 characters.',
            ]);
        }

        return $this->persistAuthoritativeRecord(
            donor: $donor,
            actor: $actor,
            status: $status,
            reason: $trimmedReason,
            clearanceSource: $trimmedSource,
            reviewedAt: null,
            nextEligibleDate: null,
            expectedCurrentRecordId: null,
            requireEmptyHistory: true,
        );
    }

    /**
     * Retrieve the donor's authoritative current eligibility record (`is_current = true`).
     */
    public function getCurrentRecord(Donor $donor): ?DonorEligibilityRecord
    {
        return DonorEligibilityRecord::query()
            ->where('donor_id', $donor->getKey())
            ->current()
            ->with('reviewedBy:id,name')
            ->first();
    }

    /**
     * Retrieve the full chronological eligibility audit trail (current record first, newest to oldest).
     *
     * @return Collection<int, DonorEligibilityRecord>
     */
    public function getHistory(Donor $donor): Collection
    {
        return DonorEligibilityRecord::query()
            ->where('donor_id', $donor->getKey())
            ->with('reviewedBy:id,name')
            ->orderByDesc('is_current')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Determine eligibility strictly from the authoritative current eligibility record, never from dates alone.
     */
    public function isCurrentlyEligible(Donor $donor): bool
    {
        if ($donor->getKey() === null) {
            return false;
        }

        return DonorEligibilityRecord::query()
            ->where('donor_id', $donor->getKey())
            ->current()
            ->withStatus('Eligible')
            ->exists();
    }

    /**
     * Authorize the actor for recording an eligibility assessment on the given donor.
     *
     * @throws AuthorizationException
     */
    private function authorizeAssessment(?User $actor, Donor $donor, ?string $targetStatus = null): void
    {
        if (! $actor instanceof User) {
            throw new AuthorizationException('An authenticated and authorized user is required to update donor eligibility.');
        }

        Gate::forUser($actor)->authorize('assessEligibility', [$donor, $targetStatus]);
    }

    /**
     * Validate and normalize eligibility assessment attributes before locking database rows.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     eligibility_status: string,
     *     reason: string,
     *     clearance_source: string,
     *     reviewed_at: ?string,
     *     next_eligible_date: ?string
     * }
     *
     * @throws ValidationException
     */
    private function validateAssessmentAttributes(array $attributes): array
    {
        $status = $attributes['eligibility_status'] ?? null;

        if (! is_string($status) || ! in_array($status, Donor::ELIGIBILITY_STATUSES, true)) {
            throw ValidationException::withMessages([
                'eligibility_status' => 'Eligibility status must be one of: '.implode(', ', Donor::ELIGIBILITY_STATUSES).'.',
            ]);
        }

        $reason = is_string($attributes['reason'] ?? null) ? trim($attributes['reason']) : '';

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A documented clinical assessment reason is required.',
            ]);
        }

        $clearanceSource = is_string($attributes['clearance_source'] ?? null)
            ? trim($attributes['clearance_source'])
            : '';

        if ($clearanceSource === '' || mb_strlen($clearanceSource) > 191) {
            throw ValidationException::withMessages([
                'clearance_source' => 'Clearance source must be between 1 and 191 characters.',
            ]);
        }

        $reviewedAt = $this->normalizeOptionalDate($attributes['reviewed_at'] ?? null, 'reviewed_at');

        if ($reviewedAt === null && in_array($status, ['Eligible', 'Not Eligible'], true)) {
            $reviewedAt = CarbonImmutable::today()->toDateString();
        }

        $nextEligibleDate = $this->normalizeOptionalDate(
            $attributes['next_eligible_date'] ?? null,
            'next_eligible_date'
        );

        return [
            'eligibility_status' => $status,
            'reason' => $reason,
            'clearance_source' => $clearanceSource,
            'reviewed_at' => $reviewedAt,
            'next_eligible_date' => $nextEligibleDate,
        ];
    }

    /**
     * Parse an optional date input into `Y-m-d` string format or throw ValidationException.
     *
     * @throws ValidationException
     */
    private function normalizeOptionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages([
                $field => "The {$field} value must be a valid calendar date.",
            ]);
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $field => "The {$field} value must be a valid calendar date.",
            ]);
        }
    }

    /**
     * Atomically supersede active records, insert the new authoritative record, and sync donor projections.
     *
     * @throws ValidationException
     */
    private function persistAuthoritativeRecord(
        Donor $donor,
        ?User $actor,
        string $status,
        string $reason,
        string $clearanceSource,
        ?string $reviewedAt,
        ?string $nextEligibleDate,
        ?int $expectedCurrentRecordId,
        bool $requireEmptyHistory
    ): DonorEligibilityRecord {
        $persistedRecord = DB::transaction(function () use (
            $donor,
            $actor,
            $status,
            $reason,
            $clearanceSource,
            $reviewedAt,
            $nextEligibleDate,
            $expectedCurrentRecordId,
            $requireEmptyHistory
        ): DonorEligibilityRecord {
            // Lock parent donor first so concurrent assessments serialize identically across callers.
            $lockedDonor = Donor::query()
                ->whereKey($donor->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($requireEmptyHistory) {
                $hasExistingRecords = DonorEligibilityRecord::query()
                    ->where('donor_id', $lockedDonor->id)
                    ->lockForUpdate()
                    ->exists();

                if ($hasExistingRecords) {
                    throw ValidationException::withMessages([
                        'eligibility_record' => 'Initial eligibility has already been recorded for this donor.',
                    ]);
                }
            }

            $activeRecords = DonorEligibilityRecord::query()
                ->where('donor_id', $lockedDonor->id)
                ->where('is_current', true)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            $activeRecordId = $activeRecords->first()?->id;

            if ($expectedCurrentRecordId !== null && $activeRecordId !== $expectedCurrentRecordId) {
                throw ValidationException::withMessages([
                    'eligibility_record' => 'Donor eligibility was modified by another review. Reload the latest status before saving.',
                ]);
            }

            foreach ($activeRecords as $activeRecord) {
                $activeRecord->is_current = false;
                $activeRecord->save();
            }

            $newRecord = DonorEligibilityRecord::query()->create([
                'donor_id' => $lockedDonor->id,
                'eligibility_status' => $status,
                'reason' => $reason,
                'clearance_source' => $clearanceSource,
                'reviewed_at' => $reviewedAt,
                'next_eligible_date' => $nextEligibleDate,
                'reviewed_by_user_id' => $actor?->getKey(),
                'is_current' => true,
            ]);

            $lockedDonor->eligibility_status = $newRecord->eligibility_status;
            $lockedDonor->next_eligible_date = $newRecord->next_eligible_date;
            $lockedDonor->save();

            return $newRecord;
        }, 3);

        $donor->refresh();

        return $persistedRecord->fresh(['donor', 'reviewedBy']) ?? $persistedRecord;
    }
}
