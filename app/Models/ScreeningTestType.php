<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('screening_test_types', key: 'id', keyType: 'int')]
#[Fillable(['code', 'name', 'description', 'is_active'])]
class ScreeningTestType extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Component screening requirement rows referencing this test type.
     */
    public function componentRequirements(): HasMany
    {
        return $this->hasMany(ComponentScreeningRequirement::class, 'test_type_id');
    }

    /**
     * Blood components linked to this screening test type.
     */
    public function bloodComponents(): BelongsToMany
    {
        return $this->belongsToMany(
            BloodComponent::class,
            'component_screening_requirements',
            'test_type_id',
            'blood_component_id'
        )
            ->withPivot(['is_required', 'is_active', 'notes'])
            ->withTimestamps();
    }

    /**
     * Individual screening records performed using this test type.
     */
    public function screeningRecords(): HasMany
    {
        return $this->hasMany(ScreeningRecord::class, 'test_type_id');
    }

    /**
     * Scope query to active screening test types.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query to a test type matching the given code.
     */
    #[Scope]
    protected function byCode(Builder $query, string $code): Builder
    {
        return $query->where('code', $code);
    }
}

