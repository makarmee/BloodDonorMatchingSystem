<?php

namespace App\Services;

use App\Models\BloodCompatibilityRule;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodRequestStatusHistory;
use App\Models\BloodUnit;
use App\Models\Hospital;
use App\Models\InventoryAllocation;
use App\Models\InventoryStock;
use App\Models\RequestFulfillment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class FulfillmentService
{
    public function __construct(
        private InventoryService $inventoryService,
        private ReferenceCodeGenerator $codeGenerator,
        private ServiceInputNormalizer $inputNormalizer
    ) {}

    /**
     * Submit a new blood request on behalf of an active, verified partner hospital.
     *
     * @param  array{
     *     blood_group?: string,
     *     blood_component_id?: int|string|null,
     *     blood_component_code?: string|null,
     *     requested_units?: int|string,
     *     priority?: string|null,
     *     request_date?: CarbonInterface|string|null,
     *     required_date?: CarbonInterface|string|null,
     *     request_reason?: string|null,
     *     additional_notes?: string|null,
     *     department_or_ward?: string|null,
     *     contact_person_snapshot?: string|null,
     *     contact_phone_snapshot?: string|null,
     *     contact_email_snapshot?: string|null
     * }  $attributes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function createRequest(Hospital $hospital, ?User $actor, array $attributes): BloodRequest
    {
        $actingUser = $this->authorizeAction($actor, 'create', [BloodRequest::class, $hospital]);

        $bloodGroup = $this->validateBloodGroup($attributes['blood_group'] ?? null, 'blood_group');
        $componentId = $this->parseOptionalPositiveInt($attributes['blood_component_id'] ?? null, 'blood_component_id');
        $componentCode = $this->inputNormalizer->normalizeOptionalText($attributes['blood_component_code'] ?? null, 32, 'blood_component_code');
        $component = ($componentId !== null || $componentCode !== null)
            ? $this->resolveActiveComponent($componentId, $componentCode, 'blood_component_id')
            : null;

        $requestedUnits = $this->parsePositiveInt($attributes['requested_units'] ?? null, 'requested_units', 'Requested units must be at least 1.');
        $priority = $this->validatePriority($attributes['priority'] ?? 'Normal');

        $requestDate = $this->inputNormalizer->parseRequiredOrDefaultDate($attributes['request_date'] ?? null, 'request_date');
        $requiredDate = $this->inputNormalizer->parseOptionalDate($attributes['required_date'] ?? null, 'required_date') ?? $requestDate;

        if ($requiredDate < $requestDate) {
            throw ValidationException::withMessages([
                'required_date' => 'The required_date must be on or after request_date.',
            ]);
        }

        $requestReason = $this->inputNormalizer->normalizeRequiredText(
            $attributes['request_reason'] ?? null,
            2000,
            'request_reason',
            'A clinical request reason is required.'
        );
        $additionalNotes = $this->inputNormalizer->normalizeOptionalText($attributes['additional_notes'] ?? null, 2000, 'additional_notes');
        $departmentOrWard = $this->inputNormalizer->normalizeOptionalText($attributes['department_or_ward'] ?? null, 150, 'department_or_ward');

        $createdRequest = DB::transaction(function () use (
            $hospital,
            $actingUser,
            $bloodGroup,
            $component,
            $requestedUnits,
            $priority,
            $requestDate,
            $requiredDate,
            $requestReason,
            $additionalNotes,
            $departmentOrWard,
            $attributes
        ): BloodRequest {
            // Global lock order: hospitals -> blood_requests.
            $lockedHospital = Hospital::query()
                ->whereKey($hospital->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedHospital->is_active || $lockedHospital->verification_status !== 'Verified') {
                throw ValidationException::withMessages([
                    'hospital_id' => 'Only active, verified partner hospitals may submit blood requests.',
                ]);
            }

            if (! $actingUser->hasPermission('requests.fulfill')) {
                $hasActiveCoordinatorLink = $actingUser->hospitalUser()
                    ->where('hospital_id', $lockedHospital->id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->exists();

                if (! $hasActiveCoordinatorLink) {
                    throw new AuthorizationException('Hospital coordinator access is inactive or does not match the target hospital.');
                }
            }

            $coordinatorProfile = $actingUser->hospitalUser()
                ->where('hospital_id', $lockedHospital->id)
                ->where('is_active', true)
                ->first();

            $contactPerson = $this->inputNormalizer->normalizeRequiredText(
                $attributes['contact_person_snapshot'] ?? $coordinatorProfile?->contact_name ?? $lockedHospital->primary_contact_name ?? $actingUser->name,
                150,
                'contact_person_snapshot',
                'A contact person snapshot is required.'
            );
            $contactPhone = $this->inputNormalizer->normalizeRequiredText(
                $attributes['contact_phone_snapshot'] ?? $coordinatorProfile?->work_phone ?? $lockedHospital->primary_phone ?? null,
                32,
                'contact_phone_snapshot',
                'A contact phone snapshot is required.'
            );
            $contactEmail = $this->inputNormalizer->normalizeRequiredText(
                $attributes['contact_email_snapshot'] ?? $coordinatorProfile?->work_email ?? $lockedHospital->primary_email ?? $actingUser->email,
                191,
                'contact_email_snapshot',
                'A contact email snapshot is required.'
            );

            $now = CarbonImmutable::now();

            $request = BloodRequest::query()->create([
                'request_code' => $this->codeGenerator->generate(BloodRequest::class, 'request_code', 'REQ-'),
                'hospital_id' => $lockedHospital->id,
                'requested_by_user_id' => $actingUser->getKey(),
                'department_or_ward' => $departmentOrWard ?? $coordinatorProfile?->department,
                'contact_person_snapshot' => $contactPerson,
                'contact_phone_snapshot' => $contactPhone,
                'contact_email_snapshot' => $contactEmail,
                'blood_group' => $bloodGroup,
                'blood_component_id' => $component?->id,
                'requested_units' => $requestedUnits,
                'reserved_units' => 0,
                'fulfilled_units' => 0,
                'priority' => $priority,
                'status' => 'Pending',
                'request_date' => $requestDate,
                'required_date' => $requiredDate,
                'request_reason' => $requestReason,
                'additional_notes' => $additionalNotes,
            ]);

            $this->recordStatusHistory(
                bloodRequest: $request,
                eventName: 'Request Submitted',
                previousStatus: null,
                newStatus: 'Pending',
                unitsAffected: 0,
                actor: $actingUser,
                notes: $requestReason,
                occurredAt: $now
            );

            return $request;
        }, 3);

        return $createdRequest->fresh(['hospital', 'bloodComponent', 'requestedBy', 'statusHistories']) ?? $createdRequest;
    }

    /**
     * Approve a Pending blood request for inventory reservation and dispatch.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function approveRequest(BloodRequest $bloodRequest, ?User $actor, ?string $notes = null): BloodRequest
    {
        $actingUser = $this->authorizeAction($actor, 'review', $bloodRequest);
        $normalizedNotes = $this->inputNormalizer->normalizeOptionalText($notes, 2000, 'notes');

        $approvedRequest = DB::transaction(function () use ($bloodRequest, $actingUser, $normalizedNotes): BloodRequest {
            $lockedRequest = $this->lockRequest($bloodRequest);

            if ($lockedRequest->status !== 'Pending') {
                throw ValidationException::withMessages([
                    'status' => "Only Pending blood requests can be approved (current status is '{$lockedRequest->status}').",
                ]);
            }

            $now = CarbonImmutable::now();

            $lockedRequest->status = 'Approved';
            $lockedRequest->reviewed_by_user_id = $actingUser->getKey();
            $lockedRequest->reviewed_at = $now;
            $lockedRequest->save();

            $this->recordStatusHistory(
                bloodRequest: $lockedRequest,
                eventName: 'Request Approved',
                previousStatus: 'Pending',
                newStatus: 'Approved',
                unitsAffected: 0,
                actor: $actingUser,
                notes: $normalizedNotes ?? 'Request approved for inventory allocation and dispatch.',
                occurredAt: $now
            );

            return $lockedRequest;
        }, 3);

        return $this->refreshRequestWithRelations($approvedRequest);
    }

    /**
     * Reject a Pending or pre-dispatch Approved blood request and release any held reservations.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function rejectRequest(BloodRequest $bloodRequest, ?User $actor, string $reason): BloodRequest
    {
        $actingUser = $this->authorizeAction($actor, 'review', $bloodRequest);
        $normalizedReason = $this->normalizeRequiredRationale($reason, 'rejection_reason', 'A rejection reason of at least 5 characters is required.');

        $rejectedRequest = DB::transaction(function () use ($bloodRequest, $actingUser, $normalizedReason): BloodRequest {
            $lockedRequest = $this->lockRequest($bloodRequest);

            $canReject = $lockedRequest->status === 'Pending'
                || ($lockedRequest->status === 'Approved' && $lockedRequest->fulfilled_units === 0);

            if (! $canReject) {
                throw ValidationException::withMessages([
                    'status' => "Blood request with status '{$lockedRequest->status}' and {$lockedRequest->fulfilled_units} fulfilled unit(s) cannot be rejected.",
                ]);
            }

            $previousStatus = $lockedRequest->status;
            $now = CarbonImmutable::now();

            $releasedCount = $this->releaseRequestHoldsLocked(
                lockedRequest: $lockedRequest,
                actor: $actingUser,
                description: "Released reserved unit(s) due to rejection of {$lockedRequest->request_code}: {$normalizedReason}",
                onlyAllocationIds: null,
                occurredAt: $now
            );

            $lockedRequest->status = 'Rejected';
            $lockedRequest->reserved_units = 0;
            $lockedRequest->reviewed_by_user_id = $actingUser->getKey();
            $lockedRequest->reviewed_at = $now;
            $lockedRequest->rejection_reason = $normalizedReason;
            $lockedRequest->save();

            $this->recordStatusHistory(
                bloodRequest: $lockedRequest,
                eventName: 'Request Rejected',
                previousStatus: $previousStatus,
                newStatus: 'Rejected',
                unitsAffected: $releasedCount,
                actor: $actingUser,
                notes: $normalizedReason,
                occurredAt: $now
            );

            return $lockedRequest;
        }, 3);

        return $this->refreshRequestWithRelations($rejectedRequest);
    }

    /**
     * Update mutable fields on a non-terminal blood request while preserving quantity invariants.
     *
     * @param  array{
     *     requested_units?: int|string,
     *     blood_group?: string|null,
     *     blood_component_id?: int|string|null,
     *     blood_component_code?: string|null,
     *     priority?: string|null,
     *     required_date?: CarbonInterface|string|null,
     *     request_reason?: string|null,
     *     additional_notes?: string|null,
     *     department_or_ward?: string|null,
     *     notes?: string|null
     * }  $attributes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function updateRequest(BloodRequest $bloodRequest, ?User $actor, array $attributes): BloodRequest
    {
        $actingUser = $this->authorizeAction($actor, 'update', $bloodRequest);

        $hasRequestedUnits = array_key_exists('requested_units', $attributes);
        $newRequestedUnits = $hasRequestedUnits
            ? $this->parsePositiveInt($attributes['requested_units'], 'requested_units', 'Requested units must be at least 1.')
            : null;

        $hasBloodGroup = array_key_exists('blood_group', $attributes);
        $newBloodGroup = $hasBloodGroup
            ? $this->validateBloodGroup($attributes['blood_group'], 'blood_group')
            : null;

        $hasComponentChange = array_key_exists('blood_component_id', $attributes) || array_key_exists('blood_component_code', $attributes);
        $parsedComponentId = $this->parseOptionalPositiveInt($attributes['blood_component_id'] ?? null, 'blood_component_id');
        $parsedComponentCode = $this->inputNormalizer->normalizeOptionalText($attributes['blood_component_code'] ?? null, 32, 'blood_component_code');
        $resolvedComponent = null;

        if ($hasComponentChange && ($parsedComponentId !== null || $parsedComponentCode !== null)) {
            $resolvedComponent = $this->resolveActiveComponent($parsedComponentId, $parsedComponentCode, 'blood_component_id');
        }

        $hasPriority = array_key_exists('priority', $attributes);
        $newPriority = $hasPriority ? $this->validatePriority($attributes['priority']) : null;

        $hasRequiredDate = array_key_exists('required_date', $attributes);
        $newRequiredDate = $hasRequiredDate
            ? $this->inputNormalizer->parseOptionalDate($attributes['required_date'], 'required_date')
            : null;

        if ($hasRequiredDate && $newRequiredDate === null) {
            throw ValidationException::withMessages([
                'required_date' => 'The required_date value cannot be empty.',
            ]);
        }

        $hasReason = array_key_exists('request_reason', $attributes);
        $newReason = $hasReason
            ? $this->inputNormalizer->normalizeRequiredText($attributes['request_reason'], 2000, 'request_reason', 'A clinical request reason is required.')
            : null;

        $hasAdditionalNotes = array_key_exists('additional_notes', $attributes);
        $newAdditionalNotes = $hasAdditionalNotes
            ? $this->inputNormalizer->normalizeOptionalText($attributes['additional_notes'], 2000, 'additional_notes')
            : null;

        $hasDepartment = array_key_exists('department_or_ward', $attributes);
        $newDepartment = $hasDepartment
            ? $this->inputNormalizer->normalizeOptionalText($attributes['department_or_ward'], 150, 'department_or_ward')
            : null;

        $auditNotes = $this->inputNormalizer->normalizeOptionalText($attributes['notes'] ?? null, 2000, 'notes');

        $updatedRequest = DB::transaction(function () use (
            $bloodRequest,
            $actingUser,
            $hasRequestedUnits,
            $newRequestedUnits,
            $hasBloodGroup,
            $newBloodGroup,
            $hasComponentChange,
            $resolvedComponent,
            $hasPriority,
            $newPriority,
            $hasRequiredDate,
            $newRequiredDate,
            $hasReason,
            $newReason,
            $hasAdditionalNotes,
            $newAdditionalNotes,
            $hasDepartment,
            $newDepartment,
            $auditNotes
        ): BloodRequest {
            $lockedRequest = $this->lockRequest($bloodRequest);

            if (in_array($lockedRequest->status, ['Rejected', 'Cancelled', 'Fulfilled'], true)) {
                throw ValidationException::withMessages([
                    'status' => "Blood request with terminal status '{$lockedRequest->status}' cannot be modified.",
                ]);
            }

            if ($hasRequestedUnits) {
                $minimumRequiredUnits = $lockedRequest->fulfilled_units + $lockedRequest->reserved_units;

                if ($newRequestedUnits < $minimumRequiredUnits) {
                    throw ValidationException::withMessages([
                        'requested_units' => "Requested units cannot be lowered below active fulfilled + reserved units ({$minimumRequiredUnits}).",
                    ]);
                }

                $lockedRequest->requested_units = $newRequestedUnits;
            }

            if ($hasBloodGroup && $newBloodGroup !== $lockedRequest->blood_group) {
                if ($lockedRequest->fulfilled_units > 0 || $lockedRequest->reserved_units > 0) {
                    throw ValidationException::withMessages([
                        'blood_group' => 'Blood group cannot be changed after units have been reserved or dispatched.',
                    ]);
                }

                $lockedRequest->blood_group = $newBloodGroup;
            }

            if ($hasComponentChange) {
                $targetComponentId = $resolvedComponent?->id;

                if ($targetComponentId !== $lockedRequest->blood_component_id) {
                    if ($lockedRequest->fulfilled_units > 0 || $lockedRequest->reserved_units > 0) {
                        throw ValidationException::withMessages([
                            'blood_component_id' => 'Blood component cannot be changed after units have been reserved or dispatched.',
                        ]);
                    }

                    $lockedRequest->blood_component_id = $targetComponentId;
                }
            }

            if ($hasPriority && $newPriority !== null) {
                $lockedRequest->priority = $newPriority;
            }

            if ($hasRequiredDate && $newRequiredDate !== null) {
                if ($newRequiredDate < $lockedRequest->request_date->toDateString()) {
                    throw ValidationException::withMessages([
                        'required_date' => 'The required_date must be on or after request_date.',
                    ]);
                }

                $lockedRequest->required_date = $newRequiredDate;
            }

            if ($hasReason && $newReason !== null) {
                $lockedRequest->request_reason = $newReason;
            }

            if ($hasAdditionalNotes) {
                $lockedRequest->additional_notes = $newAdditionalNotes;
            }

            if ($hasDepartment) {
                $lockedRequest->department_or_ward = $newDepartment;
            }

            $lockedRequest->save();

            $this->recordStatusHistory(
                bloodRequest: $lockedRequest,
                eventName: 'Request Updated',
                previousStatus: $lockedRequest->status,
                newStatus: $lockedRequest->status,
                unitsAffected: $lockedRequest->requested_units,
                actor: $actingUser,
                notes: $auditNotes ?? "Updated request details (requested_units={$lockedRequest->requested_units}, priority={$lockedRequest->priority}).",
                occurredAt: CarbonImmutable::now()
            );

            return $lockedRequest;
        }, 3);

        return $this->refreshRequestWithRelations($updatedRequest);
    }

    /**
     * Reserve non-expired Available units (FIFO or explicit IDs) from one compatible bucket for a request.
     *
     * @param  array{
     *     source_blood_group?: string|null,
     *     dispatched_blood_group?: string|null,
     *     blood_component_id?: int|string|null,
     *     blood_component_code?: string|null,
     *     blood_unit_ids?: list<int|string>|null,
     *     override_approver?: User|null,
     *     override_reason?: string|null,
     *     notes?: string|null,
     *     as_of?: CarbonInterface|string|null
     * }  $attributes
     * @return EloquentCollection<int, InventoryAllocation>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reserveUnits(BloodRequest $bloodRequest, ?User $actor, int $quantity, array $attributes = []): EloquentCollection
    {
        $actingUser = $this->authorizeAction($actor, 'allocate', $bloodRequest);

        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Reservation quantity must be at least 1 unit.',
            ]);
        }

        $rawSourceGroup = $attributes['source_blood_group'] ?? $attributes['dispatched_blood_group'] ?? null;
        $requestedSourceGroup = $rawSourceGroup !== null && $rawSourceGroup !== ''
            ? $this->validateBloodGroup($rawSourceGroup, 'source_blood_group')
            : null;

        $componentId = $this->parseOptionalPositiveInt($attributes['blood_component_id'] ?? null, 'blood_component_id');
        $componentCode = $this->inputNormalizer->normalizeOptionalText($attributes['blood_component_code'] ?? null, 32, 'blood_component_code');
        $explicitUnitIds = $this->parseExplicitUnitIds($attributes['blood_unit_ids'] ?? null, $quantity);
        $overrideApprover = $attributes['override_approver'] ?? null;

        if ($overrideApprover !== null && ! $overrideApprover instanceof User) {
            throw ValidationException::withMessages([
                'override_approver' => 'The override_approver must be a valid User instance.',
            ]);
        }

        $overrideReason = $attributes['override_reason'] ?? null;
        $allocationNotes = $this->inputNormalizer->normalizeOptionalText($attributes['notes'] ?? null, 2000, 'notes');
        $asOf = $this->inputNormalizer->parseOptionalDateTime($attributes['as_of'] ?? null, 'as_of')
            ?? CarbonImmutable::now();

        /** @var list<int> $createdAllocationIds */
        $createdAllocationIds = DB::transaction(function () use (
            $bloodRequest,
            $actingUser,
            $quantity,
            $requestedSourceGroup,
            $componentId,
            $componentCode,
            $explicitUnitIds,
            $overrideApprover,
            $overrideReason,
            $allocationNotes,
            $asOf
        ): array {
            // Global lock order: blood_requests -> inventory_stocks -> blood_units (id ASC) -> inventory_allocations (id ASC).
            $lockedRequest = $this->lockRequest($bloodRequest);

            if (! in_array($lockedRequest->status, ['Approved', 'Partially Fulfilled'], true)) {
                throw ValidationException::withMessages([
                    'status' => "Units can only be reserved for Approved or Partially Fulfilled requests (current status: '{$lockedRequest->status}').",
                ]);
            }

            $unallocatedRemaining = $lockedRequest->requested_units - $lockedRequest->fulfilled_units - $lockedRequest->reserved_units;

            if ($quantity > $unallocatedRemaining) {
                throw ValidationException::withMessages([
                    'quantity' => "Cannot reserve {$quantity} unit(s): only {$unallocatedRemaining} unallocated unit(s) remain on {$lockedRequest->request_code}.",
                ]);
            }

            $sourceBloodGroup = $requestedSourceGroup ?? $lockedRequest->blood_group;
            $targetComponentId = $this->resolveTargetComponentId($lockedRequest, $componentId, $componentCode);

            $compat = $this->validateCompatibilityAndOverride(
                lockedRequest: $lockedRequest,
                sourceBloodGroup: $sourceBloodGroup,
                componentId: $targetComponentId,
                actor: $actingUser,
                overrideApprover: $overrideApprover,
                overrideReason: $overrideReason,
                bloodGroupErrorKey: 'source_blood_group'
            );

            $lockedStock = $this->lockExistingStockBucket($sourceBloodGroup, $targetComponentId);

            if ($lockedStock->available_units < $quantity) {
                throw ValidationException::withMessages([
                    'available_units' => "Insufficient available stock in ({$sourceBloodGroup}): requested {$quantity}, available {$lockedStock->available_units}.",
                ]);
            }

            $lockedUnits = $this->selectAndLockAvailableUnits(
                lockedStock: $lockedStock,
                quantity: $quantity,
                explicitUnitIds: $explicitUnitIds,
                asOf: $asOf,
                errorKey: $explicitUnitIds !== [] ? 'blood_unit_ids' : 'available_units'
            );

            $unitIds = $lockedUnits->pluck('id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all();

            $hasActiveAllocationConflict = InventoryAllocation::query()
                ->whereIn('blood_unit_id', $unitIds)
                ->active()
                ->orderBy('id')
                ->lockForUpdate()
                ->exists();

            if ($hasActiveAllocationConflict) {
                throw ValidationException::withMessages([
                    'blood_unit_id' => 'One or more selected blood units already hold an active request allocation.',
                ]);
            }

            $now = CarbonImmutable::now();

            $this->inventoryService->reserveLockedBucketUnits(
                lockedStock: $lockedStock,
                lockedUnits: $lockedUnits,
                actor: $actingUser,
                description: "Reserved {$quantity} {$sourceBloodGroup} unit(s) for {$lockedRequest->request_code}.",
                referenceType: 'blood_request',
                referenceId: $lockedRequest->id,
                occurredAt: $now
            );

            $allocationIds = [];

            foreach ($lockedUnits as $unit) {
                $allocation = InventoryAllocation::query()->create([
                    'allocation_code' => $this->codeGenerator->generate(InventoryAllocation::class, 'allocation_code', 'ALC-'),
                    'blood_request_id' => $lockedRequest->id,
                    'inventory_stock_id' => $lockedStock->id,
                    'blood_unit_id' => $unit->id,
                    'fulfillment_id' => null,
                    'units_allocated' => 1,
                    'allocation_status' => 'Reserved',
                    'allocated_by_user_id' => $actingUser->getKey(),
                    'allocated_at' => $now,
                    'notes' => $allocationNotes ?? ($compat['is_override'] ? "Compatibility override reservation: {$compat['override_reason']}" : null),
                ]);

                $allocationIds[] = $allocation->id;
            }

            $lockedRequest->reserved_units += $quantity;
            $lockedRequest->save();

            $historyNotes = $allocationNotes
                ?? "Reserved {$quantity} unit(s) from {$sourceBloodGroup} (stock #{$lockedStock->id}).";

            if ($compat['is_override'] && $compat['override_reason'] !== null) {
                $historyNotes .= " Override rationale: {$compat['override_reason']}";
            }

            $this->recordStatusHistory(
                bloodRequest: $lockedRequest,
                eventName: 'Units Reserved',
                previousStatus: $lockedRequest->status,
                newStatus: $lockedRequest->status,
                unitsAffected: $quantity,
                actor: $actingUser,
                notes: $historyNotes,
                occurredAt: $now
            );

            return $allocationIds;
        }, 3);

        return InventoryAllocation::query()
            ->whereIn('id', $createdAllocationIds)
            ->with(['bloodUnit', 'inventoryStock', 'bloodRequest'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Release all or selected active Reserved allocations back to Available inventory.
     *
     * @param  list<int|string>|null  $allocationIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function releaseReservedAllocations(
        BloodRequest $bloodRequest,
        ?User $actor,
        ?array $allocationIds = null,
        ?string $reason = null
    ): BloodRequest {
        $actingUser = $this->authorizeAction($actor, 'releaseAllocation', $bloodRequest);

        $parsedAllocationIds = null;

        if ($allocationIds !== null) {
            if ($allocationIds === []) {
                throw ValidationException::withMessages([
                    'allocation_ids' => 'When specifying allocation_ids, at least one allocation ID is required.',
                ]);
            }

            $parsedAllocationIds = [];

            foreach ($allocationIds as $rawId) {
                $parsedId = $this->parsePositiveInt($rawId, 'allocation_ids', 'Each allocation_ids entry must be a positive integer.');
                $parsedAllocationIds[] = $parsedId;
            }

            $parsedAllocationIds = array_values(array_unique($parsedAllocationIds));

            if (count($parsedAllocationIds) !== count($allocationIds)) {
                throw ValidationException::withMessages([
                    'allocation_ids' => 'Duplicate allocation_ids entries are not permitted.',
                ]);
            }

            sort($parsedAllocationIds);
        }

        $releaseDescription = $reason !== null && trim($reason) !== ''
            ? $this->normalizeRequiredRationale($reason, 'reason', 'Release reason must be at least 5 characters when provided.')
            : "Released reserved blood unit allocation(s) on {$bloodRequest->request_code}.";

        $updatedRequest = DB::transaction(function () use (
            $bloodRequest,
            $actingUser,
            $parsedAllocationIds,
            $releaseDescription
        ): BloodRequest {
            $lockedRequest = $this->lockRequest($bloodRequest);

            if (in_array($lockedRequest->status, ['Rejected', 'Cancelled', 'Fulfilled'], true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot release allocations on blood request with status '{$lockedRequest->status}'.",
                ]);
            }

            $now = CarbonImmutable::now();

            $releasedCount = $this->releaseRequestHoldsLocked(
                lockedRequest: $lockedRequest,
                actor: $actingUser,
                description: $releaseDescription,
                onlyAllocationIds: $parsedAllocationIds,
                occurredAt: $now
            );

            if ($releasedCount === 0) {
                throw ValidationException::withMessages([
                    'allocation_status' => 'No active Reserved allocations exist on this blood request.',
                ]);
            }

            if ($lockedRequest->reserved_units < $releasedCount) {
                throw ValidationException::withMessages([
                    'reserved_units' => 'Cannot decrement request reserved_units below zero.',
                ]);
            }

            $lockedRequest->reserved_units -= $releasedCount;
            $lockedRequest->save();

            $this->recordStatusHistory(
                bloodRequest: $lockedRequest,
                eventName: 'Reservation Released',
                previousStatus: $lockedRequest->status,
                newStatus: $lockedRequest->status,
                unitsAffected: $releasedCount,
                actor: $actingUser,
                notes: $releaseDescription,
                occurredAt: $now
            );

            return $lockedRequest;
        }, 3);

        return $this->refreshRequestWithRelations($updatedRequest);
    }

    /**
     * Execute a partial or full dispatch batch from one compatible stock bucket.
     *
     * Consumes active Reserved allocations matching `(dispatched_blood_group, blood_component_id)` FIFO
     * first, supplements remaining units from non-expired unallocated Available stock, writes per-unit
     * `Dispatch` ledger entries via InventoryService, and records RequestFulfillment + status history.
     *
     * @param  array{
     *     quantity?: int|string,
     *     units_dispatched?: int|string,
     *     dispatched_blood_group?: string|null,
     *     blood_component_id?: int|string|null,
     *     blood_component_code?: string|null,
     *     dispatch_date?: CarbonInterface|string|null,
     *     courier_reference?: string|null,
     *     notes?: string|null,
     *     override_approver?: User|null,
     *     override_reason?: string|null,
     *     as_of?: CarbonInterface|string|null
     * }  $attributes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function dispatchUnits(BloodRequest $bloodRequest, ?User $actor, array $attributes): RequestFulfillment
    {
        $actingUser = $this->authorizeAction($actor, 'dispatch', $bloodRequest);

        $quantity = $this->parsePositiveInt(
            $attributes['quantity'] ?? $attributes['units_dispatched'] ?? null,
            'quantity',
            'Dispatch quantity must be at least 1 unit.'
        );

        $rawDispatchedGroup = $attributes['dispatched_blood_group'] ?? null;
        $requestedDispatchedGroup = $rawDispatchedGroup !== null && $rawDispatchedGroup !== ''
            ? $this->validateBloodGroup($rawDispatchedGroup, 'dispatched_blood_group')
            : null;

        $componentId = $this->parseOptionalPositiveInt($attributes['blood_component_id'] ?? null, 'blood_component_id');
        $componentCode = $this->inputNormalizer->normalizeOptionalText($attributes['blood_component_code'] ?? null, 32, 'blood_component_code');
        $dispatchDate = $this->inputNormalizer->parseRequiredOrDefaultDate($attributes['dispatch_date'] ?? null, 'dispatch_date');
        $courierReference = $this->inputNormalizer->normalizeOptionalText($attributes['courier_reference'] ?? null, 64, 'courier_reference');
        $notes = $this->inputNormalizer->normalizeOptionalText($attributes['notes'] ?? null, 2000, 'notes');
        $overrideApprover = $attributes['override_approver'] ?? null;

        if ($overrideApprover !== null && ! $overrideApprover instanceof User) {
            throw ValidationException::withMessages([
                'override_approver' => 'The override_approver must be a valid User instance.',
            ]);
        }

        $overrideReason = $attributes['override_reason'] ?? null;
        $asOf = $this->inputNormalizer->parseOptionalDateTime($attributes['as_of'] ?? null, 'as_of')
            ?? CarbonImmutable::now();

        $fulfillment = DB::transaction(function () use (
            $bloodRequest,
            $actingUser,
            $quantity,
            $requestedDispatchedGroup,
            $componentId,
            $componentCode,
            $dispatchDate,
            $courierReference,
            $notes,
            $overrideApprover,
            $overrideReason,
            $asOf
        ): RequestFulfillment {
            // Global lock order: blood_requests -> inventory_stocks -> blood_units (id ASC) -> inventory_allocations (id ASC).
            $lockedRequest = $this->lockRequest($bloodRequest);

            if (! in_array($lockedRequest->status, ['Approved', 'Partially Fulfilled'], true)) {
                throw ValidationException::withMessages([
                    'status' => "Only Approved or Partially Fulfilled requests can be dispatched (current status: '{$lockedRequest->status}').",
                ]);
            }

            $remainingToFulfill = $lockedRequest->requested_units - $lockedRequest->fulfilled_units;

            if ($quantity > $remainingToFulfill) {
                throw ValidationException::withMessages([
                    'quantity' => "Cannot dispatch {$quantity} unit(s): only {$remainingToFulfill} unit(s) remain unfulfilled on {$lockedRequest->request_code}.",
                ]);
            }

            $dispatchedBloodGroup = $requestedDispatchedGroup ?? $lockedRequest->blood_group;
            $targetComponentId = $this->resolveTargetComponentId($lockedRequest, $componentId, $componentCode);

            $compat = $this->validateCompatibilityAndOverride(
                lockedRequest: $lockedRequest,
                sourceBloodGroup: $dispatchedBloodGroup,
                componentId: $targetComponentId,
                actor: $actingUser,
                overrideApprover: $overrideApprover,
                overrideReason: $overrideReason,
                bloodGroupErrorKey: 'dispatched_blood_group'
            );

            $lockedStock = $this->lockExistingStockBucket($dispatchedBloodGroup, $targetComponentId);

            // Identify request-owned Reserved holds for THIS source bucket in FIFO expiry order.
            $candidateReservedUnitIds = InventoryAllocation::query()
                ->join('blood_units', 'blood_units.id', '=', 'inventory_allocations.blood_unit_id')
                ->where('inventory_allocations.blood_request_id', $lockedRequest->id)
                ->where('inventory_allocations.inventory_stock_id', $lockedStock->id)
                ->where('inventory_allocations.allocation_status', 'Reserved')
                ->orderBy('blood_units.expires_at')
                ->orderBy('blood_units.id')
                ->limit($quantity)
                ->pluck('blood_units.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $reservedCountToConsume = count($candidateReservedUnitIds);
            $additionalAvailableNeeded = $quantity - $reservedCountToConsume;
            $unallocatedRemaining = $lockedRequest->requested_units - $lockedRequest->fulfilled_units - $lockedRequest->reserved_units;

            if ($additionalAvailableNeeded > $unallocatedRemaining) {
                throw ValidationException::withMessages([
                    'quantity' => "Cannot dispatch {$additionalAvailableNeeded} unreserved unit(s) from this bucket while {$lockedRequest->reserved_units} unit(s) remain reserved elsewhere (unallocated capacity: {$unallocatedRemaining}).",
                ]);
            }

            $candidateAvailableUnitIds = [];

            if ($additionalAvailableNeeded > 0) {
                if ($lockedStock->available_units < $additionalAvailableNeeded) {
                    throw ValidationException::withMessages([
                        'available_units' => "Insufficient available stock in ({$dispatchedBloodGroup}): need {$additionalAvailableNeeded} unreserved unit(s), available {$lockedStock->available_units}.",
                    ]);
                }

                $candidateAvailableUnitIds = BloodUnit::fifoAvailable($lockedStock->blood_group, $lockedStock->blood_component_id, $asOf)
                    ->whereDoesntHave('allocations', static fn ($query) => $query->active())
                    ->orderBy('id')
                    ->limit($additionalAvailableNeeded)
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                if (count($candidateAvailableUnitIds) < $additionalAvailableNeeded) {
                    throw ValidationException::withMessages([
                        'available_units' => "Fewer than {$additionalAvailableNeeded} non-expired, unallocated Available unit(s) remain for dispatch.",
                    ]);
                }
            }

            // Lock ALL involved BloodUnits together in ascending ID order BEFORE any InventoryAllocation locks.
            $allUnitIds = array_values(array_unique(array_merge($candidateReservedUnitIds, $candidateAvailableUnitIds)));
            sort($allUnitIds);

            $lockedUnitsById = BloodUnit::query()
                ->whereIn('id', $allUnitIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedUnitsById->count() !== count($allUnitIds)) {
                throw ValidationException::withMessages([
                    'blood_unit_id' => 'One or more target blood units could not be locked.',
                ]);
            }

            sort($candidateReservedUnitIds);
            sort($candidateAvailableUnitIds);

            $lockedReservedUnits = new EloquentCollection;

            foreach ($candidateReservedUnitIds as $unitId) {
                /** @var BloodUnit $unit */
                $unit = $lockedUnitsById->get($unitId);

                if (
                    $unit->blood_group !== $lockedStock->blood_group
                    || (int) $unit->blood_component_id !== (int) $lockedStock->blood_component_id
                ) {
                    throw ValidationException::withMessages([
                        'blood_unit_id' => "Reserved unit {$unit->unit_code} does not match target dispatch bucket.",
                    ]);
                }

                if ($unit->unit_status !== 'Reserved') {
                    throw ValidationException::withMessages([
                        'unit_status' => "Reserved unit {$unit->unit_code} is no longer Reserved (current status: '{$unit->unit_status}').",
                    ]);
                }

                if ($unit->expires_at->lte($asOf)) {
                    throw ValidationException::withMessages([
                        'expires_at' => "Reserved blood unit {$unit->unit_code} has expired and cannot be dispatched.",
                    ]);
                }

                $lockedReservedUnits->push($unit);
            }

            $lockedAvailableUnits = new EloquentCollection;

            foreach ($candidateAvailableUnitIds as $unitId) {
                /** @var BloodUnit $unit */
                $unit = $lockedUnitsById->get($unitId);

                if (
                    $unit->blood_group !== $lockedStock->blood_group
                    || (int) $unit->blood_component_id !== (int) $lockedStock->blood_component_id
                ) {
                    throw ValidationException::withMessages([
                        'blood_unit_id' => "Available unit {$unit->unit_code} does not match target dispatch bucket.",
                    ]);
                }

                if ($unit->unit_status !== 'Available') {
                    throw ValidationException::withMessages([
                        'unit_status' => "Available unit {$unit->unit_code} is no longer Available (current status: '{$unit->unit_status}').",
                    ]);
                }

                if ($unit->expires_at->lte($asOf)) {
                    throw ValidationException::withMessages([
                        'expires_at' => "Blood unit {$unit->unit_code} has expired and cannot be dispatched.",
                    ]);
                }

                $lockedAvailableUnits->push($unit);
            }

            // Lock InventoryAllocations last (id ASC): existing holds to consume + active-allocation guard on newly picked units.
            $lockedReservedAllocations = new EloquentCollection;

            if ($candidateReservedUnitIds !== []) {
                $lockedReservedAllocations = InventoryAllocation::query()
                    ->where('blood_request_id', $lockedRequest->id)
                    ->where('inventory_stock_id', $lockedStock->id)
                    ->whereIn('blood_unit_id', $candidateReservedUnitIds)
                    ->where('allocation_status', 'Reserved')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($lockedReservedAllocations->count() !== count($candidateReservedUnitIds)) {
                    throw ValidationException::withMessages([
                        'allocation_status' => 'One or more reserved request allocations changed concurrently prior to dispatch.',
                    ]);
                }
            }

            if ($candidateAvailableUnitIds !== []) {
                $hasUnexpectedActiveAllocation = InventoryAllocation::query()
                    ->whereIn('blood_unit_id', $candidateAvailableUnitIds)
                    ->active()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->exists();

                if ($hasUnexpectedActiveAllocation) {
                    throw ValidationException::withMessages([
                        'blood_unit_id' => 'One or more selected Available blood units already carry an active allocation.',
                    ]);
                }
            }

            $now = CarbonImmutable::now();
            $fulfillmentCode = $this->codeGenerator->generate(RequestFulfillment::class, 'fulfillment_code', 'FUL-');

            $fulfillmentRecord = RequestFulfillment::query()->create([
                'fulfillment_code' => $fulfillmentCode,
                'blood_request_id' => $lockedRequest->id,
                'dispatched_by_user_id' => $actingUser->getKey(),
                'dispatched_blood_group' => $dispatchedBloodGroup,
                'blood_component_id' => $targetComponentId,
                'units_dispatched' => $quantity,
                'dispatch_date' => $dispatchDate,
                'dispatched_at' => $now,
                'courier_reference' => $courierReference,
                'delivery_status' => 'Dispatched',
                'notes' => $notes,
                'is_compatibility_override' => $compat['is_override'],
                'override_approved_by_user_id' => $compat['is_override'] ? $compat['approver']?->getKey() : null,
                'override_approved_at' => $compat['is_override'] ? $now : null,
                'override_reason' => $compat['is_override'] ? $compat['override_reason'] : null,
            ]);

            foreach ($lockedReservedAllocations as $allocation) {
                $allocation->allocation_status = 'Dispatched';
                $allocation->fulfillment_id = $fulfillmentRecord->id;
                $allocation->dispatched_at = $now;
                $allocation->save();
            }

            foreach ($lockedAvailableUnits as $unit) {
                InventoryAllocation::query()->create([
                    'allocation_code' => $this->codeGenerator->generate(InventoryAllocation::class, 'allocation_code', 'ALC-'),
                    'blood_request_id' => $lockedRequest->id,
                    'inventory_stock_id' => $lockedStock->id,
                    'blood_unit_id' => $unit->id,
                    'fulfillment_id' => $fulfillmentRecord->id,
                    'units_allocated' => 1,
                    'allocation_status' => 'Dispatched',
                    'allocated_by_user_id' => $actingUser->getKey(),
                    'allocated_at' => $now,
                    'dispatched_at' => $now,
                    'notes' => $notes ?? ($compat['is_override'] ? "Compatibility override dispatch: {$compat['override_reason']}" : null),
                ]);
            }

            $ledgerDescription = "Dispatched {$dispatchedBloodGroup} unit(s) for {$lockedRequest->request_code} ({$fulfillmentCode})"
                .($courierReference !== null ? " via {$courierReference}." : '.');

            $this->inventoryService->dispatchLockedBucketUnits(
                lockedStock: $lockedStock,
                lockedReservedUnits: $lockedReservedUnits,
                lockedAvailableUnits: $lockedAvailableUnits,
                actor: $actingUser,
                description: $ledgerDescription,
                fulfillmentId: $fulfillmentRecord->id,
                occurredAt: $now
            );

            $previousStatus = $lockedRequest->status;
            $lockedRequest->reserved_units -= $lockedReservedUnits->count();
            $lockedRequest->fulfilled_units += $quantity;
            $lockedRequest->status = $lockedRequest->fulfilled_units === $lockedRequest->requested_units
                ? 'Fulfilled'
                : 'Partially Fulfilled';
            $lockedRequest->save();

            $historyNotes = $notes
                ?? "Dispatched {$quantity} {$dispatchedBloodGroup} unit(s) ({$fulfillmentCode}: {$lockedReservedUnits->count()} reserved, {$lockedAvailableUnits->count()} unreserved)"
                .($courierReference !== null ? " via {$courierReference}." : '.');

            if ($compat['is_override'] && $compat['override_reason'] !== null) {
                $historyNotes .= " Override approved by {$compat['approver']?->name}: {$compat['override_reason']}";
            }

            $this->recordStatusHistory(
                bloodRequest: $lockedRequest,
                eventName: $lockedRequest->status === 'Fulfilled' ? 'Full Fulfillment Completed' : 'Partial Dispatch Recorded',
                previousStatus: $previousStatus,
                newStatus: $lockedRequest->status,
                unitsAffected: $quantity,
                actor: $actingUser,
                notes: $historyNotes,
                occurredAt: $now
            );

            return $fulfillmentRecord;
        }, 3);

        return $fulfillment->fresh([
            'bloodRequest',
            'bloodComponent',
            'dispatchedBy',
            'overrideApprovedBy',
            'allocations.bloodUnit',
        ]) ?? $fulfillment;
    }

    /**
     * Cancel a Pending, Approved, or Partially Fulfilled request and release remaining Reserved holds.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function cancelRequest(BloodRequest $bloodRequest, ?User $actor, string $reason): BloodRequest
    {
        $actingUser = $this->authorizeAction($actor, 'cancel', $bloodRequest);
        $normalizedReason = $this->normalizeRequiredRationale($reason, 'cancellation_reason', 'A cancellation reason of at least 5 characters is required.');

        $cancelledRequest = DB::transaction(function () use ($bloodRequest, $actingUser, $normalizedReason): BloodRequest {
            $lockedRequest = $this->lockRequest($bloodRequest);

            // Re-authorize against authoritative locked state to prevent Pending->Approved TOCTOU races.
            Gate::forUser($actingUser)->authorize('cancel', $lockedRequest);

            if (! in_array($lockedRequest->status, ['Pending', 'Approved', 'Partially Fulfilled'], true)) {
                throw ValidationException::withMessages([
                    'status' => "Blood request with status '{$lockedRequest->status}' cannot be cancelled.",
                ]);
            }

            $previousStatus = $lockedRequest->status;
            $now = CarbonImmutable::now();

            $releasedCount = $this->releaseRequestHoldsLocked(
                lockedRequest: $lockedRequest,
                actor: $actingUser,
                description: "Released reserved unit(s) due to cancellation of {$lockedRequest->request_code}: {$normalizedReason}",
                onlyAllocationIds: null,
                occurredAt: $now
            );

            $lockedRequest->status = 'Cancelled';
            $lockedRequest->reserved_units = 0;
            $lockedRequest->cancelled_by_user_id = $actingUser->getKey();
            $lockedRequest->cancelled_at = $now;
            $lockedRequest->cancellation_reason = $normalizedReason;
            $lockedRequest->save();

            $this->recordStatusHistory(
                bloodRequest: $lockedRequest,
                eventName: 'Request Cancelled',
                previousStatus: $previousStatus,
                newStatus: 'Cancelled',
                unitsAffected: $releasedCount,
                actor: $actingUser,
                notes: $normalizedReason,
                occurredAt: $now
            );

            return $lockedRequest;
        }, 3);

        return $this->refreshRequestWithRelations($cancelledRequest);
    }

    /**
     * Authorize the acting user via Laravel Gate policies after checking authentication.
     *
     * @throws AuthorizationException
     */
    private function authorizeAction(?User $actor, string $ability, mixed $arguments): User
    {
        if (! $actor instanceof User) {
            throw new AuthorizationException('An authenticated and authorized user is required for fulfillment operations.');
        }

        Gate::forUser($actor)->authorize($ability, $arguments);

        return $actor;
    }

    /**
     * Lock and reload the authoritative BloodRequest record from storage.
     */
    private function lockRequest(BloodRequest $bloodRequest): BloodRequest
    {
        return BloodRequest::query()
            ->whereKey($bloodRequest->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Lock all relevant buckets, units, and Reserved allocations in global lock order and release them cleanly.
     *
     * Global lock order: `blood_requests` (caller-locked) -> `inventory_stocks (id ASC)`
     * -> `blood_units (id ASC)` -> `inventory_allocations (id ASC)`.
     *
     * @param  list<int>|null  $onlyAllocationIds
     *
     * @throws ValidationException
     */
    private function releaseRequestHoldsLocked(
        BloodRequest $lockedRequest,
        User $actor,
        string $description,
        ?array $onlyAllocationIds,
        CarbonImmutable $occurredAt
    ): int {
        $candidateQuery = InventoryAllocation::query()
            ->where('blood_request_id', $lockedRequest->id)
            ->where('allocation_status', 'Reserved');

        if ($onlyAllocationIds !== null) {
            $candidateQuery->whereIn('id', $onlyAllocationIds);
        }

        $candidates = $candidateQuery
            ->orderBy('id')
            ->get(['id', 'inventory_stock_id', 'blood_unit_id']);

        if ($onlyAllocationIds !== null && $candidates->count() !== count($onlyAllocationIds)) {
            throw ValidationException::withMessages([
                'allocation_ids' => 'One or more specified allocations are not active Reserved holds on this request.',
            ]);
        }

        if ($candidates->isEmpty()) {
            if ($onlyAllocationIds === null && $lockedRequest->reserved_units > 0) {
                throw ValidationException::withMessages([
                    'reserved_units' => 'Request reserved_units count does not match active Reserved allocations.',
                ]);
            }

            return 0;
        }

        $stockIds = $candidates->pluck('inventory_stock_id')->map(static fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all();
        $unitIds = $candidates->pluck('blood_unit_id')->map(static fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all();
        $allocationIds = $candidates->pluck('id')->map(static fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all();

        $lockedStocksById = InventoryStock::query()
            ->whereIn('id', $stockIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($lockedStocksById->count() !== count($stockIds)) {
            throw ValidationException::withMessages([
                'inventory_stock_id' => 'One or more reserved stock buckets no longer exist.',
            ]);
        }

        $lockedUnitsById = BloodUnit::query()
            ->whereIn('id', $unitIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($lockedUnitsById->count() !== count($unitIds)) {
            throw ValidationException::withMessages([
                'blood_unit_id' => 'One or more reserved blood units could not be locked for release.',
            ]);
        }

        $lockedAllocations = InventoryAllocation::query()
            ->where('blood_request_id', $lockedRequest->id)
            ->whereIn('id', $allocationIds)
            ->where('allocation_status', 'Reserved')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($lockedAllocations->count() !== count($allocationIds)) {
            throw ValidationException::withMessages([
                'allocation_status' => 'Reserved allocation state changed concurrently before release could complete.',
            ]);
        }

        if ($onlyAllocationIds === null && $lockedAllocations->count() !== $lockedRequest->reserved_units) {
            throw ValidationException::withMessages([
                'reserved_units' => 'Active Reserved allocation count does not match request reserved_units counter.',
            ]);
        }

        $allocationsByStock = [];

        foreach ($lockedAllocations as $allocation) {
            $allocationsByStock[(int) $allocation->inventory_stock_id][] = $allocation;
        }

        ksort($allocationsByStock);

        foreach ($allocationsByStock as $stockId => $stockAllocations) {
            /** @var InventoryStock $lockedStock */
            $lockedStock = $lockedStocksById->get($stockId);

            usort($stockAllocations, static fn (InventoryAllocation $a, InventoryAllocation $b): int => $a->blood_unit_id <=> $b->blood_unit_id);

            $bucketUnits = new EloquentCollection;

            foreach ($stockAllocations as $allocation) {
                /** @var BloodUnit $unit */
                $unit = $lockedUnitsById->get((int) $allocation->blood_unit_id);
                $bucketUnits->push($unit);
            }

            $this->inventoryService->releaseLockedBucketUnits(
                lockedStock: $lockedStock,
                lockedReservedUnits: $bucketUnits,
                actor: $actor,
                description: $description,
                referenceType: 'blood_request',
                referenceId: $lockedRequest->id,
                occurredAt: $occurredAt
            );

            foreach ($stockAllocations as $allocation) {
                $allocation->allocation_status = 'Released';
                $allocation->released_at = $occurredAt;
                $allocation->save();
            }
        }

        return $lockedAllocations->count();
    }

    /**
     * Lock an existing InventoryStock bucket `(blood_group, component_id)` or fail validation cleanly.
     *
     * @throws ValidationException
     */
    private function lockExistingStockBucket(string $bloodGroup, int $componentId): InventoryStock
    {
        $lockedStock = InventoryStock::query()
            ->forBloodGroup($bloodGroup)
            ->forComponent($componentId)
            ->lockForUpdate()
            ->first();

        if (! $lockedStock instanceof InventoryStock) {
            throw ValidationException::withMessages([
                'available_units' => "No inventory stock bucket exists for {$bloodGroup} on component #{$componentId}.",
            ]);
        }

        return $lockedStock;
    }

    /**
     * Select candidate Available units under existing parent locks and re-lock them ordered by ID ASC.
     *
     * @param  list<int>  $explicitUnitIds
     * @return EloquentCollection<int, BloodUnit>
     *
     * @throws ValidationException
     */
    private function selectAndLockAvailableUnits(
        InventoryStock $lockedStock,
        int $quantity,
        array $explicitUnitIds,
        CarbonImmutable $asOf,
        string $errorKey
    ): EloquentCollection {
        if ($explicitUnitIds !== []) {
            $sortedIds = $explicitUnitIds;
            sort($sortedIds);

            $lockedUnits = BloodUnit::query()
                ->whereIn('id', $sortedIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lockedUnits->count() !== $quantity) {
                throw ValidationException::withMessages([
                    $errorKey => 'One or more specified blood units do not exist.',
                ]);
            }
        } else {
            $candidateIds = BloodUnit::fifoAvailable($lockedStock->blood_group, $lockedStock->blood_component_id, $asOf)
                ->whereDoesntHave('allocations', static fn ($query) => $query->active())
                ->orderBy('id')
                ->limit($quantity)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if (count($candidateIds) < $quantity) {
                throw ValidationException::withMessages([
                    $errorKey => "Insufficient non-expired, unallocated Available blood units in ({$lockedStock->blood_group}).",
                ]);
            }

            sort($candidateIds);

            $lockedUnits = BloodUnit::query()
                ->whereIn('id', $candidateIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lockedUnits->count() !== $quantity) {
                throw ValidationException::withMessages([
                    $errorKey => 'Target blood units changed concurrently during selection.',
                ]);
            }
        }

        foreach ($lockedUnits as $unit) {
            if (
                $unit->blood_group !== $lockedStock->blood_group
                || (int) $unit->blood_component_id !== (int) $lockedStock->blood_component_id
            ) {
                throw ValidationException::withMessages([
                    'blood_unit_id' => "Blood unit {$unit->unit_code} does not match target inventory bucket.",
                ]);
            }

            if ($unit->unit_status !== 'Available') {
                throw ValidationException::withMessages([
                    $errorKey => "Blood unit {$unit->unit_code} is no longer Available (current status: '{$unit->unit_status}').",
                ]);
            }

            if ($unit->expires_at->lte($asOf)) {
                throw ValidationException::withMessages([
                    'expires_at' => "Blood unit {$unit->unit_code} has expired and cannot be allocated.",
                ]);
            }
        }

        return $lockedUnits;
    }

    /**
     * Resolve the target blood component ID, enforcing locked request component parity when specified.
     *
     * @throws ValidationException
     */
    private function resolveTargetComponentId(
        BloodRequest $lockedRequest,
        ?int $componentId,
        ?string $componentCode
    ): int {
        if ($lockedRequest->blood_component_id !== null) {
            $requestComponent = $this->resolveActiveComponent((int) $lockedRequest->blood_component_id, null, 'blood_component_id');

            if ($componentId !== null || $componentCode !== null) {
                $providedComponent = $this->resolveActiveComponent($componentId, $componentCode, 'blood_component_id');

                if ((int) $providedComponent->id !== (int) $requestComponent->id) {
                    throw ValidationException::withMessages([
                        'blood_component_id' => "Dispatched/allocated component ({$providedComponent->code}) must match requested component ({$requestComponent->code}).",
                    ]);
                }
            }

            return (int) $requestComponent->id;
        }

        if ($componentId === null && $componentCode === null) {
            throw ValidationException::withMessages([
                'blood_component_id' => 'A blood component ID or code is required when the blood request does not specify a component.',
            ]);
        }

        return (int) $this->resolveActiveComponent($componentId, $componentCode, 'blood_component_id')->id;
    }

    /**
     * Validate active component compatibility rule and authorize override accountability when required.
     *
     * @return array{
     *     rule: BloodCompatibilityRule,
     *     is_override: bool,
     *     approver: ?User,
     *     override_reason: ?string
     * }
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function validateCompatibilityAndOverride(
        BloodRequest $lockedRequest,
        string $sourceBloodGroup,
        int $componentId,
        User $actor,
        ?User $overrideApprover,
        mixed $overrideReason,
        string $bloodGroupErrorKey
    ): array {
        $rule = BloodCompatibilityRule::query()
            ->active()
            ->forPair($lockedRequest->blood_group, $sourceBloodGroup, $componentId)
            ->first();

        if (! $rule instanceof BloodCompatibilityRule) {
            throw ValidationException::withMessages([
                $bloodGroupErrorKey => "No active blood compatibility rule permits supplying {$sourceBloodGroup} for {$lockedRequest->blood_group} on component #{$componentId}.",
            ]);
        }

        $requiresOverride = $rule->compatibility_level === 'Emergency Only' || $rule->requires_medical_override;

        if (! $requiresOverride) {
            return [
                'rule' => $rule,
                'is_override' => false,
                'approver' => null,
                'override_reason' => null,
            ];
        }

        $approver = $overrideApprover ?? $actor;
        Gate::forUser($approver)->authorize('overrideCompatibility', $lockedRequest);

        $normalizedReason = $this->inputNormalizer->normalizeRequiredText(
            $overrideReason,
            2000,
            'override_reason',
            'A non-empty clinical override reason is required for emergency or override-gated compatibility.'
        );

        return [
            'rule' => $rule,
            'is_override' => true,
            'approver' => $approver,
            'override_reason' => $normalizedReason,
        ];
    }

    /**
     * Resolve an active BloodComponent by ID or code.
     *
     * @throws ValidationException
     */
    private function resolveActiveComponent(?int $componentId, ?string $componentCode, string $errorKey): BloodComponent
    {
        $query = BloodComponent::query();

        $component = $componentId !== null
            ? $query->whereKey($componentId)->first()
            : $query->where('code', $componentCode)->first();

        if (! $component instanceof BloodComponent || ! $component->is_active) {
            throw ValidationException::withMessages([
                $errorKey => 'Selected blood component does not exist or is inactive.',
            ]);
        }

        return $component;
    }

    /**
     * Append an immutable timeline event row to `blood_request_status_histories`.
     */
    private function recordStatusHistory(
        BloodRequest $bloodRequest,
        string $eventName,
        ?string $previousStatus,
        string $newStatus,
        int $unitsAffected,
        User $actor,
        ?string $notes,
        CarbonImmutable $occurredAt
    ): void {
        BloodRequestStatusHistory::query()->create([
            'blood_request_id' => $bloodRequest->id,
            'event_name' => $eventName,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'units_affected' => $unitsAffected,
            'actor_user_id' => $actor->getKey(),
            'actor_name' => $actor->name,
            'notes' => $notes,
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * Reload a BloodRequest with its common fulfillment and audit relationships.
     */
    private function refreshRequestWithRelations(BloodRequest $bloodRequest): BloodRequest
    {
        return $bloodRequest->fresh([
            'hospital',
            'bloodComponent',
            'requestedBy',
            'reviewedBy',
            'cancelledBy',
            'allocations.bloodUnit',
            'fulfillments',
            'statusHistories',
        ]) ?? $bloodRequest;
    }

    /**
     * Validate a blood group string against canonical ABO/Rh enum values.
     *
     * @throws ValidationException
     */
    private function validateBloodGroup(mixed $value, string $field): string
    {
        $trimmed = is_string($value) ? trim($value) : '';

        if (! in_array($trimmed, BloodRequest::BLOOD_GROUPS, true)) {
            throw ValidationException::withMessages([
                $field => 'Blood group must be one of: '.implode(', ', BloodRequest::BLOOD_GROUPS).'.',
            ]);
        }

        return $trimmed;
    }

    /**
     * Validate a clinical triage priority against canonical BloodRequest priorities.
     *
     * @throws ValidationException
     */
    private function validatePriority(mixed $value): string
    {
        $trimmed = is_string($value) ? trim($value) : '';

        if (! in_array($trimmed, BloodRequest::PRIORITIES, true)) {
            throw ValidationException::withMessages([
                'priority' => 'Priority must be one of: '.implode(', ', BloodRequest::PRIORITIES).'.',
            ]);
        }

        return $trimmed;
    }

    /**
     * Parse and validate a required clinical rationale (>= 5 characters).
     *
     * @throws ValidationException
     */
    private function normalizeRequiredRationale(mixed $value, string $field, string $errorMessage): string
    {
        $trimmed = $this->inputNormalizer->normalizeRequiredText($value, 2000, $field, $errorMessage);

        if (mb_strlen($trimmed) < 5) {
            throw ValidationException::withMessages([
                $field => $errorMessage,
            ]);
        }

        return $trimmed;
    }

    /**
     * Parse a strictly positive integer field.
     *
     * @throws ValidationException
     */
    private function parsePositiveInt(mixed $value, string $field, string $errorMessage): int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d*$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw ValidationException::withMessages([
            $field => $errorMessage,
        ]);
    }

    /**
     * Parse an optional positive integer field or return null when empty.
     *
     * @throws ValidationException
     */
    private function parseOptionalPositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->parsePositiveInt($value, $field, "The {$field} value must be a positive integer.");
    }

    /**
     * Validate optional explicit `blood_unit_ids` list and match its count to requested quantity.
     *
     * @return list<int>
     *
     * @throws ValidationException
     */
    private function parseExplicitUnitIds(mixed $rawIds, int $quantity): array
    {
        if ($rawIds === null) {
            return [];
        }

        if (! is_array($rawIds) || $rawIds === []) {
            throw ValidationException::withMessages([
                'blood_unit_ids' => 'The blood_unit_ids field must be a non-empty list matching reservation quantity.',
            ]);
        }

        $parsedIds = [];

        foreach ($rawIds as $rawId) {
            $parsedIds[] = $this->parsePositiveInt($rawId, 'blood_unit_ids', 'Each blood_unit_ids entry must be a positive integer.');
        }

        $uniqueIds = array_values(array_unique($parsedIds));

        if (count($uniqueIds) !== count($parsedIds)) {
            throw ValidationException::withMessages([
                'blood_unit_ids' => 'Duplicate blood_unit_ids entries are not permitted.',
            ]);
        }

        if (count($uniqueIds) !== $quantity) {
            throw ValidationException::withMessages([
                'blood_unit_ids' => 'The count of blood_unit_ids must exactly match the requested quantity.',
            ]);
        }

        return $uniqueIds;
    }
}

