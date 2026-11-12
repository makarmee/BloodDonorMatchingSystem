<?php

namespace App\Models;

use Database\Factories\DonorMedicalProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('donor_medical_profiles', key: 'id', keyType: 'int')]
#[Fillable([
    'donor_id',
    'medical_notes',
    'last_hemoglobin_g_dl',
    'weight_kg',
    'deferral_notes',
    'updated_by_user_id',
])]
class DonorMedicalProfile extends Model
{
    /** @use HasFactory<DonorMedicalProfileFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_hemoglobin_g_dl' => 'decimal:2',
            'weight_kg' => 'decimal:2',
        ];
    }

    /**
     * Donor owning this clinical medical profile.
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class, 'donor_id');
    }

    /**
     * Clinical staff user who last updated this medical profile.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
