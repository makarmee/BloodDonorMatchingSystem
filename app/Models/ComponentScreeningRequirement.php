<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('component_screening_requirements', key: 'id', keyType: 'int')]
#[Fillable(['blood_component_id', 'test_type_id', 'is_required', 'is_active', 'notes'])]
class ComponentScreeningRequirement extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Blood component governed by this screening requirement.
     */
    public function bloodComponent(): BelongsTo
    {
        return $this->belongsTo(BloodComponent::class, 'blood_component_id');
    }

    /**
     * Screening test type required for this component.
     */
    public function testType(): BelongsTo
    {
        return $this->belongsTo(ScreeningTestType::class, 'test_type_id');
    }

    /**
     * Scope query to active requirement rules.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query to active mandatory release screening requirements.
     */
    #[Scope]
    protected function mandatory(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_required', true);
    }
}

