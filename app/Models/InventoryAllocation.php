<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('inventory_allocations', key: 'id', keyType: 'int')]
#[Fillable([
    'allocation_code',
    'blood_request_id',
    'inventory_stock_id',
    'blood_unit_id',
    'fulfillment_id',
    'units_allocated',
    'allocation_status',
    'allocated_by_user_id',
    'allocated_at',
    'released_at',
    'dispatched_at',
    'notes',
])]
class InventoryAllocation extends Model
{
    /**
     * Valid allocation lifecycle statuses.
     *
     * @var list<string>
     */
    public const array ALLOCATION_STATUSES = ['Reserved', 'Dispatched', 'Released', 'Cancelled'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units_allocated' => 'integer',
            'active_unit_guard' => 'boolean',
            'allocated_at' => 'datetime',
            'released_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }

    /**
     * Blood request receiving this unit allocation.
     */
    public function bloodRequest(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class, 'blood_request_id');
    }

    /**
     * Inventory stock bucket from which this allocation originates.
     */
    public function inventoryStock(): BelongsTo
    {
        return $this->belongsTo(InventoryStock::class, 'inventory_stock_id');
    }

    /**
     * Serialized blood unit reserved or dispatched by this allocation.
     */
    public function bloodUnit(): BelongsTo
    {
        return $this->belongsTo(BloodUnit::class, 'blood_unit_id');
    }

    /**
     * Optional dispatch batch associated with a dispatched allocation.
     */
    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(RequestFulfillment::class, 'fulfillment_id');
    }

    /**
     * Staff user who performed this unit allocation.
     */
    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by_user_id');
    }

    /**
     * Scope query to active unit holds and dispatches (`Reserved` or `Dispatched`).
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->whereIn('allocation_status', ['Reserved', 'Dispatched']);
    }

    /**
     * Scope query to held reservations awaiting dispatch or release.
     */
    #[Scope]
    protected function reserved(Builder $query): Builder
    {
        return $query->where('allocation_status', 'Reserved');
    }
}

