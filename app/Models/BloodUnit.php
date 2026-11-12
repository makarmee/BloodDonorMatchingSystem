<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\BloodUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Table('blood_units', key: 'id', keyType: 'int')]
#[Fillable([
    'unit_code',
    'donation_id',
    'parent_unit_id',
    'blood_group',
    'blood_component_id',
    'volume_ml',
    'storage_location',
    'unit_status',
    'collected_at',
    'expires_at',
    'released_at',
    'released_by_user_id',
])]
class BloodUnit extends Model
{
    /** @use HasFactory<BloodUnitFactory> */
    use HasFactory;

    /**
     * Valid blood group values.
     *
     * @var list<string>
     */
    public const array BLOOD_GROUPS = Donor::BLOOD_GROUPS;

    /**
     * Valid serialized blood unit lifecycle statuses.
     *
     * @var list<string>
     */
    public const array UNIT_STATUSES = [
        'Quarantined',
        'Available',
        'Reserved',
        'Dispatched',
        'Transfused',
        'Expired',
        'Discarded',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'volume_ml' => 'integer',
            'collected_at' => 'datetime',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * Provenance donation that produced this blood unit.
     */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class, 'donation_id');
    }

    /**
     * Optional parent collection bag from which this component bag was fractionated.
     */
    public function parentUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_unit_id');
    }

    /**
     * Derived component units fractionated from this parent blood unit.
     */
    public function childUnits(): HasMany
    {
        return $this->hasMany(self::class, 'parent_unit_id');
    }

    /**
     * Blood component type of this serialized bag.
     */
    public function bloodComponent(): BelongsTo
    {
        return $this->belongsTo(BloodComponent::class, 'blood_component_id');
    }

    /**
     * Officer who authorized release of this unit into available inventory.
     */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    /**
     * Ledger transactions referencing this blood unit.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'blood_unit_id');
    }

    /**
     * All historical and current allocations referencing this blood unit.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryAllocation::class, 'blood_unit_id');
    }

    /**
     * Active reservation or dispatch allocation holding this blood unit.
     */
    public function activeAllocation(): HasOne
    {
        return $this->hasOne(InventoryAllocation::class, 'blood_unit_id')
            ->whereIn('allocation_status', ['Reserved', 'Dispatched']);
    }

    /**
     * Scope query to allocatable available blood units.
     */
    #[Scope]
    protected function available(Builder $query): Builder
    {
        return $query->where('unit_status', 'Available');
    }

    /**
     * Scope query to non-expired available units ordered earliest-expiry first for FIFO allocation.
     */
    #[Scope]
    protected function fifoAvailable(
        Builder $query,
        string $bloodGroup,
        int $componentId,
        ?CarbonInterface $asOf = null
    ): Builder {
        return $query
            ->where('blood_group', $bloodGroup)
            ->where('blood_component_id', $componentId)
            ->where('unit_status', 'Available')
            ->where('expires_at', '>', $asOf ?? now())
            ->orderBy('expires_at');
    }

    /**
     * Scope query to units expiring on or before the specified timestamp.
     */
    #[Scope]
    protected function expiringBefore(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query->where('expires_at', '<=', $cutoff);
    }
}
