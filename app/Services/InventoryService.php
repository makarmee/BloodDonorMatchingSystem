<?php

namespace App\Services;

use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\InventoryAllocation;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Inventory domain service managing stock adjustments, reservations, releases, discards, expirations,
 * and serialized ledger reconciliation.
 *
 * Global pessimistic lock hierarchy shared across Donation/Screening/Inventory and future FulfillmentService:
 * `blood_requests / donors -> donations -> screening_records -> inventory_stocks -> blood_units (ordered by id) -> inventory_allocations`.
 */
class InventoryService
{
    /**
     * Allowed target BloodUnit status transitions mapped to valid ledger operations for manual decrements.
     *
     * @var array<string, list<string>>
     */
    private const array ADJUSTMENT_TARGET_STATUS_OPERATIONS = [
        'Discarded' => ['Manual Adjustment', 'Discarded'],
        'Expired' => ['Manual Adjustment', 'Expired'],
        'Dispatched' => ['Manual Adjustment', 'Removed'],
    ];

    /**
     * Internal bucket transfers whose `units_delta` tracks `available_units` movement rather than total physical stock change.
     *
     * @var list<string>
     */
    private const array INTERNAL_TRANSFER_OPERATIONS = ['Reserve', 'Release Reservation'];

    public function __construct(
        private ReferenceCodeGenerator $codeGenerator,
        private ServiceInputNormalizer $inputNormalizer
    ) {}

    /**
     * Perform an authorized manual adjustment on an inventory stock bucket.
     *
     * Rejects positive counter-only inflation lacking screened BloodUnit provenance; decrements
     * synchronize serialized Available units and append `inventory_transactions` ledger rows atomically.
     *
     * @param  array{
     *     units_delta?: int|string|null,
     *     blood_unit_ids?: list<int|string>,
     *     target_unit_status?: string|null,
     *     operation_type?: string|null,
     *     minimum_level?: int|string|null,
     *     target_capacity?: int|string|null,
     *     description?: string|null,
     *     reason?: string|null,
     *     reference_type?: string|null,
     *     reference_id?: int|string|null
     * }  $attributes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function adjustStock(InventoryStock $stock, ?User $actor, array $attributes): InventoryStock
    {
        $this->authorizeAction($actor, 'adjust', $stock);

        $validated = $this->validateAdjustmentAttributes($attributes);
        $now = CarbonImmutable::now();

        $updatedStock = DB::transaction(function () use ($stock, $actor, $validated, $now): InventoryStock {
            $lockedStock = InventoryStock::query()
                ->whereKey($stock->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($validated['minimum_level'] !== null) {
                $lockedStock->minimum_level = $validated['minimum_level'];
            }

            if ($validated['target_capacity'] !== null) {
                $lockedStock->target_capacity = $validated['target_capacity'];
            }

            if ($validated['units_delta'] < 0) {
                $quantity = abs($validated['units_delta']);
                $lockedUnits = $this->lockAvailableUnitsForDecrement(
                    lockedStock: $lockedStock,
                    quantity: $quantity,
                    explicitUnitIds: $validated['blood_unit_ids'],
                    targetUnitStatus: $validated['target_unit_status'],
                    asOf: $now
                );

                $this->decrementAvailableUnits(
                    lockedStock: $lockedStock,
                    lockedUnits: $lockedUnits,
                    actor: $actor,
                    targetUnitStatus: $validated['target_unit_status'],
                    operationType: $validated['operation_type'],
                    description: $validated['description'],
                    referenceType: $validated['reference_type'] ?? 'manual',
                    referenceId: $validated['reference_id'],
                    occurredAt: $now
                );
            } else {
                $lockedStock->last_adjusted_at = $now;
                $lockedStock->save();
            }

            return $lockedStock;
        }, 3);

        return $updatedStock->fresh(['bloodComponent']) ?? $updatedStock;
    }

    /**
     * Reserve a quantity of non-expired Available blood units in FIFO expiry order.
     *
     * @param  array{
     *     description?: string|null,
     *     reason?: string|null,
     *     reference_type?: string|null,
     *     reference_id?: int|string|null,
     *     as_of?: CarbonInterface|string|null
     * }  $attributes
     * @return Collection<int, BloodUnit>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reserveUnits(InventoryStock $stock, ?User $actor, int $quantity, array $attributes = []): Collection
    {
        $this->authorizeAction($actor, 'reserve', $stock);

        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Reservation quantity must be at least 1 unit.',
            ]);
        }

        $context = $this->validateMovementContext(
            $attributes,
            'Reserved available blood unit from inventory pool.'
        );
        $asOf = $this->inputNormalizer->parseOptionalDateTime($attributes['as_of'] ?? null, 'as_of')
            ?? CarbonImmutable::now();

        /** @var list<int> $reservedIds */
        $reservedIds = DB::transaction(function () use ($stock, $actor, $quantity, $context, $asOf): array {
            // Always lock the stock bucket row first to serialize concurrent FIFO selections.
            $lockedStock = InventoryStock::query()
                ->whereKey($stock->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedStock->available_units < $quantity) {
                throw ValidationException::withMessages([
                    'available_units' => "Insufficient available stock: requested {$quantity}, available {$lockedStock->available_units}.",
                ]);
            }

            $lockedUnits = BloodUnit::fifoAvailable(
                $lockedStock->blood_group,
                $lockedStock->blood_component_id,
                $asOf
            )
                ->whereDoesntHave('allocations', static fn ($query) => $query->active())
                ->orderBy('id')
                ->limit($quantity)
                ->lockForUpdate()
                ->get();

            if ($lockedUnits->count() < $quantity) {
                throw ValidationException::withMessages([
                    'available_units' => 'Insufficient non-expired, unallocated Available blood units to satisfy this reservation.',
                ]);
            }

            foreach ($lockedUnits as $lockedUnit) {
                $this->ensureNoActiveAllocation($lockedUnit, 'available_units');
            }

            $this->reserveLockedUnits(
                lockedStock: $lockedStock,
                lockedUnits: $lockedUnits,
                actor: $actor,
                description: $context['description'],
                referenceType: $context['reference_type'],
                referenceId: $context['reference_id'],
                occurredAt: CarbonImmutable::now()
            );

            return $lockedUnits->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        }, 3);

