<?php

namespace App\Models;

use Database\Factories\HospitalUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('hospital_users', key: 'id', keyType: 'int')]
#[Fillable([
    'hospital_id',
    'user_id',
    'contact_name',
    'department',
    'job_title',
    'work_phone',
    'work_email',
    'is_primary_contact',
    'is_active',
])]
class HospitalUser extends Model
{
    /** @use HasFactory<HospitalUserFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary_contact' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Partner hospital facility represented by this user profile.
     */
    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class, 'hospital_id');
    }

    /**
     * Login user account tied to this hospital coordinator profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope query to active hospital coordinator links.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query to primary hospital contact accounts.
     */
    #[Scope]
    protected function primaryContact(Builder $query): Builder
    {
        return $query->where('is_primary_contact', true);
    }
}
