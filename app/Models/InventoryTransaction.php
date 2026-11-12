<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('inventory_transactions', key: 'id', keyType: 'int')]
#[Fillable([
    'transaction_code',
    'inventory_stock_id',
    'blood_unit_id',
    'operation_type',
    'units_delta',
    'available_before',
    'available_after',
    'reserved_before',
    'reserved_after',
    'reference_type',
    'reference_id',
    'performed_by_user_id',
    'actor_name',
    'description',
    'occurred_at',
])]
class InventoryTransaction extends Model
{
    /**
     * Disable updated_at because inventory_transactions is an immutable append-only ledger.
     */
    public const ?string UPDATED_AT = null;

    /**
     * Valid inventory ledger operation types.
     *
     * @var list<string>
     */
    public const array OPERATION_TYPES = [
        'Stock In',
        'Reserve',
        'Release Reservation',
        'Dispatch',
        'Removed',
        'Expired',
        'Discarded',
        'Manual Adjustment',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units_delta' => 'integer',
            'available_before' => 'integer',
            'available_after' => 'integer',
            'reserved_before' => 'integer',
            'reserved_after' => 'integer',
            'reference_id' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Stock bucket whose balance was mutated.
     */
    public function inventoryStock(): BelongsTo
    {
        return $this->belongsTo(InventoryStock::class, 'inventory_stock_id');
    }

    /**
     * Optional serialized blood unit referenced by this movement.
     */
    public function bloodUnit(): BelongsTo
    {
        return $this->belongsTo(BloodUnit::class, 'blood_unit_id');
    }

    /**
     * Staff user who executed this stock adjustment.
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    /**
     * Scope query to ledger entries targeting a specific domain reference pair.
     */
    #[Scope]
    protected function forReference(Builder $query, string $referenceType, int $referenceId): Builder
    {
        return $query
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId);
    }

    /**
     * Scope query to ledger entries of a given operation type.
     */
    #[Scope]
    protected function ofOperation(Builder $query, string $operationType): Builder
    {
        return $query->where('operation_type', $operationType);
    }
}

