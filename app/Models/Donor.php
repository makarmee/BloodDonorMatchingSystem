<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Table('donors', key: 'id', keyType: 'int')]
#[Fillable([
    'donor_code',
    'user_id',
    'full_name',
    'email',
    'phone',
    'gender',
    'date_of_birth',
    'blood_group',
    'address',
    'emergency_contact',
    'availability_status',
    'account_status',
    'registered_at',
])]
class Donor extends Model
{
    /**
     * Valid ABO/Rh blood group values supported across BDMS.
     *
     * @var list<string>
     */
    public const array BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /**
     * Valid donor gender values.
     *
     * @var list<string>
     */
    public const array GENDERS = ['Female', 'Male', 'Other'];

    /**
     * Valid donor outreach availability statuses.
     *
     * @var list<string>
     */
    public const array AVAILABILITY_STATUSES = ['Available', 'Temporarily Unavailable', 'Inactive'];

    /**
     * Valid donor eligibility statuses synchronized from eligibility records.
     *
     * @var list<string>
     */
    public const array ELIGIBILITY_STATUSES = ['Eligible', 'Not Eligible', 'Pending Review', 'Unknown'];

    /**
     * Valid donor account statuses.
     *
     * @var list<string>
     */
    public const array ACCOUNT_STATUSES = ['Active', 'Inactive'];

    /**
     * Required profile attributes that must be populated before appointment booking.
     *
     * @var list<string>
     */
    public const array REQUIRED_PROFILE_FIELDS = [
        'full_name',
        'email',
        'phone',
        'gender',
        'date_of_birth',
        'blood_group',
        'address',
        'emergency_contact',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'last_donation_date' => 'date',
            'next_eligible_date' => 'date',
            'total_completed_donations' => 'integer',
            'profile_completed' => 'boolean',
            'registered_at' => 'date',
        ];
    }

    /**
     * Optional user login linked to this donor profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Isolated 1:1 clinical medical profile for this donor.
     */
    public function medicalProfile(): HasOne
    {
        return $this->hasOne(DonorMedicalProfile::class, 'donor_id');
    }

    /**
     * Longitudinal eligibility evaluation records for this donor.
     */
    public function eligibilityRecords(): HasMany
    {
        return $this->hasMany(DonorEligibilityRecord::class, 'donor_id');
    }

    /**
     * Authoritative current eligibility evaluation record (`is_current = true`).
     */
    public function currentEligibilityRecord(): HasOne
    {
        return $this->hasOne(DonorEligibilityRecord::class, 'donor_id')->where('is_current', true);
    }

    /**
     * Blood donations recorded for this donor.
     */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'donor_id');
    }

    /**
     * Scheduled appointments booked by or for this donor.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(DonorAppointment::class, 'donor_id');
    }

    /**
     * Donation camp registrations associated with this donor.
     */
    public function campRegistrations(): HasMany
    {
        return $this->hasMany(CampRegistration::class, 'donor_id');
    }

    /**
     * Scope query to active donor accounts.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('account_status', 'Active');
    }

    /**
     * Scope query to outreach-available donor accounts.
     */
    #[Scope]
    protected function available(Builder $query): Builder
    {
        return $query->where('availability_status', 'Available');
    }

    /**
     * Scope query to donors whose authoritative current eligibility record is Eligible.
     */
    #[Scope]
    protected function eligible(Builder $query): Builder
    {
        return $query->whereHas(
            'currentEligibilityRecord',
            fn (Builder $eligibilityQuery): Builder => $eligibilityQuery->where('eligibility_status', 'Eligible')
        );
    }

    /**
     * Scope query to donors with completed required profile fields.
     */
    #[Scope]
    protected function profileCompleted(Builder $query): Builder
    {
        return $query->where('profile_completed', true);
    }

    /**
     * Scope query to donors with a given blood group.
     */
    #[Scope]
    protected function forBloodGroup(Builder $query, string $bloodGroup): Builder
    {
        return $query->where('blood_group', $bloodGroup);
    }
}

