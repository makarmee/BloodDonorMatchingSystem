<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Table('users', key: 'id', keyType: 'int')]
#[Fillable(['name', 'email', 'password', 'is_active', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Roles assigned to this user.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id')
            ->withPivot(['assigned_by_user_id', 'assigned_at']);
    }

    /**
     * Optional linked donor profile for portal access.
     */
    public function donor(): HasOne
    {
        return $this->hasOne(Donor::class, 'user_id');
    }

    /**
     * Optional linked hospital coordinator profile.
     */
    public function hospitalUser(): HasOne
    {
        return $this->hasOne(HospitalUser::class, 'user_id');
    }

    /**
     * Donor medical profiles last updated by this user.
     */
    public function updatedMedicalProfiles(): HasMany
    {
        return $this->hasMany(DonorMedicalProfile::class, 'updated_by_user_id');
    }

    /**
     * Donor eligibility evaluations reviewed by this user.
     */
    public function reviewedEligibilityRecords(): HasMany
    {
        return $this->hasMany(DonorEligibilityRecord::class, 'reviewed_by_user_id');
    }

    /**
     * Donations recorded by this staff user.
     */
    public function recordedDonations(): HasMany
    {
        return $this->hasMany(Donation::class, 'recorded_by_user_id');
    }

    /**
     * Donations approved for inventory release by this user.
     */
    public function approvedDonations(): HasMany
    {
        return $this->hasMany(Donation::class, 'approved_by_user_id');
    }

    /**
     * Screening records performed by this lab user.
     */
    public function performedScreenings(): HasMany
    {
        return $this->hasMany(ScreeningRecord::class, 'performed_by_user_id');
    }

    /**
     * Blood units released to available stock by this user.
     */
    public function releasedBloodUnits(): HasMany
    {
        return $this->hasMany(BloodUnit::class, 'released_by_user_id');
    }

    /**
     * Inventory transactions performed by this user.
     */
    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'performed_by_user_id');
    }

    /**
     * Hospitals verified by this administrator.
     */
    public function verifiedHospitals(): HasMany
    {
        return $this->hasMany(Hospital::class, 'verified_by_user_id');
    }

    /**
     * Blood requests submitted by this user.
     */
    public function requestedBloodRequests(): HasMany
    {
        return $this->hasMany(BloodRequest::class, 'requested_by_user_id');
    }

    /**
     * Blood requests reviewed by this user.
     */
    public function reviewedBloodRequests(): HasMany
    {
        return $this->hasMany(BloodRequest::class, 'reviewed_by_user_id');
    }

    /**
     * Blood requests cancelled by this user.
     */
    public function cancelledBloodRequests(): HasMany
    {
        return $this->hasMany(BloodRequest::class, 'cancelled_by_user_id');
    }

    /**
     * Request status history entries authored by this user.
     */
    public function bloodRequestStatusHistories(): HasMany
    {
        return $this->hasMany(BloodRequestStatusHistory::class, 'actor_user_id');
    }

    /**
     * Request fulfillments dispatched by this user.
     */
    public function dispatchedFulfillments(): HasMany
    {
        return $this->hasMany(RequestFulfillment::class, 'dispatched_by_user_id');
    }

    /**
     * Request fulfillments whose compatibility override was approved by this user.
     */
    public function approvedCompatibilityOverrides(): HasMany
    {
        return $this->hasMany(RequestFulfillment::class, 'override_approved_by_user_id');
    }

    /**
     * Inventory allocations performed by this user.
     */
    public function inventoryAllocations(): HasMany
    {
        return $this->hasMany(InventoryAllocation::class, 'allocated_by_user_id');
    }

    /**
     * Donation camps created by this user.
     */
    public function createdDonationCamps(): HasMany
    {
        return $this->hasMany(DonationCamp::class, 'created_by_user_id');
    }

    /**
     * Camp registrations recorded by this user.
     */
    public function campRegistrationsLogged(): HasMany
    {
        return $this->hasMany(CampRegistration::class, 'registered_by_user_id');
    }

    /**
     * Camp status history entries authored by this user.
     */
    public function campStatusHistories(): HasMany
    {
        return $this->hasMany(CampStatusHistory::class, 'actor_user_id');
    }

    /**
     * Appointment status history entries authored by this user.
     */
    public function appointmentStatusHistories(): HasMany
    {
        return $this->hasMany(AppointmentStatusHistory::class, 'actor_user_id');
    }

    /**
     * Scope query to active user accounts.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query to users holding a specific role slug.
     */
    #[Scope]
    protected function withRole(Builder $query, string $roleSlug): Builder
    {
        return $query->whereHas('roles', fn (Builder $roleQuery): Builder => $roleQuery->where('slug', $roleSlug));
    }

    /**
     * Determine whether this user is assigned the given role slug.
     */
    public function hasRole(string $roleSlug): bool
    {
        return $this->roles()->where('slug', $roleSlug)->exists();
    }

    /**
     * Determine whether this user holds at least one of the specified role slugs.
     *
     * @param  list<string>  $roleSlugs
     */
    public function hasAnyRole(array $roleSlugs): bool
    {
        if ($roleSlugs === []) {
            return false;
        }

        return $this->roles()->whereIn('slug', $roleSlugs)->exists();
    }

    /**
     * Determine whether this user has been granted the given permission slug via any role.
     */
    public function hasPermission(string $permissionSlug): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn (Builder $query): Builder => $query->where('slug', $permissionSlug))
            ->exists();
    }

    /**
     * Resolve the user's primary BDMS role slug in operational precedence order.
     */
    public function primaryRoleSlug(): ?string
    {
        $this->loadMissing('roles');
        $roleSlugs = $this->roles->pluck('slug')->all();

        foreach (['admin', 'lab_officer', 'staff', 'hospital', 'donor'] as $candidate) {
            if (in_array($candidate, $roleSlugs, true)) {
                return $candidate;
            }
        }

        return $roleSlugs[0] ?? null;
    }

    /**
     * Resolve the default dashboard route name appropriate for the user's assigned roles.
     */
    public function dashboardRouteName(): string
    {
        return $this->primaryRoleSlug() === 'donor'
            ? 'donor-portal.dashboard'
            : 'dashboard';
    }
}
