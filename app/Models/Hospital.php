<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('hospitals', key: 'id', keyType: 'int')]
#[Fillable([
    'hospital_code',
    'name',
    'address',
    'city',
    'primary_contact_name',
    'primary_phone',
    'primary_email',
    'verification_status',
    'verified_by_user_id',
    'verified_at',
    'is_active',
])]
class Hospital extends Model
{
    /**
     * Valid hospital onboarding verification statuses.
     *
     * @var list<string>
     */
    public const array VERIFICATION_STATUSES = ['Pending', 'Verified', 'Suspended'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Administrator who verified this hospital facility.
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /**
     * Coordinator user links belonging to this hospital.
     */
    public function hospitalUsers(): HasMany
    {
        return $this->hasMany(HospitalUser::class, 'hospital_id');
    }

    /**
     * Portal user accounts associated with this hospital.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'hospital_users', 'hospital_id', 'user_id')
            ->withPivot([
                'contact_name',
                'department',
                'job_title',
                'work_phone',
                'work_email',
                'is_primary_contact',
                'is_active',
            ])
            ->withTimestamps();
    }

    /**
     * Blood requests placed by this hospital.
     */
    public function bloodRequests(): HasMany
    {
        return $this->hasMany(BloodRequest::class, 'hospital_id');
    }

    /**
     * Scope query to active hospital facilities.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query to active, verified hospitals permitted to place blood requests.
     */
    #[Scope]
    protected function verified(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('verification_status', 'Verified');
    }
}

