<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('blood_compatibility_rules', key: 'id', keyType: 'int')]
#[Fillable([
    'recipient_blood_group',
    'donor_blood_group',
    'blood_component_id',
    'compatibility_level',
    'requires_medical_override',
    'is_active',
    'notes',
])]
class BloodCompatibilityRule extends Model
{
    /**
     * Valid blood group values.
     *
     * @var list<string>
     */
    public const array BLOOD_GROUPS = Donor::BLOOD_GROUPS;

    /**
     * Valid clinical compatibility tiers.
     *
     * @var list<string>
     */
    public const array COMPATIBILITY_LEVELS = ['Exact Match', 'Compatible Substitution', 'Emergency Only'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_medical_override' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Blood component governed by this clinical compatibility rule.
     */
    public function bloodComponent(): BelongsTo
    {
        return $this->belongsTo(BloodComponent::class, 'blood_component_id');
    }

    /**
     * Scope query to active compatibility rules.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query to rules matching a recipient group, donor group, and blood component.
     */
    #[Scope]
    protected function forPair(
        Builder $query,
        string $recipientBloodGroup,
        string $donorBloodGroup,
        int $componentId
    ): Builder {
        return $query
            ->where('recipient_blood_group', $recipientBloodGroup)
            ->where('donor_blood_group', $donorBloodGroup)
            ->where('blood_component_id', $componentId);
    }
}

