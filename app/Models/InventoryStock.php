<?php

namespace App\Models;

use Database\Factories\InventoryStockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('inventory_stocks', key: 'id', keyType: 'int')]
#[Fillable([
    'blood_group',
    'blood_component_id',
    'available_units',
    'reserved_units',
    'minimum_level',
    'target_capacity',
    'last_adjusted_at',
])]
class InventoryStock extends Model
{
    /** @use HasFactory<InventoryStockFactory> */
    use HasFactory;

    /**
     * Valid blood group values.
     *
     * @var list<string>
     */
    public const array BLOOD_GROUPS = Donor::BLOOD_GROUPS;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'available_units' => 'integer',
            'reserved_units' => 'integer',
            'minimum_level' => 'integer',
            'target_capacity' => 'integer',
            'last_adjusted_at' => 'datetime',
        ];
    }

    /**
     * Blood component classified by this stock bucket.
     */
    public function bloodComponent(): BelongsTo
    {
        return $this->belongsTo(BloodComponent::class, 'blood_component_id');
    }

    /**
     * Append-only ledger transactions affecting this stock bucket.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'inventory_stock_id');
    }

    /**
     * Request unit allocations sourced from this stock bucket.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryAllocation::class, 'inventory_stock_id');
    }

    /**
     * Scope query to stock buckets matching a blood group.
     */
    #[Scope]
    protected function forBloodGroup(Builder $query, string $bloodGroup): Builder
    {
        return $query->where('blood_group', $bloodGroup);
    }

    /**
     * Scope query to stock buckets matching a blood component ID.
     */
    #[Scope]
    protected function forComponent(Builder $query, int $componentId): Builder
    {
        return $query->where('blood_component_id', $componentId);
    }

    /**
     * Scope query to low or critical stock buckets at or below minimum threshold.
     */
    #[Scope]
    protected function lowOrCritical(Builder $query): Builder
    {
        return $query->whereColumn('available_units', '<=', 'minimum_level');
    }
}