        return BloodUnit::query()
            ->whereIn('id', $reservedIds)
            ->with(['bloodComponent', 'donation'])
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Reserve a single serialized Available, non-expired blood unit.
     *
     * @param  array{
     *     description?: string|null,
     *     reason?: string|null,
     *     reference_type?: string|null,
     *     reference_id?: int|string|null,
     *     as_of?: CarbonInterface|string|null
     * }  $attributes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reserveUnit(BloodUnit $unit, ?User $actor, array $attributes = []): BloodUnit
    {
        $this->authorizeAction($actor, 'reserve', $unit);

        $context = $this->validateMovementContext(
            $attributes,
            "Reserved serialized blood unit {$unit->unit_code}."
        );
        $asOf = $this->inputNormalizer->parseOptionalDateTime($attributes['as_of'] ?? null, 'as_of')
            ?? CarbonImmutable::now();

        $reservedUnit = DB::transaction(function () use ($unit, $actor, $context, $asOf): BloodUnit {
            $lockedStock = $this->lockExistingStockForUnit($unit);

            $lockedUnit = BloodUnit::query()
                ->whereKey($unit->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureUnitMatchesStock($lockedUnit, $lockedStock);

            if ($lockedUnit->unit_status !== 'Available') {
                throw ValidationException::withMessages([
                    'unit_status' => "Cannot reserve blood unit with status '{$lockedUnit->unit_status}'.",
                ]);
            }

            if ($lockedUnit->expires_at->lte($asOf)) {
                throw ValidationException::withMessages([
                    'expires_at' => 'Expired blood units cannot be reserved.',
                ]);
            }

            $this->ensureNoActiveAllocation($lockedUnit, 'unit_status');

            if ($lockedStock->available_units < 1) {
                throw ValidationException::withMessages([
                    'available_units' => 'Insufficient available stock counter for unit reservation.',
                ]);
            }

            $this->reserveLockedUnits(
                lockedStock: $lockedStock,
                lockedUnits: new EloquentCollection([$lockedUnit]),
                actor: $actor,
                description: $context['description'],
                referenceType: $context['reference_type'],
                referenceId: $context['reference_id'],
                occurredAt: CarbonImmutable::now()
            );

            return $lockedUnit;
        }, 3);

        return $reservedUnit->fresh(['bloodComponent', 'donation']) ?? $reservedUnit;
    }

    /**
     * Return a standalone Reserved blood unit back to Available inventory.
     *
     * @param  array{
     *     description?: string|null,
     *     reason?: string|null,
     *     reference_type?: string|null,
     *     reference_id?: int|string|null
     * }  $attributes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function releaseReservation(BloodUnit $unit, ?User $actor, array $attributes = []): BloodUnit
    {
        $this->authorizeAction($actor, 'releaseReservation', $unit);

        $context = $this->validateMovementContext(
            $attributes,
            "Released reservation on blood unit {$unit->unit_code} back to available inventory."
        );

        $releasedUnit = DB::transaction(function () use ($unit, $actor, $context): BloodUnit {
            $lockedStock = $this->lockExistingStockForUnit($unit);

            $lockedUnit = BloodUnit::query()
                ->whereKey($unit->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureUnitMatchesStock($lockedUnit, $lockedStock);

            if ($lockedUnit->unit_status !== 'Reserved') {
                throw ValidationException::withMessages([
                    'unit_status' => "Cannot release reservation for blood unit with status '{$lockedUnit->unit_status}'.",
                ]);
            }

            $this->ensureNoActiveAllocation($lockedUnit, 'allocation_status');

            if ($lockedStock->reserved_units < 1) {
                throw ValidationException::withMessages([
                    'reserved_units' => 'Cannot decrement reserved stock counter below zero.',
                ]);
            }

            $occurredAt = CarbonImmutable::now();
            $availableBefore = $lockedStock->available_units;
            $reservedBefore = $lockedStock->reserved_units;
            $availableAfter = $availableBefore + 1;
            $reservedAfter = $reservedBefore - 1;

            $lockedUnit->unit_status = 'Available';
            $lockedUnit->save();

            $lockedStock->available_units = $availableAfter;
            $lockedStock->reserved_units = $reservedAfter;
            $lockedStock->last_adjusted_at = $occurredAt;
            $lockedStock->save();

            $this->recordTransaction(
                lockedStock: $lockedStock,
                bloodUnit: $lockedUnit,
                operationType: 'Release Reservation',
                unitsDelta: 1,
                availableBefore: $availableBefore,
                availableAfter: $availableAfter,
                reservedBefore: $reservedBefore,
                reservedAfter: $reservedAfter,
                actor: $actor,
                description: $context['description'],
                referenceType: $context['reference_type'] ?? 'manual',
                referenceId: $context['reference_id'],
                occurredAt: $occurredAt
            );

            return $lockedUnit;
        }, 3);

        return $releasedUnit->fresh(['bloodComponent', 'donation']) ?? $releasedUnit;
    }

    /**
     * Discard an Available, standalone Reserved, or pre-release Quarantined blood unit.
     *
     * Decrements `available_units` for Available units, decrements `reserved_units` for Reserved
     * units without active request allocations, and preserves zero-delta audit logging for
     * pre-release Quarantined units without duplicating ScreeningService screening workflows.
     *
     * @param  array{
     *     description?: string|null,
     *     reason?: string|null,
     *     reference_type?: string|null,
     *     reference_id?: int|string|null
     * }  $attributes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function discardUnit(BloodUnit $unit, ?User $actor, array $attributes = []): BloodUnit
    {
        $this->authorizeAction($actor, 'discard', $unit);

        $description = $this->normalizeRequiredJustification($attributes);
        $referenceType = $this->inputNormalizer->normalizeOptionalText($attributes['reference_type'] ?? null, 64, 'reference_type');
        $referenceId = $this->parseOptionalPositiveInt($attributes['reference_id'] ?? null, 'reference_id');

        $discardedUnit = DB::transaction(function () use ($unit, $actor, $description, $referenceType, $referenceId): BloodUnit {
            $bucket = $this->resolveFreshUnitBucketKey($unit);
            $lockedStock = $this->ensureAndLockInventoryStock($bucket['blood_group'], $bucket['blood_component_id']);

            $lockedUnit = BloodUnit::query()
                ->whereKey($unit->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureUnitMatchesStock($lockedUnit, $lockedStock);

            $occurredAt = CarbonImmutable::now();

            if ($lockedUnit->unit_status === 'Available') {
                $this->ensureNoActiveAllocation($lockedUnit, 'allocation_status');

                if ($lockedStock->available_units < 1) {
                    throw ValidationException::withMessages([
                        'available_units' => 'Cannot discard Available unit because stock available_units is already zero.',
                    ]);
                }

                $this->decrementAvailableUnits(
                    lockedStock: $lockedStock,
                    lockedUnits: new EloquentCollection([$lockedUnit]),
                    actor: $actor,
                    targetUnitStatus: 'Discarded',
                    operationType: 'Discarded',
                    description: $description,
                    referenceType: $referenceType ?? 'manual',
                    referenceId: $referenceId,
                    occurredAt: $occurredAt
                );

                return $lockedUnit;
            }

            if ($lockedUnit->unit_status === 'Reserved') {
                $this->ensureNoActiveAllocation($lockedUnit, 'allocation_status');

                if ($lockedStock->reserved_units < 1) {
                    throw ValidationException::withMessages([
                        'reserved_units' => 'Cannot discard Reserved unit because stock reserved_units is already zero.',
                    ]);
                }

                $availableBefore = $lockedStock->available_units;
                $reservedBefore = $lockedStock->reserved_units;
                $reservedAfter = $reservedBefore - 1;

                $lockedUnit->unit_status = 'Discarded';
                $lockedUnit->save();

                $lockedStock->reserved_units = $reservedAfter;
                $lockedStock->last_adjusted_at = $occurredAt;
                $lockedStock->save();

                $this->recordTransaction(
                    lockedStock: $lockedStock,
                    bloodUnit: $lockedUnit,
                    operationType: 'Discarded',
                    unitsDelta: -1,
                    availableBefore: $availableBefore,
                    availableAfter: $availableBefore,
                    reservedBefore: $reservedBefore,
                    reservedAfter: $reservedAfter,
                    actor: $actor,
                    description: $description,
                    referenceType: $referenceType ?? 'manual',
                    referenceId: $referenceId,
                    occurredAt: $occurredAt
                );

                return $lockedUnit;
            }

            if ($lockedUnit->unit_status === 'Quarantined') {
                $donation = Donation::query()
                    ->whereKey($lockedUnit->donation_id)
                    ->first();

                if ($donation instanceof Donation && $donation->screening_status === 'Failed') {
                    throw ValidationException::withMessages([
                        'unit_status' => 'Failed-screening quarantine disposal is managed by ScreeningService.',
                    ]);
                }

                $lockedUnit->unit_status = 'Discarded';
                $lockedUnit->save();

                $this->recordTransaction(
                    lockedStock: $lockedStock,
                    bloodUnit: $lockedUnit,
                    operationType: 'Discarded',
                    unitsDelta: 0,
                    availableBefore: $lockedStock->available_units,
                    availableAfter: $lockedStock->available_units,
                    reservedBefore: $lockedStock->reserved_units,
                    reservedAfter: $lockedStock->reserved_units,
                    actor: $actor,
                    description: $description,
                    referenceType: $referenceType ?? 'donation',
                    referenceId: $referenceId ?? $lockedUnit->donation_id,
                    occurredAt: $occurredAt
                );

                return $lockedUnit;
            }

            throw ValidationException::withMessages([
                'unit_status' => "Blood unit with status '{$lockedUnit->unit_status}' cannot be discarded.",
            ]);
        }, 3);

        return $discardedUnit->fresh(['bloodComponent', 'donation']) ?? $discardedUnit;
    }

    /**
     * Mark a past-expiry Available blood unit as Expired and decrement available stock.
     *
     * @param  array{
     *     description?: string|null,
     *     reason?: string|null,
     *     reference_type?: string|null,
     *     reference_id?: int|string|null,
     *     as_of?: CarbonInterface|string|null
     * }  $attributes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function markUnitExpired(BloodUnit $unit, ?User $actor, array $attributes = []): BloodUnit
    {
        $this->authorizeAction($actor, 'markExpired', $unit);

        $context = $this->validateMovementContext(
            $attributes,
            "Marked expired blood unit {$unit->unit_code} and removed from available inventory."
        );
        $asOf = $this->inputNormalizer->parseOptionalDateTime($attributes['as_of'] ?? null, 'as_of')
            ?? CarbonImmutable::now();

        $expiredUnit = DB::transaction(function () use ($unit, $actor, $context, $asOf): BloodUnit {
            $lockedStock = $this->lockExistingStockForUnit($unit);

            $lockedUnit = BloodUnit::query()
                ->whereKey($unit->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureUnitMatchesStock($lockedUnit, $lockedStock);

            if ($lockedUnit->unit_status !== 'Available') {
                throw ValidationException::withMessages([
                    'unit_status' => "Only Available blood units can be marked Expired; current status is '{$lockedUnit->unit_status}'.",
                ]);
            }

            if ($lockedUnit->expires_at->gt($asOf)) {
                throw ValidationException::withMessages([
                    'expires_at' => 'Cannot mark a non-expired blood unit as Expired.',
                ]);
            }

            $this->ensureNoActiveAllocation($lockedUnit, 'allocation_status');

            if ($lockedStock->available_units < 1) {
                throw ValidationException::withMessages([
                    'available_units' => 'Cannot mark unit Expired because stock available_units is already zero.',
                ]);
            }

            $this->decrementAvailableUnits(
                lockedStock: $lockedStock,
                lockedUnits: new EloquentCollection([$lockedUnit]),
                actor: $actor,
                targetUnitStatus: 'Expired',
                operationType: 'Expired',
                description: $context['description'],
                referenceType: $context['reference_type'] ?? 'manual',
                referenceId: $context['reference_id'],
                occurredAt: $asOf
            );

            return $lockedUnit;
        }, 3);

        return $expiredUnit->fresh(['bloodComponent', 'donation']) ?? $expiredUnit;
    }

    /**
     * Evaluate stock level status (`critical`, `low`, or `normal`) dynamically from `inventory_stocks`.
     */
    public function evaluateStockStatus(InventoryStock $stock): string
    {
        $criticalThreshold = (int) floor($stock->minimum_level * 0.6);

        if ($stock->available_units <= $criticalThreshold) {
            return 'critical';
        }

        if ($stock->available_units <= $stock->minimum_level) {
            return 'low';
        }

        return 'normal';
    }

    /**
     * Verify stock counter integrity against the append-only ledger and serialized BloodUnit counts.
     *
     * Detects inconsistencies without modifying or repairing stock or ledger data.
     *
     * @return array{
     *     inventory_stock_id: int,
     *     blood_group: string,
     *     blood_component_id: int,
     *     is_reconciled: bool,
     *     discrepancies: list<string>,
     *     stock_available_units: int,
     *     stock_reserved_units: int,
     *     stock_total_units: int,
     *     ledger_available_delta_sum: int,
     *     ledger_reserved_delta_sum: int,
     *     ledger_physical_units_delta_sum: int,
     *     quarantined_zero_delta_discards_count: int,
     *     unit_available_count: int,
     *     unit_reserved_count: int,
     *     transaction_count: int
     * }
     */
    public function reconcileStock(InventoryStock $stock): array
    {
        return DB::transaction(function () use ($stock): array {
            // Global lock order: inventory_stocks -> blood_units (id ASC) for a serialized point-in-time view.
            $freshStock = InventoryStock::query()
                ->whereKey($stock->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $activeUnits = BloodUnit::query()
                ->where('blood_group', $freshStock->blood_group)
                ->where('blood_component_id', $freshStock->blood_component_id)
                ->whereIn('unit_status', ['Available', 'Reserved'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'unit_status']);

            $transactions = InventoryTransaction::query()
                ->where('inventory_stock_id', $freshStock->id)
                ->with('bloodUnit:id,unit_code,blood_group,blood_component_id')
                ->orderBy('id')
                ->get();

            $discrepancies = [];
            $expectedAvailable = 0;
            $expectedReserved = 0;
            $availableDeltaSum = 0;
            $reservedDeltaSum = 0;
            $physicalUnitsDeltaSum = 0;
            $quarantinedZeroDeltaDiscardsCount = 0;
            /** @var array<int, string> $stockInUnitIds */
            $stockInUnitIds = [];
            /** @var array<int, string> $terminalUnitIds */
            $terminalUnitIds = [];

            foreach ($transactions as $txn) {
                if ($txn->available_before !== $expectedAvailable || $txn->reserved_before !== $expectedReserved) {
                    $discrepancies[] = "Transaction {$txn->transaction_code} (ID {$txn->id}) starting balance ({$txn->available_before}/{$txn->reserved_before}) does not match prior ledger balance ({$expectedAvailable}/{$expectedReserved}).";
                }

                $availableDelta = $txn->available_after - $txn->available_before;
                $reservedDelta = $txn->reserved_after - $txn->reserved_before;
                $availableDeltaSum += $availableDelta;
                $reservedDeltaSum += $reservedDelta;

                array_push(
                    $discrepancies,
                    ...$this->validateReconciliationTransaction(
                        txn: $txn,
                        freshStock: $freshStock,
                        availableDelta: $availableDelta,
                        reservedDelta: $reservedDelta,
                        stockInUnitIds: $stockInUnitIds,
                        terminalUnitIds: $terminalUnitIds,
                        quarantinedZeroDeltaDiscardsCount: $quarantinedZeroDeltaDiscardsCount,
                        physicalUnitsDeltaSum: $physicalUnitsDeltaSum
                    )
                );

                $expectedAvailable = $txn->available_after;
                $expectedReserved = $txn->reserved_after;
            }

            if ($availableDeltaSum !== $freshStock->available_units) {
                $discrepancies[] = "Stock available_units ({$freshStock->available_units}) does not match ledger available delta sum ({$availableDeltaSum}).";
            }

            if ($reservedDeltaSum !== $freshStock->reserved_units) {
                $discrepancies[] = "Stock reserved_units ({$freshStock->reserved_units}) does not match ledger reserved delta sum ({$reservedDeltaSum}).";
            }

            $stockTotalUnits = $freshStock->available_units + $freshStock->reserved_units;

            if ($physicalUnitsDeltaSum !== $stockTotalUnits) {
                $discrepancies[] = "Total physical stock ({$stockTotalUnits}) does not match net physical units_delta sum ({$physicalUnitsDeltaSum}).";
            }

            $unitAvailableCount = $activeUnits->where('unit_status', 'Available')->count();
            $unitReservedCount = $activeUnits->where('unit_status', 'Reserved')->count();

            if ($unitAvailableCount !== $freshStock->available_units) {
                $discrepancies[] = "Serialized Available blood_units count ({$unitAvailableCount}) does not match stock available_units ({$freshStock->available_units}).";
            }

            if ($unitReservedCount !== $freshStock->reserved_units) {
                $discrepancies[] = "Serialized Reserved blood_units count ({$unitReservedCount}) does not match stock reserved_units ({$freshStock->reserved_units}).";
            }

            return [
                'inventory_stock_id' => $freshStock->id,
                'blood_group' => $freshStock->blood_group,
                'blood_component_id' => $freshStock->blood_component_id,
                'is_reconciled' => $discrepancies === [],
                'discrepancies' => $discrepancies,
                'stock_available_units' => $freshStock->available_units,
                'stock_reserved_units' => $freshStock->reserved_units,
                'stock_total_units' => $stockTotalUnits,
                'ledger_available_delta_sum' => $availableDeltaSum,
                'ledger_reserved_delta_sum' => $reservedDeltaSum,
                'ledger_physical_units_delta_sum' => $physicalUnitsDeltaSum,
                'quarantined_zero_delta_discards_count' => $quarantinedZeroDeltaDiscardsCount,
                'unit_available_count' => $unitAvailableCount,
                'unit_reserved_count' => $unitReservedCount,
                'transaction_count' => $transactions->count(),
            ];
        }, 3);
    }

    /**
     * Validate a single ledger transaction's operation semantics, unit dimension parity, and uniqueness.
     *
     * @param  array<int, string>  $stockInUnitIds
     * @param  array<int, string>  $terminalUnitIds
     * @return list<string>
     */
    private function validateReconciliationTransaction(
        InventoryTransaction $txn,
        InventoryStock $freshStock,
        int $availableDelta,
        int $reservedDelta,
        array &$stockInUnitIds,
        array &$terminalUnitIds,
        int &$quarantinedZeroDeltaDiscardsCount,
        int &$physicalUnitsDeltaSum
    ): array {
        $errors = [];

        switch ($txn->operation_type) {
            case 'Stock In':
                $physicalUnitsDeltaSum += $txn->units_delta;
                if ($txn->units_delta <= 0 || $availableDelta !== $txn->units_delta || $reservedDelta !== 0) {
                    $errors[] = "Stock In transaction {$txn->transaction_code} has invalid delta ({$txn->units_delta}) or balance changes ({$availableDelta}/{$reservedDelta}).";
                }
                break;

            case 'Reserve':
                if ($txn->units_delta >= 0 || $availableDelta !== $txn->units_delta || $reservedDelta !== -$txn->units_delta) {
                    $errors[] = "Reserve transaction {$txn->transaction_code} has invalid delta ({$txn->units_delta}) or balance changes ({$availableDelta}/{$reservedDelta}).";
                }
                break;

            case 'Release Reservation':
                if ($txn->units_delta <= 0 || $availableDelta !== $txn->units_delta || $reservedDelta !== -$txn->units_delta) {
                    $errors[] = "Release Reservation transaction {$txn->transaction_code} has invalid delta ({$txn->units_delta}) or balance changes ({$availableDelta}/{$reservedDelta}).";
                }
                break;

            case 'Manual Adjustment':
                $physicalUnitsDeltaSum += $txn->units_delta;
                if ($txn->units_delta === 0 || $availableDelta !== $txn->units_delta || $reservedDelta !== 0) {
                    $errors[] = "Manual Adjustment transaction {$txn->transaction_code} has invalid delta ({$txn->units_delta}) or balance changes ({$availableDelta}/{$reservedDelta}).";
                }
                break;

            case 'Removed':
            case 'Expired':
                $physicalUnitsDeltaSum += $txn->units_delta;
                if ($txn->units_delta >= 0 || $availableDelta !== $txn->units_delta || $reservedDelta !== 0) {
                    $errors[] = "{$txn->operation_type} transaction {$txn->transaction_code} has invalid delta ({$txn->units_delta}) or balance changes ({$availableDelta}/{$reservedDelta}).";
                }
                break;

            case 'Dispatch':
                $physicalUnitsDeltaSum += $txn->units_delta;
                if ($txn->units_delta >= 0 || $availableDelta > 0 || $reservedDelta > 0 || ($availableDelta + $reservedDelta) !== $txn->units_delta) {
                    $errors[] = "Dispatch transaction {$txn->transaction_code} has invalid delta ({$txn->units_delta}) or balance changes ({$availableDelta}/{$reservedDelta}).";
                }
                break;

            case 'Discarded':
                if ($txn->units_delta === 0) {
                    if ($availableDelta !== 0 || $reservedDelta !== 0) {
                        $errors[] = "Zero-delta Discarded transaction {$txn->transaction_code} unexpectedly modified balances ({$availableDelta}/{$reservedDelta}).";
                    } else {
                        $quarantinedZeroDeltaDiscardsCount++;
                    }
                } else {
                    $physicalUnitsDeltaSum += $txn->units_delta;
                    if ($txn->units_delta > 0 || $availableDelta > 0 || $reservedDelta > 0 || ($availableDelta + $reservedDelta) !== $txn->units_delta) {
                        $errors[] = "Discarded transaction {$txn->transaction_code} has invalid delta ({$txn->units_delta}) or balance changes ({$availableDelta}/{$reservedDelta}).";
                    }
                }
                break;

            default:
                $errors[] = "Transaction {$txn->transaction_code} has unsupported operation_type '{$txn->operation_type}'.";
        }

        if ($txn->blood_unit_id !== null) {
            if (! $txn->bloodUnit instanceof BloodUnit) {
                $errors[] = "Transaction {$txn->transaction_code} references missing BloodUnit ID {$txn->blood_unit_id}.";
            } else {
                if (
                    $txn->bloodUnit->blood_group !== $freshStock->blood_group
                    || (int) $txn->bloodUnit->blood_component_id !== (int) $freshStock->blood_component_id
                ) {
                    $errors[] = "Transaction {$txn->transaction_code} references BloodUnit {$txn->bloodUnit->unit_code} from dimension ({$txn->bloodUnit->blood_group}/{$txn->bloodUnit->blood_component_id}) instead of ({$freshStock->blood_group}/{$freshStock->blood_component_id}).";
                }

                if ($txn->operation_type === 'Stock In') {
                    if (isset($stockInUnitIds[$txn->blood_unit_id])) {
                        $errors[] = "Duplicate Stock In transaction {$txn->transaction_code} for BloodUnit ID {$txn->blood_unit_id} (prior: {$stockInUnitIds[$txn->blood_unit_id]}).";
                    } else {
                        $stockInUnitIds[$txn->blood_unit_id] = $txn->transaction_code;
                    }
                }

                if (in_array($txn->operation_type, ['Dispatch', 'Removed', 'Expired', 'Discarded'], true)) {
                    if (isset($terminalUnitIds[$txn->blood_unit_id])) {
                        $errors[] = "Duplicate terminal transaction {$txn->transaction_code} ({$txn->operation_type}) for BloodUnit ID {$txn->blood_unit_id} (prior: {$terminalUnitIds[$txn->blood_unit_id]}).";
                    } else {
                        $terminalUnitIds[$txn->blood_unit_id] = $txn->transaction_code;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Authorize the acting user via Laravel Gate policies after checking authentication.
     *
     * @throws AuthorizationException
     */
    private function authorizeAction(?User $actor, string $ability, mixed $arguments): void
    {
        if (! $actor instanceof User) {
            throw new AuthorizationException('An authenticated and authorized user is required for inventory operations.');
        }

        Gate::forUser($actor)->authorize($ability, $arguments);
    }

    /**
     * Read current blood group and component dimensions for a unit directly from storage.
     *
     * @return array{blood_group: string, blood_component_id: int}
     */
    private function resolveFreshUnitBucketKey(BloodUnit $unit): array
    {
        $freshUnit = BloodUnit::query()
            ->whereKey($unit->getKey())
            ->firstOrFail(['id', 'blood_group', 'blood_component_id']);

        return [
            'blood_group' => (string) $freshUnit->blood_group,
            'blood_component_id' => (int) $freshUnit->blood_component_id,
        ];
    }

    /**
     * Lock the existing `inventory_stocks` row matching a serialized BloodUnit's current database dimension.
     *
     * @throws ValidationException
     */
    private function lockExistingStockForUnit(BloodUnit $unit): InventoryStock
    {
        $bucket = $this->resolveFreshUnitBucketKey($unit);

        $lockedStock = InventoryStock::query()
            ->forBloodGroup($bucket['blood_group'])
            ->forComponent($bucket['blood_component_id'])
            ->lockForUpdate()
            ->first();

        if (! $lockedStock instanceof InventoryStock) {
            throw ValidationException::withMessages([
                'inventory_stock_id' => 'Matching inventory stock bucket does not exist for this blood unit.',
            ]);
        }

        return $lockedStock;
    }

    /**
     * Ensure an `inventory_stocks` bucket exists for a pre-release unit and lock it `FOR UPDATE`.
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
            ->forBloodGroup($bloodGroup)
            ->forComponent($bloodComponentId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Verify blood group and component parity between locked unit and locked stock bucket.
     *
     * @throws ValidationException
     */
    private function ensureUnitMatchesStock(BloodUnit $lockedUnit, InventoryStock $lockedStock): void
    {
        if (
            $lockedUnit->blood_group !== $lockedStock->blood_group
            || (int) $lockedUnit->blood_component_id !== (int) $lockedStock->blood_component_id
        ) {
            throw ValidationException::withMessages([
                'blood_unit_id' => 'Blood unit group or component does not match target inventory stock bucket.',
            ]);
        }
    }

    /**
     * Verify a BloodUnit is not currently held by an active hospital request allocation.
     *
     * @throws ValidationException
     */
    private function ensureNoActiveAllocation(BloodUnit $lockedUnit, string $errorKey): void
    {
        $hasActiveAllocation = InventoryAllocation::query()
            ->where('blood_unit_id', $lockedUnit->id)
            ->active()
            ->lockForUpdate()
            ->exists();

        if ($hasActiveAllocation) {
            throw ValidationException::withMessages([
                $errorKey => "Blood unit {$lockedUnit->unit_code} is linked to an active request allocation and must be released through request fulfillment workflows.",
            ]);
        }
    }

    /**
     * Lock target Available units for a negative manual stock adjustment.
     *
     * @param  list<int>  $explicitUnitIds
     * @return EloquentCollection<int, BloodUnit>
     *
     * @throws ValidationException
     */
    private function lockAvailableUnitsForDecrement(
        InventoryStock $lockedStock,
        int $quantity,
        array $explicitUnitIds,
        string $targetUnitStatus,
        CarbonImmutable $asOf
    ): EloquentCollection {
        if ($lockedStock->available_units < $quantity) {
            throw ValidationException::withMessages([
                'available_units' => "Cannot decrease stock by {$quantity}: only {$lockedStock->available_units} Available unit(s) remain.",
            ]);
        }

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
                    'blood_unit_ids' => 'One or more specified blood units do not exist.',
                ]);
            }

            foreach ($lockedUnits as $unit) {
                $this->ensureUnitMatchesStock($unit, $lockedStock);

                if ($unit->unit_status !== 'Available') {
                    throw ValidationException::withMessages([
                        'blood_unit_ids' => "Blood unit {$unit->unit_code} must have status 'Available' for stock adjustment (found '{$unit->unit_status}').",
                    ]);
                }

                $this->ensureNoActiveAllocation($unit, 'blood_unit_ids');

                if ($targetUnitStatus === 'Expired' && $unit->expires_at->gt($asOf)) {
                    throw ValidationException::withMessages([
                        'blood_unit_ids' => "Blood unit {$unit->unit_code} has not reached its expiry timestamp.",
                    ]);
                }
            }

            return $lockedUnits;
        }

        $query = BloodUnit::query()
            ->where('blood_group', $lockedStock->blood_group)
            ->where('blood_component_id', $lockedStock->blood_component_id)
            ->where('unit_status', 'Available')
            ->whereDoesntHave('allocations', static fn ($subQuery) => $subQuery->active());

        if ($targetUnitStatus === 'Expired') {
            $query->expiringBefore($asOf);
        }

        $lockedUnits = $query
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($quantity)
            ->lockForUpdate()
            ->get();

        if ($lockedUnits->count() < $quantity) {
            throw ValidationException::withMessages([
                'available_units' => "Fewer than {$quantity} eligible Available blood unit(s) exist for this adjustment.",
            ]);
        }

        foreach ($lockedUnits as $unit) {
            $this->ensureNoActiveAllocation($unit, 'blood_unit_ids');
        }

        return $lockedUnits;
    }

    /**
     * Atomically reserve locked Available units and record paired `Reserve` ledger entries.
     *
     * @param  EloquentCollection<int, BloodUnit>  $lockedUnits
     */
    private function reserveLockedUnits(
        InventoryStock $lockedStock,
        EloquentCollection $lockedUnits,
        User $actor,
        string $description,
        ?string $referenceType,
        ?int $referenceId,
        CarbonImmutable $occurredAt
    ): void {
        $runningAvailable = $lockedStock->available_units;
        $runningReserved = $lockedStock->reserved_units;

        foreach ($lockedUnits as $unit) {
            $unit->unit_status = 'Reserved';
            $unit->save();

            $availableBefore = $runningAvailable;
            $reservedBefore = $runningReserved;
            $runningAvailable--;
            $runningReserved++;

            $this->recordTransaction(
                lockedStock: $lockedStock,
                bloodUnit: $unit,
                operationType: 'Reserve',
                unitsDelta: -1,
                availableBefore: $availableBefore,
                availableAfter: $runningAvailable,
                reservedBefore: $reservedBefore,
                reservedAfter: $runningReserved,
                actor: $actor,
                description: $description,
                referenceType: $referenceType ?? 'manual',
                referenceId: $referenceId,
                occurredAt: $occurredAt
            );
        }

        $lockedStock->available_units = $runningAvailable;
        $lockedStock->reserved_units = $runningReserved;
        $lockedStock->last_adjusted_at = $occurredAt;
        $lockedStock->save();
    }

    /**
     * Atomically decrement `available_units`, transition units, and write append-only ledger rows.
     *
     * @param  EloquentCollection<int, BloodUnit>  $lockedUnits
     */
    private function decrementAvailableUnits(
        InventoryStock $lockedStock,
        EloquentCollection $lockedUnits,
        User $actor,
        string $targetUnitStatus,
        string $operationType,
        string $description,
        string $referenceType,
        ?int $referenceId,
        CarbonImmutable $occurredAt
    ): void {
        $runningAvailable = $lockedStock->available_units;
        $reservedUnits = $lockedStock->reserved_units;

        foreach ($lockedUnits as $unit) {
            $unit->unit_status = $targetUnitStatus;
            $unit->save();

            $availableBefore = $runningAvailable;
            $runningAvailable--;

            $this->recordTransaction(
                lockedStock: $lockedStock,
                bloodUnit: $unit,
                operationType: $operationType,
                unitsDelta: -1,
                availableBefore: $availableBefore,
                availableAfter: $runningAvailable,
                reservedBefore: $reservedUnits,
                reservedAfter: $reservedUnits,
                actor: $actor,
                description: $description,
                referenceType: $referenceType,
                referenceId: $referenceId,
                occurredAt: $occurredAt
            );
        }

        $lockedStock->available_units = $runningAvailable;
        $lockedStock->last_adjusted_at = $occurredAt;
        $lockedStock->save();
    }

    /**
     * Append an immutable `inventory_transactions` ledger record.
     */
    private function recordTransaction(
        InventoryStock $lockedStock,
        ?BloodUnit $bloodUnit,
        string $operationType,
        int $unitsDelta,
        int $availableBefore,
        int $availableAfter,
        int $reservedBefore,
        int $reservedAfter,
        User $actor,
        string $description,
        string $referenceType,
        ?int $referenceId,
        CarbonImmutable $occurredAt
    ): InventoryTransaction {
        return InventoryTransaction::query()->create([
            'transaction_code' => $this->codeGenerator->generate(InventoryTransaction::class, 'transaction_code', 'TXN-', 7),
            'inventory_stock_id' => $lockedStock->id,
            'blood_unit_id' => $bloodUnit?->id,
            'operation_type' => $operationType,
            'units_delta' => $unitsDelta,
            'available_before' => $availableBefore,
            'available_after' => $availableAfter,
            'reserved_before' => $reservedBefore,
            'reserved_after' => $reservedAfter,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'performed_by_user_id' => $actor->getKey(),
            'actor_name' => $actor->name,
            'description' => $description,
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * Validate and normalize manual adjustment payload before acquiring pessimistic locks.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     units_delta: int,
     *     blood_unit_ids: list<int>,
     *     target_unit_status: string,
     *     operation_type: string,
     *     minimum_level: ?int,
     *     target_capacity: ?int,
     *     description: string,
     *     reference_type: ?string,
     *     reference_id: ?int
     * }
     *
     * @throws ValidationException
     */
    private function validateAdjustmentAttributes(array $attributes): array
    {
        $description = $this->normalizeRequiredJustification($attributes);

        $unitsDelta = 0;

        if (array_key_exists('units_delta', $attributes) && $attributes['units_delta'] !== null && $attributes['units_delta'] !== '') {
            $rawDelta = $attributes['units_delta'];

            if (is_int($rawDelta)) {
                $unitsDelta = $rawDelta;
            } elseif (is_string($rawDelta) && preg_match('/^-?\d+$/', trim($rawDelta)) === 1) {
                $unitsDelta = (int) trim($rawDelta);
            } else {
                throw ValidationException::withMessages([
                    'units_delta' => 'The units_delta must be a valid integer.',
                ]);
            }
        }

        if ($unitsDelta > 0) {
            throw ValidationException::withMessages([
                'units_delta' => 'Positive stock adjustments are not permitted without screened BloodUnit release provenance via ScreeningService.',
            ]);
        }

        $targetUnitStatus = is_string($attributes['target_unit_status'] ?? null)
            ? trim($attributes['target_unit_status'])
            : 'Discarded';

        if (! array_key_exists($targetUnitStatus, self::ADJUSTMENT_TARGET_STATUS_OPERATIONS)) {
            throw ValidationException::withMessages([
                'target_unit_status' => 'Target unit status must be one of: '.implode(', ', array_keys(self::ADJUSTMENT_TARGET_STATUS_OPERATIONS)).'.',
            ]);
        }

        $operationType = is_string($attributes['operation_type'] ?? null)
            ? trim($attributes['operation_type'])
            : 'Manual Adjustment';

        if (! in_array($operationType, self::ADJUSTMENT_TARGET_STATUS_OPERATIONS[$targetUnitStatus], true)) {
            throw ValidationException::withMessages([
                'operation_type' => "Invalid operation_type '{$operationType}' for target unit status '{$targetUnitStatus}'.",
            ]);
        }

        $bloodUnitIds = [];

        if (array_key_exists('blood_unit_ids', $attributes) && $attributes['blood_unit_ids'] !== null) {
            if (! is_array($attributes['blood_unit_ids'])) {
                throw ValidationException::withMessages([
                    'blood_unit_ids' => 'The blood_unit_ids field must be a list of blood unit IDs.',
                ]);
            }

            foreach ($attributes['blood_unit_ids'] as $rawId) {
                $parsedId = $this->parseOptionalPositiveInt($rawId, 'blood_unit_ids');

                if ($parsedId === null) {
                    throw ValidationException::withMessages([
                        'blood_unit_ids' => 'Each blood_unit_ids entry must be a positive integer.',
                    ]);
                }

                $bloodUnitIds[] = $parsedId;
            }

            $uniqueUnitIds = array_values(array_unique($bloodUnitIds));

            if (count($uniqueUnitIds) !== count($bloodUnitIds)) {
                throw ValidationException::withMessages([
                    'blood_unit_ids' => 'Duplicate blood_unit_ids entries are not permitted.',
                ]);
            }

            $bloodUnitIds = $uniqueUnitIds;

            if ($unitsDelta === 0) {
                $unitsDelta = -count($bloodUnitIds);
            } elseif (count($bloodUnitIds) !== abs($unitsDelta)) {
                throw ValidationException::withMessages([
                    'blood_unit_ids' => 'The count of blood_unit_ids must exactly match the absolute units_delta.',
                ]);
            }
        }

        $minimumLevel = $this->parseOptionalPositiveInt($attributes['minimum_level'] ?? null, 'minimum_level');
        $targetCapacity = $this->parseOptionalPositiveInt($attributes['target_capacity'] ?? null, 'target_capacity');

        if ($unitsDelta === 0 && $minimumLevel === null && $targetCapacity === null) {
            throw ValidationException::withMessages([
                'units_delta' => 'A negative units_delta or threshold update (minimum_level / target_capacity) is required.',
            ]);
        }

        return [
            'units_delta' => $unitsDelta,
            'blood_unit_ids' => $bloodUnitIds,
            'target_unit_status' => $targetUnitStatus,
            'operation_type' => $operationType,
            'minimum_level' => $minimumLevel,
            'target_capacity' => $targetCapacity,
            'description' => $description,
            'reference_type' => $this->inputNormalizer->normalizeOptionalText($attributes['reference_type'] ?? null, 64, 'reference_type'),
            'reference_id' => $this->parseOptionalPositiveInt($attributes['reference_id'] ?? null, 'reference_id'),
        ];
    }

    /**
     * Validate optional description/reference context with a safe default audit description.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     description: string,
     *     reference_type: ?string,
     *     reference_id: ?int
     * }
     *
     * @throws ValidationException
     */
    private function validateMovementContext(array $attributes, string $defaultDescription): array
    {
        $rawDescription = $attributes['description'] ?? $attributes['reason'] ?? null;

        if ($rawDescription === null || (is_string($rawDescription) && trim($rawDescription) === '')) {
            $description = $defaultDescription;
        } else {
            $description = $this->normalizeRequiredJustification($attributes);
        }

        return [
            'description' => $description,
            'reference_type' => $this->inputNormalizer->normalizeOptionalText($attributes['reference_type'] ?? null, 64, 'reference_type'),
            'reference_id' => $this->parseOptionalPositiveInt($attributes['reference_id'] ?? null, 'reference_id'),
        ];
    }

    /**
     * Validate mandatory justification description (>= 3 characters per BUSINESS-RULES.md §2.3).
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function normalizeRequiredJustification(array $attributes): string
    {
        $rawDescription = $attributes['description'] ?? $attributes['reason'] ?? null;
        $description = $this->inputNormalizer->normalizeRequiredText(
            $rawDescription,
            2000,
            'description',
            'A justification description of at least 3 characters is required.'
        );

        if (mb_strlen($description) < 3) {
            throw ValidationException::withMessages([
                'description' => 'A justification description of at least 3 characters is required.',
            ]);
        }

        return $description;
    }

    /**
     * Parse an optional positive integer field or throw ValidationException.
     *
     * @throws ValidationException
     */
    private function parseOptionalPositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d*$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw ValidationException::withMessages([
            $field => "The {$field} value must be a positive integer.",
        ]);
    }
}
