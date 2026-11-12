<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('blood_components', key: 'id', keyType: 'int')]
#[Fillable(['code', 'name', 'default_volume_ml', 'shelf_life_days', 'storage_requirements', 'is_active'])]
class BloodComponent extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_volume_ml' => 'integer',
            'shelf_life_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Screening requirement configurations for this component.
     */
    public function screeningRequirements(): HasMany
    {
        return $this->hasMany(ComponentScreeningRequirement::class, 'blood_component_id');
    }

    /**
     * Screening test types configured for this component.
     */
    public function screeningTestTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            ScreeningTestType::class,
            'component_screening_requirements',
            'blood_component_id',
            'test_type_id'
        )
            ->withPivot(['is_required', 'is_active', 'notes'])
            ->withTimestamps();
    }

    /**
     * Donations yielding this blood component.
     */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'blood_component_id');
    }

    /**
     * Inventory stock buckets tracked for this component.
     */
    public function inventoryStocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'blood_component_id');
    }

    /**
     * Serialized blood units of this component.
     */
    public function bloodUnits(): HasMany
    {
        return $this->hasMany(BloodUnit::class, 'blood_component_id');
    }

    /**
     * Compatibility rules governing this component.
     */
    public function compatibilityRules(): HasMany
    {
        return $this->hasMany(BloodCompatibilityRule::class, 'blood_component_id');
    }

    /**
     * Blood requests explicitly targeting this component.
     */
    public function bloodRequests(): HasMany
    {
        return $this->hasMany(BloodRequest::class, 'blood_component_id');
    }

    /**
     * Request fulfillments dispatching this component.
     */
    public function requestFulfillments(): HasMany
    {
        return $this->hasMany(RequestFulfillment::class, 'blood_component_id');
    }

    /**
     * Donor appointments scheduled for this component.
     */
    public function donorAppointments(): HasMany
    {
        return $this->hasMany(DonorAppointment::class, 'donation_component_id');
    }

    /**
     * Scope query to active blood components.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query to a component matching the given code.
     */
    #[Scope]
    protected function byCode(Builder $query, string $code): Builder
    {
        return $query->where('code', $code);
    }
}

