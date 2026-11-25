<?php

namespace App\Models;

use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('staff', key: 'id', keyType: 'int')]
#[Fillable([
    'employee_id',
    'user_id',
    'full_name',
    'professional_role',
    'department',
    'phone',
    'email',
    'status',
    'notes',
])]
class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use HasFactory;

    /**
     * Valid employment statuses for BDMS staff members.
     *
     * @var list<string>
     */
    public const array STATUSES = ['Active', 'Inactive'];

    /**
     * Controlled medical and operational professional positions.
     *
     * @var list<string>
     */
    public const array PROFESSIONAL_ROLES = [
        'Doctor / Physician',
        'Nurse',
        'Laboratory Technician',
        'Phlebotomist',
        'Blood Bank Officer',
        'Medical Officer',
        'Donation Officer',
    ];

    /**
     * Controlled operational departments across BDMS facilities.
     *
     * @var list<string>
     */
    public const array DEPARTMENTS = [
        'Donor Collection & Phlebotomy',
        'Laboratory & Screening',
        'Blood Inventory & Cold Chain',
        'Transfusion & Fulfillment',
        'Mobile Camps & Outreach',
        'Administration & Quality',
    ];

    /**
     * Optional system login account linked to this staff profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Donations attended or collected by this staff member.
     */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'staff_id');
    }

    /**
     * Scope query to active staff members eligible for new donation assignments.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('status', 'Active');
    }

    /**
     * Determine whether this staff member is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === 'Active';
    }

    /**
     * Format staff member display label showing full name and employee ID.
     */
    public function displayLabel(): string
    {
        return "{$this->full_name} ({$this->employee_id})";
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'employee_id';
    }

    /**
     * Resolve route binding by employee_id or numeric primary key.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->where($field ?? 'employee_id', $value)
            ->when(is_numeric($value), fn (Builder $query): Builder => $query->orWhere('id', (int) $value))
            ->first();
    }
}
